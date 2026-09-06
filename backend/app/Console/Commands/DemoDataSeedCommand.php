<?php

namespace App\Console\Commands;

use App\Domains\Demo\DemoDataSeeder;
use App\Domains\Demo\DemoEnvironmentGuard;
use App\Domains\Demo\Exceptions\DemoDatasetAlreadyExists;
use App\Domains\Demo\Exceptions\DemoPrimaryActorPasswordInvalid;
use App\Domains\Demo\Exceptions\DemoRolePrerequisiteMissing;
use App\Domains\Demo\Exceptions\UnsafeDemoEnvironment;
use App\Domains\Identity\PasswordRules;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

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
 * **The guard runs before anything else** — before the idempotency check,
 * before the password prompt, before any transaction, before any query
 * beyond what the guard itself performs. A wrong environment or database is
 * refused here, every time, regardless of what state the target database
 * happens to be in, and regardless of whether a terminal is even attached.
 *
 * Refuse-not-overwrite: a dataset that already exists ({@see
 * DemoDataSeeder::alreadySeeded()}) is reported and left untouched **before
 * the password prompt is ever reached** — an operator should never be asked
 * to type and confirm a password for a run that was always going to refuse.
 * Exiting is always a **non-zero** status here — nothing was done, and a
 * caller (a script, CI, an operator relying on `$?`) must be able to tell
 * that apart from a run that actually seeded anything. Nothing here builds a
 * `demo:reset`; removing an existing dataset is its own, separately-scoped
 * decision.
 *
 * **The primary demo user's password is collected here, and nowhere else.**
 * {@see collectPrimaryActorPassword()} asks for it at a hidden interactive
 * prompt, confirmed by a second hidden prompt, validated by the same {@see
 * PasswordRules} every other password-setting surface in this application
 * uses (D-051, D-070) — the same mechanism, and the same rule,
 * `BootstrapDeploymentCommand::collectInput()` uses for the first
 * administrator (D-060). `DemoDataSeeder` never touches the console, an
 * environment variable, or a config value for this — it receives the
 * validated password as a plain parameter to {@see DemoDataSeeder::seed()}.
 * A non-interactive run (`--no-interaction`, or no terminal attached) is
 * refused before the prompt is attempted at all; there is no default and no
 * fallback.
 *
 * The seeder's own role prerequisite (see {@see DemoDataSeeder::seed()}) is
 * refused the same way as the two checks above: reported, left untouched,
 * non-zero exit.
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

        if ($seeder->alreadySeeded()) {
            $this->error(DemoDatasetAlreadyExists::markedBy(DemoDataSeeder::OFFICE_CODE)->getMessage());

            return self::FAILURE;
        }

        try {
            $password = $this->collectPrimaryActorPassword(
                interactive: $this->input->isInteractive(),
                ask: fn (string $question): string => (string) $this->secret($question),
            );
        } catch (DemoPrimaryActorPasswordInvalid $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $result = $seeder->seed($password);
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
            ['Notary Deeds', $result->notaryDeeds],
            ['Notary Minuta', $result->notaryMinutas],
        ]);
        $this->newLine();
        $this->info('Demo dataset created.');
        $this->info('The primary demo user ('.DemoDataSeeder::PRIMARY_ACTOR_EMAIL.') can sign in with the password just entered.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Collects and validates the primary demo user's password at a hidden
     * interactive prompt, confirmed by a second hidden prompt — the same
     * {@see PasswordRules} rule every other password-setting surface in this
     * application validates against, applied the same way
     * `BootstrapDeploymentCommand::collectInput()` applies it to the first
     * administrator's password.
     *
     * `$ask` is the only way this method reaches the console, and it is a
     * plain closure rather than `$this->secret(...)` called directly — which
     * is what makes this method a pure function of `$interactive` and `$ask`:
     * testable with a canned closure and no console, Artisan, or database
     * attached at all, entirely separately from {@see DemoEnvironmentGuard}.
     *
     * Every rejection path throws {@see DemoPrimaryActorPasswordInvalid},
     * which never carries the password, its confirmation, or a validator
     * message that echoes either back (D-051).
     *
     * @param  Closure(string): string  $ask
     *
     * @throws DemoPrimaryActorPasswordInvalid
     */
    private function collectPrimaryActorPassword(bool $interactive, Closure $ask): string
    {
        if (! $interactive) {
            throw DemoPrimaryActorPasswordInvalid::notInteractive();
        }

        try {
            $password = $ask('Primary demo user password ('.DemoDataSeeder::PRIMARY_ACTOR_EMAIL.')');
            $confirmation = $ask('Confirm primary demo user password');
        } catch (DemoPrimaryActorPasswordInvalid $e) {
            throw $e;
        } catch (Throwable) {
            // A cancelled prompt (Ctrl+D / closed input) surfaces from
            // Symfony's QuestionHelper as its own runtime exception rather
            // than an empty answer — caught here so it fails the same clean,
            // non-zero way as every other rejection in this method, instead
            // of an uncaught stack trace.
            throw DemoPrimaryActorPasswordInvalid::unavailable();
        }

        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => PasswordRules::forNewPassword()],
        );

        if ($validator->fails()) {
            throw DemoPrimaryActorPasswordInvalid::invalid($validator->errors()->get('password'));
        }

        return $password;
    }
}
