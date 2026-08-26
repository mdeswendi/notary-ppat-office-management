<?php

namespace App\Domains\Dashboard\Services;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Document\DocumentVisibility;
use App\Domains\Identity\UserVisibility;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\Enums\MatterStatus;
use App\Domains\Matter\MatterVisibility;
use App\Domains\Notary\Enums\NotaryDeedStatus;
use App\Domains\Notary\NotaryDeedVisibility;
use App\Domains\Ppat\Enums\PpatDeedStatus;
use App\Domains\Ppat\PpatDeedVisibility;
use App\Domains\Project\Enums\ProjectStatus;
use App\Domains\Project\ProjectVisibility;
use App\Domains\Task\Enums\TaskStatus;
use App\Domains\Task\TaskVisibility;
use App\Models\Activity;
use App\Models\Document;
use App\Models\Matter;
use App\Models\NotaryDeed;
use App\Models\PpatDeed;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Every number on the Dashboard, and every one of them scoped (M8.1, D-122).
 *
 * ## The rule this class exists to enforce
 *
 * **A count is a disclosure.** Every figure here is computed through the same
 * Data Scope predicate as the list it summarises — an actor whose
 * `notary.matters.view` scope is `ASSIGNED` sees a count of the Matters assigned
 * to them, never the Office total.
 *
 * This is the rule most easily got wrong, because a count feels like less
 * disclosure than a list and is not: *"there are 47 Matters"* is information
 * about 47 records the actor may not read, and on a small Office a count plus a
 * filter reconstructs the list. The M8.1 brief's sample queries were unscoped
 * (`Matter::whereIn('status', [...])->count()`); every one of them is scoped here.
 *
 * ## Null means "not permitted"; zero means "permitted and empty"
 *
 * A panel the actor may not see returns `null`, and the interface omits it. A
 * panel they may see with nothing in it returns `0` or `[]`. Collapsing the two
 * would either invent a zero for somebody who is not allowed to know — the lie
 * O-046 refused for document counts — or imply a permission problem where the
 * office simply has no work outstanding.
 *
 * ## No capability of its own
 *
 * There is no `dashboard.*` code in the registry and none is needed. Each panel
 * is gated by the capability of the resource it summarises, resolved through the
 * same {@see EffectiveAccessResolver} path as that resource's own page. An actor
 * holding none of them gets every panel null, which is correct behaviour rather
 * than an error state (D-122).
 *
 * ## Matters are counted per domain, and the two are separate grants
 *
 * `notary.matters.view` and `ppat.matters.view` are different capabilities
 * (D-101). An actor holding one sees only that domain's Matters in every figure
 * here — never a total that quietly includes the other.
 */
class DashboardAggregator
{
    /**
     * How far ahead "upcoming" reaches, in days.
     *
     * A presentation window, not an office policy: it decides what a widget
     * shows, gates nothing, and changes no record. The M8.1 brief additionally
     * proposed staleness thresholds — attention after 3 days waiting, 2 days
     * pending approval, 1 day overdue — and those are **not** implemented, because
     * how long an office tolerates a stalled Matter is an office rule nobody has
     * written down. {@see self::needsAttention()} uses only facts the records
     * already state.
     */
    private const UPCOMING_DAYS = 7;

