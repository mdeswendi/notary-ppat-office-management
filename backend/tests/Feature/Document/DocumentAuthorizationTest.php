<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Enums\UserPermissionEffect;
use App\Domains\Document\DocumentVisibility;
use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use App\Policies\DocumentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * An actor in a fresh Office holding the named permissions at one scope.
 *
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function documentActor(array $permissions, DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

function documentPolicy(): DocumentPolicy
{
    return app(DocumentPolicy::class);
}

/*
|--------------------------------------------------------------------------
| Data Scopes are predicates
|--------------------------------------------------------------------------
*/

it('reaches only its own office at OFFICE', function (): void {
    [$actor, $office] = documentActor(['documents.view'], DataScope::OFFICE);

    $mine = Document::factory()->inOffice($office)->create();
    $theirs = Document::factory()->create();

    expect(documentPolicy()->view($actor, $mine))->toBeTrue()
        ->and(documentPolicy()->view($actor, $theirs))->toBeFalse();
});

it('reaches what the actor filed at OWN, even in another office', function (): void {
    // OWN applies here where it does not for Party (D-080) or Service Type
    // (D-106): `created_by` names the person who filed the document, not a
    // colleague who typed in a shared reference record.
    [$actor] = documentActor(['documents.view'], DataScope::OWN);

    $mine = Document::factory()->createdBy($actor)->create();
    $theirs = Document::factory()->create();

    expect(documentPolicy()->view($actor, $mine))->toBeTrue()
        ->and(documentPolicy()->view($actor, $theirs))->toBeFalse();
});

it('reaches every office at ALL', function (): void {
    [$actor] = documentActor(['documents.view'], DataScope::ALL);

    expect(documentPolicy()->view($actor, Document::factory()->create()))->toBeTrue()
        ->and(documentPolicy()->view($actor, Document::factory()->create()))->toBeTrue();
});

it('unions two grants rather than ranking them', function (): void {
    // D-028: multiple grants union, no scope outranks another. An actor holding
    // both reaches what either predicate selects — never only the "wider" one.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'documents.view', DataScope::OWN);
    grantPermissionScope($actor, 'documents.view', DataScope::OFFICE);
    $actor = $actor->fresh();

    $ownedElsewhere = Document::factory()->createdBy($actor)->create();
    $inMyOffice = Document::factory()->inOffice($office)->create();
    $neither = Document::factory()->create();

    expect(documentPolicy()->view($actor, $ownedElsewhere))->toBeTrue()
        ->and(documentPolicy()->view($actor, $inMyOffice))->toBeTrue()
        ->and(documentPolicy()->view($actor, $neither))->toBeFalse();
});

it('reaches nothing at ASSIGNED, because a document has no assignee', function (): void {
    // Withheld rather than silently ignored: a Document has no `pic_user_id` and
    // no assignment entity, so there is nothing for the predicate to match. The
    // Permission Matrix does not offer it either, which is what keeps an
    // administrator from saving a silently powerless grant (D-080's dead
    // control).
    [$actor, $office] = documentActor(['documents.view'], DataScope::ASSIGNED);

    $document = Document::factory()->inOffice($office)->createdBy($actor)->create();

    expect(documentPolicy()->view($actor, $document))->toBeFalse()
        ->and(documentPolicy()->viewAny($actor))->toBeFalse();
});

it('reaches nothing at TEAM, because no Team entity exists', function (): void {
    // D-042. The scope stays in the canonical vocabulary and grants nothing.
    [$actor, $office] = documentActor([], DataScope::TEAM);

    $permission = Permission::firstOrCreate(['name' => 'documents.view', 'guard_name' => 'web']);
    $role = makeRole('TEAM_SCOPED_DOCUMENTS');
    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::TEAM);
    $actor->assignRole($role);
    $actor = $actor->fresh();

    $document = Document::factory()->inOffice($office)->create();

    expect(documentPolicy()->view($actor, $document))->toBeFalse()
        ->and(documentPolicy()->viewAny($actor))->toBeFalse();
});

it('grants nothing when a role carries the permission with no scope', function (): void {
    // M1's rule, unchanged: a role grant with no Data Scope grants nothing.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    $role = makeRole('UNSCOPED_DOCUMENTS');
    $role->givePermissionTo(Permission::firstOrCreate(['name' => 'documents.view', 'guard_name' => 'web']));
    $actor->assignRole($role);
    $actor = $actor->fresh();

    $document = Document::factory()->inOffice($office)->create();

    expect(documentPolicy()->view($actor, $document))->toBeFalse();
});

