<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Notary\Enums\NotaryDeedStatus;
use App\Domains\Reports\Report;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Reports\Services\ReportFilters;
use App\Domains\Reports\Services\ReportQueries;
use App\Http\Controllers\Controller;
use App\Models\NotaryDeed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Notarial deeds over a period (M8.3, D-126).
 *
 * ## This is not a Repertorium, and must never become one
 *
 * The lock's section 10 is explicit: *"a report that looks like a statutory
 * return is worse than no report, because it invites being filed as one"*. The
 * Repertorium's format, its numbering and its procedure are open questions in
 * `08_NOTARY_WORKFLOW.md` section 6 that nobody in this repository has answered
 * (O-035, O-036).
 *
 * So this is a **list of rows with counts on it**, ordered by the deed date the
 * office recorded, exported as CSV working data. It carries no letterhead, no
 * sequence of its own, and no column that exists only because a register would
 * have one.
 *
 * `deed_number` is reported because the office typed it; nothing here allocates
 * or validates one, and `CLAUDE.md` section 38's distinction between an internal
 * reference and a legal deed number stands.
 */
class NotaryReportController extends Controller
{
    public function __construct(
        private readonly ReportQueries $queries,
        private readonly ReportFilters $filters,
        private readonly ReportExporter $exporter,
    ) {}

    public function deeds(Request $request): JsonResponse
    {
        $this->authorize('viewNotary', Report::class);

        $page = $this->deedQuery($request)
            ->orderByDesc('notary_deeds.deed_date')
            ->orderByDesc('notary_deeds.created_at')
            ->paginate($this->filters->perPage($request))
            ->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (NotaryDeed $deed): array => $this->row($deed))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function exportDeeds(Request $request): StreamedResponse
    {
        $this->authorize('viewNotary', Report::class);
        $this->authorize('export', Report::class);

        return $this->exporter->stream(
            $this->deedQuery($request)->reorder('notary_deeds.id'),
            'notary-deeds',
            ['deed_number', 'deed_date', 'deed_type_code', 'title', 'status', 'matter', 'finalized_at'],
            fn (NotaryDeed $deed): array => array_values($this->row($deed)),
        );
    }

    /**
     * Counts by status and by type, over the same scoped set.
     *
     * Every status appears, including the ones at zero: a histogram with holes
     * in it reads as a bug rather than as an empty bucket.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewNotary', Report::class);

        $query = $this->deedQuery($request);

        $byStatus = (clone $query)->getQuery()
            ->select('status')->selectRaw('count(*) as aggregate')
            ->groupBy('status')->pluck('aggregate', 'status');

        $counts = [];

        foreach (NotaryDeedStatus::values() as $status) {
            $counts[$status] = (int) ($byStatus[$status] ?? 0);
        }

        $byType = (clone $query)->getQuery()
            ->select('deed_type_code')->selectRaw('count(*) as aggregate')
            ->groupBy('deed_type_code')->pluck('aggregate', 'deed_type_code')
            ->map(static fn ($value): int => (int) $value)
            ->all();

        return response()->json([
            'data' => [
                'total' => (clone $query)->count(),
                'by_status' => $counts,
                'by_type' => $byType,
            ],
        ]);
    }

    /**
     * @return Builder<NotaryDeed>
     */
    private function deedQuery(Request $request): Builder
    {
        $query = $this->queries->notaryDeeds($request->user());

        $this->filters->exact($query, $request, 'status', 'notary_deeds.status');
        $this->filters->exact($query, $request, 'type', 'notary_deeds.deed_type_code');
        $this->filters->exact($query, $request, 'matter_id', 'notary_deeds.matter_id');
        $this->filters->dateRange($query, $request, 'notary_deeds.deed_date');

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(NotaryDeed $deed): array
    {
        return [
            'deed_number' => $deed->deed_number,
            'deed_date' => $deed->deed_date?->toDateString(),
            'deed_type_code' => $deed->deed_type_code,
            'title' => $deed->title,
            'status' => $deed->status->value,
            'matter' => $deed->matter?->matter_number,
            'finalized_at' => $deed->finalized_at?->toDateString(),
        ];
    }
}
