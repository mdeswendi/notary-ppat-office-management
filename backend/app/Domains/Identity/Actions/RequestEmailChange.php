<?php

namespace App\Domains\Identity\Actions;

use App\Models\User;
use App\Notifications\VerifyPendingEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Ask to move the account to a different email address.
 *
 * **The current address does not change** (D-073). It stays authoritative until
 * the new one proves it can receive mail, so a typo or a hijacked form never
 * costs somebody the ability to sign in — which for an email-as-username system
 * would mean losing the account outright.
 *
 * The verification link goes to the **new** address, because the question being
 * answered is "does this person control that mailbox". The current password was
 * already required by the Form Request, which answers "is this the account
 * owner".
 *
 * Only a SHA-256 of the token is stored. The raw token exists in the emailed
 * link and nowhere else, so reading the database cannot complete somebody
 * else's change.
 *
 * Requesting again replaces any earlier pending request, so a mistyped address
 * is corrected by simply asking again.
 */
class RequestEmailChange
{
    /**
     * How long a pending verification stays valid.
     */
    public const TTL_MINUTES = 60;

    public function handle(User $user, string $newEmail): void
    {
        $rawToken = Str::random(64);

        DB::transaction(function () use ($user, $newEmail, $rawToken): void {
            $user->pending_email = $newEmail;
            $user->pending_email_token = hash('sha256', $rawToken);
            $user->pending_email_requested_at = now();
            $user->save();
        });

        // Routed explicitly to the new address. `$user->notify()` would deliver
        // to the model's own `email` — the *old* one — which is precisely the
        // mailbox this message must not reach.
        Notification::route('mail', $newEmail)->notify(
            (new VerifyPendingEmail($rawToken, $newEmail))->locale($user->preferredLocale()),
        );

        // The raw token is never logged — only that a request happened.
        Log::info('EMAIL_CHANGE_REQUESTED', ['user_id' => $user->getKey()]);
    }
}
