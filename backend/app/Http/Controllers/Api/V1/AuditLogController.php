<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Audit\Enums\AuditEvent;
use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Authorization\Enums\DataScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Reading the audit trail (M8.1, D-123).
 *
 * `audit.view` has been catalogued since M1.2 and authorized nothing until now.
 * This is the surface that makes it real — and makes D-115's *"queryable by
 * resource"* requirement true rather than aspirational, which is the whole
 * difference between an audit store and a log file.
 *
 * ## Read-only, and structurally so
 *
 * There is no `store`, no `update`, no `destroy`, and no route for any of them.
 * `CLAUDE.md` section 31 forbids `audit.update` and `audit.delete`; the catalogue
 * contains neither, {@see AuditLog} throws on both, and this controller offers no
 * address that could reach one.
 *
 * ## Scope
 *
 * Audit is an Office-level record, so the scope predicate is Office reach:
 * `ALL` reads across Offices, anything narrower reads the actor's own. `OWN` and
 * `ASSIGNED` are meaningless here — an audit trail limited to the rows you
 * yourself caused is not an audit trail — so a grant carrying only those reaches
 * nothing rather than silently behaving like `OFFICE`.
 */
class AuditLogController extends Controller
{
    public function __construct(private readonly EffectiveAccessResolver $resolver) {}

    /**
     * The audit trail the caller may read, newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $request->user();
        $access = $this->resolver->resolve($actor, 'audit.view');

        abort_unless($access->granted, 403);

        $query = AuditLog::query()->with('actor:id,name');

        // `ALL` reads across Offices; everything else is confined to the actor's
        // own. A grant carrying neither reaches nothing — fails closed (D-039).
        if (! $access->hasScope(DataScope::ALL)) {
            abort_unless($access->hasScope(DataScope::OFFICE), 403);

            $query->where('office_id', $actor->office_id);
        }

        $this->applyFilters($query, $request);

        return AuditLogResource::collection(
            $query->orderByDesc('created_at')
                ->paginate(min((int) $request->query('per_page', '25'), 100))
                ->withQueryString()
        );
    }

    /**
     * Filters, not nested routes (D-118).
     *
     * "What happened to this deed", "what has this person done" and "what
     * happened last week" are three questions about one collection, so they are
     * three parameters on one address.
     *
     * @param  Builder<AuditLog>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        $event = $request->query('event');

        if (is_string($event) && in_array($event, AuditEvent::values(), true)) {
            $query->byEvent($event);
        }

        $actorId = $request->query('actor_user_id');

        if (is_string($actorId) && $actorId !== '') {
            $query->where('actor_user_id', $actorId);
        }

        $type = $request->query('auditable_type');
        $id = $request->query('auditable_id');

        // Both or neither: a type without an id is a whole-table scan dressed as
        // a filter, and an id without a type matches across unrelated domains.
        if (is_string($type) && $type !== '' && is_string($id) && $id !== '') {
            $query->forAuditable($type, $id);
        }

        $from = $request->query('from');
        $until = $request->query('until');

        $query->byDateRange(
            is_string($from) && $from !== '' ? $from : null,
            is_string($until) && $until !== '' ? $until : null,
        );
    }
}
