<?php

use App\Domains\Audit\Enums\AuditEvent;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Billing\AllocateBillingReference;
use App\Domains\Billing\BillingReference;
use App\Domains\Billing\Enums\InvoiceStatus;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Office;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Billing (M8.2, D-124, D-125, O-050).
 */
function billingActor(array $permissions = [], DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

function billingCapabilities(): array
{
    return [
        'billing.view', 'billing.amount.view',
        'quotations.view', 'quotations.create', 'quotations.update', 'quotations.approve',
        'invoices.view', 'invoices.create', 'invoices.update', 'invoices.issue', 'invoices.cancel',
        'payments.view', 'payments.create', 'payments.verify',
        'disbursements.view', 'disbursements.create', 'disbursements.update',
    ];
}

/*
|--------------------------------------------------------------------------
| Schema
|--------------------------------------------------------------------------
*/

it('builds the five billing tables plus a counter', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();
})->with([
    'quotations', 'quotation_items', 'invoices', 'invoice_items',
    'payments', 'disbursements', 'billing_reference_counters',
]);

it('gives no billing table a tax column', function (string $table): void {
    // D-124 §9.4 forbids it in as many words, CLAUDE.md §62 names tax rules
    // among the things not to invent, and O-040 is still open. An office showing
    // PPN adds a line it names and prices itself.
    foreach (['tax', 'tax_amount', 'tax_rate', 'ppn', 'vat'] as $column) {
        expect(Schema::hasColumn($table, $column))->toBeFalse();
    }
})->with(['quotations', 'invoices', 'invoice_items', 'payments', 'disbursements']);

it('gives disbursements no status column', function (): void {
    // `disbursements.*` has no lifecycle verb, so a status would be vocabulary
    // nothing could reach — the D-109 pattern.
    expect(Schema::hasColumn('disbursements', 'status'))->toBeFalse();
});

it('gives payments no soft delete and no updated_by', function (string $column): void {
    // The catalogue gives payments no update and no delete, so neither column
    // exists to imply one (O-050).
    expect(Schema::hasColumn('payments', $column))->toBeFalse();
})->with(['deleted_at', 'updated_by']);

it('stores no derived settlement column on invoices', function (string $column): void {
    // Paid and remaining are computed from verified payments; stored, they drift.
    expect(Schema::hasColumn('invoices', $column))->toBeFalse();
})->with(['paid_amount', 'remaining_amount', 'issue_date', 'sent_at']);

it('registers no new permission', function (): void {
    expect(PermissionRegistry::all())->toHaveCount(177);
});

/*
|--------------------------------------------------------------------------
| Numbering
|--------------------------------------------------------------------------
*/

it('allocates quotation and invoice references per office and year', function (): void {
    [, $office] = billingActor();
    $other = Office::factory()->create();

    $allocator = app(AllocateBillingReference::class);

    expect($allocator->forOffice(BillingReference::INVOICE, $office->getKey(), 2026))->toBe('INV-2026-000001')
        ->and($allocator->forOffice(BillingReference::INVOICE, $office->getKey(), 2026))->toBe('INV-2026-000002')
        // A separate sequence, not a shared counter.
        ->and($allocator->forOffice(BillingReference::QUOTATION, $office->getKey(), 2026))->toBe('QUO-2026-000001')
        // Another Office starts at one: the namespace is (office, code, year).
        ->and($allocator->forOffice(BillingReference::INVOICE, $other->getKey(), 2026))->toBe('INV-2026-000001')
        // And a new year restarts it.
        ->and($allocator->forOffice(BillingReference::INVOICE, $office->getKey(), 2027))->toBe('INV-2027-000001');
});

/*
|--------------------------------------------------------------------------
| Quotation lifecycle
|--------------------------------------------------------------------------
*/

it('creates a quotation as a draft with an allocated number', function (): void {
    [$actor] = billingActor(billingCapabilities());

    $response = $this->actingAs($actor)->postJson('/api/v1/quotations', [
        'title' => 'Jasa pembuatan AJB',
    ])->assertCreated();

    expect($response->json('data.status'))->toBe('DRAFT')
        ->and($response->json('data.quotation_number'))->toBe('QUO-'.date('Y').'-000001')
        ->and($response->json('data.total_amount'))->toBe('0.00');
});

it('refuses a submitted status, number, total or tax', function (string $field): void {
    [$actor] = billingActor(billingCapabilities());

    $this->actingAs($actor)->postJson('/api/v1/quotations', [
        'title' => 'Uji',
        $field => 'X',
    ])->assertStatus(422)->assertJsonValidationErrors($field);
})->with(['status', 'quotation_number', 'total_amount', 'tax']);

