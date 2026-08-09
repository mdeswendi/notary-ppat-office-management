<?php

namespace App\Console\Commands;

use App\Domains\Authorization\PermissionRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reconciles the canonical permission registry into the permissions table.
 *
 * Run explicitly — never on boot, never on request. Permission changes are a
 * deployment step, not a side effect of serving traffic.
 *
 * The command is additive and idempotent. It creates what the registry declares
 * and is missing; it never truncates, prunes, renames, or reassigns. Rows that
 * exist in the database but not in the registry are reported as unmanaged and
 * left untouched: this command cannot tell an obsolete leftover apart from a
 * permission an operator added deliberately, and deleting one would silently
 * strip capability from every role holding it.
 */
class SyncPermissionsCommand extends Command
{
    protected $signature = 'permissions:sync';

    protected $description = 'Synchronize the canonical permission registry into the permissions table';

    public function handle(PermissionRegistrar $registrar): int
    {
        // The registry's own guard, not `auth.defaults.guard` — the two agree
        // on the console but not inside a request, and permissions written
        // under one guard are invisible to a check made under another (D-046).
        $guard = PermissionRegistry::GUARD;

        // Read through a cold cache. A stale cached collection would make the
        // "already present" check answer from a snapshot rather than the table.
        $registrar->forgetCachedPermissions();

        $permissionClass = $registrar->getPermissionClass();

        $canonical = PermissionRegistry::all();

        $existing = $permissionClass::query()
            ->where('guard_name', $guard)
            ->pluck('name')
            ->all();

        $missing = array_values(array_diff($canonical, $existing));
        $unmanaged = array_values(array_diff($existing, $canonical));

        sort($missing);
        sort($unmanaged);

        // One transaction for the whole batch: a partially applied permission
        // set is worse than none, because role configuration would be designed
        // against a surface that does not fully exist.
        DB::transaction(function () use ($permissionClass, $missing, $guard): void {
            foreach ($missing as $name) {
                $permissionClass::create([
                    'name' => $name,
                    'guard_name' => $guard,
                ]);
            }
        });

        // Invalidate again so the next request resolves against the new rows.
        $registrar->forgetCachedPermissions();

        $this->report($guard, $canonical, $existing, $missing, $unmanaged);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $canonical
     * @param  array<int, string>  $existing
     * @param  array<int, string>  $missing
     * @param  array<int, string>  $unmanaged
     */
    private function report(string $guard, array $canonical, array $existing, array $missing, array $unmanaged): void
    {
        $this->newLine();
        $this->line("Guard: <fg=cyan>{$guard}</>");
        $this->newLine();

        $this->table(
            ['', 'Count'],
            [
                ['Canonical permissions', count($canonical)],
                ['Already present', count($canonical) - count($missing)],
                ['Created', count($missing)],
                ['Unmanaged (preserved)', count($unmanaged)],
            ]
        );

        if ($missing !== []) {
            $this->newLine();
            $this->line('Created:');

            foreach ($missing as $name) {
                $this->line("  <fg=green>+</> {$name}");
            }
        }

        if ($unmanaged !== []) {
            $this->newLine();
            $this->warn('Present in the database but absent from the canonical registry.');
            $this->warn('These were preserved. Remove them manually only after confirming no role depends on them.');
            $this->newLine();

            foreach ($unmanaged as $name) {
                $this->line("  <fg=yellow>?</> {$name}");
            }
        }

        $this->newLine();

        $this->info($missing === []
            ? 'Already synchronized. Nothing to create.'
            : sprintf('Synchronized. %d permission(s) created.', count($missing)));
    }
}
