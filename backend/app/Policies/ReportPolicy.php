<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Authorization\Enums\DataScope;
use App\Models\User;

/**
 * Who may read which report family, and who may take one away (M8.3, D-126).
 *
 * Six abilities for six canonical codes — five `reports.*.view` and
 * `reports.export`. **No seventh**, and in particular nothing here touches
 * `ppat.reports.generate`, `.review` or `.approve`: those five codes belong to
 * the PPAT **monthly reporting obligation**, whose deadline, recipient and format
 * nobody in this repository has authored (O-043). `reports.ppat.view` and
 * `ppat.reports.view` differ only in word order and are different capabilities —
 * the lock's section 3.2 states it twice for that reason.
 *
 * ## Opening a report is not the same as reading its rows
 *
 * These abilities answer *"may this actor open this family at all"*. **What rows
 * come back is decided separately**, by each source domain's own visibility class
 * and Data Scope — a Matter report runs through `MatterVisibility` under
 * `notary.matters.view` or `ppat.matters.view`, not under `reports.operational.view`.
 *
 * That separation is the whole of the lock's ruling that *"a report is a list
 * with arithmetic on it; the arithmetic does not widen the list"*. Holding
 * `reports.operational.view` and nothing else opens a page that is correctly
 * empty.
 *
 * ## Scope
 *
 * Reports are Office-level surfaces: `ALL` reads across Offices, anything
 * narrower reads the actor's own, and a grant carrying only `OWN`, `ASSIGNED` or
 * `TEAM` reaches nothing rather than serving a reliably empty page. The narrowing
 * that matters happens per row anyway.
 */
class ReportPolicy
{
    public function __construct(private readonly EffectiveAccessResolver $resolver) {}

    public function viewOperational(User $actor): bool
    {
        return $this->holds($actor, 'reports.operational.view');
    }

    public function viewNotary(User $actor): bool
    {
        return $this->holds($actor, 'reports.notary.view');
    }

    public function viewPpat(User $actor): bool
    {
        return $this->holds($actor, 'reports.ppat.view');
    }

    public function viewFinancial(User $actor): bool
    {
        return $this->holds($actor, 'reports.financial.view');
    }

    public function viewAudit(User $actor): bool
    {
        return $this->holds($actor, 'reports.audit.view');
    }

    /**
     * May the actor take a report away as a file?
     *
     * **A second gate, never implied by any view code** (D-091, and the lock's
     * section 10 by name). An actor may hold `reports.financial.view` and not
     * this; the page renders and the download button does not.
     *
     * Export never widens what a view discloses: the same rows, the same scope,
     * and — for a financial export by an actor without `billing.amount.view` —
     * the same absent amounts.
     */
    public function export(User $actor): bool
    {
        return $this->holds($actor, 'reports.export');
    }

    /**
     * Does the actor hold this code at a scope that reaches an Office at all?
     *
     * `OWN` and `ASSIGNED` are meaningless for a report surface — a report
     * limited to rows you personally caused is not a report — so a grant
     * carrying only those fails closed rather than behaving like `OFFICE`
     * (D-039).
     */
    private function holds(User $actor, string $permission): bool
    {
        $access = $this->resolver->resolve($actor, $permission);

        if (! $access->granted) {
            return false;
        }

        return $access->hasScope(DataScope::ALL) || $access->hasScope(DataScope::OFFICE);
    }
}
