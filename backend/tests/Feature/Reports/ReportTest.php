<?php

use App\Domains\Audit\Enums\AuditEvent;
use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Matter\Enums\MatterDomain;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Office;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Reports (M8.3, D-126).
 *
 * The rule under test throughout: **opening a family is not reading its rows.**
 * `ReportPolicy` answers the first; every row is narrowed by its own source
 * domain's capability and Data Scope.
 */
function reportActor(array $permissions = [], DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

function reportCapabilities(): array
{
    return [
        'reports.operational.view', 'reports.notary.view', 'reports.ppat.view',
        'reports.financial.view', 'reports.audit.view', 'reports.export',
    ];
}

/**
 * Read a streamed CSV response into lines.
 *
 * @return array<int, string>
 */
function csvLines(TestResponse $response): array
{
    ob_start();
    $response->baseResponse->sendContent();
    $body = (string) ob_get_clean();

    // Strip the UTF-8 BOM Excel needs before parsing.
    return array_values(array_filter(explode("\n", str_replace("\u{FEFF}", '', $body)), fn ($l) => trim($l) !== ''));
}

/*
|--------------------------------------------------------------------------
| Nothing new was registered
|--------------------------------------------------------------------------
*/

it('registers no new permission', function (): void {
    expect(PermissionRegistry::all())->toHaveCount(177);
});

it('builds no table', function (): void {
    // Reports are queries. No capability in `reports.*` authorizes creating a
    // stored report, so there is nothing to store one in.
    expect(Schema::hasTable('reports'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| ppat.reports.* is untouched — the milestone's sharpest refusal
|--------------------------------------------------------------------------
*/

it('reaches no ppat.reports.* capability from any route', function (): void {
    // `reports.ppat.view` and `ppat.reports.view` differ only in word order.
    // The second belongs to a generate/review/approve workflow that is the PPAT
    // monthly reporting obligation — deadline, recipient and format unauthored
    // (O-043). M8.3 builds no endpoint for any of those five.
    $uris = collect(Route::getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_contains($uri, 'report'))
        ->values();

    expect($uris)->not->toContain('api/v1/ppat/reports')
        ->and($uris->filter(fn (string $u) => str_contains($u, 'generate')))->toBeEmpty()
        ->and($uris->filter(fn (string $u) => str_contains($u, 'monthly')))->toBeEmpty()
        ->and($uris->filter(fn (string $u) => str_contains($u, 'repertorium')))->toBeEmpty()
        ->and($uris->filter(fn (string $u) => str_contains($u, 'register')))->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Each family answers to its own code
|--------------------------------------------------------------------------
*/

it('refuses every report family to an actor holding none of them', function (string $path): void {
    [$actor] = reportActor(['projects.view']);

    $this->actingAs($actor)->getJson($path)->assertForbidden();
})->with([
    '/api/v1/reports/operational/matters',
    '/api/v1/reports/operational/tasks',
    '/api/v1/reports/operational/documents',
    '/api/v1/reports/notary/deeds',
    '/api/v1/reports/notary/summary',
    '/api/v1/reports/ppat/deeds',
    '/api/v1/reports/ppat/properties',
    '/api/v1/reports/ppat/warkah',
    '/api/v1/reports/ppat/summary',
    '/api/v1/reports/financial/invoices',
    '/api/v1/reports/financial/payments',
    '/api/v1/reports/financial/revenue',
    '/api/v1/reports/audit/activity',
]);

it('does not let one family open another', function (): void {
    // D-091: every act its own capability, and none implies another.
    [$actor] = reportActor(['reports.operational.view']);

    $this->actingAs($actor)->getJson('/api/v1/reports/operational/matters')->assertOk();
    $this->actingAs($actor)->getJson('/api/v1/reports/financial/invoices')->assertForbidden();
    $this->actingAs($actor)->getJson('/api/v1/reports/audit/activity')->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Opening a family is not reading its rows
|--------------------------------------------------------------------------
*/

it('serves an empty matter report to an actor who may not read matters', function (): void {
    // The lock's ruling: a report is a list with arithmetic on it, and the
    // arithmetic does not widen the list. Holding only the report code opens a
    // page that is correctly empty.
    [$actor, $office] = reportActor(['reports.operational.view']);

    $project = Project::factory()->create(['office_id' => $office->getKey()]);

    Matter::factory()->count(3)->create([
        'office_id' => $office->getKey(),
        'project_id' => $project->getKey(),
        'domain' => MatterDomain::NOTARY,
    ]);

    $this->actingAs($actor)->getJson('/api/v1/reports/operational/matters')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);
});

it('reports only the matter domain the actor may read', function (): void {
    // `notary.matters.view` and `ppat.matters.view` are separate grants (D-101).
    [$actor, $office] = reportActor(['reports.operational.view', 'notary.matters.view']);

    $project = Project::factory()->create(['office_id' => $office->getKey()]);

    Matter::factory()->count(2)->create([
        'office_id' => $office->getKey(),
        'project_id' => $project->getKey(),
        'domain' => MatterDomain::NOTARY,
    ]);

    Matter::factory()->count(5)->create([
        'office_id' => $office->getKey(),
        'project_id' => $project->getKey(),
        'domain' => MatterDomain::PPAT,
    ]);

    $this->actingAs($actor)->getJson('/api/v1/reports/operational/matters')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

it('keeps another office out of every report', function (): void {
    [$actor] = reportActor([...reportCapabilities(), 'notary.matters.view', 'invoices.view']);

    $elsewhere = Office::factory()->create();
    $stranger = User::factory()->for($elsewhere)->create();
    $project = Project::factory()->create(['office_id' => $elsewhere->getKey()]);

    Matter::factory()->create([
        'office_id' => $elsewhere->getKey(),
        'project_id' => $project->getKey(),
        'domain' => MatterDomain::NOTARY,
    ]);

    Invoice::factory()->inOffice($elsewhere, $stranger)->create();

    $this->actingAs($actor)->getJson('/api/v1/reports/operational/matters')
        ->assertOk()->assertJsonPath('meta.total', 0);

    $this->actingAs($actor)->getJson('/api/v1/reports/financial/invoices')
        ->assertOk()->assertJsonPath('meta.total', 0);
});

/*
|--------------------------------------------------------------------------
| reports.export is a second gate
|--------------------------------------------------------------------------
*/

it('refuses an export to an actor who may read the report', function (): void {
    // An actor may hold `reports.financial.view` and not `reports.export`: the
    // page renders and the download does not (the lock's §10, D-091).
    [$actor] = reportActor(['reports.financial.view', 'invoices.view', 'billing.amount.view']);

    $this->actingAs($actor)->getJson('/api/v1/reports/financial/invoices')->assertOk();
    $this->actingAs($actor)->get('/api/v1/reports/financial/invoices/export')->assertForbidden();
});

it('refuses an export of a family the actor cannot open, before mentioning export', function (): void {
    [$actor] = reportActor(['reports.export']);

    $this->actingAs($actor)->get('/api/v1/reports/financial/invoices/export')->assertForbidden();
});

it('exports the same rows the report showed', function (): void {
    [$actor, $office] = reportActor([
        'reports.operational.view', 'reports.export', 'notary.matters.view',
    ]);

    $project = Project::factory()->create(['office_id' => $office->getKey()]);

    Matter::factory()->count(2)->create([
        'office_id' => $office->getKey(),
        'project_id' => $project->getKey(),
        'domain' => MatterDomain::NOTARY,
    ]);

    // Another Office's matter must not appear in the file either — the export
    // re-uses the scoped query rather than rebuilding one.
    $elsewhere = Office::factory()->create();
    $otherProject = Project::factory()->create(['office_id' => $elsewhere->getKey()]);
    Matter::factory()->create([
        'office_id' => $elsewhere->getKey(),
        'project_id' => $otherProject->getKey(),
        'domain' => MatterDomain::NOTARY,
    ]);

    $response = $this->actingAs($actor)->get('/api/v1/reports/operational/matters/export');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $lines = csvLines($response);

    // One header plus two rows.
    expect($lines)->toHaveCount(3)
        ->and($lines[0])->toContain('matter_number');
});

/*
|--------------------------------------------------------------------------
| Financial masking — the gate that matters most here
|--------------------------------------------------------------------------
*/

it('withholds every amount from a financial report without billing.amount.view', function (): void {
    [$actor, $office] = reportActor(['reports.financial.view', 'invoices.view']);

    Invoice::factory()->inOffice($office, $actor)->issued($actor)->create([
        'total_amount' => '7500000.00',
    ]);

    $response = $this->actingAs($actor)->getJson('/api/v1/reports/financial/invoices')->assertOk();

    $row = $response->json('data.0');

    expect($row)->not->toHaveKey('total_amount')
        ->and($row)->not->toHaveKey('paid_amount')
        ->and($row)->not->toHaveKey('outstanding_amount')
        ->and($response->json('meta.amounts_visible'))->toBeFalse()
        // Not anywhere in the payload, under any key.
        ->and(json_encode($response->json()))->not->toContain('7500000');

    // What survives: the record exists, and its state.
    expect($row['invoice_number'])->not->toBeEmpty()
        ->and($row['status'])->toBe('ISSUED');
});

it('omits the masked columns from the CSV header, rather than blanking them', function (): void {
    // A column of empty cells invites the reader to conclude the office was paid
    // nothing. The column is absent instead.
    [$actor, $office] = reportActor(['reports.financial.view', 'reports.export', 'invoices.view']);

    Invoice::factory()->inOffice($office, $actor)->issued($actor)->create([
        'total_amount' => '7500000.00',
    ]);

    $lines = csvLines($this->actingAs($actor)->get('/api/v1/reports/financial/invoices/export')->assertOk());

    expect($lines[0])->toContain('invoice_number')
        ->and($lines[0])->not->toContain('total_amount')
        ->and($lines[0])->not->toContain('outstanding_amount')
        ->and(implode("\n", $lines))->not->toContain('7500000');
});

it('discloses the figures once billing.amount.view is held', function (): void {
    [$actor, $office] = reportActor([
        'reports.financial.view', 'reports.export', 'invoices.view', 'billing.amount.view',
    ]);

    Invoice::factory()->inOffice($office, $actor)->issued($actor)->create([
        'total_amount' => '7500000.00',
    ]);

    $this->actingAs($actor)->getJson('/api/v1/reports/financial/invoices')
        ->assertOk()
        ->assertJsonPath('meta.amounts_visible', true)
        ->assertJsonPath('data.0.total_amount', '7500000.00');

    $lines = csvLines($this->actingAs($actor)->get('/api/v1/reports/financial/invoices/export')->assertOk());

    expect($lines[0])->toContain('total_amount')
        ->and(implode("\n", $lines))->toContain('7500000.00');
});

it('returns no revenue report at all without billing.amount.view', function (): void {
    // Every cell of this report is a sum; there is no non-monetary half to serve,
    // and a revenue report of row counts would be a different report pretending
    // to be this one.
    [$actor] = reportActor(['reports.financial.view', 'payments.view']);

    $this->actingAs($actor)->getJson('/api/v1/reports/financial/revenue')
        ->assertOk()
        ->assertJsonPath('data', null)
        ->assertJsonPath('meta.amounts_visible', false);
});

it('sums only verified payments into revenue', function (): void {
    // The same rule an invoice's paid total follows: a recorded-but-unverified
    // payment moves no figure anywhere, including here (O-050).
    [$actor, $office] = reportActor([
        'reports.financial.view', 'payments.view', 'billing.amount.view',
    ]);

    $invoice = Invoice::factory()->inOffice($office, $actor)->issued($actor)->create();

    Payment::factory()->forInvoice($invoice, $actor)->verified($actor)->create(['amount' => '1000000.00']);
    Payment::factory()->forInvoice($invoice, $actor)->create(['amount' => '9000000.00']);

    $response = $this->actingAs($actor)->getJson('/api/v1/reports/financial/revenue')->assertOk();

    $rows = $response->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['total_amount'])->toBe('1000000.00')
        ->and($rows[0]['payment_count'])->toBe(1);
});

it('reports a service type by code and both names, never one language', function (): void {
    [$actor, $office] = reportActor([
        'reports.financial.view', 'payments.view', 'billing.amount.view',
    ]);

    $invoice = Invoice::factory()->inOffice($office, $actor)->issued($actor)->create();
    Payment::factory()->forInvoice($invoice, $actor)->verified($actor)->create();

    $row = $this->actingAs($actor)->getJson('/api/v1/reports/financial/revenue')->json('data.0');

    // Present as keys even when null: the shape does not change per row, and
    // choosing a language in SQL would put presentation in an aggregate.
    expect($row)->toHaveKeys([
        'service_type_code', 'service_type_name_id', 'service_type_name_en',
    ]);
});

/*
|--------------------------------------------------------------------------
| Audit report
|--------------------------------------------------------------------------
*/

it('filters the audit report to one record when both keys are given', function (): void {
    // "The trail for this record" is a filter, not a second route (D-118).
    [$actor, $office] = reportActor(['reports.audit.view']);

    $project = Project::factory()->create(['office_id' => $office->getKey()]);
    $other = Project::factory()->create(['office_id' => $office->getKey()]);

    app(AuditLogger::class)->created($project, $actor);
    app(AuditLogger::class)->created($other, $actor);

    $this->actingAs($actor)->getJson('/api/v1/reports/audit/activity')
        ->assertOk()->assertJsonPath('meta.total', 2);

    $this->actingAs($actor)->getJson(
        '/api/v1/reports/audit/activity?auditable_type='.urlencode(Project::class).'&auditable_id='.$project->getKey()
    )->assertOk()->assertJsonPath('meta.total', 1);
});

it('ignores an id without a type, rather than scanning the table', function (): void {
    [$actor, $office] = reportActor(['reports.audit.view']);

    $project = Project::factory()->create(['office_id' => $office->getKey()]);
    app(AuditLogger::class)->created($project, $actor);

    // Both or neither. An id alone matches across unrelated domains.
    $this->actingAs($actor)->getJson('/api/v1/reports/audit/activity?auditable_id=whatever')
        ->assertOk()->assertJsonPath('meta.total', 1);
});

it('reports the short class name, not the namespace', function (): void {
    [$actor, $office] = reportActor(['reports.audit.view']);

    $project = Project::factory()->create(['office_id' => $office->getKey()]);
    app(AuditLogger::class)->created($project, $actor);

    $this->actingAs($actor)->getJson('/api/v1/reports/audit/activity')
        ->assertOk()
        ->assertJsonPath('data.0.auditable_type', 'Project')
        ->assertJsonPath('data.0.event', AuditEvent::CREATED->value);
});

it('confines the audit report to the actor own office below ALL scope', function (): void {
    [$actor, $office] = reportActor(['reports.audit.view']);

    $elsewhere = Office::factory()->create();
    $stranger = User::factory()->for($elsewhere)->create();

    app(AuditLogger::class)->log(AuditEvent::LOGIN, $actor, $actor);
    app(AuditLogger::class)->log(AuditEvent::LOGIN, $stranger, $stranger);

    $this->actingAs($actor)->getJson('/api/v1/reports/audit/activity')
        ->assertOk()->assertJsonPath('meta.total', 1);
});

/*
|--------------------------------------------------------------------------
| Summaries
|--------------------------------------------------------------------------
*/

it('reports every deed status including the ones at zero', function (): void {
    // A histogram with holes in it reads as a bug rather than an empty bucket.
    [$actor] = reportActor(['reports.notary.view', 'notary.deeds.view']);

    $summary = $this->actingAs($actor)->getJson('/api/v1/reports/notary/summary')
        ->assertOk()->json('data');

    expect($summary['by_status'])->toHaveKeys(['DRAFT', 'UNDER_REVIEW', 'APPROVED', 'FINALIZED'])
        ->and($summary['by_status']['DRAFT'])->toBe(0)
        ->and($summary['total'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Pagination — reports are not unbounded
|--------------------------------------------------------------------------
*/

it('paginates rather than returning everything', function (): void {
    // CLAUDE.md §43: do not load unbounded database records into the frontend.
    // The M8.3 brief's sketch called `->get()`; export is the answer for "all of
    // it", and it streams.
    [$actor, $office] = reportActor(['reports.operational.view', 'notary.matters.view']);

    $project = Project::factory()->create(['office_id' => $office->getKey()]);

    Matter::factory()->count(7)->create([
        'office_id' => $office->getKey(),
        'project_id' => $project->getKey(),
        'domain' => MatterDomain::NOTARY,
    ]);

    $this->actingAs($actor)->getJson('/api/v1/reports/operational/matters?per_page=3')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.total', 7)
        ->assertJsonPath('meta.last_page', 3);
});

/*
|--------------------------------------------------------------------------
| Sensitive documents
|--------------------------------------------------------------------------
*/

it('excludes sensitive documents from the document report', function (): void {
    // A report is a reading surface like any other, and `documents.sensitive.view`
    // applies to it identically (D-115). A count that quietly included KTP scans
    // would disclose that they exist.
    [$actor, $office] = reportActor(['reports.operational.view', 'documents.view']);

    Document::factory()->inOffice($office)->create();
    Document::factory()->inOffice($office)->sensitive()->create();

    $this->actingAs($actor)->getJson('/api/v1/reports/operational/documents')
        ->assertOk()->assertJsonPath('meta.total', 1);
});

it('includes sensitive documents for an actor who may reach them', function (): void {
    [$actor, $office] = reportActor([
        'reports.operational.view', 'documents.view', 'documents.sensitive.view',
    ]);

    Document::factory()->inOffice($office)->create();
    Document::factory()->inOffice($office)->sensitive()->create();

    $this->actingAs($actor)->getJson('/api/v1/reports/operational/documents')
        ->assertOk()->assertJsonPath('meta.total', 2);
});
