<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Audit\Enums\AuditEvent;
use App\Domains\Reports\Report;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Reports\Services\ReportFilters;
use App\Domains\Reports\Services\ReportQueries;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Who did what, as a report (M8.3, D-126).
 *
 * ## One address with filters, not two routes
 *
 * The M8.3 brief proposed `/reports/audit/activity` and `/reports/audit/trail`.
 * "The trail for this deed" is the same collection with `auditable_type` and
 * `auditable_id` supplied — a filter, not a second surface (D-118). Two URLs over
 * one table is two places for the scope predicate to drift apart.
 *
 * ## A second reader of `audit_logs`, and a legitimate one
 *
 * M8.1 built `GET /api/v1/audit-logs` under `audit.view`. This reads the same
 * rows under `reports.audit.view`. Both codes are canonical and neither implies
 * the other (D-091): an office may give a compliance reviewer the report without
 * the operational audit surface, or the reverse.
 *
 * ## What it never discloses
 *
 * `old_values` and `new_values` ship **as stored**, which means already redacted:
 * `AuditLogger` withholds sensitive values before writing, so a NIK was never in
 * the table to export. That ordering is the point — redacting on the way out
 * would leave the raw value sitting in a table nobody may delete from (D-105,
 * D-115).
 */
class AuditReportController extends Controller
{
    public function __construct(
        private readonly ReportQueries $queries,
        private readonly ReportFilters $filters,
        private readonly ReportExporter $exporter,
    ) {}

    public function activity(Request $request): JsonResponse
    {
        $this->authorize('viewAudit', Report::class);

        $page = $this->query($request)
            ->orderByDesc('created_at')
            ->paginate($this->filters->perPage($request))
            ->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditLog $log): array => $this->row($log))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function exportActivity(Request $request): StreamedResponse
    {
        $this->authorize('viewAudit', Report::class);
        $this->authorize('export', Report::class);

        return $this->exporter->stream(
            $this->query($request)->reorder('audit_logs.id'),
            'audit',
            ['created_at', 'event', 'actor', 'auditable_type', 'auditable_id', 'ip_address', 'reason', 'old_values', 'new_values'],
            fn (AuditLog $log): array => array_values($this->row($log, true)),
        );
    }

    /**
     * @return Builder<AuditLog>
     */
    private function query(Request $request): Builder
    {
        $query = $this->queries->audit($request->user());

        $event = $request->query('event_type');

        // Validated against the enum rather than passed through: an unknown
        // event is a caller error, not a filter that silently matches nothing.
        if (is_string($event) && in_array($event, AuditEvent::values(), true)) {
            $query->where('event', $event);
        }

        $this->filters->exact($query, $request, 'user_id', 'actor_user_id');
        $this->filters->dateRange($query, $request, 'created_at');

        $type = $request->query('auditable_type');
        $id = $request->query('auditable_id');

        // Both or neither: a type without an id is a whole-table scan dressed as
        // a filter, and an id without a type matches across unrelated domains.
        if (is_string($type) && $type !== '' && is_string($id) && $id !== '') {
            $query->where('auditable_type', $type)->where('auditable_id', $id);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(AuditLog $log, bool $forCsv = false): array
    {
        $old = $log->old_values;
        $new = $log->new_values;

        return [
            'created_at' => $log->created_at?->toIso8601String(),
            'event' => $log->event->value,
            'actor' => $log->actor?->name,
            // The short name, not the FQCN: how the server is built is not the
            // auditor's business.
            'auditable_type' => class_basename($log->auditable_type),
            'auditable_id' => $log->auditable_id,
            'ip_address' => $log->ip_address,
            'reason' => $log->reason,
            'old_values' => $forCsv ? $this->flatten($old) : $old,
            'new_values' => $forCsv ? $this->flatten($new) : $new,
        ];
    }

    /**
     * A JSON column as one CSV cell.
     *
     * Encoded rather than spread across columns, because the shape differs per
     * row and a spreadsheet with a ragged right edge is unreadable.
     *
     * @param  array<string, mixed>|null  $values
     */
    private function flatten(?array $values): ?string
    {
        return $values === null ? null : json_encode($values, JSON_UNESCAPED_UNICODE);
    }
}
