<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The state of your own account security, as the `/security` page shows it.
 *
 * Everything here is a *fact about* a secret, never a secret. Whether two-factor
 * is on, when it was confirmed, how many recovery codes are left, which address
 * is awaiting confirmation. Nothing that could be replayed:
 *
 * - no `two_factor_secret` and no provisioning URI (those exist only in the
 *   enrolment response, once);
 * - no recovery codes, only a count;
 * - no `pending_email_token`;
 * - no session identifiers beyond the opaque keys `SessionRegistry` produces.
 *
 * The attribute list is explicit rather than a model dump, and the model hides
 * those columns as well (D-076). Two independent defences, because a resource
 * that leaks a TOTP secret leaks it to the log, the browser cache, and every
 * proxy in between.
 *
 * @mixin User
 */
class SecurityOverviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'email' => $this->email,

            // Present only while a change is awaiting confirmation. Shown to the
            // account owner so a request they did not make is visible to them —
            // that is the point of not switching the address immediately.
            'pending_email' => $this->pending_email,
            'pending_email_requested_at' => $this->pending_email_requested_at?->toIso8601String(),

            'two_factor_enabled' => $this->resource->hasConfirmedTwoFactor(),
            'two_factor_confirmed_at' => $this->two_factor_confirmed_at?->toIso8601String(),

            // A count, never the codes. Useful because running low is the
            // warning that matters, and useless to anybody who reads it.
            'recovery_codes_remaining' => $this->resource->hasConfirmedTwoFactor()
                ? count($this->two_factor_recovery_codes ?? [])
                : 0,

            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
