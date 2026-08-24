<?php

namespace App\Domains\Document;

use App\Domains\Authorization\EffectiveAccess;
use App\Domains\Authorization\Enums\DataScope;
use App\Models\Document;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which Documents an actor may reach (M5.1, D-116).
 *
 * **Data Scopes are predicates, never a ladder** (D-028). Multiple grants union;
 * no scope outranks another; there is no widest-scope and no `maxScope`. An
 * unknown or missing scope fails closed (D-039).
 *
 * ## Three scopes apply, and two are withheld for stated reasons
 *
 * ```text
 * OWN       documents.created_by = actor id
 * OFFICE    documents.office_id  = actor office
 * ALL       cross-office reach
 * ASSIGNED  no grant — a Document has no assignee
 * TEAM      no grant — no Team entity exists (D-042)
 * ```
 *
 * `OWN` applies here where it does **not** for Party (D-080) or Service Type
 * (D-106), and the difference is real rather than an inconsistency. A Party is a
 * shared directory record and a Service Type is shared configuration — the
 * colleague who typed either in has no claim on it. A Document is something
 * somebody filed: `created_by` names the person who put it there, which is
 * exactly what `OWN` is for. Project made the same argument at D-088.
 *
 * `ASSIGNED` is withheld because there is no assignment entity for the predicate
 * to match. Offering it would let an administrator grant `documents.view` at
 * `ASSIGNED`, see it saved, and hold a silently powerless grant — the dead
 * control D-080 named.
 *
 * ## Sensitivity is not a visibility predicate
 *
 * **`is_sensitive` is deliberately absent from every query below.** It is not a
 * scope: whether an actor may see a sensitive document answers to
 * `documents.sensitive.view`, a separate canonical capability that is not an
 * escalation of `documents.view` and does not imply it (D-115). Folding it in
 * here would make one code answer two questions and would silently reinterpret
 * every existing `documents.view` grant.
 *
 * The milestone that builds the read surface applies that capability *on top of*
 * this scope, and decides what a stub for an unreachable sensitive document may
 * carry — a question `15_M5_DOCUMENT_TASK_ARCHITECTURE.md` section 14 records as
 * genuinely open.
 *
 * ## Archived and soft-deleted
 *
 * Archived Documents are reached normally. `archived_at` is a lifecycle state,
 * not a visibility rule: somebody must be able to read what the office archived,
 * and a record referencing an archived document must stay readable
 * (`CLAUDE.md` section 63). `deleted_at` is reserved capability with no lifecycle
 * in M5.1 and the model applies no global scope, so nothing here filters on it.
 */
class DocumentVisibility
{
    /**
     * The scopes that can select a Document at all.
     */
    private const APPLICABLE = [
        DataScope::ALL,
        DataScope::OFFICE,
        DataScope::OWN,
    ];

    /**
     * Narrow a Document query to what the actor may reach.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scope(Builder $query, User $actor, EffectiveAccess $access): Builder
    {
        $scopes = $this->usable($access);

        // No usable predicate is not "no restriction" — it is no access.
        if ($scopes === []) {
            return $query->whereRaw('1 = 0');
        }

        // ALL imposes no record restriction, so it short-circuits the rest.
        // Evaluating the others alongside it could only widen nothing.
        if (in_array(DataScope::ALL, $scopes, true)) {
            return $query;
        }

        $table = $query->getModel()->getTable();

        return $query->where(function (BuilderContract $outer) use ($actor, $scopes, $table): void {
            // One OR branch per granted scope. The union is the point:
            // collapsing them to one would be the ranking D-028 forbids.
            foreach ($scopes as $scope) {
                match ($scope) {
                    DataScope::OFFICE => $outer->orWhere($table.'.office_id', $actor->office_id),
                    DataScope::OWN => $outer->orWhere($table.'.created_by', $actor->getKey()),
                    // ALL returned above; ASSIGNED and TEAM never reach
                    // `usable()`.
                    default => null,
                };
            }
        });
    }

    /**
     * May the actor reach this specific Document?
     */
    public function permits(User $actor, EffectiveAccess $access, Document $document): bool
    {
        return $this->scope(
            Document::query()->whereKey($document->getKey()),
            $actor,
            $access,
        )->exists();
    }

    /**
     * Does the actor hold this permission at any scope that reaches a Document?
     *
     * Used for collection-level abilities. A grant carrying only `ASSIGNED` or
     * `TEAM` reaches nothing, so it is refused outright rather than serving a
     * reliably empty list.
     */
    public function hasUsableScope(EffectiveAccess $access): bool
    {
        return $this->usable($access) !== [];
    }

    /**
     * May the actor file a Document in this Office?
     *
     * **Filing is always into the actor's own Office**, so the only honest answer
     * for any other destination is no — including for an actor holding `ALL`.
     * `ALL` is reach over records that already exist; it is not authority to
     * decide which Office a new one belongs to. The line D-097, D-098 and D-107
     * all drew.
     *
     * `OWN` qualifies: a document the actor is about to file will carry their own
     * `created_by`, so the predicate is true of the record about to exist. That
     * is the opposite of Matter creation, where `ASSIGNED` was excluded precisely
     * because a new Matter has no PIC yet (D-107).
     */
    public function permitsCreationIn(User $actor, EffectiveAccess $access, ?string $officeId = null): bool
    {
        if ($this->usable($access) === []) {
            return false;
        }

        $officeId ??= $actor->office_id;

        return $officeId === $actor->office_id;
    }

    /**
     * The granted scopes that mean something for a Document, in canonical order.
     *
     * @return array<int, DataScope>
     */
    private function usable(EffectiveAccess $access): array
    {
        if (! $access->granted) {
            return [];
        }

        return array_values(array_filter(
            self::APPLICABLE,
            static fn (DataScope $scope): bool => $access->hasScope($scope),
        ));
    }
}
