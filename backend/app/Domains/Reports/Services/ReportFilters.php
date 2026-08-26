<?php

namespace App\Domains\Reports\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The filters every report shares, applied the same way each time (M8.3).
 *
 * **A report is one question on one surface** (D-118): "matters this quarter",
 * "matters for this PIC" and "Notary matters" are three parameters on one
 * address, not three routes.
 *
 * ## A date range is inclusive at both ends, and compared as a date
 *
 * `whereDate` rather than a raw timestamp comparison, because a caller asking for
 * `date_to=2026-08-31` means the whole of the 31st. Comparing a timestamp against
 * a bare date silently excludes everything after midnight — a report that loses
 * the last day of the month, which is exactly the day somebody is looking for.
 *
 * ## An unknown filter is ignored, never guessed
 *
 * Each method takes the column explicitly, so a request parameter can never
 * choose a column. `?status=x` filters `status`; there is no path by which
 * `?order_by=password` reaches SQL.
 */
class ReportFilters
{
    /**
     * Narrow by an inclusive date range on one column.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function dateRange(Builder $query, Request $request, string $column): Builder
    {
        $from = $request->query('date_from');
        $until = $request->query('date_to');

        if (is_string($from) && $from !== '') {
            $query->whereDate($column, '>=', $from);
        }

        if (is_string($until) && $until !== '') {
            $query->whereDate($column, '<=', $until);
        }

        return $query;
    }

    /**
     * Narrow by an exact match, when the caller supplied one.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function exact(Builder $query, Request $request, string $parameter, ?string $column = null): Builder
    {
        $value = $request->query($parameter);

        if (is_string($value) && $value !== '') {
            $query->where($column ?? $parameter, $value);
        }

        return $query;
    }

    /**
     * How many rows a page of a report carries.
     *
     * **Reports paginate.** The M8.3 brief's sketch returned `->get()` on an
     * unbounded query; `CLAUDE.md` section 43 forbids loading unbounded database
     * records into the frontend, and an office with four years of matters would
     * discover that the hard way.
     *
     * Export is the answer for "I want all of it", and it streams (see
     * {@see ReportExporter}) rather than materialising the set in memory.
     */
    public function perPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', '50'), 1), 200);
    }
}
