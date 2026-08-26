<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Ppat\Enums\PpatDeedStatus;
use App\Domains\Reports\Report;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Reports\Services\ReportFilters;
use App\Domains\Reports\Services\ReportQueries;
use App\Http\Controllers\Controller;
use App\Models\PpatDeed;
use App\Models\PpatWarkah;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PPAT deeds, land objects and Warkah completeness (M8.3, D-126).
 *
 * ## This answers to `reports.ppat.view`, not `ppat.reports.*`
 *
 * The two families differ only in word order and are different capabilities.
 * **`ppat.reports.generate`, `.review` and `.approve` are untouched here**: they
 * describe producing a document and signing it off, which is the PPAT monthly
 * reporting obligation — deadline, recipient and format all unauthored (O-043).
 * The lock states this twice, in sections 3.2 and 10, because confusing them
 * would be the most consequential mistake available in this milestone.
 *
 * Nothing here is a register extract or a monthly return. It is a filtered list
 * with counts, exported as CSV working data.
 *
 * ## The property report has no status filter
 *
 * `properties.status` has no vocabulary in the ERD and nothing in the
 * application writes it (M7.3) — it is null on every row. Filtering by it would
 * narrow by nothing and grouping by it would produce one unlabelled bucket.
 * `property_type` and `right_type` are real, and are what this reports.
 */
class PpatReportController extends Controller
{
    public function __construct(
        private readonly ReportQueries $queries,
        private readonly ReportFilters $filters,
        private readonly ReportExporter $exporter,
    ) {}

    public function deeds(Request $request): JsonResponse
    {
        $this->authorize('viewPpat', Report::class);

        $page = $this->deedQuery($request)
            ->orderByDesc('ppat_deeds.deed_date')
            ->orderByDesc('ppat_deeds.created_at')
            ->paginate($this->filters->perPage($request))
            ->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (PpatDeed $deed): array => $this->deedRow($deed))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function exportDeeds(Request $request): StreamedResponse
    {
        $this->authorize('viewPpat', Report::class);
        $this->authorize('export', Report::class);

        return $this->exporter->stream(
            $this->deedQuery($request)->reorder('ppat_deeds.id'),
            'ppat-deeds',
            ['deed_number', 'deed_date', 'deed_type_code', 'title', 'status', 'matter', 'finalized_at'],
            fn (PpatDeed $deed): array => array_values($this->deedRow($deed)),
        );
    }

    public function properties(Request $request): JsonResponse
    {
        $this->authorize('viewPpat', Report::class);

        $page = $this->propertyQuery($request)
            ->orderBy('properties.property_number')
            ->paginate($this->filters->perPage($request))
            ->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (Property $p): array => $this->propertyRow($p))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function exportProperties(Request $request): StreamedResponse
    {
        $this->authorize('viewPpat', Report::class);
        $this->authorize('export', Report::class);

        return $this->exporter->stream(
            $this->propertyQuery($request)->reorder('properties.id'),
            'properties',
            ['property_number', 'property_type', 'right_type', 'certificate_number', 'land_area', 'village', 'district', 'city', 'province'],
            fn (Property $property): array => array_values($this->propertyRow($property)),
        );
    }

    /**
     * Warkah bundles and how complete each one is.
     *
     * `completeness_percentage` is stored and maintained by
     * `PpatWarkah::recalculateCompleteness()`, so the range filter is an
     * ordinary comparison. **Completeness counts documents, never judges them**
     * (M7.4): a bundle at 100% is one where every line the office created has a
     * file, not one this software considers legally complete.
     */
    public function warkah(Request $request): JsonResponse
    {
        $this->authorize('viewPpat', Report::class);

        $page = $this->warkahQuery($request)
            ->orderBy('completeness_percentage')
            ->paginate($this->filters->perPage($request))
            ->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (PpatWarkah $w): array => $this->warkahRow($w))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function exportWarkah(Request $request): StreamedResponse
    {
        $this->authorize('viewPpat', Report::class);
        $this->authorize('export', Report::class);

        return $this->exporter->stream(
            $this->warkahQuery($request)->reorder('ppat_warkah.id'),
            'warkah',
            ['deed_number', 'status', 'completeness_percentage', 'verified_at', 'archive_location'],
            fn (PpatWarkah $warkah): array => array_values($this->warkahRow($warkah)),
        );
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewPpat', Report::class);

        $query = $this->deedQuery($request);

        $byStatus = (clone $query)->getQuery()
            ->select('status')->selectRaw('count(*) as aggregate')
            ->groupBy('status')->pluck('aggregate', 'status');

        $counts = [];

        foreach (PpatDeedStatus::values() as $status) {
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

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return Builder<PpatDeed>
     */
    private function deedQuery(Request $request): Builder
    {
        $query = $this->queries->ppatDeeds($request->user());

        $this->filters->exact($query, $request, 'status', 'ppat_deeds.status');
        $this->filters->exact($query, $request, 'type', 'ppat_deeds.deed_type_code');
        $this->filters->exact($query, $request, 'matter_id', 'ppat_deeds.matter_id');
        $this->filters->dateRange($query, $request, 'ppat_deeds.deed_date');

        return $query;
    }

    /**
     * @return Builder<Property>
     */
    private function propertyQuery(Request $request): Builder
    {
        $query = $this->queries->properties($request->user());

        // No `status` filter — see the class docblock.
        $this->filters->exact($query, $request, 'type', 'properties.property_type');
        $this->filters->exact($query, $request, 'right_type', 'properties.right_type');
        $this->filters->exact($query, $request, 'city', 'properties.city');

        return $query;
    }

    /**
     * @return Builder<PpatWarkah>
     */
    private function warkahQuery(Request $request): Builder
    {
        $query = $this->queries->warkah($request->user());

        $this->filters->exact($query, $request, 'status', 'ppat_warkah.status');

        $min = $request->query('completeness_min');
        $max = $request->query('completeness_max');

        if (is_numeric($min)) {
            $query->where('completeness_percentage', '>=', (int) $min);
        }

        if (is_numeric($max)) {
            $query->where('completeness_percentage', '<=', (int) $max);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function deedRow(PpatDeed $deed): array
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

    /**
     * @return array<string, mixed>
     */
    private function propertyRow(Property $property): array
    {
        return [
            'property_number' => $property->property_number,
            'property_type' => $property->property_type,
            'right_type' => $property->right_type,
            'certificate_number' => $property->certificate_number,
            'land_area' => $property->land_area,
            'village' => $property->village,
            'district' => $property->district,
            'city' => $property->city,
            'province' => $property->province,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function warkahRow(PpatWarkah $warkah): array
    {
        return [
            'deed_number' => $warkah->deed?->deed_number,
            'status' => $warkah->status?->value,
            'completeness_percentage' => $warkah->completeness_percentage,
            'verified_at' => $warkah->verified_at?->toDateString(),
            'archive_location' => $warkah->archive_location,
        ];
    }
}
