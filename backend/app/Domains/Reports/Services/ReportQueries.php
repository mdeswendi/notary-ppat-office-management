<?php

namespace App\Domains\Reports\Services;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Billing\BillingVisibility;
use App\Domains\Document\DocumentVisibility;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\MatterVisibility;
use App\Domains\Notary\NotaryDeedVisibility;
use App\Domains\Ppat\PpatDeedVisibility;
use App\Domains\Ppat\PropertyVisibility;
use App\Domains\Task\TaskVisibility;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\NotaryDeed;
use App\Models\Payment;
use App\Models\PpatDeed;
use App\Models\PpatWarkah;
use App\Models\Property;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * The scoped source query behind every report (M8.3, D-126).
 *
 * ## Opening a report and reading its rows are two different questions
 *
 * `ReportPolicy` answers the first: may this actor open the financial family at
 * all. **This class answers the second**, and it does so through each source
 * domain's own visibility class under that domain's own capability — never under
 * a `reports.*` code.
 *
 * So an actor holding `reports.operational.view` and nothing else gets a Matter
 * report that is correctly **empty**, and one whose `tasks.view` scope is `OWN`
 * gets a task report of their own work rather than the Office's. That is the
 * lock's ruling in one sentence: *a report is a list with arithmetic on it, and
 * the arithmetic does not widen the list*.
 *
 * **Every method here returns a query, never rows.** The caller paginates it or
 * hands it to {@see ReportExporter}, and both see the same predicate — which is
 * what makes "export produces the same rows the actor just saw" a property of the
 * code rather than a promise in a comment.
 *
 * ## Matters and deeds are per-domain
 *
 * `notary.matters.view` and `ppat.matters.view` are separate grants (D-101), as
 * are `notary.deeds.view` and `ppat.deeds.view`. An actor holding one never sees
 * the other's rows in any report — the same union `DashboardAggregator` performs.
 */
