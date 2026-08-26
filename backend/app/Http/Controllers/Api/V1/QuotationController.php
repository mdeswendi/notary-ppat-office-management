<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Billing\Actions\ApproveQuotation;
use App\Domains\Billing\Actions\CreateQuotation;
use App\Domains\Billing\Actions\ManageBillingLines;
use App\Domains\Billing\Actions\UpdateQuotation;
use App\Domains\Billing\BillingVisibility;
use App\Domains\Matter\MatterVisibility;
use App\Domains\Party\PartyVisibility;
use App\Domains\Project\ProjectVisibility;
use App\Http\Controllers\Api\V1\Concerns\ResolvesBillingContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\BillingLineRequest;
use App\Http\Requests\Billing\StoreQuotationRequest;
use App\Http\Requests\Billing\UpdateQuotationRequest;
use App\Http\Resources\QuotationItemResource;
use App\Http\Resources\QuotationResource;
use App\Models\Quotation;
use App\Models\QuotationItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The Quotation surface (M8.2, D-124).
 *
 * **Four acts, because the catalogue defines four codes.** The M8.2 brief asked
 * for `send`, `reject`, `convert` and a `DELETE`; none of `quotations.send`,
 * `.reject`, `.convert` or `.delete` exists, and the brief itself forbade adding
 * permissions. There is no route here for any of them — the D-064 discipline
 * applied to endpoints rather than to menu entries.
 *
 * **Line items live under this controller, not their own.** There is no
 * `quotations.items.*` family; editing what an offer contains *is* editing the
 * offer, so every line route authorizes `update` on the parent and inherits its
 * `DRAFT`-only rule.
 *
 * **`billing.amount.view` is resolved once per request**, before anything is
 * serialised. Resolving it inside the Resource would put a resolver call on every
 * row of every page.
 */
