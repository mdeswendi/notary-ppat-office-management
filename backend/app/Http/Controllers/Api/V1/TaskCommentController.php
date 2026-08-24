<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Task\Actions\AddTaskComment;
use App\Domains\Task\TaskVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Task\StoreTaskCommentRequest;
use App\Http\Resources\TaskCommentResource;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The conversation on a Task (M5.4, D-119).
 *
 * **Its own controller, because a comment is its own resource** with its own
 * lifetime — nested under the Task that scopes it, exactly as
 * `MatterPartyController` sits under a Matter.
 *
 * **Both acts answer to `tasks.view`.** The registry defines eight `tasks.*` codes
 * and none of them is `tasks.comment`; a person who may read the task may say
 * something about it. Requiring `tasks.update` would mean only those who can
 * change the work may discuss it, which is not how an office runs — and inventing
 * a ninth code would change a canonical catalogue this milestone has no authority
 * to extend.
 *
 * **A comment can be added in any status.** Explaining why something was closed is
 * the remark most worth having, and it usually arrives just after the closing.
 *
 * **There is no edit and no delete.** A remark records what somebody said at the
 * time; rewriting it would make the conversation around it stop making sense, and
 * the model refuses an update outright. Whether anyone may retract a comment, and
 * whose, is a decision with its own capability question that this milestone does
 * not take.
 */
class TaskCommentController extends Controller
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly TaskVisibility $visibility,
    ) {}

    /**
     * Every remark on this Task, oldest first.
     */
    public function index(Request $request, string $task): AnonymousResourceCollection
    {
        $record = $this->resolveTask($request, $task);

        $this->authorize('comment', $record);

        return TaskCommentResource::collection(
            $record->comments()->with('author')->get()
        );
    }

    public function store(
        StoreTaskCommentRequest $request,
        string $task,
        AddTaskComment $add,
    ): JsonResponse {
        $record = $this->resolveTask($request, $task);

        $this->authorize('comment', $record);

        $comment = $add->handle($request->user(), $record, $request->comment());

        return (new TaskCommentResource($comment->load('author')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Find a Task the caller may reach, or 404.
     *
     * The comment surface is scoped entirely through its parent: a Task the caller
     * cannot open has no readable conversation, and an unreachable Task is
     * indistinguishable from a nonexistent one.
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
}
