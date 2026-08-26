<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Billing\Actions\CreateDisbursement;
use App\Domains\Billing\Actions\UpdateDisbursement;
use App\Domains\Billing\BillingVisibility;
use App\Domains\Matter\MatterVisibility;
use App\Domains\Party\PartyVisibility;
use App\Domains\Project\ProjectVisibility;
use App\Http\Controllers\Api\V1\Concerns\ResolvesBillingContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreDisbursementRequest;
use App\Http\Requests\Billing\UpdateDisbursementRequest;
use App\Http\Resources\DisbursementResource;
use App\Models\Disbursement;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The Disbursement surface (M8.2, D-124).
 *
 * **Three acts for three codes** — view, create, update. No `DELETE`, because
 * `disbursements.delete` does not exist (O-051), and **no lifecycle route**,
 * because the catalogue gives this surface no lifecycle verb — which is also why
 * the table has no `status` column.
 *
 * A disbursement records that the office spent money for a client. **It does not
 * know whether that money was a tax**, it computes no rate, and it gates
 * nothing — the line that keeps O-040 intact.
 */
class DisbursementController extends Controller
{
    use ResolvesBillingContext;

    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly BillingVisibility $visibility,
        private readonly PartyVisibility $parties,
        private readonly ProjectVisibility $projects,
        private readonly MatterVisibility $matters,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Disbursement::class);

        DisbursementResource::resolveAmountVisibility($request);

        $actor = $request->user();

        $query = $this->visibility->scope(
            Disbursement::query(),
            $actor,
            $this->resolver->resolve($actor, 'disbursements.view'),
        )->with([
            'clientParty:id,display_name',
            'project:id,project_number,title',
            'matter:id,matter_number,title',
            'invoice:id,invoice_number',
        ]);

        $this->applyFilters($query, $request);

        $page = $query
            ->orderByDesc('incurred_on')
            ->paginate(min((int) $request->query('per_page', '25'), 100))
            ->withQueryString();

        return DisbursementResource::collection($page);
    }

    public function store(
        StoreDisbursementRequest $request,
        CreateDisbursement $create,
    ): JsonResponse {
        $this->authorize('create', [Disbursement::class, $request->user()->office_id]);

        DisbursementResource::resolveAmountVisibility($request);

        $disbursement = $create->handle(
            $request->user(),
            $request->disbursementAttributes(),
            $this->resolveParty($request, $request->input('client_party_id')),
            $this->resolveProject($request, $request->input('project_id')),
            $this->resolveMatter($request, $request->input('matter_id')),
            $this->resolveInvoice($request, $request->input('invoice_id')),
        );

        return (new DisbursementResource($this->loadForDetail($disbursement)))->withCapabilities($this->capabilitiesFor($disbursement))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $disbursement): DisbursementResource
    {
        $record = $this->resolveDisbursement($request, $disbursement);

        $this->authorize('view', $record);

        DisbursementResource::resolveAmountVisibility($request);

        return (new DisbursementResource($this->loadForDetail($record)))->withCapabilities($this->capabilitiesFor($record));
    }

    public function update(
        UpdateDisbursementRequest $request,
        string $disbursement,
        UpdateDisbursement $update,
    ): DisbursementResource {
        $record = $this->resolveDisbursement($request, $disbursement);

        $this->authorize('update', $record);

        DisbursementResource::resolveAmountVisibility($request);

        $updated = $update->handle($request->user(), $record, $request->disbursementAttributes());

        return (new DisbursementResource($this->loadForDetail($updated)))->withCapabilities($this->capabilitiesFor($updated));
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function resolveDisbursement(Request $request, string $id): Disbursement
    {
        $actor = $request->user();

        $record = $this->visibility->scope(
            Disbursement::query()->whereKey($id),
            $actor,
            $this->resolver->resolve($actor, 'disbursements.view'),
        )->first();

        abort_if($record === null, 404);

        return $record;
    }

    /**
     * The invoice this cost is meant to be re-billed on, if any.
     *
     * A 422 rather than a 404: the id is a field on this request. **Nothing is
     * copied onto that invoice** — re-billing is adding a line, a deliberate act
     * under `invoices.update`.
     */
    private function resolveInvoice(Request $request, ?string $id): ?Invoice
    {
        if ($id === null || $id === '') {
            return null;
        }

        $actor = $request->user();

        $invoice = $this->visibility->scope(
            Invoice::query()->whereKey($id),
            $actor,
            $this->resolver->resolve($actor, 'invoices.view'),
        )->first();

        abort_if($invoice === null, 422, 'The selected invoice is not available.');

        return $invoice;
    }

    private function loadForDetail(Disbursement $disbursement): Disbursement
    {
        return $disbursement->load([
            'clientParty:id,display_name',
            'project:id,project_number,title',
            'matter:id,matter_number,title',
            'invoice:id,invoice_number',
        ]);
    }

    /**
     * @return array<string, bool>
     */
    private function capabilitiesFor(Disbursement $disbursement): array
    {
        return [
            'can_update' => request()->user()->can('update', $disbursement),
        ];
    }

    /**
     * @param  Builder<Disbursement>  $query
     */
    private function applyFilters($query, Request $request): void
    {
        foreach (['client_party_id', 'project_id', 'matter_id', 'invoice_id'] as $column) {
            $value = $request->query($column);

            if (is_string($value) && $value !== '') {
                $query->where($column, $value);
            }
        }

        $from = $request->query('from');
        $until = $request->query('until');

        if (is_string($from) && $from !== '') {
            $query->whereDate('incurred_on', '>=', $from);
        }

        if (is_string($until) && $until !== '') {
            $query->whereDate('incurred_on', '<=', $until);
        }

        $search = $request->query('search');

        if (is_string($search) && $search !== '') {
            $query->where('description', 'like', "%{$search}%");
        }
    }
}