it('sums lines into the quotation total', function (): void {
    [$actor] = billingActor(billingCapabilities());

    $id = $this->actingAs($actor)->postJson('/api/v1/quotations', ['title' => 'Uji'])
        ->json('data.id');

    $this->actingAs($actor)->postJson("/api/v1/quotations/{$id}/items", [
        'description' => 'Jasa notaris',
        'quantity' => 2,
        'unit_amount' => 1500000,
    ])->assertCreated()
        ->assertJsonPath('data.line_amount', '3000000.00');

    // A second line the office names itself — this is where tax goes, and the
    // software neither recognises nor computes it.
    $this->actingAs($actor)->postJson("/api/v1/quotations/{$id}/items", [
        'description' => 'PPN 11%',
        'quantity' => 1,
        'unit_amount' => 330000,
    ])->assertCreated();

    $this->actingAs($actor)->getJson("/api/v1/quotations/{$id}")
        ->assertOk()
        ->assertJsonPath('data.total_amount', '3330000.00');
});

it('approves a quotation once and refuses a second approval', function (): void {
    [$actor] = billingActor(billingCapabilities());

    $id = $this->actingAs($actor)->postJson('/api/v1/quotations', ['title' => 'Uji'])->json('data.id');

    $this->actingAs($actor)->patchJson("/api/v1/quotations/{$id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'APPROVED');

    // Refused by state, not by capability: 422, never 403.
    $this->actingAs($actor)->patchJson("/api/v1/quotations/{$id}/approve")
        ->assertStatus(422);
});

it('refuses to edit an approved quotation', function (): void {
    // Approving is the finalization act: CLAUDE.md §64 applied to a commercial
    // record, because a client has agreed the figures.
    [$actor] = billingActor(billingCapabilities());

    $id = $this->actingAs($actor)->postJson('/api/v1/quotations', ['title' => 'Uji'])->json('data.id');

    $this->actingAs($actor)->patchJson("/api/v1/quotations/{$id}/approve")->assertOk();

    $this->actingAs($actor)->putJson("/api/v1/quotations/{$id}", ['title' => 'Diubah'])
        ->assertForbidden();

    // And its lines are as fixed as its total.
    $this->actingAs($actor)->postJson("/api/v1/quotations/{$id}/items", [
        'description' => 'Tambahan', 'quantity' => 1, 'unit_amount' => 1,
    ])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Invoice lifecycle
|--------------------------------------------------------------------------
*/

it('bills an approved quotation by copying its lines', function (): void {
    // The brief's `convert` verb, delivered as `invoices.create` — the code that
    // actually exists.
    [$actor] = billingActor(billingCapabilities());

    $quotationId = $this->actingAs($actor)->postJson('/api/v1/quotations', ['title' => 'Jasa AJB'])
        ->json('data.id');

    $this->actingAs($actor)->postJson("/api/v1/quotations/{$quotationId}/items", [
        'description' => 'Jasa notaris', 'quantity' => 1, 'unit_amount' => 5000000,
    ])->assertCreated();

    $this->actingAs($actor)->patchJson("/api/v1/quotations/{$quotationId}/approve")->assertOk();

    $response = $this->actingAs($actor)->postJson('/api/v1/invoices', [
        'quotation_id' => $quotationId,
    ])->assertCreated();

    expect($response->json('data.total_amount'))->toBe('5000000.00')
        ->and($response->json('data.items'))->toHaveCount(1)
        ->and($response->json('data.title'))->toBe('Jasa AJB');
});

it('refuses to bill a quotation nobody approved', function (): void {
    [$actor] = billingActor(billingCapabilities());

    $quotationId = $this->actingAs($actor)->postJson('/api/v1/quotations', ['title' => 'Uji'])
        ->json('data.id');

    $this->actingAs($actor)->postJson('/api/v1/invoices', ['quotation_id' => $quotationId])
        ->assertStatus(422);
});

it('refuses to issue an invoice with no lines', function (): void {
    // A bill for nothing is not a bill.
    [$actor] = billingActor(billingCapabilities());

    $id = $this->actingAs($actor)->postJson('/api/v1/invoices', ['title' => 'Kosong'])->json('data.id');

    $this->actingAs($actor)->patchJson("/api/v1/invoices/{$id}/issue")->assertStatus(422);
});

it('freezes an invoice once issued', function (): void {
    [$actor] = billingActor(billingCapabilities());

    $id = $this->actingAs($actor)->postJson('/api/v1/invoices', ['title' => 'Tagihan'])->json('data.id');

    $this->actingAs($actor)->postJson("/api/v1/invoices/{$id}/items", [
        'description' => 'Jasa', 'quantity' => 1, 'unit_amount' => 1000000,
    ])->assertCreated();

    $this->actingAs($actor)->patchJson("/api/v1/invoices/{$id}/issue")
        ->assertOk()
        ->assertJsonPath('data.status', 'ISSUED');

    $this->actingAs($actor)->putJson("/api/v1/invoices/{$id}", ['title' => 'Diubah'])
        ->assertForbidden();

    $this->actingAs($actor)->postJson("/api/v1/invoices/{$id}/items", [
        'description' => 'Tambahan', 'quantity' => 1, 'unit_amount' => 1,
    ])->assertForbidden();
});

it('cancels rather than deletes, before and after issue', function (): void {
    // There is no `invoices.delete` in the catalogue, so a draft raised in error
    // is cancelled — which keeps the number where an audit can find it (O-051).
    [$actor] = billingActor(billingCapabilities());

    $id = $this->actingAs($actor)->postJson('/api/v1/invoices', ['title' => 'Salah'])->json('data.id');

    $this->actingAs($actor)->patchJson("/api/v1/invoices/{$id}/cancel", ['reason' => 'Dibuat keliru'])
        ->assertOk()
        ->assertJsonPath('data.status', 'CANCELLED')
        ->assertJsonPath('data.cancellation_reason', 'Dibuat keliru');

    // Cancelling twice is refused by state.
    $this->actingAs($actor)->patchJson("/api/v1/invoices/{$id}/cancel")->assertStatus(422);
});

it('offers no delete route on any billing document', function (string $path): void {
    [$actor, $office] = billingActor(billingCapabilities());

    $this->actingAs($actor)->deleteJson($path)->assertStatus(405);
})->with([
    '/api/v1/quotations/01JQXY0000000000000000000A',
    '/api/v1/invoices/01JQXY0000000000000000000A',
    '/api/v1/disbursements/01JQXY0000000000000000000A',
]);

/*
|--------------------------------------------------------------------------
| Payments — the verify gate, and O-050
|--------------------------------------------------------------------------
*/

it('counts only verified payments toward what an invoice has been paid', function (): void {
    [$actor, $office] = billingActor(billingCapabilities());

    $invoice = Invoice::factory()->inOffice($office, $actor)->issued($actor)->create([
        'total_amount' => '1000000.00',
    ]);

    $pending = $this->actingAs($actor)->postJson("/api/v1/invoices/{$invoice->getKey()}/payments", [
        'amount' => 400000,
        'method_code' => 'BANK_TRANSFER',
        'paid_at' => now()->toDateString(),
    ])->assertCreated();

    // Recorded, visible, and counting toward nothing.
    $this->actingAs($actor)->getJson("/api/v1/invoices/{$invoice->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.paid_amount', '0.00')
        ->assertJsonPath('data.outstanding_amount', '1000000.00');

    $this->actingAs($actor)->patchJson("/api/v1/payments/{$pending->json('data.id')}/verify")
        ->assertOk()
        ->assertJsonPath('data.status', 'VERIFIED');

    $this->actingAs($actor)->getJson("/api/v1/invoices/{$invoice->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.paid_amount', '400000.00')
        ->assertJsonPath('data.outstanding_amount', '600000.00')
        ->assertJsonPath('data.is_settled', false);
});

it('offers no route that corrects or removes a payment', function (): void {
    // O-050: the catalogue gives payments no update, delete or reject. The gap
    // ships stated rather than closed with an uncatalogued verb.
    [$actor, $office] = billingActor(billingCapabilities());

    $invoice = Invoice::factory()->inOffice($office, $actor)->create();
    $payment = Payment::factory()->forInvoice($invoice, $actor)->create();

    $id = $payment->getKey();

    $this->actingAs($actor)->putJson("/api/v1/payments/{$id}", ['amount' => 1])->assertStatus(405);
    $this->actingAs($actor)->deleteJson("/api/v1/payments/{$id}")->assertStatus(405);
    $this->actingAs($actor)->patchJson("/api/v1/payments/{$id}/reject")->assertNotFound();
});

it('refuses to rewrite a payment through the model', function (): void {
    [$actor, $office] = billingActor();

    $invoice = Invoice::factory()->inOffice($office, $actor)->create();
    $payment = Payment::factory()->forInvoice($invoice, $actor)->create();

    $payment->amount = '999.00';

    expect(fn () => $payment->save())->toThrow(RuntimeException::class, 'immutable');
});

it('refuses a payment against a cancelled invoice', function (): void {
    [$actor, $office] = billingActor(billingCapabilities());

    $invoice = Invoice::factory()->inOffice($office, $actor)->create([
        'status' => InvoiceStatus::CANCELLED,
    ]);

    // **422, not 403.** The actor holds `payments.create` and may reach this
    // invoice; it is the invoice's *state* that refuses, and answering 403 would
    // tell them they lack an authority they have — the M6/M7 convention.
    $this->actingAs($actor)->postJson("/api/v1/invoices/{$invoice->getKey()}/payments", [
        'amount' => 100, 'method_code' => 'CASH', 'paid_at' => now()->toDateString(),
    ])->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Overdue is computed, never stored
|--------------------------------------------------------------------------
*/

it('computes overdue from the due date rather than a status', function (): void {
    [$actor, $office] = billingActor(billingCapabilities());

    $overdue = Invoice::factory()->inOffice($office, $actor)->overdue()->create([
        'total_amount' => '500000.00',
    ]);

    $future = Invoice::factory()->inOffice($office, $actor)->issued($actor)->create([
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    // A draft is never overdue: nobody has been asked to pay it.
    $draft = Invoice::factory()->inOffice($office, $actor)->create([
        'due_date' => now()->subDays(30)->toDateString(),
    ]);

    expect($overdue->isOverdue())->toBeTrue()
        ->and($future->isOverdue())->toBeFalse()
        ->and($draft->isOverdue())->toBeFalse();

    // And the list filters on it without a stored column.
    $response = $this->actingAs($actor)->getJson('/api/v1/invoices?overdue=true')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($overdue->getKey());
});

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

it('refuses every billing surface to an actor holding nothing', function (string $path): void {
    [$actor] = billingActor(['projects.view']);

    $this->actingAs($actor)->getJson($path)->assertForbidden();
})->with(['/api/v1/quotations', '/api/v1/invoices', '/api/v1/payments', '/api/v1/disbursements']);

it('keeps another office billing out of reach', function (): void {
    [$actor] = billingActor(billingCapabilities());

    $elsewhere = Office::factory()->create();
    $stranger = User::factory()->for($elsewhere)->create();
    $invoice = Invoice::factory()->inOffice($elsewhere, $stranger)->create();

    // Unreachable is 404, indistinguishable from nonexistent (D-098).
    $this->actingAs($actor)->getJson("/api/v1/invoices/{$invoice->getKey()}")->assertNotFound();

    $this->actingAs($actor)->getJson('/api/v1/invoices')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('separates each act from the others', function (): void {
    // D-091: holding `invoices.update` never confers `issue` or `cancel`.
    [$actor, $office] = billingActor(['invoices.view', 'invoices.update']);

    $invoice = Invoice::factory()->inOffice($office, $actor)->create();

    $this->actingAs($actor)->patchJson("/api/v1/invoices/{$invoice->getKey()}/issue")
        ->assertForbidden();

    $this->actingAs($actor)->patchJson("/api/v1/invoices/{$invoice->getKey()}/cancel")
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| billing.amount.view — the masking gate (D-125)
|--------------------------------------------------------------------------
*/

it('withholds every monetary figure from an actor without billing.amount.view', function (): void {
    // **Absent, not null and not a placeholder.** Sending a value the client is
    // expected to hide is a disclosure with a request attached.
    [$actor, $office] = billingActor([
        'invoices.view', 'quotations.view', 'payments.view', 'disbursements.view',
    ]);

    $invoice = Invoice::factory()->inOffice($office, $actor)->issued($actor)->create([
        'total_amount' => '7500000.00',
        'due_date' => now()->subDay()->toDateString(),
    ]);

    $response = $this->actingAs($actor)->getJson("/api/v1/invoices/{$invoice->getKey()}")->assertOk();

    $data = $response->json('data');

    expect($data)->not->toHaveKey('total_amount')
        ->and($data)->not->toHaveKey('subtotal_amount')
        ->and($data)->not->toHaveKey('paid_amount')
        ->and($data)->not->toHaveKey('outstanding_amount')
        ->and($data['amounts_visible'])->toBeFalse()
        // The figure must not appear anywhere in the payload, under any key.
        ->and(json_encode($data))->not->toContain('7500000');

    // What survives masking: the record exists, and it is late.
    expect($data['invoice_number'])->not->toBeEmpty()
        ->and($data['status'])->toBe('ISSUED')
        ->and($data['is_overdue'])->toBeTrue();
});

it('discloses the figures once billing.amount.view is held', function (): void {
    [$actor, $office] = billingActor(['invoices.view', 'billing.amount.view']);

    $invoice = Invoice::factory()->inOffice($office, $actor)->create([
        'total_amount' => '7500000.00',
    ]);

    $this->actingAs($actor)->getJson("/api/v1/invoices/{$invoice->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.amounts_visible', true)
        ->assertJsonPath('data.total_amount', '7500000.00');
});

it('withholds line quantities along with the money', function (): void {
    // Quantity plus a known rate reconstructs the amount, so it is masked too.
    // The description is not: what is being charged for is often the useful half.
    [$actor, $office] = billingActor(['invoices.view']);

    $invoice = Invoice::factory()->inOffice($office, $actor)->create();

    // Built explicitly rather than mass-assigned: `office_id` is the constraint
    // carrier and `line_amount` is computed by the Action, so neither is
    // fillable on the model.
    $line = new InvoiceItem;
    $line->office_id = $office->getKey();
    $line->invoice_id = $invoice->getKey();
    $line->line_number = 1;
    $line->description = 'Jasa notaris';
    $line->quantity = '3.00';
    $line->unit_amount = '1000000.00';
    $line->line_amount = '3000000.00';
    $line->save();

    $line = $this->actingAs($actor)->getJson("/api/v1/invoices/{$invoice->getKey()}")
        ->assertOk()
        ->json('data.items.0');

    expect($line['description'])->toBe('Jasa notaris')
        ->and($line)->not->toHaveKey('quantity')
        ->and($line)->not->toHaveKey('unit_amount')
        ->and($line)->not->toHaveKey('line_amount');
});

it('keeps amounts out of the activity feed entirely', function (): void {
    // The feed consults no billing capability at all, so an amount in a timeline
    // entry would disclose exactly what D-125 withholds.
    [$actor] = billingActor(billingCapabilities());

    $this->actingAs($actor)->postJson('/api/v1/quotations', ['title' => 'Uji'])->assertCreated();

    $activity = Activity::query()->sole();

    expect($activity->metadata)->not->toHaveKey('total_amount')
        ->and($activity->metadata)->not->toHaveKey('amount')
        ->and($activity->metadata)->toHaveKey('reference');
});

/*
|--------------------------------------------------------------------------
| Audit
|--------------------------------------------------------------------------
*/

it('audits every billing act', function (): void {
    [$actor, $office] = billingActor(billingCapabilities());

    $id = $this->actingAs($actor)->postJson('/api/v1/quotations', ['title' => 'Uji'])->json('data.id');

    $this->actingAs($actor)->patchJson("/api/v1/quotations/{$id}/approve")->assertOk();

    $events = AuditLog::query()
        ->where('auditable_type', Quotation::class)
        ->pluck('event')
        ->map(fn ($event) => $event instanceof AuditEvent ? $event->value : $event)
        ->all();

    expect($events)->toContain(AuditEvent::CREATED->value)
        ->and($events)->toContain(AuditEvent::STATUS_CHANGED->value);
});

/*
|--------------------------------------------------------------------------
| Surface boundary
|--------------------------------------------------------------------------
*/

it('exposes exactly the billing routes the catalogue authorizes', function (): void {
    $routes = collect(Route::getRoutes())
        ->map(fn ($route): string => strtoupper(implode('|', array_diff($route->methods(), ['HEAD']))).' '.$route->uri())
        ->filter(fn (string $route): bool => (bool) preg_match(
            '#api/v1/(quotations|invoices|payments|disbursements)#',
            $route,
        ))
        ->sort()
        ->values()
        ->all();

    // Nine acts the M8.2 brief asked for are absent, because their codes are:
    // quotations.send/.reject/.convert/.delete, invoices.send/.delete,
    // payments.reject, disbursements.delete.
    expect($routes)->not->toContain('DELETE api/v1/quotations/{quotation}')
        ->and($routes)->not->toContain('DELETE api/v1/invoices/{invoice}')
        ->and($routes)->not->toContain('DELETE api/v1/disbursements/{disbursement}')
        ->and($routes)->not->toContain('PATCH api/v1/quotations/{quotation}/send')
        ->and($routes)->not->toContain('PATCH api/v1/quotations/{quotation}/reject')
        ->and($routes)->not->toContain('PATCH api/v1/quotations/{quotation}/convert')
        ->and($routes)->not->toContain('PATCH api/v1/invoices/{invoice}/send')
        ->and($routes)->not->toContain('PATCH api/v1/payments/{payment}/reject');

    expect($routes)->toHaveCount(26);
});
