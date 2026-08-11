<?php

namespace App\Console\Commands;

use App\Domains\Authorization\SyncCanonicalPermissions;
use App\Domains\Authorization\SyncCanonicalPermissionsResult;
use Illuminate\Console\Command;

/**
 * Reconciles the canonical permission registry into the permissions table.
 *
 * Run explicitly — never on boot, never on request. Permission changes are a
 * deployment step, not a side effect of serving traffic.
 *
 * The work itself lives in {@see SyncCanonicalPermissions} so the deployment
 * bootstrap can reuse it inside its own transaction (D-059). This command is
 * now the reporting layer around that service; its behaviour is unchanged.
 */
class SyncPermissionsCommand extends Command
{
    protected $signature = 'permissions:sync';

    protected $description = 'Synchronize the canonical permission registry into the permissions table';

    public function handle(SyncCanonicalPermissions $sync): int
    {
        $this->report($sync->handle());

        return self::SUCCESS;
    }

    private function report(SyncCanonicalPermissionsResult $result): void
    {
        $this->newLine();
        $this->line("Guard: <fg=cyan>{$result->guard}</>");
        $this->newLine();

        $this->table(
            ['', 'Count'],
            [
                ['Canonical permissions', count($result->canonical)],
                ['Already present', $result->alreadyPresent()],
                ['Created', count($result->created)],
                ['Unmanaged (preserved)', count($result->unmanaged)],
            ]
        );

        if ($result->created !== []) {
            $this->newLine();
            $this->line('Created:');

            foreach ($result->created as $name) {
                $this->line("  <fg=green>+</> {$name}");
            }
        }

        if ($result->unmanaged !== []) {
            $this->newLine();
            $this->warn('Present in the database but absent from the canonical registry.');
            $this->warn('These were preserved. Remove them manually only after confirming no role depends on them.');
            $this->newLine();

            foreach ($result->unmanaged as $name) {
                $this->line("  <fg=yellow>?</> {$name}");
            }
        }

        $this->newLine();

        $this->info($result->created === []
            ? 'Already synchronized. Nothing to create.'
            : sprintf('Synchronized. %d permission(s) created.', count($result->created)));
    }
}
