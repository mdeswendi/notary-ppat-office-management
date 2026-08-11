<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account-security state: pending email change and TOTP two-factor.
 *
 * Columns on `users` rather than a separate table. Every field here is a
 * property of exactly one account, always read with that account, and never
 * queried on its own — a side table would add a join to every security check
 * and buy nothing (D-076).
 *
 * Nothing framework-owned is duplicated. Laravel's `password_reset_tokens`
 * already stores hashed, expiring reset tokens, and the `sessions` table is
 * already the authoritative server-side session store, so M1.9 reuses both
 * rather than inventing a second of either.
 *
 * Every column added here is hidden from serialization on the model. None of
 * them may ever reach `/api/v1/me`, `/api/v1/profile`, or any user-management
 * response.
 *
 * ## Pending email change
 *
 * The current `email` stays authoritative until the new address proves it can
 * receive mail (D-073). Storing the requested address separately is what makes
 * that possible: a typo or a hijacked form never costs somebody their login.
 *
 * Only a SHA-256 of the verification token is stored — the raw token exists in
 * the emailed link and nowhere else, so a database read cannot complete
 * somebody else's email change.
 *
 * ## Two-factor
 *
 * The TOTP secret is encrypted at rest through the model's `encrypted` cast, so
 * a database dump does not hand over the ability to mint valid codes.
 *
 * `two_factor_confirmed_at` is what makes MFA real: a secret alone means an
 * enrolment somebody started and abandoned, and treating that as active would
 * lock people out of their own accounts (D-012 of this milestone's design,
 * recorded as D-076).
 *
 * Recovery codes are stored as a JSON array of hashes, never in raw form. They
 * are shown once at generation and are unrecoverable afterwards by design —
 * including to an administrator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Pending email change. The address is readable because the owner
            // must be shown what is pending; the token is not.
            $table->string('pending_email')->nullable();
            $table->string('pending_email_token')->nullable();
            $table->timestamp('pending_email_requested_at')->nullable();

            // TOTP. Encrypted by the model cast, so the column is text rather
            // than a fixed-width secret.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            // An enrolment that is started and never confirmed expires instead
            // of lingering as a half-armed credential.
            $table->timestamp('two_factor_setup_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'pending_email',
                'pending_email_token',
                'pending_email_requested_at',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_setup_expires_at',
            ]);
        });
    }
};
