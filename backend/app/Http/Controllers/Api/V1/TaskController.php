<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\MatterVisibility;
use App\Domains\Project\Enums\ProjectPriority;
use App\Domains\Project\ProjectVisibility;
use App\Domains\Task\Actions\AssignTask;
use App\Domains\Task\Actions\CancelTask;
use App\Domains\Task\Actions\CompleteTask;
use App\Domains\Task\Actions\CreateTask;
use App\Domains\Task\Actions\DeleteTask;
use App\Domains\Task\Actions\ReopenTask;
use App\Domains\Task\Actions\UpdateTask;
use App\Domains\Task\Enums\TaskStatus;
use App\Domains\Task\TaskVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Task\AssignTaskRequest;
use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Matter;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Task management (M5.4, D-119).
 *
 * Thin (`CLAUDE.md` section 35): authorize, take validated input, call an Action,
 * return a Resource. Scope rules live in {@see TaskVisibility}, status rules in
 * {@see TaskStatus}, and mutation rules in the Actions.
 *
 * **One surface, not two.** `tasks.*` is a single canonical namespace with no
 * Notary/PPAT split, so there is nothing for a route prefix to select — D-101
 * governs Matter because *that* catalogue is split.
 *
 * **Seven acts, seven capabilities, and none implies another.** `tasks.create`,
 * `update`, `assign`, `complete`, `reopen`, `delete` and `view` are separate codes,
 * so separate abilities and separate routes. `tasks.reopen` in particular is its
 * own code, and the plan's proposal to fold it into completion is not what the
 * registry says.
 *
 * **Parents and assignees are re-resolved through their own visibility.** A caller
 * who names a Project, Matter or user must be able to reach it — `tasks.create` is
 * authority to raise work, never authority to discover which records exist. An
 * unreachable target, one in another Office, and a nonexistent one produce the
 * same 422.
 *
 * For a Matter the permission namespace is read from the row's own `domain`
 * column, as at M5.2 and M5.3 (D-117, D-118): the caller supplies an id, the
 * namespace comes from a row they cannot influence, and the resulting check is the
 * stricter of the two rather than either.
 */