    /**
     * The most rows any single panel will return.
     */
    private const PANEL_LIMIT = 10;

    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly ProjectVisibility $projects,
        private readonly MatterVisibility $matters,
        private readonly TaskVisibility $tasks,
        private readonly DocumentVisibility $documents,
        private readonly NotaryDeedVisibility $notaryDeeds,
        private readonly PpatDeedVisibility $ppatDeeds,
        private readonly UserVisibility $users,
    ) {}

    /**
     * The four headline figures, plus this month's deed total.
     *
     * @return array<string, int|null>
     */
    public function stats(User $actor): array
    {
        return [
            'active_projects' => $this->countProjects($actor, ProjectStatus::activeValues()),
            'active_matters' => $this->countMatters($actor, MatterStatus::activeValues()),
            'pending_reviews' => $this->countDeedsUnderReview($actor),
            'overdue_tasks' => $this->countOverdueTasks($actor),
            'total_deeds_this_month' => $this->countDeedsThisMonth($actor),
        ];
    }

    /**
     * The caller's own work, in three buckets.
     *
     * **Filtered to the actor on top of the scope**, deliberately. Somebody
     * holding `OFFICE` reach still wants their own queue on the dashboard, and
     * `MyTasksWidget` has taken the same position since M5.4: ask for your own
     * work explicitly, and let the backend's scope narrow it further if it must.
     *
     * @return array{today: Collection<int, Task>, overdue: Collection<int, Task>, upcoming: Collection<int, Task>}|null
     */
    public function tasks(User $actor): ?array
    {
        $access = $this->resolver->resolve($actor, 'tasks.view');

        if (! $this->tasks->hasUsableScope($access)) {
            return null;
        }

        $mine = fn (): Builder => $this->tasks
            ->scope(Task::query(), $actor, $access)
            ->where('assigned_to', $actor->getKey())
            ->whereIn('status', TaskStatus::openValues())
            ->with(['matter:id,matter_number,title', 'project:id,project_number,title']);

        $now = Date::now();

        return [
            'today' => $mine()
                ->whereDate('due_at', $now->toDateString())
                ->orderBy('due_at')
                ->limit(self::PANEL_LIMIT)
                ->get(),

            'overdue' => $mine()
                ->where('due_at', '<', $now)
                ->orderBy('due_at')
                ->limit(self::PANEL_LIMIT)
                ->get(),

            'upcoming' => $mine()
                ->whereBetween('due_at', [$now, $now->copy()->addDays(self::UPCOMING_DAYS)])
                ->orderBy('due_at')
                ->limit(self::PANEL_LIMIT)
                ->get(),
        ];
    }

    /**
     * What is stalled, stated from facts the records already carry.
     *
     * **No invented staleness thresholds.** The brief proposed "waiting more than
     * 3 days", "pending approval more than 2 days" and "overdue by more than 1
     * day". How long an office tolerates a stalled Matter is an office policy
     * nobody has written down, and encoding a number here would make this
     * software assert one. Instead every item that is *actually* waiting,
     * *actually* under review, or *actually* past its due date is included, newest
     * problem last, and `days_waiting` is reported so the reader judges.
     *
     * That is strictly more information than a threshold would give, and it
     * invents nothing.
     *
     * One requested category is missing entirely: **"Matters with required
     * documents missing" cannot be built**, because `matter_requirements` does
     * not exist — D-115 deferred it along with `service_document_requirements`,
     * and both remain unbuilt.
     *
     * @return list<array<string, mixed>>|null
     */
    public function needsAttention(User $actor): ?array
    {
        $items = [];
        $permitted = false;

        $matterAccess = $this->matterQuery($actor);

        if ($matterAccess !== null) {
            $permitted = true;

            $waiting = $matterAccess
                ->whereIn('matters.status', [MatterStatus::WAITING->value, MatterStatus::ON_HOLD->value])
                ->orderBy('matters.updated_at')
                ->limit(self::PANEL_LIMIT)
                ->get(['matters.id', 'matters.matter_number', 'matters.title', 'matters.status', 'matters.updated_at']);

            foreach ($waiting as $matter) {
                $items[] = [
                    'type' => 'MATTER_WAITING',
                    'id' => $matter->getKey(),
                    'reference' => $matter->matter_number,
                    'title' => $matter->title,
                    'status' => $matter->status->value,
                    'days_waiting' => $this->daysSince($matter->updated_at),
                ];
            }
        }

        foreach ($this->deedQueries($actor) as $domain => $query) {
            $permitted = true;

            $pending = $query
                ->where('status', $this->underReviewValue($domain))
                ->orderBy('updated_at')
                ->limit(self::PANEL_LIMIT)
                ->get(['id', 'deed_number', 'title', 'status', 'updated_at']);

            foreach ($pending as $deed) {
                $items[] = [
                    'type' => 'DEED_PENDING_REVIEW',
                    'domain' => $domain,
                    'id' => $deed->getKey(),
                    'reference' => $deed->deed_number,
                    'title' => $deed->title,
                    'status' => $deed->status->value,
                    'days_waiting' => $this->daysSince($deed->updated_at),
                ];
            }
        }

        $taskAccess = $this->resolver->resolve($actor, 'tasks.view');

        if ($this->tasks->hasUsableScope($taskAccess)) {
            $permitted = true;

            $overdue = $this->tasks
                ->scope(Task::query(), $actor, $taskAccess)
                ->whereIn('status', TaskStatus::openValues())
                ->whereNotNull('due_at')
                ->where('due_at', '<', Date::now())
                ->orderBy('due_at')
                ->limit(self::PANEL_LIMIT)
                ->get(['id', 'title', 'status', 'due_at']);

            foreach ($overdue as $task) {
                $items[] = [
                    'type' => 'TASK_OVERDUE',
                    'id' => $task->getKey(),
                    'reference' => null,
                    'title' => $task->title,
                    'status' => $task->status->value,
                    'days_waiting' => $this->daysSince($task->due_at),
                ];
            }
        }

        if (! $permitted) {
            return null;
        }

        usort($items, static fn (array $a, array $b): int => ($b['days_waiting'] ?? 0) <=> ($a['days_waiting'] ?? 0));

        return array_slice($items, 0, self::PANEL_LIMIT * 2);
    }

    /**
     * Who is carrying how much.
     *
     * **Not filtered by role name.** The brief specified "only users with role
     * `NOTARY_STAFF`, `PPAT_STAFF`, `OFFICE_MANAGER`", which is exactly the
     * role-name authorization `CLAUDE.md` section 24 and D-048 forbid — and it
     * would also be wrong on its own terms, since who holds which role is
     * configuration an office changes.
     *
     * The list is instead every user the actor may **read** (`users.view`, with
     * its own Data Scope) who actually holds live work. Somebody with nothing
     * assigned does not appear, so the panel answers "who is busy" without
     * asserting who is supposed to be.
     *
     * @return list<array<string, mixed>>|null
     */
    public function workload(User $actor): ?array
    {
        $access = $this->resolver->resolve($actor, 'users.view');

        if (! $access->granted) {
            return null;
        }

        $users = $this->users
            ->scopeForReading(User::query(), $actor, $access)
            ->get(['id', 'name', 'office_id']);

        if ($users->isEmpty()) {
            return [];
        }

        $ids = $users->pluck('id')->all();

        // Two grouped queries rather than one per user: the N+1 every surface
        // since M2.6 has avoided by construction.
        $taskCounts = $this->taskCountsFor($actor, $ids);
        $matterCounts = $this->matterCountsFor($actor, $ids);

        $rows = [];

        foreach ($users as $user) {
            $key = $user->getKey();
            $tasks = $taskCounts[$key] ?? 0;
            $matters = $matterCounts[$key] ?? 0;

            if ($tasks === 0 && $matters === 0) {
                continue;
            }

            $rows[] = [
                'user_id' => $key,
                'user_name' => $user->name,
                'matter_count' => $matters,
                'task_count' => $tasks,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => ($b['matter_count'] + $b['task_count'])
            <=> ($a['matter_count'] + $a['task_count']));

        return array_slice($rows, 0, self::PANEL_LIMIT);
    }

    /**
     * The recent timeline, authorized per row by its subject.
     *
     * **Reads `activities`, never `audit_logs`** (D-123). The audit table answers
     * to `audit.view`; a feed drawn from it would either be denied to every actor
     * without that capability or would become a way to read audit content without
     * holding it, which D-122 forbids by name.
     *
     * **This starts empty.** Nothing is backfilled, so an office that upgraded to
     * M8.1 today sees nothing here until the next thing happens. That is expected
     * behaviour, and the interface says so rather than showing a spinner.
     *
     * @return Collection<int, Activity>
     */
    public function activity(User $actor, int $limit = 20): Collection
    {
        $query = Activity::query()
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->limit($limit);

        $this->constrainActivityToReachableSubjects($query, $actor);

        return $query->get();
    }

    /**
     * Deeds by domain and status, for whichever domains the actor may read.
     *
     * @return array<string, array<string, int>|null>
     */
    public function deeds(User $actor): array
    {
        $notary = $this->deedQuery($actor, MatterDomain::NOTARY);
        $ppat = $this->deedQuery($actor, MatterDomain::PPAT);

        return [
            'notary' => $notary === null ? null : $this->countByStatus($notary, 'notary'),
            'ppat' => $ppat === null ? null : $this->countByStatus($ppat, 'ppat'),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Scoped query builders
    |--------------------------------------------------------------------------
    |
    | Each returns null when the actor holds no usable scope for that resource,
    | which is what the panels turn into a null rather than a zero.
    */

    /**
     * @param  list<string>  $statuses
     */
    private function countProjects(User $actor, array $statuses): ?int
    {
        $access = $this->resolver->resolve($actor, 'projects.view');

        if (! $this->projects->hasUsableScope($access)) {
            return null;
        }

        return $this->projects
            ->scope(Project::query(), $actor, $access)
            ->whereIn('status', $statuses)
            ->count();
    }

    /**
     * @param  list<string>  $statuses
     */
    private function countMatters(User $actor, array $statuses): ?int
    {
        $query = $this->matterQuery($actor);

        return $query?->whereIn('matters.status', $statuses)->count();
    }

    /**
     * Matters across both domains, unioned by the grants the actor actually has.
     *
     * @return Builder<Matter>|null
     */
    private function matterQuery(User $actor): ?Builder
    {
        $reachable = [];

        foreach (MatterDomain::cases() as $domain) {
            $access = $this->resolver->resolve($actor, $this->matterCode($domain));

            if (! $this->matters->hasUsableScope($access)) {
                continue;
            }

            $reachable[] = $domain;
        }

        if ($reachable === []) {
            return null;
        }

        $query = Matter::query();

        // One OR branch per domain the actor may read, each carrying that
        // domain's own scope predicate. An actor holding only the Notary grant
        // never sees a PPAT Matter in any figure on this page.
        return $query->where(function (Builder $outer) use ($actor, $reachable): void {
            foreach ($reachable as $domain) {
                $access = $this->resolver->resolve($actor, $this->matterCode($domain));

                $outer->orWhere(function (Builder $branch) use ($actor, $access, $domain): void {
                    $branch->where('matters.domain', $domain->value);
                    $this->matters->scope($branch, $actor, $access);
                });
            }
        });
    }

    private function matterCode(MatterDomain $domain): string
    {
        return $domain === MatterDomain::NOTARY ? 'notary.matters.view' : 'ppat.matters.view';
    }

    /**
     * @return Builder<NotaryDeed>|Builder<PpatDeed>|null
     */
    private function deedQuery(User $actor, MatterDomain $domain): ?Builder
    {
        if ($domain === MatterDomain::NOTARY) {
            $access = $this->resolver->resolve($actor, 'notary.deeds.view');

            return $this->notaryDeeds->hasUsableScope($access)
                ? $this->notaryDeeds->scope(NotaryDeed::query(), $actor, $access)
                : null;
        }

        $access = $this->resolver->resolve($actor, 'ppat.deeds.view');

        return $this->ppatDeeds->hasUsableScope($access)
            ? $this->ppatDeeds->scope(PpatDeed::query(), $actor, $access)
            : null;
    }

    /**
     * Both deed queries the actor may run, keyed by domain.
     *
     * @return array<string, Builder<NotaryDeed>|Builder<PpatDeed>>
     */
    private function deedQueries(User $actor): array
    {
        $queries = [];

        foreach (MatterDomain::cases() as $domain) {
            $query = $this->deedQuery($actor, $domain);

            if ($query !== null) {
                $queries[strtolower($domain->value)] = $query;
            }
        }

        return $queries;
    }

    private function countDeedsUnderReview(User $actor): ?int
    {
        $queries = $this->deedQueries($actor);

        if ($queries === []) {
            return null;
        }

        $total = 0;

        foreach ($queries as $domain => $query) {
            $total += $query->where('status', $this->underReviewValue($domain))->count();
        }

        return $total;
    }

    /**
     * `UNDER_REVIEW` as each domain's own enum spells it.
     *
     * The two vocabularies are identical today — M7 adopted Notary's six for
     * PPAT, and D-121 recorded that as a **decision rather than a
     * transcription**, to be reconciled if a canonical PPAT list ever turns up.
     * Reading each domain's own enum means this code follows that reconciliation
     * instead of quietly assuming it never happens.
     */
    private function underReviewValue(string $domain): string
    {
        return $domain === 'notary'
            ? NotaryDeedStatus::UNDER_REVIEW->value
            : PpatDeedStatus::UNDER_REVIEW->value;
    }

    /**
     * Every status value for a domain, in that domain's own enum order.
     *
     * @return array<int, string>
     */
    private function deedStatusValues(string $domain): array
    {
        return $domain === 'notary'
            ? NotaryDeedStatus::values()
            : PpatDeedStatus::values();
    }

    private function countDeedsThisMonth(User $actor): ?int
    {
        $queries = $this->deedQueries($actor);

        if ($queries === []) {
            return null;
        }

        $start = Date::now()->startOfMonth();
        $total = 0;

        foreach ($queries as $query) {
            // A range rather than `whereMonth`, so the index on `created_at` is
            // usable and a December/January boundary cannot match last year.
            $total += $query->where('created_at', '>=', $start)->count();
        }

        return $total;
    }

    private function countOverdueTasks(User $actor): ?int
    {
        $access = $this->resolver->resolve($actor, 'tasks.view');

        if (! $this->tasks->hasUsableScope($access)) {
            return null;
        }

        return $this->tasks
            ->scope(Task::query(), $actor, $access)
            ->whereIn('status', TaskStatus::openValues())
            ->whereNotNull('due_at')
            ->where('due_at', '<', Date::now())
            ->count();
    }

    /**
     * @param  Builder<NotaryDeed>|Builder<PpatDeed>  $query
     * @return array<string, int>
     */
    private function countByStatus(Builder $query, string $domain): array
    {
        $counts = $query->getQuery()
            ->select('status')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $result = [];

        // Every status appears, including the ones at zero. A status missing
        // from the payload would make the interface guess whether it is empty or
        // unsupported, and a chart with holes in it reads as a bug.
        foreach ($this->deedStatusValues($domain) as $status) {
            $result[$status] = (int) ($counts[$status] ?? 0);
        }

        return $result;
    }

    /**
     * @param  list<string>  $userIds
     * @return array<string, int>
     */
    private function taskCountsFor(User $actor, array $userIds): array
    {
        $access = $this->resolver->resolve($actor, 'tasks.view');

        if (! $this->tasks->hasUsableScope($access)) {
            return [];
        }

        return $this->tasks
            ->scope(Task::query(), $actor, $access)
            ->whereIn('assigned_to', $userIds)
            ->whereIn('status', TaskStatus::openValues())
            ->getQuery()
            ->select('assigned_to')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('assigned_to')
            ->pluck('aggregate', 'assigned_to')
            ->map(static fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @param  list<string>  $userIds
     * @return array<string, int>
     */
    private function matterCountsFor(User $actor, array $userIds): array
    {
        $query = $this->matterQuery($actor);

        if ($query === null) {
            return [];
        }

        return $query
            ->whereIn('matters.pic_user_id', $userIds)
            ->whereIn('matters.status', MatterStatus::activeValues())
            ->getQuery()
            ->select('matters.pic_user_id')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('matters.pic_user_id')
            ->pluck('aggregate', 'pic_user_id')
            ->map(static fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * Narrow a feed query to rows whose subject the actor can reach.
     *
     * **This is the whole authorization story for `activities`** (O-047). The
     * table has no capability of its own; a row is visible exactly when its
     * subject is, and a row whose subject is out of reach is absent rather than
     * redacted — D-098's treatment of unreachable records, applied to a feed.
     *
     * Implemented as one OR branch per subject type, each carrying that domain's
     * own scope predicate as a subquery. A subject type the actor may not read at
     * all contributes no branch, so its rows simply never match.
     *
     * @param  Builder<Activity>  $query
     */
    private function constrainActivityToReachableSubjects(Builder $query, User $actor): void
    {
        $branches = [];

        $projectAccess = $this->resolver->resolve($actor, 'projects.view');

        if ($this->projects->hasUsableScope($projectAccess)) {
            $branches[Project::class] = fn (): Builder => $this->projects
                ->scope(Project::query(), $actor, $projectAccess)
                ->select('id');
        }

        $matterQuery = $this->matterQuery($actor);

        if ($matterQuery !== null) {
            $branches[Matter::class] = fn (): Builder => $matterQuery->clone()->select('matters.id');
        }

        $taskAccess = $this->resolver->resolve($actor, 'tasks.view');

        if ($this->tasks->hasUsableScope($taskAccess)) {
            $branches[Task::class] = fn (): Builder => $this->tasks
                ->scope(Task::query(), $actor, $taskAccess)
                ->select('id');
        }

        $documentAccess = $this->resolver->resolve($actor, 'documents.view');

        if ($this->documents->hasUsableScope($documentAccess)) {
            $branches[Document::class] = fn (): Builder => $this->documents
                ->scope(Document::query(), $actor, $documentAccess)
                ->select('id');
        }

        // A list of pairs rather than an enum-keyed map: PHP array keys are only
        // ever int or string, so `[MatterDomain::NOTARY => ...]` is a TypeError.
        foreach ([
            [MatterDomain::NOTARY, NotaryDeed::class, 'notary_deeds.id'],
            [MatterDomain::PPAT, PpatDeed::class, 'ppat_deeds.id'],
        ] as [$domain, $model, $column]) {
            $deedQuery = $this->deedQuery($actor, $domain);

            if ($deedQuery !== null) {
                $branches[$model] = fn (): Builder => $deedQuery->clone()->select($column);
            }
        }

        if ($branches === []) {
            // No reachable subject type at all means no feed, not an open one.
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $outer) use ($branches): void {
            foreach ($branches as $type => $subquery) {
                $outer->orWhere(function (Builder $branch) use ($type, $subquery): void {
                    $branch->where('subject_type', $type)
                        ->whereIn('subject_id', $subquery());
                });
            }
        });
    }

    private function daysSince(mixed $moment): ?int
    {
        if ($moment === null) {
            return null;
        }

        return (int) Date::now()->diffInDays($moment, absolute: true);
    }
}
