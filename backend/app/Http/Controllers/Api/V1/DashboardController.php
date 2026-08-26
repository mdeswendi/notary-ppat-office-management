<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Dashboard\Services\DashboardAggregator;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\DashboardTaskResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The office's operational overview (M8.1, D-122).
 *
 * ## There is no `authorize()` call in this file, and that is the design
 *
 * The registry contains **no `dashboard.*` code**, none is registered, and none
 * is needed. The Dashboard is not a resource; it is a composition of readings the
 * actor is already entitled to make, and composing several onto one page creates
 * no new thing to authorize.
 *
 * What stops it becoming a back door is that **every figure is produced by
 * {@see DashboardAggregator}, which resolves each panel's own capability and
 * applies that resource's own Data Scope predicate**. A panel the actor may not
 * see comes back `null`; a panel they may see with nothing in it comes back `0`
 * or `[]`. An actor holding nothing gets a page of nulls and a 200, which is
 * correct behaviour rather than an error state.
 *
 * So these endpoints require authentication and nothing else. Adding a
 * `$this->authorize()` here would be inventing a capability the catalogue does
 * not define — the D-064 mistake in reverse.
 *
 * ## Six endpoints rather than one
 *
 * The panels have very different costs, and a single `/dashboard` call would make
 * the cheapest one wait for the most expensive. Six addresses also let the
 * interface refresh the task queue without recomputing deed histograms, and let a
 * client skip entirely what its user cannot see.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardAggregator $aggregator) {}

    /**
     * The headline figures.
     *
     * Each is `null` where the actor holds no usable scope for the resource
     * behind it — never `0`, which would be a lie about records they may not
     * know the number of.
     */
    public function stats(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->aggregator->stats($request->user()),
        ]);
    }

    /**
     * The caller's own work: due today, overdue, and coming up.
     */
    public function tasks(Request $request): JsonResponse
    {
        $buckets = $this->aggregator->tasks($request->user());

        if ($buckets === null) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => [
                'today' => DashboardTaskResource::collection($buckets['today'])->resolve(),
                'overdue' => DashboardTaskResource::collection($buckets['overdue'])->resolve(),
                'upcoming' => DashboardTaskResource::collection($buckets['upcoming'])->resolve(),
            ],
        ]);
    }

    /**
     * What is stalled, with no invented staleness threshold.
     *
     * See {@see DashboardAggregator::needsAttention()} for why the brief's
     * "3 days waiting / 2 days pending / 1 day overdue" rules are not encoded.
     */
    public function needsAttention(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->aggregator->needsAttention($request->user()),
        ]);
    }

    /**
     * Who is carrying how much — by assignment, never by role name.
     */
    public function workload(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->aggregator->workload($request->user()),
        ]);
    }

    /**
     * The recent timeline, authorized per row by each entry's subject.
     *
     * **Empty until something happens.** Nothing is backfilled (D-123), so an
     * office that upgraded today sees nothing here, and that is expected.
     */
    public function activity(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', '20');
        $limit = max(1, min($limit, 50));

        $activities = $this->aggregator->activity($request->user(), $limit);

        return response()->json([
            'data' => ActivityResource::collection($activities)->resolve(),
        ]);
    }

    /**
     * Deed counts per domain and status, for the domains the actor may read.
     */
    public function deeds(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->aggregator->deeds($request->user()),
        ]);
    }
}
