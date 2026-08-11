<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Enums\UserPermissionEffect;
use App\Domains\Authorization\SyncCanonicalPermissions;
use App\Domains\Identity\SupportedLocales;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/*
|--------------------------------------------------------------------------
| Reading your own profile
|--------------------------------------------------------------------------
*/

it('rejects an unauthenticated profile request', function (): void {
    $this->getJson('/api/v1/profile')->assertUnauthorized();
    $this->patchJson('/api/v1/profile', ['name' => 'X'])->assertUnauthorized();
});

it('serves the profile to any authenticated user, with no permission at all', function (): void {
    // Self-service is authentication plus self-ownership. No canonical
    // permission guards it and none was invented (D-066).
    $user = User::factory()->create();

    expect($user->getAllPermissions())->toBeEmpty()
        ->and($user->getRoleNames())->toBeEmpty();

    $this->actingAs($user)->getJson('/api/v1/profile')->assertOk();
});

it('returns the caller\'s own record', function (): void {
    $office = Office::factory()->create(['code' => 'PST', 'name' => 'Kantor Pusat']);

    $user = User::factory()->for($office)->create([
        'name' => 'Budi Santoso',
        'email' => 'budi@example.test',
        'phone' => '021-555-0100',
        'preferred_locale' => 'id',
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/profile')->assertOk();

    $response->assertJsonPath('data.id', $user->getKey())
        ->assertJsonPath('data.name', 'Budi Santoso')
        ->assertJsonPath('data.email', 'budi@example.test')
        ->assertJsonPath('data.phone', '021-555-0100')
        ->assertJsonPath('data.preferred_locale', 'id')
        ->assertJsonPath('data.office.code', 'PST')
        ->assertJsonPath('data.office.name', 'Kantor Pusat');
});

it('exposes no credential or authorization internals', function (): void {
    $user = User::factory()->create(['password' => 'a-known-password']);
    $user->givePermissionTo(makePermission('projects.view'));

    $response = $this->actingAs($user)->getJson('/api/v1/profile')->assertOk();

    expect(array_keys($response->json('data')))
        ->toBe(['id', 'name', 'email', 'phone', 'preferred_locale', 'last_login_at', 'office', 'roles']);

    $body = $response->getContent();

    expect($body)->not->toContain('a-known-password')
        ->and($body)->not->toContain($user->password)
        ->and($body)->not->toContain('remember_token')
        ->and($body)->not->toContain('email_verified_at')
        ->and($body)->not->toContain('permission_scopes');
});

it('offers no way to ask for somebody else\'s profile', function (): void {
    $user = User::factory()->create();
    $stranger = User::factory()->create(['name' => 'Somebody Else']);

    // No id parameter exists, and a query string cannot introduce one.
    $response = $this->actingAs($user)
        ->getJson("/api/v1/profile?user_id={$stranger->getKey()}&id={$stranger->getKey()}")
        ->assertOk();

    expect($response->json('data.id'))->toBe($user->getKey())
        ->and($response->json('data.name'))->not->toBe('Somebody Else');

    $this->actingAs($user)->getJson("/api/v1/profile/{$stranger->getKey()}")->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Editing what you may edit
|--------------------------------------------------------------------------
*/

it('updates your own name', function (): void {
    $user = User::factory()->create(['name' => 'Before']);

    $this->actingAs($user)
        ->patchJson('/api/v1/profile', ['name' => 'After'])
        ->assertOk()
        ->assertJsonPath('data.name', 'After');

    expect($user->fresh()->name)->toBe('After');
});

it('updates your own phone', function (): void {
    $user = User::factory()->create(['phone' => null]);

    $this->actingAs($user)
        ->patchJson('/api/v1/profile', ['phone' => '081234567890'])
        ->assertOk()
        ->assertJsonPath('data.phone', '081234567890');

    expect($user->fresh()->phone)->toBe('081234567890');
});

it('clears your phone when sent as null', function (): void {
    $user = User::factory()->create(['phone' => '021-555-0100']);

    $this->actingAs($user)->patchJson('/api/v1/profile', ['phone' => null])->assertOk();

    expect($user->fresh()->phone)->toBeNull();
});

it('stores a phone number as written', function (string $phone): void {
    // No country prefix required and nothing reformatted, as M1.5 established.
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patchJson('/api/v1/profile', ['phone' => $phone])
        ->assertOk()
        ->assertJsonPath('data.phone', $phone);
})->with(['081234567890', '+62 812 3456 7890', '(021) 555-0100']);

it('accepts either supported language', function (string $locale): void {
    $user = User::factory()->create(['preferred_locale' => 'id']);

    $this->actingAs($user)
        ->patchJson('/api/v1/profile', ['preferred_locale' => $locale])
        ->assertOk()
        ->assertJsonPath('data.preferred_locale', $locale);

    expect($user->fresh()->preferred_locale)->toBe($locale);
})->with(['id', 'en']);

it('rejects a language outside the supported pair', function (mixed $locale): void {
    // Stored codes are bare `id` and `en` — never a regional tag, never a
    // display name (D-068).
    $user = User::factory()->create(['preferred_locale' => 'id']);

    $this->actingAs($user)
        ->patchJson('/api/v1/profile', ['preferred_locale' => $locale])
        ->assertStatus(422)
        ->assertJsonValidationErrors('preferred_locale');

    expect($user->fresh()->preferred_locale)->toBe('id');
})->with([
    'regional tag' => 'id-ID',
    'english regional' => 'en-US',
    'display name' => 'Indonesia',
    'english display name' => 'English',
    'unsupported' => 'fr',
    'empty' => '',
    'uppercase' => 'ID',
]);

it('leaves untouched fields alone on a partial update', function (): void {
    $user = User::factory()->create([
        'name' => 'Original',
        'phone' => '021-555-0100',
        'preferred_locale' => 'en',
    ]);

    $this->actingAs($user)->patchJson('/api/v1/profile', ['name' => 'Renamed'])->assertOk();

    $user->refresh();

    expect($user->name)->toBe('Renamed')
        ->and($user->phone)->toBe('021-555-0100')
        ->and($user->preferred_locale)->toBe('en');
});

it('rejects a blank name', function (): void {
    $user = User::factory()->create(['name' => 'Original']);

    $this->actingAs($user)
        ->patchJson('/api/v1/profile', ['name' => '   '])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    expect($user->fresh()->name)->toBe('Original');
});

/*
|--------------------------------------------------------------------------
| What self-service may never touch
|--------------------------------------------------------------------------
*/

it('refuses a field the profile does not own, rather than ignoring it', function (string $field, mixed $value): void {
    // Refused out loud, not silently discarded: an interface that appears to
    // accept a change it never made is worse than one that says no (D-066).
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patchJson('/api/v1/profile', ['name' => 'Renamed', $field => $value])
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);

    // The whole request is rejected, so the legitimate field did not apply
    // either.
    expect($user->fresh()->name)->not->toBe('Renamed');
})->with([
    'email' => ['email', 'attacker@example.test'],
    'email_verified_at' => ['email_verified_at', '2026-01-01T00:00:00Z'],
    'office_id' => ['office_id', '01JZZZZZZZZZZZZZZZZZZZZZZZ'],
    'is_active' => ['is_active', false],
    'password' => ['password', 'attacker-chosen-password'],
    'roles' => ['roles', ['SUPER_ADMIN']],
    'permissions' => ['permissions', ['permissions.assign']],
    'last_login_at' => ['last_login_at', '2026-01-01T00:00:00Z'],
    'deleted_at' => ['deleted_at', '2026-01-01T00:00:00Z'],
]);

it('leaves the email and its verification untouched', function (): void {
    $verifiedAt = now()->subDay()->startOfSecond();

    $user = User::factory()->create(['email' => 'original@example.test']);
    $user->forceFill(['email_verified_at' => $verifiedAt])->save();

    $this->actingAs($user)
        ->patchJson('/api/v1/profile', ['email' => 'attacker@example.test'])
        ->assertStatus(422);

    $user->refresh();

    expect($user->email)->toBe('original@example.test')
        ->and($user->email_verified_at->equalTo($verifiedAt))->toBeTrue();
});

it('leaves the office untouched', function (): void {
    $office = Office::factory()->create();
    $elsewhere = Office::factory()->create();

    $user = User::factory()->for($office)->create();

    $this->actingAs($user)
        ->patchJson('/api/v1/profile', ['office_id' => $elsewhere->getKey()])
        ->assertStatus(422);

    expect($user->fresh()->office_id)->toBe($office->getKey());
});

it('leaves the password untouched', function (): void {
    $user = User::factory()->create(['password' => 'a-known-password']);
    $original = $user->password;

    $this->actingAs($user)
        ->patchJson('/api/v1/profile', ['password' => 'attacker-chosen-password'])
        ->assertStatus(422);

    $user->refresh();

    expect($user->password)->toBe($original)
        ->and(Hash::check('a-known-password', $user->password))->toBeTrue()
        ->and(Hash::check('attacker-chosen-password', $user->password))->toBeFalse();
});

it('cannot deactivate yourself through the profile', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson('/api/v1/profile', ['is_active' => false])->assertStatus(422);

    expect($user->fresh()->is_active)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| A profile edit changes nothing about authorization
|--------------------------------------------------------------------------
*/

it('leaves every authorization assignment untouched', function (): void {
    app(SyncCanonicalPermissions::class)->handle();

    $user = User::factory()->create();
    grantPermissionScope($user, 'users.view', DataScope::OFFICE);
    $user->givePermissionTo(Permission::findByName('tasks.view'));
    makeOverride($user, Permission::findByName('calendar.view'), UserPermissionEffect::DENY, createdBy: $user);

    $roles = DB::table('model_has_roles')->orderBy('role_id')->get()->toArray();
    $scopes = DB::table('role_permission_scopes')->orderBy('id')->get()->toArray();
    $direct = DB::table('model_has_permissions')->orderBy('permission_id')->get()->toArray();
    $overrides = DB::table('user_permission_overrides')->orderBy('id')->get()->toArray();
    $permissions = DB::table('permissions')->count();

    $this->actingAs($user)->patchJson('/api/v1/profile', [
        'name' => 'Renamed',
        'phone' => '081234567890',
        'preferred_locale' => 'en',
    ])->assertOk();

    expect(DB::table('model_has_roles')->orderBy('role_id')->get()->toArray())->toEqual($roles)
        ->and(DB::table('role_permission_scopes')->orderBy('id')->get()->toArray())->toEqual($scopes)
        ->and(DB::table('model_has_permissions')->orderBy('permission_id')->get()->toArray())->toEqual($direct)
        ->and(DB::table('user_permission_overrides')->orderBy('id')->get()->toArray())->toEqual($overrides)
        ->and(DB::table('permissions')->count())->toBe($permissions)
        ->and(DB::table('roles')->count())->toBe(1);
});

it('leaves the effective authorization projection identical', function (string $field, mixed $value): void {
    app(SyncCanonicalPermissions::class)->handle();

    $user = User::factory()->create();
    grantPermissionScope($user, 'users.view', DataScope::OFFICE);
    grantPermissionScope($user, 'users.view', DataScope::OWN);
    grantPermissionScope($user, 'roles.view', DataScope::ALL);

    $before = $this->actingAs($user)->getJson('/api/v1/me')->assertOk();

    $this->actingAs($user)->patchJson('/api/v1/profile', [$field => $value])->assertOk();

    $after = $this->actingAs($user)->getJson('/api/v1/me')->assertOk();

    expect($after->json('data.permissions'))->toBe($before->json('data.permissions'))
        ->and($after->json('data.permission_scopes'))->toBe($before->json('data.permission_scopes'))
        ->and($after->json('data.roles'))->toBe($before->json('data.roles'));
})->with([
    'name' => ['name', 'Renamed'],
    'phone' => ['phone', '081234567890'],
    'preferred_locale' => ['preferred_locale', 'en'],
]);

/*
|--------------------------------------------------------------------------
| /api/v1/me sees the change
|--------------------------------------------------------------------------
*/

it('reflects a name change in the next current-user response', function (): void {
    $user = User::factory()->create(['name' => 'Before']);

    $this->actingAs($user)->patchJson('/api/v1/profile', ['name' => 'After'])->assertOk();

    $this->actingAs($user)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.name', 'After');
});

it('reflects a language change in the next current-user response', function (): void {
    $user = User::factory()->create(['preferred_locale' => 'id']);

    $this->actingAs($user)->patchJson('/api/v1/profile', ['preferred_locale' => 'en'])->assertOk();

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.preferred_locale', 'en');
});

/*
|--------------------------------------------------------------------------
| Supported locales
|--------------------------------------------------------------------------
*/

it('supports exactly the two canonical locale codes', function (): void {
    expect(SupportedLocales::all())->toBe(['id', 'en'])
        ->and(SupportedLocales::DEFAULT)->toBe('id')
        ->and(SupportedLocales::supports('id'))->toBeTrue()
        ->and(SupportedLocales::supports('en'))->toBeTrue()
        ->and(SupportedLocales::supports('id-ID'))->toBeFalse()
        ->and(SupportedLocales::supports('Indonesia'))->toBeFalse()
        ->and(SupportedLocales::supports(null))->toBeFalse();
});

it('agrees with the frontend routing configuration', function (): void {
    // Two files naming the same pair is how they start disagreeing. Asserted
    // rather than hoped for.
    $routing = file_get_contents(base_path('../frontend/src/i18n/routing.ts'));

    preg_match('/locales:\s*\[(.*?)\]/s', $routing, $matches);

    expect($matches)->toHaveKey(1);

    preg_match_all('/"([a-z-]+)"/', $matches[1], $locales);

    expect($locales[1])->toBe(SupportedLocales::all());

    preg_match('/defaultLocale:\s*"([a-z-]+)"/', $routing, $default);

    expect($default[1])->toBe(SupportedLocales::DEFAULT);
});

it('never writes to the database while reading the profile', function (): void {
    $user = User::factory()->create();

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $this->actingAs($user)->getJson('/api/v1/profile')->assertOk();

    expect($statements)->not->toBeEmpty();

    foreach ($statements as $sql) {
        expect(strtolower(ltrim($sql)))->toStartWith('select');
    }
});
