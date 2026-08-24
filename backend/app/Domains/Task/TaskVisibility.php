<?php

namespace App\Domains\Task;

use App\Domains\Authorization\EffectiveAccess;
use App\Domains\Authorization\Enums\DataScope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which Tasks an actor may reach (M5.4, D-119).
 *
 * **Data Scopes are predicates, never a ladder** (D-028). Multiple grants union;
 * no scope outranks another; there is no widest-scope and no `maxScope`. An
 * unknown or missing scope fails closed (D-039).
 *
 * ## Four scopes apply, and one is withheld
 *
 * ```text
 * OWN       tasks.created_by  = actor id
 * ASSIGNED  tasks.assigned_to = actor id
 * OFFICE    tasks.office_id   = actor office
 * ALL       cross-office reach
 * TEAM      no grant — no Team entity exists (D-042)
 * ```
 *
 * **`OWN` and `ASSIGNED` are separate predicates and neither contains the
 * other**, which is the decision this milestone owed (`15_M5_DOCUMENT_TASK_ARCHITECTURE.md`
 * section 11.2). The plan proposed defining `OWN` as *"created_by OR
 * assigned_to"*; that would have made `OWN` a superset of `ASSIGNED`, leaving
 * `ASSIGNED` unable to express anything `OWN` did not already — a ranking between
 * scopes, which is exactly what D-028 forbids.
 *
 * Kept apart, they answer different questions an administrator may want to grant
 * separately: *"work I raised"* and *"work I was given"*. An actor holding both
 * reaches the union, which is the behaviour the plan actually wanted — arrived at
 * through the mechanism the model already has rather than by collapsing two
 * predicates into one.
 *
 * **A Task's `ASSIGNED` widens nothing else.** Being given a Task confers no
 * Matter reach and no Project reach — the symmetric statement of D-100.
 * `projects.pic_user_id`, `matters.pic_user_id` and `tasks.assigned_to` stay three
 * separate predicates.
 *
 * ## What is not a predicate here
 *
 * **Neither `status` nor `due_at` filters anything.** A completed task is reached
 * normally, and so is an overdue one: they are lifecycle facts a caller filters on
 * when they choose to, not conditions on who may look. `deleted_at` is handled by
 * the model's global scope, so nothing here mentions it either.
 */
class TaskVisibility
{
    /**
     * The scopes that can select a Task at all.
     */
    private const APPLICABLE = [
        DataScope::ALL,
        DataScope::OFFICE,
        DataScope::ASSIGNED,
        DataScope::OWN,
    ];

    /**
     * Narrow a Task query to what the actor may reach.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scope(Builder $query, User $actor, EffectiveAccess $access): Builder
    {
        $scopes = $this->usable($access);

        // No usable predicate is not "no restriction" — it is no access.
        if ($scopes === []) {
            return $query->whereRaw('1 = 0');
        }

        // ALL imposes no record restriction, so it short-circuits the rest.
        if (in_array(DataScope::ALL, $scopes, true)) {
            return $query;
        }

        $table = $query->getModel()->getTable();

        return $query->where(function (BuilderContract $outer) use ($actor, $scopes, $table): void {
            // One OR branch per granted scope. The union is the point:
            // collapsing them would be the ranking D-028 forbids.
            foreach ($scopes as $scope) {
                match ($scope) {
                    DataScope::OFFICE => $outer->orWhere($table.'.office_id', $actor->office_id),
                    DataScope::ASSIGNED => $outer->orWhere($table.'.assigned_to', $actor->getKey()),
                    DataScope::OWN => $outer->orWhere($table.'.created_by', $actor->getKey()),
                    // ALL returned above; TEAM never reaches `usable()`.
                    default => null,
                };
            }
        });
    }

    /**
     * May the actor reach this specific Task?
     */
    public function permits(User $actor, EffectiveAccess $access, Task $task): bool
    {
        return $this->scope(
            Task::query()->whereKey($task->getKey()),
            $actor,
            $access,
        )->exists();
    }

    /**
     * Does the actor hold this permission at any scope that reaches a Task?
     *
     * Used for collection-level abilities. A grant carrying only `TEAM` reaches
     * nothing, so it is refused outright rather than serving a reliably empty
     * list.
     */
    public function hasUsableScope(EffectiveAccess $access): bool
    {
        return $this->usable($access) !== [];
    }

    /**
     * May the actor raise a Task in this Office?
     *
     * **Always their own Office**, so the only honest answer for any other
     * destination is no — including for an actor holding `ALL`. `ALL` is reach
     * over records that already exist; it is not authority to decide which Office
     * a new one belongs to. The line D-097, D-098 and D-107 all drew.
     *
     * **`ASSIGNED` does not qualify**, and `OWN` does. A task the actor is about
     * to raise will carry their own `created_by`, so the `OWN` predicate is true
     * of the record about to exist; `assigned_to` is null until somebody is given
     * the work, so `ASSIGNED` is false of it — the same exclusion D-107 made for
     * Matter creation, where a new Matter has no PIC yet.
     */
    public function permitsCreationIn(User $actor, EffectiveAccess $access, ?string $officeId = null): bool
    {
        $scopes = $this->usable($access);

        if ($scopes === [] || $scopes === [DataScope::ASSIGNED]) {
            return false;
        }

        $officeId ??= $actor->office_id;

        return $officeId === $actor->office_id;
    }

    /**
     * The granted scopes that mean something for a Task, in canonical order.
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