class QuotationController extends Controller
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
        $this->authorize('viewAny', Quotation::class);

        QuotationResource::resolveAmountVisibility($request);

        $actor = $request->user();

        $query = $this->visibility->scope(
            Quotation::query(),
            $actor,
            $this->resolver->resolve($actor, 'quotations.view'),
        )->with(['clientParty:id,display_name', 'project:id,project_number,title', 'matter:id,matter_number,title'])
            ->withCount(['items', 'invoices']);

        $this->applyFilters($query, $request);

        $page = $query
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->query('per_page', '25'), 100))
            ->withQueryString();

        return QuotationResource::collection($page);
    }

    public function store(
        StoreQuotationRequest $request,
        CreateQuotation $create,
    ): JsonResponse {
        $this->authorize('create', [Quotation::class, $request->user()->office_id]);

        QuotationResource::resolveAmountVisibility($request);

        $quotation = $create->handle(
            $request->user(),
            $request->quotationAttributes(),
            $this->resolveParty($request, $request->input('client_party_id')),
            $this->resolveProject($request, $request->input('project_id')),
            $this->resolveMatter($request, $request->input('matter_id')),
        );

        return (new QuotationResource($this->loadForDetail($quotation)))->withCapabilities($this->capabilitiesFor($quotation))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $quotation): QuotationResource
    {
        $record = $this->resolveQuotation($request, $quotation);

        $this->authorize('view', $record);

        QuotationResource::resolveAmountVisibility($request);

        return (new QuotationResource($this->loadForDetail($record)))->withCapabilities($this->capabilitiesFor($record));
    }

    public function update(
        UpdateQuotationRequest $request,
        string $quotation,
        UpdateQuotation $update,
    ): QuotationResource {
        $record = $this->resolveQuotation($request, $quotation);

        $this->authorize('update', $record);

        QuotationResource::resolveAmountVisibility($request);

        $updated = $update->handle($request->user(), $record, $request->quotationAttributes());

        return (new QuotationResource($this->loadForDetail($updated)))->withCapabilities($this->capabilitiesFor($updated));
    }

    /**
     * Record that the client agreed the price.
     */
    public function approve(
        Request $request,
        string $quotation,
        ApproveQuotation $approve,
    ): QuotationResource {
        $record = $this->resolveQuotation($request, $quotation);

        $this->authorize('approve', $record);

        QuotationResource::resolveAmountVisibility($request);

        $approved = $approve->handle($request->user(), $record);

        return (new QuotationResource($this->loadForDetail($approved)))->withCapabilities($this->capabilitiesFor($approved));
    }

    /*
    |--------------------------------------------------------------------------
    | Lines — authorized as an update to the parent
    |--------------------------------------------------------------------------
    */

    public function storeLine(
        BillingLineRequest $request,
        string $quotation,
        ManageBillingLines $lines,
    ): JsonResponse {
        $record = $this->resolveQuotation($request, $quotation);

        $this->authorize('update', $record);

        QuotationItemResource::resolveAmountVisibility($request);

        $line = $lines->addToQuotation($request->user(), $record, $request->lineAttributes());

        return (new QuotationItemResource($line))->response()->setStatusCode(201);
    }

    public function updateLine(
        BillingLineRequest $request,
        string $quotation,
        string $item,
        ManageBillingLines $lines,
    ): QuotationItemResource {
        $record = $this->resolveQuotation($request, $quotation);

        $this->authorize('update', $record);

        QuotationItemResource::resolveAmountVisibility($request);

        $line = $this->resolveLine($record, $item);

        return new QuotationItemResource(
            $lines->updateQuotationLine($request->user(), $record, $line, $request->lineAttributes())
        );
    }

    public function destroyLine(
        Request $request,
        string $quotation,
        string $item,
        ManageBillingLines $lines,
    ): Response {
        $record = $this->resolveQuotation($request, $quotation);

        $this->authorize('update', $record);

        $lines->removeQuotationLine($request->user(), $record, $this->resolveLine($record, $item));

        return response()->noContent();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve a Quotation the caller can reach, or 404.
     *
     * **Always under `quotations.view`**, never under the act's own code. A
     * record the caller cannot reach is indistinguishable from one that does not
     * exist (D-098); lacking authority over a *reachable* record is the Policy's
     * 403. Resolving under the act's code would collapse the two.
     */
    private function resolveQuotation(Request $request, string $id): Quotation
    {
        $actor = $request->user();

        $record = $this->visibility->scope(
            Quotation::query()->whereKey($id),
            $actor,
            $this->resolver->resolve($actor, 'quotations.view'),
        )->first();

        abort_if($record === null, 404);

        return $record;
    }

    private function resolveLine(Quotation $quotation, string $id): QuotationItem
    {
        $line = $quotation->items()->whereKey($id)->first();

        abort_if($line === null, 404);

        return $line;
    }

    private function loadForDetail(Quotation $quotation): Quotation
    {
        return $quotation->load([
            'clientParty:id,display_name',
            'project:id,project_number,title',
            'matter:id,matter_number,title',
            'approvedBy:id,name',
            'items',
        ])->loadCount(['items', 'invoices']);
    }

    /**
     * What this actor may do to this record, reported rather than guessed.
     *
     * @return array<string, bool>
     */
    private function capabilitiesFor(Quotation $quotation): array
    {
        $actor = request()->user();

        return [
            'can_update' => $actor->can('update', $quotation),

            // Capability **and** state. The Policy checks only the first, so a
            // flag built from `can()` alone would offer a button that answers
            // 422 on an already-approved quotation.
            'can_approve' => $quotation->status->isApprovable()
                && $actor->can('approve', $quotation),
        ];
    }

    /**
     * @param  Builder<Quotation>  $query
     */
    private function applyFilters($query, Request $request): void
    {
        $status = $request->query('status');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        foreach (['client_party_id', 'project_id', 'matter_id'] as $column) {
            $value = $request->query($column);

            if (is_string($value) && $value !== '') {
                $query->where($column, $value);
            }
        }

        $search = $request->query('search');

        if (is_string($search) && $search !== '') {
            $query->where(function ($inner) use ($search): void {
                $inner->where('quotation_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%");
            });
        }
    }
}
