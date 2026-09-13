<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Office\Enums\OfficePracticeType;
use App\Models\Individual;
use App\Models\Office;
use App\Models\Party;
use App\Models\ProfessionalAppointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function officePracticeActor(array $permissions): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, DataScope::OFFICE);
    }

    return [$actor->fresh(), $office];
}

function officePracticeIndividual(Office $office, array $attributes = []): Individual
{
    $individual = Individual::factory()
        ->for(Party::factory()->individual()->for($office), 'party')
        ->create($attributes);

    $individual->party->forceFill([
        'display_name' => trim((string) $individual->full_name),
    ])->save();

    return $individual->fresh(['party']);
}

it('requires authentication for the office practice surface', function (): void {
    $this->getJson('/api/v1/office-practice')->assertUnauthorized();
});

it('shows the current office and professional history with offices.view', function (): void {
    [$actor, $office] = officePracticeActor(['offices.view']);
    $person = officePracticeIndividual($office, ['full_name' => 'Mila Widyahastuti']);
    $person->party->forceFill(['display_name' => 'Mila Widyahastuti'])->save();

    ProfessionalAppointment::factory()->create([
        'office_id' => $office->id,
        'party_id' => $person->party_id,
        'profession_type' => 'PPAT',
        'relationship_type' => 'INTERNAL',
    ]);

    $this->actingAs($actor)->getJson('/api/v1/office-practice')
        ->assertOk()
        ->assertJsonPath('data.id', $office->id)
        ->assertJsonPath('data.professionals.0.individual.display_name', 'Mila Widyahastuti')
        ->assertJsonPath('data.professionals.0.profession_type', 'PPAT')
        ->assertJsonPath('data.can_update', false);
});

it('does not let settings.view substitute for offices.view', function (): void {
    [$actor] = officePracticeActor(['settings.view']);

    $this->actingAs($actor)->getJson('/api/v1/office-practice')->assertForbidden();
});

it('updates only the verified practice identity with offices.update', function (): void {
    [$actor, $office] = officePracticeActor(['offices.update']);

    $this->actingAs($actor)->patchJson('/api/v1/office-practice', [
        'practice_type' => 'PPAT',
        'jurisdiction' => 'Kabupaten Sambas, Kalimantan Barat',
    ])->assertOk()
        ->assertJsonPath('data.practice_type', 'PPAT')
        ->assertJsonPath('data.jurisdiction', 'Kabupaten Sambas, Kalimantan Barat')
        ->assertJsonPath('data.can_update', true);

    expect($office->fresh()->practice_type)->toBe(OfficePracticeType::PPAT);
});

it('does not let offices.view update the practice identity', function (): void {
    [$actor] = officePracticeActor(['offices.view']);

    $this->actingAs($actor)->patchJson('/api/v1/office-practice', [
        'practice_type' => 'PPAT', 'jurisdiction' => 'Kabupaten Sambas',
    ])->assertForbidden();
});

it('rejects an invalid practice type and unrelated office fields', function (): void {
    [$actor] = officePracticeActor(['offices.update']);

    $this->actingAs($actor)->patchJson('/api/v1/office-practice', [
        'practice_type' => 'NOTARY_BEFORE_APPOINTMENT',
        'jurisdiction' => 'Kabupaten Sambas',
        'name' => 'Changed through the wrong surface',
    ])->assertUnprocessable()->assertJsonValidationErrors(['practice_type', 'name']);
});

it('offers only active Individuals in the current office as candidates', function (): void {
    [$actor, $office] = officePracticeActor(['offices.update']);
    $candidate = officePracticeIndividual($office, ['full_name' => 'Mila Widyahastuti']);
    $candidate->party->forceFill(['display_name' => 'Mila Widyahastuti'])->save();
    $archived = officePracticeIndividual($office);
    $archived->party->delete();
    officePracticeIndividual(Office::factory()->create());

    $response = $this->actingAs($actor)->getJson('/api/v1/office-practice/options')->assertOk();

    expect($response->json('data.individuals'))->toBe([
        ['id' => $candidate->party_id, 'display_name' => 'Mila Widyahastuti'],
    ])->and($response->json('data.profession_types'))->toBe(['NOTARY', 'PPAT'])
        ->and($response->json('data.relationship_types'))->toBe(['INTERNAL', 'EXTERNAL']);
});

