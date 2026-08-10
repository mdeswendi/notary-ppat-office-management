<?php

namespace App\Console\Commands;

use App\Domains\Authorization\DefaultRoleRegistry;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Authorization\SyncCanonicalPermissions;
use App\Models\Office;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Prepares a fresh deployment: one Organization, one Office, the canonical
 * permissions, the nine default roles, and the first administrator (D-034).
 *
 * Interactive and one-time. There is **no default password and no password
 * option** — the secret is typed at a hidden prompt, hashed, and never printed,
 * logged, stored in plaintext, or passed on a command line where a shell history
 * would keep it (D-060).
 *
 * `SUPER_ADMIN` receives every canonical permission explicitly, each at the
 * `ALL` scope (D-057). Not a wildcard, not a `Gate::before`, not a role-name
 * shortcut — D-032 forbids all three, so the role's power is a list of grants
 * like any other role's, and revoking one revokes it.
 *
 * The other eight roles are created **empty**. The high-level matrix in
 * `02_MENU_AND_PERMISSIONS.md` section 5 grades modules F / V / A / —, which
 * cannot be turned into 171 permission codes and their Data Scopes without
 * inventing the mapping, and invented authorization is worse than absent
 * authorization. They are configured through the Permission Matrix.
 *
 * Rerunning on an initialized deployment changes nothing. Nothing resynchronizes
 * default roles either, so a role an office deleted stays deleted (D-058).
 */
class BootstrapDeploymentCommand extends Command
{
    protected $signature = 'app:bootstrap';

    protected $description = 'Prepare a fresh deployment: organization, office, roles, permissions, and the first administrator';