it('lets an active DENY override win', function (): void {
    [$actor, $office] = documentActor(['documents.view'], DataScope::OFFICE);
    $document = Document::factory()->inOffice($office)->create();

    expect(documentPolicy()->view($actor, $document))->toBeTrue();

    makeOverride($actor, Permission::findByName('documents.view'), UserPermissionEffect::DENY);

    expect(documentPolicy()->view($actor->fresh(), $document))->toBeFalse();
});

it('refuses the list to an actor whose only scope reaches nothing', function (): void {
    // A reliably empty page is worse than a refusal: it looks like "no
    // documents" rather than "no access".
    [$assigned] = documentActor(['documents.view'], DataScope::ASSIGNED);
    [$office] = documentActor(['documents.view'], DataScope::OFFICE);

    expect(documentPolicy()->viewAny($assigned))->toBeFalse()
        ->and(documentPolicy()->viewAny($office))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Filing is always into the actor's own Office
|--------------------------------------------------------------------------
*/

it('lets an actor file only into their own office', function (): void {
    [$actor, $office] = documentActor(['documents.upload'], DataScope::OFFICE);
    $elsewhere = Office::factory()->create();

    expect(documentPolicy()->create($actor, $office->getKey()))->toBeTrue()
        ->and(documentPolicy()->create($actor))->toBeTrue()
        ->and(documentPolicy()->create($actor, $elsewhere->getKey()))->toBeFalse();
});

it('does not let ALL choose which office a new document belongs to', function (): void {
    // ALL is reach over records that already exist, never authority to decide
    // where a new one lands. The line D-097, D-098 and D-107 all drew.
    [$actor] = documentActor(['documents.upload'], DataScope::ALL);
    $elsewhere = Office::factory()->create();

    expect(documentPolicy()->create($actor))->toBeTrue()
        ->and(documentPolicy()->create($actor, $elsewhere->getKey()))->toBeFalse();
});

it('refuses filing to an actor holding only view', function (): void {
    [$actor, $office] = documentActor(['documents.view'], DataScope::OFFICE);

    expect(documentPolicy()->create($actor, $office->getKey()))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Nine independent capabilities
|--------------------------------------------------------------------------
*/

it('maps each ability to its own canonical capability', function (string $ability, string $capability): void {
    [$actor, $office] = documentActor([$capability]);
    $document = Document::factory()->inOffice($office)->create();

    expect(documentPolicy()->{$ability}($actor, $document))->toBeTrue("{$ability} / {$capability}");
})->with([
    ['view', 'documents.view'],
    ['upload', 'documents.upload'],
    ['download', 'documents.download'],
    ['update', 'documents.update'],
    ['verify', 'documents.verify'],
    ['archive', 'documents.archive'],
    ['delete', 'documents.delete'],
]);

it('lets no document capability imply another', function (): void {
    // The discipline D-091 applies to Project and D-110 to participation.
    // `documents.update` does not reach `verify`; `verify` does not reach
    // `archive`; `upload` does not reach `download`. No umbrella `manage` exists.
    $abilities = [
        'documents.view' => 'view',
        'documents.upload' => 'upload',
        'documents.download' => 'download',
        'documents.update' => 'update',
        'documents.verify' => 'verify',
        'documents.archive' => 'archive',
        'documents.delete' => 'delete',
    ];

    foreach (array_keys($abilities) as $held) {
        [$actor, $office] = documentActor([$held]);
        $document = Document::factory()->inOffice($office)->create();

        foreach ($abilities as $capability => $ability) {
            expect(documentPolicy()->{$ability}($actor, $document))
                ->toBe($capability === $held, "holding {$held}, asked {$capability}");
        }
    }
});

it('refuses every ability to an actor holding nothing', function (): void {
    [$actor, $office] = documentActor([]);
    $document = Document::factory()->inOffice($office)->create();

    foreach (['view', 'upload', 'download', 'update', 'verify', 'archive', 'delete'] as $ability) {
        expect(documentPolicy()->{$ability}($actor, $document))->toBeFalse($ability);
    }

    expect(documentPolicy()->viewAny($actor))->toBeFalse()
        ->and(documentPolicy()->create($actor))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Sensitive documents answer to their own capability
|--------------------------------------------------------------------------
*/

it('refuses a sensitive document to an actor holding only the ordinary code', function (): void {
    // D-115: `documents.sensitive.view` is a separate capability, not an
    // escalation of `documents.view`. Holding the ordinary code says nothing
    // about a KTP scan.
    [$actor, $office] = documentActor(['documents.view']);

    $ordinary = Document::factory()->inOffice($office)->create();
    $sensitive = Document::factory()->inOffice($office)->sensitive()->create();

    expect(documentPolicy()->view($actor, $ordinary))->toBeTrue()
        ->and(documentPolicy()->view($actor, $sensitive))->toBeFalse();
});

it('reaches a sensitive document when both codes are held', function (): void {
    [$actor, $office] = documentActor(['documents.view', 'documents.sensitive.view']);

    $sensitive = Document::factory()->inOffice($office)->sensitive()->create();

    expect(documentPolicy()->view($actor, $sensitive))->toBeTrue();
});

it('does not let the sensitive code stand in for the ordinary one', function (): void {
    // Both are checked, so an actor holding only the sensitive code cannot read
    // an ordinary document through it. The independence runs in both directions.
    [$actor, $office] = documentActor(['documents.sensitive.view']);

    $ordinary = Document::factory()->inOffice($office)->create();
    $sensitive = Document::factory()->inOffice($office)->sensitive()->create();

    expect(documentPolicy()->view($actor, $ordinary))->toBeFalse()
        ->and(documentPolicy()->view($actor, $sensitive))->toBeFalse();
});

it('separates sensitive viewing from sensitive downloading', function (): void {
    // Two codes, two answers. Somebody may legitimately be allowed to know a KTP
    // scan exists and not to read it.
    //
    // **Narrowed at M5.2, and unchanged at M8.1.** M5.1 asserted that granting
    // `documents.sensitive.download` made the Policy answer true; the D-115 gate
    // then made it false for everyone, so this narrowed to the half that was
    // always the point — the two codes are independent, and holding the view one
    // does not reach a download.
    //
    // M8.1 lifted that gate and this test did not move, because the actor below
    // still holds only `documents.sensitive.view`. Granting it back is now
    // asserted directly, one test down.
    [$actor, $office] = documentActor([
        'documents.view', 'documents.download', 'documents.sensitive.view',
    ]);

    $sensitive = Document::factory()->inOffice($office)->sensitive()->create();

    expect(documentPolicy()->view($actor, $sensitive))->toBeTrue()
        ->and(documentPolicy()->download($actor, $sensitive))->toBeFalse();

    // The capability itself still resolves independently, which is what keeps the
    // gate a gate rather than a redefinition of the permission.
    grantPermissionScope($actor, 'documents.sensitive.download', DataScope::OFFICE);

    expect(resolveAccess($actor->fresh(), 'documents.sensitive.download')->granted)->toBeTrue();
});

it('permits a sensitive download now that the audit store exists', function (): void {
    // **This test changed at M8.1, exactly as its previous version instructed.**
    // It read: "When audit_logs lands, the gate in DocumentPolicy::download comes
    // out and this test changes with it." M8.1 built the store (D-123), so the
    // gate came out and the assertion inverts.
    //
    // D-115's rule was never that sensitive downloads are forbidden — it was that
    // the capability to read a KTP scan and the record of who read it belong in
    // the same milestone. They now do.
    [$actor, $office] = documentActor([
        'documents.view', 'documents.sensitive.view',
        'documents.download', 'documents.sensitive.download',
    ]);

    $ordinary = Document::factory()->inOffice($office)->create();
    $sensitive = Document::factory()->inOffice($office)->sensitive()->create();

    expect(documentPolicy()->download($actor, $ordinary))->toBeTrue()
        ->and(documentPolicy()->download($actor, $sensitive))->toBeTrue()
        ->and(documentPolicy()->view($actor, $sensitive))->toBeTrue();

    expect(Schema::hasTable('audit_logs'))->toBeTrue();
});

it('still refuses a sensitive download to an actor without the sensitive code', function (): void {
    // The half of the old gate that was never about audit: the two download codes
    // are independent, and `documents.download` alone never reaches a sensitive
    // file. Lifting the D-115 gate must not have quietly widened this.
    [$actor, $office] = documentActor([
        'documents.view', 'documents.sensitive.view', 'documents.download',
    ]);

    $ordinary = Document::factory()->inOffice($office)->create();
    $sensitive = Document::factory()->inOffice($office)->sensitive()->create();

    expect(documentPolicy()->download($actor, $ordinary))->toBeTrue()
        ->and(documentPolicy()->download($actor, $sensitive))->toBeFalse()
        // Metadata is unaffected. Refusing the file is not refusing to admit the
        // document exists.
        ->and(documentPolicy()->view($actor, $sensitive))->toBeTrue();
});

it('gates every write ability on a sensitive document too', function (string $ability, string $capability): void {
    // Sensitivity is not only a read concern: correcting, verifying, archiving
    // or deleting a KTP scan all disclose it.
    [$actor, $office] = documentActor([$capability]);
    $sensitive = Document::factory()->inOffice($office)->sensitive()->create();

    expect(documentPolicy()->{$ability}($actor, $sensitive))->toBeFalse();

    grantPermissionScope($actor, 'documents.sensitive.view', DataScope::OFFICE);

    expect(documentPolicy()->{$ability}($actor->fresh(), $sensitive))->toBeTrue();
})->with([
    ['upload', 'documents.upload'],
    ['update', 'documents.update'],
    ['verify', 'documents.verify'],
    ['archive', 'documents.archive'],
    ['delete', 'documents.delete'],
]);

it('keeps sensitivity out of the visibility query', function (): void {
    // `is_sensitive` is not a Data Scope. Folding it into the scope predicate
    // would make one permission answer two questions and would silently
    // reinterpret every existing `documents.view` grant.
    $executable = '';

    foreach (token_get_all(file_get_contents(app_path('Domains/Document/DocumentVisibility.php'))) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $executable .= is_array($token) ? $token[1] : $token;
    }

    expect($executable)->not->toContain('is_sensitive')
        ->and($executable)->not->toContain('sensitive.view');
});

/*
|--------------------------------------------------------------------------
| Lifecycle state is not a visibility rule
|--------------------------------------------------------------------------
*/

it('keeps an archived document reachable', function (): void {
    // Somebody must be able to read what the office archived, and a record
    // referencing an archived document must stay readable (CLAUDE.md section 63).
    [$actor, $office] = documentActor(['documents.view']);

    $archived = Document::factory()->inOffice($office)->archived($actor)->create();

    expect(documentPolicy()->view($actor, $archived))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The visibility query itself
|--------------------------------------------------------------------------
*/

it('selects nothing rather than everything when no predicate applies', function (): void {
    // No usable predicate is not "no restriction" — it is no access. A visibility
    // class that returned the query untouched here would hand an unauthorized
    // caller the whole table.
    [$actor] = documentActor(['documents.view'], DataScope::ASSIGNED);

    Document::factory()->count(3)->create();

    $reachable = app(DocumentVisibility::class)
        ->scope(Document::query(), $actor, resolveAccess($actor, 'documents.view'))
        ->count();

    expect($reachable)->toBe(0);
});

it('scopes a list without a query per row', function (): void {
    // The predicate is one WHERE clause, so the query count must not grow with
    // the number of rows. Compared at two list sizes rather than against a
    // guessed threshold, which would only pin today's number.
    [$actor, $office] = documentActor(['documents.view']);

    Document::factory()->count(1)->inOffice($office)->create();

    $access = resolveAccess($actor, 'documents.view');
    $visibility = app(DocumentVisibility::class);

    // One listener, counted twice. Registering a second would keep the first
    // running and quietly double every measurement after it.
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $visibility->scope(Document::query(), $actor, $access)->get();
    $small = $queries;

    Document::factory()->count(12)->inOffice($office)->create();

    $queries = 0;
    $visibility->scope(Document::query(), $actor, $access)->get();
    $large = $queries;

    expect($large)->toBe($small);
});

it('introduces no scope ranking', function (): void {
    // D-028: predicates, never a ladder. No widest-scope, no maxScope, no
    // comparison between scopes anywhere in the Document domain.
    $methods = array_map(
        fn (ReflectionMethod $method): string => strtolower($method->getName()),
        (new ReflectionClass(DocumentVisibility::class))->getMethods(),
    );

    foreach (['widest', 'max', 'rank', 'level', 'weight', 'priority', 'compare', 'strongest'] as $forbidden) {
        expect($methods)->not->toContain($forbidden);
    }
});
