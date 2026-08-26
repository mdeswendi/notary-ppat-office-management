<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Billing\Actions\CancelInvoice;
use App\Domains\Billing\Actions\CreateInvoice;
use App\Domains\Billing\Actions\IssueInvoice;
use App\Domains\Billing\Actions\ManageBillingLines;
use App\Domains\Billing\Actions\UpdateInvoice;
use App\Domains\Billing\BillingVisibility;
use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Enums\QuotationStatus;
use App\Domains\Matter\MatterVisibility;
use App\Domains\Party\PartyVisibility;
use App\Domains\Project\ProjectVisibility;
use App\Http\Controllers\Api\V1\Concerns\ResolvesBillingContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\BillingLineRequest;
use App\Http\Requests\Billing\CancelInvoiceRequest;
use App\Http\Requests\Billing\StoreInvoiceRequest;
use App\Http\Requests\Billing\UpdateInvoiceRequest;
use App\Http\Resources\InvoiceItemResource;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The Invoice surface (M8.2, D-124).
 *
 * **Five acts for five codes** — view, create, update, issue, cancel. **There is
 * no `DELETE` route**, because `invoices.delete` does not exist: a draft raised
 * in error is cancelled, which keeps the number and the figures where an audit
 * can find them (O-051).
 *
 * **There is no `send` route either.** The catalogue's verb is `issue`, and
 * issuing is sending.
 *
 * **`store` is also the conversion endpoint.** Supplying `quotation_id` copies an
 * approved quotation's lines onto the new invoice, which is how the brief's
 * `PATCH /quotations/{id}/convert` is delivered without a code that does not
 * exist.
 */
