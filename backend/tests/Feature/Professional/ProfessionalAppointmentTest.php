<?php

use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Office\Enums\OfficePracticeType;
use App\Domains\Party\Enums\PartyType;
use App\Domains\Professional\Enums\ProfessionalRelationshipType;
use App\Domains\Professional\Enums\ProfessionType;
use App\Models\Individual;
use App\Models\Matter;
use App\Models\MatterProfessional;
use App\Models\Office;
use App\Models\Party;
use App\Models\ProfessionalAppointment;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function professionalParty(Office $office, string $name): Party
{
    return Individual::factory()
        ->for(Party::factory()->individual()->for($office)->state(['display_name' => $name]), 'party')
        ->create(['full_name' => $name])->party;
}

function appointment(Office $office, Party $party, ProfessionType $profession, ProfessionalRelationshipType $relationship): ProfessionalAppointment
{
    return ProfessionalAppointment::factory()->create([
        'office_id' => $office->id,
        'party_id' => $party->id,
        'party_type' => PartyType::INDIVIDUAL->value,
        'profession_type' => $profession->value,
        'relationship_type' => $relationship->value,
    ]);
}

it('stores the office practice identity separately from its address', function (): void {
    $office = Office::factory()->create([
        'practice_type' => OfficePracticeType::PPAT->value,
        'city' => 'Kabupaten Sambas',
        'province' => 'Kalimantan Barat',
        'jurisdiction' => 'Kabupaten Sambas, Kalimantan Barat',
    ]);

    expect($office->practice_type)->toBe(OfficePracticeType::PPAT)
        ->and($office->jurisdiction)->toBe('Kabupaten Sambas, Kalimantan Barat');
});

it('represents internal PPAT and external Notary appointments through Individual parties', function (): void {
    $office = Office::factory()->create();
    $mila = professionalParty($office, 'Mila Widyahastuti');
    $partner = professionalParty($office, 'Notaris Rekanan');

    $internal = appointment($office, $mila, ProfessionType::PPAT, ProfessionalRelationshipType::INTERNAL);
    $external = appointment($office, $partner, ProfessionType::NOTARY, ProfessionalRelationshipType::EXTERNAL);

    expect($internal->party->is($mila))->toBeTrue()
        ->and($external->relationship_type)->toBe(ProfessionalRelationshipType::EXTERNAL)
        ->and($office->professionalAppointments()->count())->toBe(2);
});

it('does not permit a Company party to hold a professional appointment', function (): void {
    $office = Office::factory()->create();
    $company = Party::factory()->company()->for($office)->create();

    expect(fn () => ProfessionalAppointment::factory()->create([
        'office_id' => $office->id,
        'party_id' => $company->id,
        'party_type' => PartyType::INDIVIDUAL->value,
    ]))->toThrow(QueryException::class);
});

it('links a matter to its executing professional within the same office', function (): void {
    $office = Office::factory()->create();
    $party = professionalParty($office, 'Mila Widyahastuti');
    $professional = appointment($office, $party, ProfessionType::PPAT, ProfessionalRelationshipType::INTERNAL);
    $matter = Matter::factory()
        ->for($office)
        ->for(Project::factory()->for($office))
        ->state(['office_id' => $office->id, 'domain' => MatterDomain::PPAT->value])
        ->create();

    MatterProfessional::factory()->create([
        'office_id' => $office->id,
        'matter_id' => $matter->id,
        'professional_appointment_id' => $professional->id,
    ]);

    expect($matter->matterProfessionals()->first()->professionalAppointment->party->is($party))->toBeTrue();
});

it('makes cross-office professional assignment unrepresentable', function (): void {
    $office = Office::factory()->create();
    $other = Office::factory()->create();
    $professional = appointment(
        $other,
        professionalParty($other, 'Notaris Rekanan'),
        ProfessionType::NOTARY,
        ProfessionalRelationshipType::EXTERNAL,
    );
    $matter = Matter::factory()
        ->for($office)
        ->for(Project::factory()->for($office))
        ->state(['office_id' => $office->id])
        ->create();

    expect(fn () => MatterProfessional::factory()->create([
        'office_id' => $office->id,
        'matter_id' => $matter->id,
        'professional_appointment_id' => $professional->id,
    ]))->toThrow(QueryException::class);
});

it('keeps a professional appointment identity immutable', function (string $attribute, mixed $value): void {
    $office = Office::factory()->create();
    $professional = appointment(
        $office,
        professionalParty($office, 'Mila Widyahastuti'),
        ProfessionType::PPAT,
        ProfessionalRelationshipType::INTERNAL,
    );

    $professional->{$attribute} = $value;

    expect(fn () => $professional->save())->toThrow(RuntimeException::class, 'immutable');
})->with([
    'office' => ['office_id', '01k00000000000000000000000'],
    'party' => ['party_id', '01k00000000000000000000000'],
    'party type' => ['party_type', PartyType::COMPANY->value],
    'profession' => ['profession_type', ProfessionType::NOTARY->value],
    'relationship' => ['relationship_type', ProfessionalRelationshipType::EXTERNAL->value],
]);

it('keeps a matter professional boundary and provenance immutable', function (string $attribute): void {
    $office = Office::factory()->create();
    $matter = Matter::factory()
        ->for($office)
        ->for(Project::factory()->for($office))
        ->state(['office_id' => $office->id])
        ->create();
    $professional = appointment(
        $office,
        professionalParty($office, 'Mila Widyahastuti'),
        ProfessionType::PPAT,
        ProfessionalRelationshipType::INTERNAL,
    );
    $assignment = MatterProfessional::factory()->create([
        'office_id' => $office->id,
        'matter_id' => $matter->id,
        'professional_appointment_id' => $professional->id,
    ]);

    $assignment->{$attribute} = '01k00000000000000000000000';

    expect(fn () => $assignment->save())->toThrow(RuntimeException::class, 'immutable');
})->with(['office_id', 'matter_id', 'professional_appointment_id', 'created_by']);

it('creates only the professional foundation and no duplicate client table', function (): void {
    expect(Schema::hasTable('professional_appointments'))->toBeTrue()
        ->and(Schema::hasTable('matter_professionals'))->toBeTrue()
        ->and(Schema::hasTable('clients'))->toBeFalse();
});