class ReportQueries
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly MatterVisibility $matters,
        private readonly TaskVisibility $tasks,
        private readonly DocumentVisibility $documents,
        private readonly NotaryDeedVisibility $notaryDeeds,
        private readonly PpatDeedVisibility $ppatDeeds,
        private readonly PropertyVisibility $properties,
        private readonly BillingVisibility $billing,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Operational
    |--------------------------------------------------------------------------
    */

    /**
     * Matters the actor may read, across whichever domains they hold.
     *
     * @return Builder<Matter>
     */
    public function matters(User $actor): Builder
    {
        $reachable = [];

        foreach (MatterDomain::cases() as $domain) {
            $code = $domain === MatterDomain::NOTARY ? 'notary.matters.view' : 'ppat.matters.view';
            $access = $this->resolver->resolve($actor, $code);

            if ($this->matters->hasUsableScope($access)) {
                $reachable[] = [$domain, $access];
            }
        }

        $query = Matter::query()->with([
            'project:id,project_number,title',
            'picUser:id,name',
            'serviceType:id,code,name_id,name_en',
        ]);

        if ($reachable === []) {
            // No usable predicate is not "no restriction" — it is no access.
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $outer) use ($actor, $reachable): void {
            foreach ($reachable as [$domain, $access]) {
                $outer->orWhere(function (Builder $branch) use ($actor, $access, $domain): void {
                    $branch->where('matters.domain', $domain->value);
                    $this->matters->scope($branch, $actor, $access);
                });
            }
        });
    }

    /**
     * @return Builder<Task>
     */
    public function tasks(User $actor): Builder
    {
        $access = $this->resolver->resolve($actor, 'tasks.view');

        return $this->tasks->scope(
            Task::query()->with([
                'matter:id,matter_number,title',
                'project:id,project_number,title',
                'assignee:id,name',
            ]),
            $actor,
            $access,
        );
    }

    /**
     * @return Builder<Document>
     */
    public function documents(User $actor): Builder
    {
        $access = $this->resolver->resolve($actor, 'documents.view');

        $query = $this->documents->scope(
            Document::query()->with('creator:id,name'),
            $actor,
            $access,
        );

        // **Sensitive documents are excluded unless the actor may reach them**,
        // exactly as `DocumentController` does it (D-115). A report is a reading
        // surface like any other, and the separate `documents.sensitive.view`
        // capability applies to it identically — a count that quietly included
        // KTP scans would disclose that they exist.
        if (! $this->documents->hasUsableScope($this->resolver->resolve($actor, 'documents.sensitive.view'))) {
            $query->where('is_sensitive', false);
        }

        return $query;
    }

    /*
    |--------------------------------------------------------------------------
    | Notary and PPAT
    |--------------------------------------------------------------------------
    */

    /**
     * @return Builder<NotaryDeed>
     */
    public function notaryDeeds(User $actor): Builder
    {
        $access = $this->resolver->resolve($actor, 'notary.deeds.view');

        if (! $this->notaryDeeds->hasUsableScope($access)) {
            return NotaryDeed::query()->whereRaw('1 = 0');
        }

        return $this->notaryDeeds->scope(
            NotaryDeed::query()->with('matter:id,matter_number,title'),
            $actor,
            $access,
        );
    }

    /**
     * @return Builder<PpatDeed>
     */
    public function ppatDeeds(User $actor): Builder
    {
        $access = $this->resolver->resolve($actor, 'ppat.deeds.view');

        if (! $this->ppatDeeds->hasUsableScope($access)) {
            return PpatDeed::query()->whereRaw('1 = 0');
        }

        return $this->ppatDeeds->scope(
            PpatDeed::query()->with('matter:id,matter_number,title'),
            $actor,
            $access,
        );
    }

    /**
     * Land objects the actor may read.
     *
     * **No status filter is offered**, and this is not an oversight:
     * `properties.status` has no vocabulary in `03_DATABASE_ERD.md` and nothing
     * in the application writes it (M7.3). Filtering on it would narrow by a
     * column that is null on every row, and grouping by it would produce one
     * bucket labelled nothing. `property_type` and `right_type` are real, and are
     * what this report reports.
     *
     * @return Builder<Property>
     */
    public function properties(User $actor): Builder
    {
        $access = $this->resolver->resolve($actor, 'properties.view');

        if (! $this->properties->hasUsableScope($access)) {
            return Property::query()->whereRaw('1 = 0');
        }

        return $this->properties->scope(Property::query(), $actor, $access);
    }

    /**
     * Warkah bundles, with the completeness the office has actually reached.
     *
     * `completeness_percentage` is a **stored** column maintained by
     * `PpatWarkah::recalculateCompleteness()`, so a range filter here is an
     * ordinary SQL comparison rather than a computed-column workaround.
     *
     * Reached through the parent deed's visibility, because a Warkah's reach *is*
     * its deed's reach (M7.4) — but under `ppat.warkah.view`, its own capability.
     *
     * @return Builder<PpatWarkah>
     */
    public function warkah(User $actor): Builder
    {
        $access = $this->resolver->resolve($actor, 'ppat.warkah.view');

        if (! $this->ppatDeeds->hasUsableScope($access)) {
            return PpatWarkah::query()->whereRaw('1 = 0');
        }

        $reachableDeeds = $this->ppatDeeds
            ->scope(PpatDeed::query(), $actor, $access)
            ->select('ppat_deeds.id');

        return PpatWarkah::query()
            ->with('deed:id,deed_number,title')
            ->whereIn('ppat_deed_id', $reachableDeeds);
    }

    /*
    |--------------------------------------------------------------------------
    | Financial
    |--------------------------------------------------------------------------
    */

    /**
     * @return Builder<Invoice>
     */
    public function invoices(User $actor): Builder
    {
        $access = $this->resolver->resolve($actor, 'invoices.view');

        if (! $this->billing->hasUsableScope($access)) {
            return Invoice::query()->whereRaw('1 = 0');
        }

        return $this->billing
            ->scope(
                Invoice::query()->with([
                    'clientParty:id,display_name',
                    'project:id,project_number,title',
                    'matter:id,matter_number,title',
                ]),
                $actor,
                $access,
            )
            ->withSettlement();
    }

    /**
     * @return Builder<Payment>
     */
    public function payments(User $actor): Builder
    {
        $access = $this->resolver->resolve($actor, 'payments.view');

        if (! $this->billing->hasUsableScope($access)) {
            return Payment::query()->whereRaw('1 = 0');
        }

        return $this->billing->scope(
            Payment::query()->with('invoice:id,invoice_number,title,client_party_id'),
            $actor,
            $access,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Audit
    |--------------------------------------------------------------------------
    */

    /**
     * The audit trail, under `reports.audit.view`.
     *
     * **A second surface over `audit_logs`, and a legitimate one.** M8.1 built
     * `GET /api/v1/audit-logs` under `audit.view`; this reads the same table
     * under a different canonical code. Both exist in the catalogue and neither
     * implies the other (D-091) — an office may give a compliance reviewer the
     * report without giving them the operational audit surface, or the reverse.
     *
     * **Filters, not a second route** (D-118). "The trail for this deed" is
     * `auditable_type` plus `auditable_id` on this address; the M8.3 brief
     * proposed a separate `/reports/audit/trail`, which would be the same rows
     * at a second URL.
     *
     * @return Builder<AuditLog>
     */
    public function audit(User $actor): Builder
    {
        $access = $this->resolver->resolve($actor, 'reports.audit.view');

        if (! $access->granted) {
            throw new RuntimeException('The audit report was reached without reports.audit.view.');
        }

        $query = AuditLog::query()->with('actor:id,name');

        // `ALL` reads across Offices; everything narrower is confined to the
        // actor's own — the same predicate `AuditLogController` applies.
        if (! $access->hasScope(DataScope::ALL)) {
            $query->where('office_id', $actor->office_id);
        }

        return $query;
    }
}