it('records an internal PPAT appointment without granting an account', function (): void {
    [$actor, $office] = officePracticeActor(['offices.update']);
    $candidate = officePracticeIndividual($office, ['full_name' => 'Mila Widyahastuti']);

    $this->actingAs($actor)->postJson('/api/v1/office-practice/professionals', [
        'individual_id' => $candidate->party_id,
        'profession_type' => 'PPAT',
        'relationship_type' => 'INTERNAL',
        'jurisdiction' => 'Kabupaten Sambas, Kalimantan Barat',
    ])->assertCreated()
        ->assertJsonPath('data.individual.id', $candidate->party_id)
        ->assertJsonPath('data.is_active', true);

    expect(ProfessionalAppointment::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe(1);
});

it('refuses a professional from another office without disclosing them', function (): void {
    [$actor] = officePracticeActor(['offices.update']);
    $stranger = officePracticeIndividual(Office::factory()->create());

    $this->actingAs($actor)->postJson('/api/v1/office-practice/professionals', [
        'individual_id' => $stranger->party_id,
        'profession_type' => 'NOTARY',
        'relationship_type' => 'EXTERNAL',
    ])->assertUnprocessable();

    expect(ProfessionalAppointment::query()->count())->toBe(0);
});

it('refuses a duplicate active appointment', function (): void {
    [$actor, $office] = officePracticeActor(['offices.update']);
    $candidate = officePracticeIndividual($office);
    $payload = [
        'individual_id' => $candidate->party_id,
        'profession_type' => 'PPAT',
        'relationship_type' => 'INTERNAL',
    ];

    $this->actingAs($actor)->postJson('/api/v1/office-practice/professionals', $payload)->assertCreated();
    $this->actingAs($actor)->postJson('/api/v1/office-practice/professionals', $payload)->assertConflict();
});

it('ends rather than deletes a professional appointment', function (): void {
    [$actor, $office] = officePracticeActor(['offices.update']);
    $candidate = officePracticeIndividual($office);
    $appointment = ProfessionalAppointment::factory()->create([
        'office_id' => $office->id,
        'party_id' => $candidate->party_id,
    ]);

    $this->actingAs($actor)->postJson("/api/v1/office-practice/professionals/{$appointment->id}/end", [
        'ended_at' => '2026-09-13',
    ])->assertOk()
        ->assertJsonPath('data.ended_at', '2026-09-13')
        ->assertJsonPath('data.is_active', false);

    expect($appointment->fresh())->not->toBeNull()
        ->and($appointment->fresh()->is_active)->toBeFalse();

    $this->actingAs($actor)->postJson("/api/v1/office-practice/professionals/{$appointment->id}/end", [
        'ended_at' => '2026-09-14',
    ])->assertConflict();
});

it('does not reveal whether an appointment id belongs to another office', function (): void {
    [$actor] = officePracticeActor(['offices.update']);
    $otherOffice = Office::factory()->create();
    $appointment = ProfessionalAppointment::factory()->create([
        'office_id' => $otherOffice->id,
        'party_id' => officePracticeIndividual($otherOffice)->party_id,
    ]);

    $this->actingAs($actor)->postJson("/api/v1/office-practice/professionals/{$appointment->id}/end", [
        'ended_at' => '2026-09-13',
    ])->assertNotFound();
});

it('rejects malformed professional input', function (): void {
    [$actor] = officePracticeActor(['offices.update']);

    $this->actingAs($actor)->postJson('/api/v1/office-practice/professionals', [
        'individual_id' => (string) Str::ulid(),
        'profession_type' => 'LAWYER',
        'relationship_type' => 'PARTNER',
        'ended_at' => '2026-09-13',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['profession_type', 'relationship_type', 'ended_at']);
});