class TaskController extends Controller
{
    /**
     * The mutation abilities the interface asks about.
     *
     * Each resolves through the same bulk path, so a page of tasks costs no extra
     * query for carrying them.
     */
    private const CAPABILITIES = [
        'can_update' => 'tasks.update',
        'can_assign' => 'tasks.assign',
        'can_complete' => 'tasks.complete',
        'can_reopen' => 'tasks.reopen',
        'can_delete' => 'tasks.delete',
    ];

    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly TaskVisibility $visibility,
        private readonly ProjectVisibility $projects,
        private readonly MatterVisibility $matters,
    ) {}

    /**
     * Tasks the caller may see.
     *
     * Visibility is applied **in the query**, so a scoped caller's SQL never
     * selects a row they may not open — the pagination total counts only what they
     * may see, and no filter can widen it.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Task::class);

        $actor = $request->user();

        $query = $this->visibility->scope(
            Task::query()->with(['office', 'project', 'matter', 'creator', 'assignee']),
            $actor,
            $this->resolver->resolve($actor, 'tasks.view'),
        );

        $this->applyFilters($query, $request);

        // Overdue work first when sorting by due date, and rows without a due date
        // last rather than first — `NULLS LAST` is not SQLite's default, so it is
        // stated rather than assumed.
        $sort = $this->sortColumn($request);
        $direction = strtolower((string) $request->query('sort_direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        if ($sort === 'due_at') {
            $query->orderByRaw('due_at IS NULL')->orderBy('due_at', $direction);
        } else {
            $query->orderBy($sort, $direction);
        }

        $query->orderBy('id');

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $page = $query->paginate($perPage)->withQueryString();

        $capabilities = $this->capabilityMap(collect($page->items()), $request);

        return TaskResource::collection($page->through(
            fn (Task $task): TaskResource => new TaskResource($task, $capabilities[$task->getKey()] ?? [])
        ));
    }

    /**
     * Raise a Task.
     */
    public function store(StoreTaskRequest $request, CreateTask $create): JsonResponse
    {
        $actor = $request->user();

        $this->authorize('create', [Task::class, $actor->office_id]);

        $project = $this->resolveProject($request, $request->projectId());
        $matter = $this->resolveMatter($request, $request->matterId());
        $assignee = $this->resolveAssignee($request, $request->assigneeId(), $actor->office_id);

        // Assigning at creation is still the assign capability. A caller holding
        // only `tasks.create` may raise work; handing it to somebody is a second
        // act with its own code (D-091), so the request is refused rather than
        // silently dropping the assignee.
        if ($assignee !== null && ! $this->holdsAssign($request)) {
            abort(403, 'Assigning a task answers to its own capability.');
        }

        $task = $create->handle($actor, $request->taskAttributes(), $project, $matter, $assignee);

        return (new TaskResource(
            $this->loadForDetail($task),
            $this->capabilitiesFor($task, $request),
        ))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $task): TaskResource
    {
        $record = $this->resolveTask($request, $task);

        $this->authorize('view', $record);

        return new TaskResource(
            $this->loadForDetail($record),
            $this->capabilitiesFor($record, $request),
        );
    }

    public function update(UpdateTaskRequest $request, string $task, UpdateTask $update): TaskResource
    {
        $record = $this->resolveTask($request, $task);

        $this->authorize('update', $record);

        $updated = $update->handle($request->user(), $record, $request->taskAttributes());

        return new TaskResource(
            $this->loadForDetail($updated),
            $this->capabilitiesFor($updated, $request),
        );
    }

    /**
     * Hand the Task to somebody, or take it back.
     */
    public function assign(AssignTaskRequest $request, string $task, AssignTask $assign): TaskResource
    {
        $record = $this->resolveTask($request, $task);

        $this->authorize('assign', $record);

        $assignee = $this->resolveAssignee($request, $request->assigneeId(), $record->office_id);

        $updated = $assign->handle($request->user(), $record, $assignee);

        return new TaskResource(
            $this->loadForDetail($updated),
            $this->capabilitiesFor($updated, $request),
        );
    }

    public function complete(Request $request, string $task, CompleteTask $complete): TaskResource
    {
        $record = $this->resolveTask($request, $task);

        $this->authorize('complete', $record);

        $updated = $complete->handle($request->user(), $record);

        return new TaskResource(
            $this->loadForDetail($updated),
            $this->capabilitiesFor($updated, $request),
        );
    }

    public function reopen(Request $request, string $task, ReopenTask $reopen): TaskResource
    {
        $record = $this->resolveTask($request, $task);

        $this->authorize('reopen', $record);

        $updated = $reopen->handle($request->user(), $record);

        return new TaskResource(
            $this->loadForDetail($updated),
            $this->capabilitiesFor($updated, $request),
        );
    }

    /**
     * Call the work off.
     *
     * Its own endpoint rather than a status a `PATCH` could write, because
     * cancelling answers to `tasks.delete` while ordinary editing answers to
     * `tasks.update`.
     */
    public function cancel(Request $request, string $task, CancelTask $cancel): TaskResource
    {
        $record = $this->resolveTask($request, $task);

        $this->authorize('delete', $record);

        $updated = $cancel->handle($request->user(), $record);

        return new TaskResource(
            $this->loadForDetail($updated),
            $this->capabilitiesFor($updated, $request),
        );
    }

    /**
     * Remove a Task that is finished or called off.
     *
     * 204, and the body is deliberately empty: the record is gone from every
     * ordinary query, so returning a representation would invite a client to keep
     * rendering one.
     */
    public function destroy(Request $request, string $task, DeleteTask $delete): Response
    {
        $record = $this->resolveTask($request, $task);

        $this->authorize('delete', $record);

        $delete->handle($request->user(), $record);

        return response()->noContent();
    }

    /**
     * What the task form needs to render.
     */
    public function options(Request $request): JsonResponse
    {
        $actor = $request->user();

        $this->authorize('create', [Task::class, $actor->office_id]);

        return response()->json(['data' => [
            'statuses' => TaskStatus::values(),
            'settable_statuses' => array_map(
                static fn (TaskStatus $case): string => $case->value,
                TaskStatus::settableByUpdate(),
            ),
            'priorities' => array_map(
                static fn (ProjectPriority $case): string => $case->value,
                ProjectPriority::cases(),
            ),

            // Active colleagues in the actor's own Office — the only people a Task
            // may be assigned to, because the composite keys accept no others.
            'assignees' => User::query()
                ->where('office_id', $actor->office_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
                ->all(),
        ]]);
    }

    /**
     * Find a Task the caller may reach, or 404.
     *
     * Resolved **through canonical visibility** rather than a bare lookup, so an
     * unreachable Task is indistinguishable from a nonexistent one. Soft deleted
     * rows are excluded by the model's global scope.
     */
    private function resolveTask(Request $request, string $taskId): Task
    {
        $actor = $request->user();

        $record = $this->visibility->scope(
            Task::query()->whereKey($taskId),
            $actor,
            $this->resolver->resolve($actor, 'tasks.view'),
        )->first();

        if ($record === null) {
            abort(404);
        }

        return $record;
    }

    /**
     * @param  Builder<Task>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        if ($search = trim((string) $request->query('search', ''))) {
            // Grouped so the search cannot escape the visibility constraint.
            $query->where(function (BuilderContract $inner) use ($search): void {
                $inner->whereLike('title', "%{$search}%")
                    ->orWhereLike('description', "%{$search}%");
            });
        }

        // Unrecognized filter values are ignored rather than erroring: a stale
        // bookmark should show the unfiltered list, not a 422.
        if (TaskStatus::tryFrom((string) $request->query('status', '')) !== null) {
            $query->where('status', $request->query('status'));
        }

        if (ProjectPriority::tryFrom((string) $request->query('priority', '')) !== null) {
            $query->where('priority', $request->query('priority'));
        }

        foreach (['assigned_to', 'created_by', 'project_id', 'matter_id'] as $filter) {
            if (($value = trim((string) $request->query($filter, ''))) !== '') {
                $query->where($filter, $value);
            }
        }

        // `open` and `overdue` are conveniences over the same columns, not new
        // state. Overdue is computed here exactly as the model computes it, so the
        // list and the badge cannot disagree.
        if ($request->query('open') === 'true') {
            $query->whereIn('status', [
                TaskStatus::OPEN->value,
                TaskStatus::IN_PROGRESS->value,
                TaskStatus::WAITING->value,
            ]);
        }

        if ($request->query('overdue') === 'true') {
            $query->whereNotNull('due_at')
                ->where('due_at', '<', Date::now())
                ->whereIn('status', [
                    TaskStatus::OPEN->value,
                    TaskStatus::IN_PROGRESS->value,
                    TaskStatus::WAITING->value,
                ]);
        }

        foreach (['due_from' => '>=', 'due_to' => '<='] as $parameter => $operator) {
            if (($value = trim((string) $request->query($parameter, ''))) !== '') {
                $query->whereNotNull('due_at')->where('due_at', $operator, $value);
            }
        }
    }

    /**
     * An allow-list, because a sort column is a column name reaching SQL.
     */
    private function sortColumn(Request $request): string
    {
        $requested = (string) $request->query('sort_by', 'due_at');

        return in_array($requested, ['due_at', 'created_at', 'title', 'priority', 'status'], true)
            ? $requested
            : 'due_at';
    }

    private function resolveProject(Request $request, ?string $projectId): ?Project
    {
        if ($projectId === null) {
            return null;
        }

        $actor = $request->user();

        $project = $this->projects->scope(
            Project::query()->whereKey($projectId)->where('office_id', $actor->office_id),
            $actor,
            $this->resolver->resolve($actor, 'projects.view'),
        )->first();

        if ($project === null) {
            abort(422, 'Select a project you can open in your own office.');
        }

        return $project;
    }

    /**
     * A Matter reachable under **its own domain's** view capability.
     */
    private function resolveMatter(Request $request, ?string $matterId): ?Matter
    {
        if ($matterId === null) {
            return null;
        }

        $actor = $request->user();

        $matter = Matter::query()
            ->whereKey($matterId)
            ->where('office_id', $actor->office_id)
            ->first(['id', 'domain', 'office_id']);

        if ($matter !== null) {
            $domain = $matter->domain instanceof MatterDomain
                ? $matter->domain
                : MatterDomain::from((string) $matter->domain);

            $matter = $this->matters->scope(
                Matter::query()->whereKey($matterId),
                $actor,
                $this->resolver->resolve($actor, $domain->permission('view')),
            )->first();
        }

        if ($matter === null) {
            abort(422, 'Select a matter you can open in your own office.');
        }

        return $matter;
    }

    /**
     * An active colleague in the Task's Office.
     *
     * **No user capability is required to name an assignee**, and that is
     * deliberate: `users.view` is an administration capability, and needing it to
     * hand a colleague a task would mean only administrators could delegate work.
     * The candidate set is already bounded to the actor's own Office and to active
     * accounts, which discloses no more than the assignee picker does.
     */
    private function resolveAssignee(Request $request, ?string $userId, string $officeId): ?User
    {
        if ($userId === null) {
            return null;
        }

        $assignee = User::query()
            ->whereKey($userId)
            ->where('office_id', $officeId)
            ->where('is_active', true)
            ->first();

        if ($assignee === null) {
            abort(422, 'Select an active colleague in this office.');
        }

        return $assignee;
    }

    private function holdsAssign(Request $request): bool
    {
        return $this->visibility->hasUsableScope(
            $this->resolver->resolve($request->user(), 'tasks.assign')
        );
    }

    private function loadForDetail(Task $task): Task
    {
        return $task->load([
            'office', 'project', 'matter',
            'creator', 'assignee', 'assigner', 'completer',
            'comments.author',
        ]);
    }

    /**
     * @return array<string, bool>
     */
    private function capabilitiesFor(Task $task, Request $request): array
    {
        return $this->capabilityMap(collect([$task]), $request)[$task->getKey()] ?? [];
    }

    /**
     * The capability flags for a page of Tasks, resolved in bulk.
     *
     * The actor's effective access does not vary by row, so it is resolved once
     * per capability and the record predicate asked for every Task at once — the
     * N+1 M2.6 measured and every surface since has avoided by construction.
     *
     * **Status eligibility is folded in from data the row already carries**, so no
     * flag offers something the endpoint would answer 422 to: `can_complete` is
     * false on a finished task, `can_reopen` false on a live one, `can_delete`
     * false on anything still in flight.
     *
     * @param  Collection<int, Task>  $tasks
     * @return array<string, array<string, bool>>
     */
    private function capabilityMap(Collection $tasks, Request $request): array
    {
        if ($tasks->isEmpty()) {
            return [];
        }

        $actor = $request->user();
        $ids = $tasks->map(fn (Task $task): string => $task->getKey())->all();

        $reachable = [];

        foreach (self::CAPABILITIES as $flag => $permission) {
            $reachable[$flag] = $this->visibility->scope(
                Task::query()->whereIn('id', $ids),
                $actor,
                $this->resolver->resolve($actor, $permission),
            )->pluck('id')->flip();
        }

        $map = [];

        foreach ($tasks as $task) {
            $key = $task->getKey();

            $map[$key] = [
                'can_update' => $reachable['can_update']->has($key) && $task->status->isOpen(),
                'can_assign' => $reachable['can_assign']->has($key) && $task->status->isOpen(),
                'can_complete' => $reachable['can_complete']->has($key) && $task->status->isCompletable(),
                'can_reopen' => $reachable['can_reopen']->has($key) && $task->status->isReopenable(),
                'can_cancel' => $reachable['can_delete']->has($key) && $task->status->isCancellable(),
                'can_delete' => $reachable['can_delete']->has($key) && $task->status->isDeletable(),
            ];
        }

        return $map;
    }
}
