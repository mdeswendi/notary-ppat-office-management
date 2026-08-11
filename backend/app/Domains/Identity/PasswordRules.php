<?php

namespace App\Domains\Identity;

use Illuminate\Validation\Rules\Password;

/**
 * One password rule for every place a password is set.
 *
 * User creation, deployment bootstrap, self-service change, and reset
 * completion all use this. Four copies of `Password::default()` would look
 * identical right up to the day one of them was tightened and the others were
 * not, leaving the weakest path as the real policy (D-070).
 *
 * The rule is Laravel's own default — a reasonable minimum length — plus a
 * check against known-compromised passwords, which `07_SECURITY_RULES.md`
 * section 4 asks for. No arbitrary "one uppercase, one symbol, one digit"
 * requirement: those push people toward `Password1!` and predictable
 * substitutions without adding real strength.
 *
 * The compromised-password check calls the Have I Been Pwned range API, so it
 * is enabled only outside the test environment — a network call inside the suite
 * would make it slow and flaky, which is how a useful check ends up being
 * disabled entirely.
 */
class PasswordRules
{
    /**
     * @return array<int, mixed>
     */
    public static function forNewPassword(): array
    {
        return ['required', 'confirmed', self::rule()];
    }

    public static function rule(): Password
    {
        $rule = Password::default();

        if (! app()->runningUnitTests()) {
            // Laravel's verifier treats an unreachable API as "not compromised",
            // so an outage cannot block somebody from changing their password.
            // That is the right trade here: this is a safety net, not the
            // policy itself.
            $rule = $rule->uncompromised();
        }

        return $rule;
    }
}
