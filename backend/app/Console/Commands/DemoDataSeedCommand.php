<?php

namespace App\Console\Commands;

use App\Domains\Demo\DemoDataSeeder;
use App\Domains\Demo\DemoEnvironmentGuard;
use App\Domains\Demo\Exceptions\DemoDatasetAlreadyExists;
use App\Domains\Demo\Exceptions\DemoRolePrerequisiteMissing;
use App\Domains\Demo\Exceptions\UnsafeDemoEnvironment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seed the minimal local demo dataset — local environment and the exact
 * `notary_ppat_demo` database only.
 *
 * **Thin on purpose.** Everything this command does, in order: resolve the
 * live connection and environment, run {@see DemoEnvironmentGuard} against
 * them, and — only if that passes — delegate the entire dataset to
 * {@see DemoDataSeeder}. No environment or database-name check is duplicated
 * here; the guard is the one place that logic lives; see its own docblock.
 *
 * **The guard runs before anything else** — before the seeder's own
 * idempotency check, before any transaction, before any query beyond what the
 * guard itself performs. A wrong environment or database is refused here,
 * every time, regardless of what state the target database happens to be in.
 *
 * Refuse-not-overwrite: a dataset that already exists (the seeder's own
 * marker check) is reported and left untouched, exiting with a **non-zero**
 * status — nothing was done, and a caller (a script, CI, an operator relying
 * on `$?`) must be able to tell that apart from a run that actually seeded
 * anything. Nothing here builds a `demo:reset`; removing an existing dataset
 * is its own, separately-scoped decision.
 *
 * The seeder's own role prerequisite (see {@see DemoDataSeeder::seed()}) is
 * refused the same way: reported, left untouched, non-zero exit.
 */
class DemoDataSeedCommand extends Command
{
    protected $signature = 'demo:seed';

    protected $description = 'Seed the minimal local demo dataset (local environment, notary_ppat_demo database only)';

    public function handle(DemoDataSeeder $seeder): int
    {
        $this->newLine();
        $this->info('Demo dataset seed');
        $this->newLine();

        $guard = new DemoEnvironmentGuard(DB::connection(), app()->environment());

        try {
            $guard->assertSafe();
        } catch (UnsafeDemoEnvironment $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $result = $seeder->seed();
        } catch (DemoDatasetAlreadyExists $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (DemoRolePrerequisiteMissing $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['', 'Count'], [
            ['Office code', $result->officeCode],
            ['Users', $result->users],
            ['Parties', $result->parties],
            ['Projects', $result->projects],
            ['Matters', $result->matters],
            ['Documents', $result->documents],
            ['Tasks', $result->tasks],
        ]);
        $this->newLine();
        $this->info('Demo dataset created.');
        $this->newLine();

        return self::SUCCESS;
    }
}
