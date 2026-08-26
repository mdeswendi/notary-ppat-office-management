<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Reports\Report;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Reports\Services\ReportFilters;
use App\Domains\Reports\Services\ReportQueries;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Matter;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What the office is working on (M8.3, D-126).
 *
 * Three reports — Matters, Tasks, Documents — each opened by
 * `reports.operational.view` and each **narrowed row by row** by its own
 * domain's capability and Data Scope. An actor holding the report code and
 * nothing else gets three correctly empty pages.
 *
 * **The document report excludes sensitive documents** unless the actor holds
 * `documents.sensitive.view`, exactly as the document surface does (D-115). A
 * report is a reading surface like any other, and a count that quietly included
 * KTP scans would disclose that they exist.
 */
class OperationalReportController extends Controller
{
    public function __construct(
        private readonly ReportQueries $queries,
        private readonly ReportFilters $filters,
        private readonly ReportExporter $exporter,
    ) {}

    public function matters(Request $request): JsonResponse
    {
        $this->authorize('viewOperational', Report::class);

        return $this->page(
            $this->matterQuery($request)->orderByDesc('matters.created_at'),
            $request,
            fn (Matter $matter): array => $this->matterRow($matter),
        );
    }

    public function exportMatters(Request $request): StreamedResponse
    {
        $this->authorizeExport('viewOperational');

        return $this->exporter->stream(
            $this->matterQuery($request)->reorder('matters.id'),
            'matters',
            ['matter_number', 'title', 'domain', 'status', 'service_type', 'project', 'pic', 'opened_at', 'target_completion_date', 'completed_at'],
            fn (Matter $matter): array => array_values($this->matterRow($matter)),
        );
    }

    public function tasks(Request $request): JsonResponse
    {
        $this->authorize('viewOperational', Report::class);

        return $this->page(
            $this->taskQuery($request)->orderByDesc('tasks.created_at'),
            $request,
            fn (Task $task): array => $this->taskRow($task),
        );
    }

    public function exportTasks(Request $request): StreamedResponse
    {
        $this->authorizeExport('viewOperational');

        return $this->exporter->stream(
            $this->taskQuery($request)->reorder('tasks.id'),
            'tasks',
            ['title', 'status', 'priority', 'matter', 'project', 'assignee', 'due_at', 'is_overdue', 'completed_at'],
            fn (Task $task): array => array_values($this->taskRow($task)),
        );
    }

    public function documents(Request $request): JsonResponse
    {
        $this->authorize('viewOperational', Report::class);

        return $this->page(
            $this->documentQuery($request)->orderByDesc('documents.created_at'),
            $request,
            fn (Document $document): array => $this->documentRow($document),
        );
    }

    public function exportDocuments(Request $request): StreamedResponse
    {
        $this->authorizeExport('viewOperational');

        return $this->exporter->stream(
            $this->documentQuery($request)->reorder('documents.id'),
            'documents',
            ['document_number', 'title', 'document_type_code', 'status', 'document_date', 'created_by', 'created_at'],
            fn (Document $document): array => array_values($this->documentRow($document)),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return Builder<Matter>
     */
    private function matterQuery(Request $request): Builder
    {
        $query = $this->queries->matters($request->user());

        $this->filters->exact($query, $request, 'domain', 'matters.domain');
        $this->filters->exact($query, $request, 'status', 'matters.status');
        $this->filters->exact($query, $request, 'pic_user_id', 'matters.pic_user_id');
        $this->filters->exact($query, $request, 'service_type_id', 'matters.service_type_id');
        $this->filters->dateRange($query, $request, 'matters.created_at');

        return $query;
    }

    /**
     * @return Builder<Task>
     */
    private function taskQuery(Request $request): Builder
    {
        $query = $this->queries->tasks($request->user());

        $this->filters->exact($query, $request, 'status', 'tasks.status');
        $this->filters->exact($query, $request, 'priority', 'tasks.priority');
        $this->filters->exact($query, $request, 'assignee_id', 'tasks.assigned_to');
        $this->filters->dateRange($query, $request, 'tasks.created_at');

        return $query;
    }

    /**
     * @return Builder<Document>
     */
    private function documentQuery(Request $request): Builder
    {
        $query = $this->queries->documents($request->user());

        $this->filters->exact($query, $request, 'status', 'documents.status');
        $this->filters->exact($query, $request, 'type', 'documents.document_type_code');
        $this->filters->exact($query, $request, 'created_by', 'documents.created_by');
        $this->filters->dateRange($query, $request, 'documents.created_at');

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function matterRow(Matter $matter): array
    {
        return [
            'matter_number' => $matter->matter_number,
            'title' => $matter->title,
            'domain' => $matter->domain->value,
            'status' => $matter->status->value,
            // The code, never a picked language: the name is bilingual and the
            // presentation layer chooses (CLAUDE.md sections 6, 10).
            'service_type' => $matter->serviceType?->code,
            'project' => $matter->project?->project_number,
            'pic' => $matter->picUser?->name,
            'opened_at' => $matter->opened_at?->toDateString(),
            'target_completion_date' => $matter->target_completion_date?->toDateString(),
            'completed_at' => $matter->completed_at?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(Task $task): array
    {
        return [
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'matter' => $task->matter?->matter_number,
            'project' => $task->project?->project_number,
            'assignee' => $task->assignee?->name,
            'due_at' => $task->due_at?->toDateString(),
            // Computed at read time, never a stored status (M5.4).
            'is_overdue' => $task->isOverdue() ? 'yes' : 'no',
            'completed_at' => $task->completed_at?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentRow(Document $document): array
    {
        return [
            'document_number' => $document->document_number,
            'title' => $document->title,
            'document_type_code' => $document->document_type_code,
            'status' => $document->status->value,
            'document_date' => $document->document_date?->toDateString(),
            'created_by' => $document->creator?->name,
            'created_at' => $document->created_at?->toDateString(),
        ];
    }

    /**
     * Both gates, in the order that gives the more useful refusal first.
     *
     * Somebody who cannot open the family should be told that, not told they
     * cannot export something they were never going to see.
     */
    private function authorizeExport(string $family): void
    {
        $this->authorize($family, Report::class);
        $this->authorize('export', Report::class);
    }

    /**
     * One page of a report, in the shape every report family returns.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): array<string, mixed>  $row
     */
    private function page(Builder $query, Request $request, callable $row): JsonResponse
    {
        $page = $query->paginate($this->filters->perPage($request))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map($row)->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