    public function handle(SyncCanonicalPermissions $sync, PermissionRegistrar $registrar): int
    {
        $this->line('');
        $this->info('Deployment bootstrap');
        $this->line('');

        $state = $this->inspect();

        if ($state['initialized']) {
            $this->info('This deployment is already initialized. No changes were made.');
            $this->reportState($state);

            return self::SUCCESS;
        }

        if ($state['blockers'] !== []) {
            $this->error('This deployment cannot be bootstrapped safely.');
            $this->line('');

            foreach ($state['blockers'] as $blocker) {
                $this->line("  <fg=red>-</> {$blocker}");
            }

            $this->line('');
            $this->warn('Nothing was changed. Resolve the above, or provision manually.');

            return self::FAILURE;
        }

        $input = $this->collectInput();

        if ($input === null) {
            $this->warn('Cancelled. Nothing was changed.');

            return self::FAILURE;
        }

        // Permissions are synchronized before the transaction: the sync is
        // idempotent and additive, and its rows are exactly what a re-run would
        // produce anyway, so they are safe to keep even if the rest aborts.
        $syncResult = $sync->handle();

        DB::transaction(function () use ($input, $registrar): void {
            $organization = new Organization;
            $organization->name = $input['organization_name'];
            $organization->save();

            $office = new Office;
            $office->organization_id = $organization->getKey();
            $office->code = $input['office_code'];
            $office->name = $input['office_name'];
            $office->save();

            $roles = [];

            foreach (DefaultRoleRegistry::all() as $name) {
                $roles[$name] = Role::create(['name' => $name, 'guard_name' => PermissionRegistry::GUARD]);
            }

            $this->grantEverything($roles[DefaultRoleRegistry::ADMINISTRATOR]);

            $administrator = new User;
            $administrator->name = $input['admin_name'];
            $administrator->email = $input['admin_email'];
            $administrator->password = $input['admin_password'];
            $administrator->office_id = $office->getKey();
            $administrator->is_active = true;
            $administrator->save();

            $administrator->assignRole($roles[DefaultRoleRegistry::ADMINISTRATOR]);

            $registrar->forgetCachedPermissions();
        });

        $registrar->forgetCachedPermissions();

        $this->line('');
        $this->info('Bootstrap complete.');
        $this->line('');
        $this->table(['', 'Count'], [
            ['Organizations', Organization::query()->count()],
            ['Offices', Office::query()->count()],
            ['Roles', Role::query()->count()],
            ['Canonical permissions', count($syncResult->canonical)],
            [DefaultRoleRegistry::ADMINISTRATOR.' grants', PermissionRegistry::count()],
            ['Administrators', User::query()->count()],
        ]);

        $this->line('');
        $this->line('The other default roles were created with no permissions. Configure them');
        $this->line('through Role Management before assigning anyone to them.');
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Every canonical permission, at `ALL`, written explicitly.
     */
    private function grantEverything(Role $role): void
    {
        $permissions = Permission::query()
            ->where('guard_name', PermissionRegistry::GUARD)
            ->whereIn('name', PermissionRegistry::all())
            ->get();

        $role->syncPermissions($permissions);

        $now = now();

        DB::table('role_permission_scopes')->insert(
            $permissions->map(fn (Permission $permission): array => [
                'id' => (string) Str::ulid(),
                'role_id' => $role->getKey(),
                'permission_id' => $permission->getKey(),
                'scope' => DataScope::ALL->value,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
    }

    /**
     * Tell a fresh deployment from a partially provisioned one.
     *
     * Permissions may legitimately already exist — `permissions:sync` is a
     * normal deployment step and says nothing about whether identity has been
     * provisioned. Organizations, Offices, Users, and Roles are what indicate a
     * deployment already has an identity, and finding some of them is not
     * something to merge blindly.
     *
     * @return array{initialized: bool, blockers: array<int, string>, counts: array<string, int>}
     */
    private function inspect(): array
    {
        $counts = [
            'organizations' => Organization::query()->count(),
            'offices' => Office::query()->count(),
            'users' => User::withTrashed()->count(),
            'roles' => Role::query()->count(),
            'permissions' => Permission::query()->count(),
        ];

        $identity = $counts['organizations'] + $counts['offices'] + $counts['users'] + $counts['roles'];

        if ($identity === 0) {
            return ['initialized' => false, 'blockers' => [], 'counts' => $counts];
        }

        // A complete-looking deployment: at least one Organization, Office, and
        // an active user who can already administer authorization.
        $hasAdministrator = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($roles) => $roles->whereHas(
                'permissions',
                fn ($permissions) => $permissions->where('name', 'permissions.assign')
            ))
            ->exists();

        if ($counts['organizations'] >= 1 && $counts['offices'] >= 1 && $hasAdministrator) {
            return ['initialized' => true, 'blockers' => [], 'counts' => $counts];
        }

        $blockers = [];

        foreach (['organizations', 'offices', 'users', 'roles'] as $table) {
            if ($counts[$table] > 0) {
                $blockers[] = sprintf('%s already contains %d row(s).', $table, $counts[$table]);
            }
        }

        $blockers[] = 'This looks like a partially provisioned deployment, and merging into it '
            .'cannot be done safely without knowing what is missing and why.';

        return ['initialized' => false, 'blockers' => $blockers, 'counts' => $counts];
    }

    /**
     * @param  array{initialized: bool, blockers: array<int, string>, counts: array<string, int>}  $state
     */
    private function reportState(array $state): void
    {
        $this->line('');
        $this->table(
            ['', 'Count'],
            array_map(fn (string $key, int $value): array => [$key, $value], array_keys($state['counts']), $state['counts'])
        );
    }

    /**
     * @return array<string, string>|null
     */
    private function collectInput(): ?array
    {
        $input = [
            'organization_name' => trim((string) $this->ask('Organization name')),
            'office_code' => trim((string) $this->ask('Office code')),
            'office_name' => trim((string) $this->ask('Office name')),
            'admin_name' => trim((string) $this->ask('Administrator name')),
            'admin_email' => trim((string) $this->ask('Administrator email')),
        ];

        // secret() hides the input and keeps it out of the terminal scrollback.
        $input['admin_password'] = (string) $this->secret('Administrator password');
        $input['admin_password_confirmation'] = (string) $this->secret('Confirm administrator password');

        $validator = Validator::make($input, [
            'organization_name' => ['required', 'string', 'max:255'],
            'office_code' => ['required', 'string', 'max:255'],
            'office_name' => ['required', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            // The same rule the user-creation endpoint uses (D-051).
            'admin_password' => ['required', 'confirmed', Password::default()],
        ]);

        if ($validator->fails()) {
            $this->line('');
            $this->error('The details provided are not valid:');

            foreach ($validator->errors()->all() as $message) {
                // Password messages describe the rule, never the value.
                $this->line("  <fg=red>-</> {$message}");
            }

            return null;
        }

        return $input;
    }
}
