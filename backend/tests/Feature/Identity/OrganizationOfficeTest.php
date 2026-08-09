<?php

use App\Models\Office;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('gives an organization a generated ULID primary key', function (): void {
    $organization = Organization::factory()->create();

    expect($organization->getKeyType())->toBe('string')
        ->and($organization->getIncrementing())->toBeFalse()
        ->and(strlen($organization->id))->toBe(26)
        ->and(Str::isUlid($organization->id))->toBeTrue();
});

it('gives an office a generated ULID primary key', function (): void {
    $office = Office::factory()->create();

    expect($office->getKeyType())->toBe('string')
        ->and($office->getIncrementing())->toBeFalse()
        ->and(strlen($office->id))->toBe(26)
        ->and(Str::isUlid($office->id))->toBeTrue();
});

it('links an office to its organization', function (): void {
    $organization = Organization::factory()->create();
    $office = Office::factory()->for($organization)->create();

    expect($office->organization_id)->toBe($organization->id)
        ->and($office->organization->is($organization))->toBeTrue();
});

it('lists the offices belonging to an organization', function (): void {
    $organization = Organization::factory()->create();
    Office::factory()->count(3)->for($organization)->create();
    // A second organization's office must not leak into the first's list.
    Office::factory()->create();

    expect($organization->offices()->count())->toBe(3);
});

it('links a user to its office', function (): void {
    $office = Office::factory()->create();
    $user = User::factory()->for($office)->create();

    expect($user->office_id)->toBe($office->id)
        ->and($user->office->is($office))->toBeTrue();
});

it('lists the users belonging to an office', function (): void {
    $office = Office::factory()->create();
    User::factory()->count(2)->for($office)->create();
    User::factory()->create();

    expect($office->users()->count())->toBe(2);
});

it('reaches the organization through the office rather than a second column', function (): void {
    $organization = Organization::factory()->create();
    $office = Office::factory()->for($organization)->create();
    $user = User::factory()->for($office)->create();

    expect($user->office->organization->id)->toBe($organization->id);

    // The Organization is derived, never duplicated onto users (D-027).
    expect(Schema::hasColumn('users', 'organization_id'))->toBeFalse();
});

it('builds a complete hierarchy from the default user factory', function (): void {
    $user = User::factory()->create();

    expect($user->office)->not->toBeNull()
        ->and($user->office->organization)->not->toBeNull()
        ->and(Str::isUlid($user->office_id))->toBeTrue();
});

it('reuses an explicitly supplied hierarchy instead of creating another', function (): void {
    $organization = Organization::factory()->create();
    $office = Office::factory()->for($organization)->create();

    User::factory()->count(3)->for($office)->create();

    // One organization and one office, despite three users.
    expect(Organization::query()->count())->toBe(1)
        ->and(Office::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe(3);
});

it('requires a user to have an office', function (): void {
    expect(fn () => User::factory()->create(['office_id' => null]))
        ->toThrow(QueryException::class);
});

it('rejects an office pointing at a nonexistent organization', function (): void {
    expect(fn () => Office::factory()->create([
        'organization_id' => (string) Str::ulid(),
    ]))->toThrow(QueryException::class);
});

it('rejects a user pointing at a nonexistent office', function (): void {
    expect(fn () => User::factory()->create([
        'office_id' => (string) Str::ulid(),
    ]))->toThrow(QueryException::class);
});

it('refuses to delete an organization that still has offices', function (): void {
    $organization = Organization::factory()->create();
    Office::factory()->for($organization)->create();

    // RESTRICT, not CASCADE: removing a parent must never silently take its
    // children with it.
    expect(fn () => $organization->delete())->toThrow(QueryException::class);

    expect(Office::query()->count())->toBe(1)
        ->and(Organization::query()->count())->toBe(1);
});

it('refuses to delete an office that still has users', function (): void {
    $office = Office::factory()->create();
    User::factory()->for($office)->create();

    expect(fn () => $office->delete())->toThrow(QueryException::class);

    expect(User::query()->count())->toBe(1)
        ->and(Office::query()->count())->toBe(1);
});

it('defaults an organization to the Indonesian locale and Jakarta timezone', function (): void {
    // Insert without the factory so column defaults are what is exercised.
    $id = (string) Str::ulid();
    DB::table('organizations')->insert([
        'id' => $id,
        'name' => 'Default Probe',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $organization = Organization::query()->findOrFail($id);

    expect($organization->default_locale)->toBe('id')
        ->and($organization->timezone)->toBe('Asia/Jakarta')
        ->and($organization->is_active)->toBeTrue()
        ->and($organization->legal_name)->toBeNull();
});

it('defaults an office to active with the Jakarta timezone', function (): void {
    $organization = Organization::factory()->create();
    $id = (string) Str::ulid();

    DB::table('offices')->insert([
        'id' => $id,
        'organization_id' => $organization->id,
        'code' => 'PROBE',
        'name' => 'Probe Office',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $office = Office::query()->findOrFail($id);

    expect($office->is_active)->toBeTrue()
        ->and($office->timezone)->toBe('Asia/Jakarta')
        ->and($office->city)->toBeNull();
});

it('casts the active flag to a boolean on both models', function (): void {
    $organization = Organization::factory()->inactive()->create();
    $office = Office::factory()->inactive()->create();

    expect($organization->is_active)->toBeFalse()
        ->and($office->is_active)->toBeFalse();
});

it('keeps office reassignment out of mass assignment', function (): void {
    $office = Office::factory()->create();
    $other = Office::factory()->create();
    $user = User::factory()->for($office)->create();

    // Moving someone between offices is a User Management operation, not
    // something a fillable attribute should permit.
    $user->fill(['office_id' => $other->id]);

    expect($user->office_id)->toBe($office->id);
});

it('does not create an office membership pivot', function (): void {
    expect(Schema::hasTable('user_offices'))->toBeFalse();
});