class InvoiceController extends Controller
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
        $this->authorize('viewAny', Invoice::class);

        InvoiceResource::resolveAmountVisibility($request);

        $actor = $request->user();

        $query = $this->visibility->scope(
            Invoice::query(),
            $actor,
            $this->resolver->resolve($actor, 'invoices.view'),
        )->with(['clientParty:id,display_name', 'project:id,project_number,title', 'matter:id,matter_number,title'])
            ->withCount(['items', 'payments'])
            ->withSettlement();

        $this->applyFilters($query, $request);

        $page = $query
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->query('per_page', '25'), 100))
            ->withQueryString();

        return InvoiceResource::collection($page);
    }

    public function store(StoreInvoiceRequest $request, CreateInvoice $create): JsonResponse
    {
        $this->authorize('create', [Invoice::class, $request->user()->office_id]);

        InvoiceResource::resolveAmountVisibility($request);

        $invoice = $create->handle(
            $request->user(),
            $request->invoiceAttributes(),
            $this->resolveParty($request, $request->input('client_party_id')),
            $this->resolveProject($request, $request->input('project_id')),
            $this->resolveMatter($request, $request->input('matter_id')),
            $this->resolveQuotationForConversion($request, $request->input('quotation_id')),
        );

        return (new InvoiceResource($this->loadForDetail($invoice)))->withCapabilities($this->capabilitiesFor($invoice))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $invoice): InvoiceResource
    {
        $record = $this->resolveInvoice($request, $invoice);

        $this->authorize('view', $record);

        InvoiceResource::resolveAmountVisibility($request);

        return (new InvoiceResource($this->loadForDetail($record)))->withCapabilities($this->capabilitiesFor($record));
    }

    public function update(
        UpdateInvoiceRequest $request,
        string $invoice,
        UpdateInvoice $update,
    ): InvoiceResource {
        $record = $this->resolveInvoice($request, $invoice);

        $this->authorize('update', $record);

        InvoiceResource::resolveAmountVisibility($request);

        $updated = $update->handle($request->user(), $record, $request->invoiceAttributes());

        return (new InvoiceResource($this->loadForDetail($updated)))->withCapabilities($this->capabilitiesFor($updated));
    }

    public function issue(Request $request, string $invoice, IssueInvoice $issue): InvoiceResource
    {
        $record = $this->resolveInvoice($request, $invoice);

        $this->authorize('issue', $record);

        InvoiceResource::resolveAmountVisibility($request);

        $issued = $issue->handle($request->user(), $record);

        return (new InvoiceResource($this->loadForDetail($issued)))->withCapabilities($this->capabilitiesFor($issued));
    }

    public function cancel(
        CancelInvoiceRequest $request,
        string $invoice,
        CancelInvoice $cancel,
    ): InvoiceResource {
        $record = $this->resolveInvoice($request, $invoice);

        $this->authorize('cancel', $record);

        InvoiceResource::resolveAmountVisibility($request);

        $cancelled = $cancel->handle($request->user(), $record, $request->cancellationReason());

        return (new InvoiceResource($this->loadForDetail($cancelled)))->withCapabilities($this->capabilitiesFor($cancelled));
    }

    /*
    |--------------------------------------------------------------------------
    | Lines — authorized as an update to the parent
    |--------------------------------------------------------------------------
    */

    public function storeLine(
        BillingLineRequest $request,
        string $invoice,
        ManageBillingLines $lines,
    ): JsonResponse {
        $record = $this->resolveInvoice($request, $invoice);

        $this->authorize('update', $record);

        InvoiceItemResource::resolveAmountVisibility($request);

        $line = $lines->addToInvoice($request->user(), $record, $request->lineAttributes());

        return (new InvoiceItemResource($line))->response()->setStatusCode(201);
    }

    public function updateLine(
        BillingLineRequest $request,
        string $invoice,
        string $item,
        ManageBillingLines $lines,
    ): InvoiceItemResource {
        $record = $this->resolveInvoice($request, $invoice);

        $this->authorize('update', $record);

        InvoiceItemResource::resolveAmountVisibility($request);

        $line = $this->resolveLine($record, $item);

        return new InvoiceItemResource(
            $lines->updateInvoiceLine($request->user(), $record, $line, $request->lineAttributes())
        );
    }

    public function destroyLine(
        Request $request,
        string $invoice,
        string $item,
        ManageBillingLines $lines,
    ): Response {
        $record = $this->resolveInvoice($request, $invoice);

        $this->authorize('update', $record);

        $lines->removeInvoiceLine($request->user(), $record, $this->resolveLine($record, $item));

        return response()->noContent();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function resolveInvoice(Request $request, string $id): Invoice
    {
        $actor = $request->user();

        $record = $this->visibility->scope(
            Invoice::query()->whereKey($id),
            $actor,
            $this->resolver->resolve($actor, 'invoices.view'),
        )->withSettlement()->first();

        abort_if($record === null, 404);

        return $record;
    }

    /**
     * The quotation this invoice is being raised from, if any.
     *
     * **Re-resolved under `quotations.view`, and it must be approved.** Billing
     * an offer nobody agreed to is the one sequencing rule this surface has, and
     * it follows from what the two states mean rather than from an invented
     * workflow. A 422 rather than a 404: the id is a field on this request.
     */
    private function resolveQuotationForConversion(Request $request, ?string $id): ?Quotation
    {
        if ($id === null || $id === '') {
            return null;
        }

        $actor = $request->user();

        $quotation = $this->visibility->scope(
            Quotation::query()->whereKey($id),
            $actor,
            $this->resolver->resolve($actor, 'quotations.view'),
        )->first();

        abort_if($quotation === null, 422, 'The selected quotation is not available.');

        abort_if(
            $quotation->status !== QuotationStatus::APPROVED,
            422,
            'Only an approved quotation can be billed.',
        );

        return $quotation;
    }

    private function resolveLine(Invoice $invoice, string $id): InvoiceItem
    {
        $line = $invoice->items()->whereKey($id)->first();

        abort_if($line === null, 404);

        return $line;
    }

    private function loadForDetail(Invoice $invoice): Invoice
    {
        $invoice->load([
            'clientParty:id,display_name',
            'project:id,project_number,title',
            'matter:id,matter_number,title',
            'quotation:id,quotation_number',
            'items',
            'payments.verifiedBy:id,name',
        ])->loadCount(['items', 'payments']);

        // The settlement aggregate is a query attribute rather than a relation,
        // so a model reloaded here has to be given it again — otherwise
        // `paidAmount()` falls back to a second query per detail response.
        //
        // Summed from the loaded payments rather than re-queried, and filtered
        // through the enum rather than by string: `counts()` is the one place
        // that decides what a verified payment means.
        $invoice->setAttribute(
            'verified_payments_sum',
            $invoice->payments
                ->filter(static fn (Payment $payment): bool => $payment->status->counts())
                ->sum(static fn (Payment $payment): float => (float) $payment->amount),
        );

        return $invoice;
    }

    /**
     * @return array<string, bool>
     */
    private function capabilitiesFor(Invoice $invoice): array
    {
        $actor = request()->user();

        return [
            'can_update' => $actor->can('update', $invoice),

            // Capability **and** state, so the interface never offers an act
            // the record would refuse. Issuing additionally needs a line: a
            // bill for nothing is not a bill.
            'can_issue' => $invoice->status->isIssuable()
                && $invoice->items()->exists()
                && $actor->can('issue', $invoice),

            'can_cancel' => $invoice->status->isCancellable()
                && $actor->can('cancel', $invoice),
        ];
    }

    /**
     * @param  Builder<Invoice>  $query
     */
    private function applyFilters($query, Request $request): void
    {
        $status = $request->query('status');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        foreach (['client_party_id', 'project_id', 'matter_id', 'quotation_id'] as $column) {
            $value = $request->query($column);

            if (is_string($value) && $value !== '') {
                $query->where($column, $value);
            }
        }

        // "What is late" — one filter rather than a stored status (D-124).
        if (in_array($request->query('overdue'), ['true', '1'], true)) {
            $query->where('status', InvoiceStatus::ISSUED->value)
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', now()->toDateString());
        }

        $search = $request->query('search');

        if (is_string($search) && $search !== '') {
            $query->where(function ($inner) use ($search): void {
                $inner->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%");
            });
        }
    }
}
