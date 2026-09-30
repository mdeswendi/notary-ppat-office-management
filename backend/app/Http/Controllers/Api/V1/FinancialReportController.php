<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Reports\Report;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Reports\Services\ReportFilters;
use App\Domains\Reports\Services\ReportQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\Concerns\MasksBillingAmounts;
use App\Models\Disbursement;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Money in, and what is still owed (M8.3, D-126, D-125).
 *
 * ## Every figure obeys `billing.amount.view`, including in the export
 *
 * This is the report family where the masking gate matters most, because a
 * report is exactly the shape in which somebody would try to read amounts they
 * may not see one at a time. The rule is the same as M8.2's and applied in the
 * same place: **a masked amount is absent from the payload**, never blanked or
 * zeroed — and the CSV's header is built from the same decision, so a withheld
 * column is not a column of empty cells, it is **not a column**.
 *
 * `reports.financial.view` opens the family; `billing.amount.view` decides
 * whether it carries money; `reports.export` decides whether it can be taken
 * away. Three separate codes and none implies another (D-091).
 *
 * ## Revenue means money received, not money billed
 *
 * The revenue summary reads explicit **client receipt records**. It is separate
 * from the internal quotation price and from invoice settlement, because this
 * office records actual client receipts per payment rather than tagging an
 * invoice. Process costs are a separate outbound stream.
 *
 * **Nothing here computes tax.** Grouping by domain and service type is
 * arithmetic over rows the office typed; no rate is applied to anything (D-129,
 * and O-040 is still open).
 */
class FinancialReportController extends Controller
{
    use MasksBillingAmounts;

    public function __construct(
        private readonly ReportQueries $queries,
        private readonly ReportFilters $filters,
        private readonly ReportExporter $exporter,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Invoices
    |--------------------------------------------------------------------------
    */

    public function invoices(Request $request): JsonResponse
    {
        $this->authorize('viewFinancial', Report::class);

        $visible = self::resolveAmountVisibility($request);

        $page = $this->invoiceQuery($request)
            ->orderByDesc('issued_at')
            ->orderByDesc('created_at')
            ->paginate($this->filters->perPage($request))
            ->withQueryString();

        return response()->json([
            'data' => collect($page->items())
                ->map(fn (Invoice $invoice): array => $this->invoiceRow($invoice, $visible))
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'amounts_visible' => $visible,
            ],
        ]);
    }

    public function exportInvoices(Request $request): StreamedResponse
    {
        $this->authorize('viewFinancial', Report::class);
        $this->authorize('export', Report::class);

        $visible = self::resolveAmountVisibility($request);

        return $this->exporter->stream(
            $this->invoiceQuery($request)->reorder('invoices.id'),
            'invoices',
            $this->invoiceHeaders($visible),
            fn (Invoice $invoice): array => array_values($this->invoiceRow($invoice, $visible)),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Payments
    |--------------------------------------------------------------------------
    */

    public function payments(Request $request): JsonResponse
    {
        $this->authorize('viewFinancial', Report::class);

        $visible = self::resolveAmountVisibility($request);

        $page = $this->paymentQuery($request)
            ->orderByDesc('paid_at')
            ->paginate($this->filters->perPage($request))
            ->withQueryString();

        return response()->json([
            'data' => collect($page->items())
                ->map(fn (Payment $payment): array => $this->paymentRow($payment, $visible))
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'amounts_visible' => $visible,
            ],
        ]);
    }

    public function exportPayments(Request $request): StreamedResponse
    {
        $this->authorize('viewFinancial', Report::class);
        $this->authorize('export', Report::class);

        $visible = self::resolveAmountVisibility($request);

        return $this->exporter->stream(
            $this->paymentQuery($request)->reorder('payments.id'),
            'payments',
            $this->paymentHeaders($visible),
            fn (Payment $payment): array => array_values($this->paymentRow($payment, $visible)),
        );
    }

    public function disbursements(Request $request): JsonResponse
    {
        $this->authorize('viewFinancial', Report::class);

        $visible = self::resolveAmountVisibility($request);
        $page = $this->disbursementQuery($request)
            ->orderByDesc('incurred_on')
            ->paginate($this->filters->perPage($request))
            ->withQueryString();

        return response()->json([
            'data' => collect($page->items())
                ->map(fn (Disbursement $disbursement): array => $this->disbursementRow($disbursement, $visible))
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'amounts_visible' => $visible,
            ],
        ]);
    }

