<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Actions\VerifyPayment;
use App\Domains\Billing\BillingVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The Payment surface (M8.2, D-124, O-050).
 *
 * **Three acts for three codes** — view, create, verify. There is no `update`,
 * no `DELETE` and no `reject` route, because none of those codes exists. The
 * M8.2 brief asked for `PATCH /payments/{id}/reject`; `payments.reject` is absent
 * from the catalogue and the brief forbade adding permissions.
 *
 * That leaves **no correction path for a verified payment** (O-050). What
 * mitigates it is the gap between recording and verifying: only verified
 * payments count toward anything, so a mistake caught in between moves no figure.
 * A wrong pending payment stays visible and uncounted rather than being hidden.
 *
 * ## Two list addresses, one collection
 *
 * `/invoices/{invoice}/payments` answers "what has been paid on this bill";
 * `/payments` answers "what has this office received". Both are the same rows
 * under the same capability, which is why the second is a filter rather than a
 * different surface (D-118).
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly BillingVisibility $visibility,
    ) {}

    /**
     * Every payment this office has recorded.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Payment::class);

        PaymentResource::resolveAmountVisibility($request);

        $actor = $request->user();

        $query = $this->visibility->scope(
            Payment::query(),
            $actor,
            $this->resolver->resolve($actor, 'payments.view'),
        )->with(['invoice:id,invoice_number,title', 'verifiedBy:id,name']);

        $this->applyFilters($query, $request);

        $page = $query
            ->orderByDesc('paid_at')
            ->paginate(min((int) $request->query('per_page', '25'), 100))
            ->withQueryString();

        return PaymentResource::collection($page);
    }

    /**
     * What has been paid against one invoice.
     */
    public function forInvoice(Request $request, string $invoice): AnonymousResourceCollection
    {
        $record = $this->resolveInvoice($request, $invoice);

        $this->authorize('viewAny', Payment::class);

        PaymentResource::resolveAmountVisibility($request);

        $payments = $record->payments()->with('verifiedBy:id,name')->get();

        return PaymentResource::collection($payments);
    }

    public function store(
        StorePaymentRequest $request,
        string $invoice,
        RecordPayment $record,
    ): JsonResponse {
        $target = $this->resolveInvoice($request, $invoice);

        // Authorized against the parent Invoice: the question is whether this
        // actor may record money against *this* bill, and a Payment row does not
        // exist yet to judge.
        $this->authorize('create', [Payment::class, $target]);

        PaymentResource::resolveAmountVisibility($request);

        $payment = $record->handle($request->user(), $target, $request->paymentAttributes());

        return (new PaymentResource($this->loadForDetail($payment)))->withCapabilities($this->capabilitiesFor($payment))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $payment): PaymentResource
    {
        $record = $this->resolvePayment($request, $payment);

        $this->authorize('view', $record);

        PaymentResource::resolveAmountVisibility($request);

        return (new PaymentResource($this->loadForDetail($record)))->withCapabilities($this->capabilitiesFor($record));
    }

    /**
     * Confirm that the money really arrived.
     *
     * **The one-way door.** After this the payment counts toward the invoice's
     * paid total and nothing can undo it (O-050).
     */
    public function verify(Request $request, string $payment, VerifyPayment $verify): PaymentResource
    {
        $record = $this->resolvePayment($request, $payment);

        $this->authorize('verify', $record);

        PaymentResource::resolveAmountVisibility($request);

        $verified = $verify->handle($request->user(), $record);

        return (new PaymentResource($this->loadForDetail($verified)))->withCapabilities($this->capabilitiesFor($verified));
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve a Payment the caller can reach, or 404.
     *
     * Always under `payments.view`, never under `payments.verify` — a record out
     * of reach is a 404, and lacking authority over a reachable one is the
     * Policy's 403 (D-098).
     */
    private function resolvePayment(Request $request, string $id): Payment
    {
        $actor = $request->user();

        $record = $this->visibility->scope(
            Payment::query()->whereKey($id),
            $actor,
            $this->resolver->resolve($actor, 'payments.view'),
        )->first();

        abort_if($record === null, 404);

        return $record;
    }

    /**
     * The parent invoice, reached under `invoices.view`.
     *
     * Reaching the invoice never confers authority to record money against it —
     * `payments.create` is checked separately by the Policy (D-091).
     */
    private function resolveInvoice(Request $request, string $id): Invoice
    {
        $actor = $request->user();

        $record = $this->visibility->scope(
            Invoice::query()->whereKey($id),
            $actor,
            $this->resolver->resolve($actor, 'invoices.view'),
        )->first();

        abort_if($record === null, 404);

        return $record;
    }

    private function loadForDetail(Payment $payment): Payment
    {
        return $payment->load(['invoice:id,invoice_number,title', 'verifiedBy:id,name']);
    }

    /**
     * @return array<string, bool>
     */
    private function capabilitiesFor(Payment $payment): array
    {
        return [
            // Capability **and** state. Verifying is a one-way door (O-050), so
            // the interface must not offer it on a payment already through it.
            'can_verify' => $payment->status->isVerifiable()
                && request()->user()->can('verify', $payment),
        ];
    }

    /**
     * @param  Builder<Payment>  $query
     */
    private function applyFilters($query, Request $request): void
    {
        foreach (['status', 'method_code', 'invoice_id'] as $column) {
            $value = $request->query($column);

            if (is_string($value) && $value !== '') {
                $query->where($column, $value);
            }
        }

        $from = $request->query('from');
        $until = $request->query('until');

        if (is_string($from) && $from !== '') {
            $query->whereDate('paid_at', '>=', $from);
        }

        if (is_string($until) && $until !== '') {
            $query->whereDate('paid_at', '<=', $until);
        }
    }
}