    public function exportDisbursements(Request $request): StreamedResponse
    {
        $this->authorize('viewFinancial', Report::class);
        $this->authorize('export', Report::class);

        $visible = self::resolveAmountVisibility($request);

        return $this->exporter->stream(
            $this->disbursementQuery($request)->reorder('disbursements.id'),
            'process-costs',
            $this->disbursementHeaders($visible),
            fn (Disbursement $disbursement): array => array_values($this->disbursementRow($disbursement, $visible)),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Revenue
    |--------------------------------------------------------------------------
    */

    /**
     * Actual client receipts grouped by month (D-139).
     *
     * **Returns nothing at all without `billing.amount.view`.** Every cell of
     * this report is a sum; there is no non-monetary half to serve, and a
     * "revenue report" of row counts would be a different report pretending to
     * be this one.
     */
    public function revenue(Request $request): JsonResponse
    {
        $this->authorize('viewFinancial', Report::class);

        if (! self::resolveAmountVisibility($request)) {
            return response()->json([
                'data' => null,
                'meta' => ['amounts_visible' => false],
            ]);
        }

        $rows = $this->revenueQuery($request);

        return response()->json([
            'data' => $rows,
            'meta' => ['amounts_visible' => true],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return Builder<Invoice>
     */
    private function invoiceQuery(Request $request)
    {
        $query = $this->queries->invoices($request->user());

        $this->filters->exact($query, $request, 'status', 'invoices.status');
        $this->filters->exact($query, $request, 'party_id', 'invoices.client_party_id');
        $this->filters->exact($query, $request, 'project_id', 'invoices.project_id');
        $this->filters->exact($query, $request, 'matter_id', 'invoices.matter_id');
        $this->filters->dateRange($query, $request, 'invoices.created_at');

        // "What is still owed and late" — computed from `due_date`, because
        // there is no OVERDUE status (D-124).
        if (in_array($request->query('overdue'), ['true', '1'], true)) {
            $query->where('invoices.status', InvoiceStatus::ISSUED->value)
                ->whereNotNull('invoices.due_date')
                ->whereDate('invoices.due_date', '<', now()->toDateString());
        }

        return $query;
    }

    /**
     * @return Builder<Payment>
     */
    private function paymentQuery(Request $request)
    {
        $query = $this->queries->payments($request->user());

        $this->filters->exact($query, $request, 'status', 'payments.status');
        $this->filters->exact($query, $request, 'method_code', 'payments.method_code');
        $this->filters->exact($query, $request, 'invoice_id', 'payments.invoice_id');
        $this->filters->dateRange($query, $request, 'payments.paid_at');

        return $query;
    }

    /**
     * @return Builder<Disbursement>
     */
    private function disbursementQuery(Request $request)
    {
        $query = $this->queries->disbursements($request->user());

        $this->filters->exact($query, $request, 'client_party_id', 'disbursements.client_party_id');
        $this->filters->dateRange($query, $request, 'disbursements.incurred_on');

        return $query;
    }

    /**
     * Sum office-scoped client receipts by month. Invoice payments,
     * disbursements and quotations are separate financial facts.
     *
     * @return array<int, array<string, mixed>>
     */
    private function revenueQuery(Request $request): array
    {
        $query = $this->queries->clientReceipts($request->user());

        return $query
            ->getQuery()
            ->selectRaw($this->monthExpression('client_receipts.received_on').' as period')
            ->selectRaw('sum(client_receipts.amount) as total_amount')
            ->selectRaw('count(*) as receipt_count')
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn (object $row): array => [
                'period' => $row->period,
                'total_amount' => $this->fixedAmount($row->total_amount),
                'receipt_count' => (int) $row->receipt_count,
            ])
            ->all();
    }

    /**
     * `YYYY-MM` from a date, in the dialect of whichever engine is connected.
     *
     * PostgreSQL runs production and SQLite runs the suite, and neither
     * understands the other's date formatting. Branching here keeps one grouping
     * rule rather than two reports that agree only by coincidence — the same
     * accommodation the CHECK-constraint migrations make.
     */
    private function monthExpression(string $column = 'payments.paid_at'): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? "to_char({$column}, 'YYYY-MM')"
            : "strftime('%Y-%m', {$column})";
    }

    /** Keep PostgreSQL NUMERIC totals out of binary floating-point. */
    private function fixedAmount(mixed $amount): string
    {
        $value = (string) $amount;
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $whole.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceRow(Invoice $invoice, bool $visible): array
    {
        return [
            'invoice_number' => $invoice->invoice_number,
            'title' => $invoice->title,
            'client' => $invoice->clientParty?->display_name,
            'project' => $invoice->project?->project_number,
            'matter' => $invoice->matter?->matter_number,
            'status' => $invoice->status->value,
            'currency' => $invoice->currency,
            'issued_at' => $invoice->issued_at?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'is_overdue' => $invoice->isOverdue() ? 'yes' : 'no',

            // Absent, not blank, when the grant is missing.
            ...($visible ? [
                'total_amount' => $invoice->total_amount,
                'paid_amount' => $invoice->paidAmount(),
                'outstanding_amount' => $invoice->outstandingAmount(),
            ] : []),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function invoiceHeaders(bool $visible): array
    {
        return [
            'invoice_number', 'title', 'client', 'project', 'matter',
            'status', 'currency', 'issued_at', 'due_date', 'is_overdue',

            // The masked columns are **absent from the header**, not present and
            // empty. A column of blanks invites the reader to conclude the office
            // was paid nothing.
            ...($visible ? ['total_amount', 'paid_amount', 'outstanding_amount'] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentRow(Payment $payment, bool $visible): array
    {
        return [
            'paid_at' => $payment->paid_at?->toDateString(),
            'invoice_number' => $payment->invoice?->invoice_number,
            'status' => $payment->status->value,
            'method_code' => $payment->method_code->value,
            'reference' => $payment->reference,
            'currency' => $payment->currency,

            ...($visible ? ['amount' => $payment->amount] : []),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function paymentHeaders(bool $visible): array
    {
        return [
            'paid_at', 'invoice_number', 'status', 'method_code', 'reference', 'currency',
            ...($visible ? ['amount'] : []),
        ];
    }

    /** @return array<string, mixed> */
    private function disbursementRow(Disbursement $disbursement, bool $visible): array
    {
        return [
            'incurred_on' => $disbursement->incurred_on?->toDateString(),
            'description' => $disbursement->description,
            'client' => $disbursement->clientParty?->display_name,
            'project' => $disbursement->project?->project_number,
            'matter' => $disbursement->matter?->matter_number,
            'currency' => $disbursement->currency,
            'reference' => $disbursement->reference,
            ...($visible ? ['amount' => $disbursement->amount] : []),
        ];
    }

    /** @return array<int, string> */
    private function disbursementHeaders(bool $visible): array
    {
        return [
            'incurred_on', 'description', 'client', 'project', 'matter', 'currency', 'reference',
            ...($visible ? ['amount'] : []),
        ];
    }
}
