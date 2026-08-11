<?php

namespace App\Domains\Identity;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The authoritative list of a user's server-side sessions.
 *
 * Sessions live in the `sessions` table (`SESSION_DRIVER=database`), so they can
 * be enumerated and revoked — which is what makes "sign out everywhere" and
 * "disabling an account ends its access" real rather than aspirational (D-074).
 *
 * **A raw session id is a credential.** Anyone holding one can forge the cookie,
 * so it never leaves the server. The API exposes a SHA-256 of it instead: stable
 * enough to name a row for revocation, useless for impersonation. Revocation
 * therefore matches on the digest rather than trusting client input.
 *
 * Also never exposed: the session payload (which contains the CSRF token and
 * whatever else the framework stored) and the cookie value itself.
 *
 * When the driver is not `database` this degrades honestly — an empty list
 * rather than a fabricated one. The test suite runs on the `array` driver by
 * default, and inventing rows to keep it happy would be inventing evidence.
 */
class SessionRegistry
{
    /**
     * The opaque identifier the API uses for a session.
     *
     * SHA-256 of the row's primary key. One-way, so it cannot be turned back
     * into a usable session id.
     */
    public function opaqueKey(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    public function isDatabaseDriven(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * Safe metadata for each of a user's sessions, newest activity first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forUser(User $user, ?Request $request = null): Collection
    {
        if (! $this->isDatabaseDriven()) {
            return collect();
        }

        $currentId = $request?->hasSession() ? $request->session()->getId() : null;

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session): array => [
                'key' => $this->opaqueKey($session->id),
                'current' => $currentId !== null && $session->id === $currentId,
                'ip_address' => $session->ip_address,
                // A short label, not the raw string: the full user agent is
                // fingerprinting detail nobody reading this screen needs.
                'device' => $this->describeDevice($session->user_agent),
                'last_active_at' => $session->last_activity
                    ? now()->setTimestamp((int) $session->last_activity)->toIso8601String()
                    : null,
            ])
            ->values();
    }

    /**
     * Revoke one of a user's sessions, named by its opaque key.
     *
     * Scoped to the user, so an opaque key belonging to somebody else matches
     * nothing. Returns whether a row was actually removed, letting the caller
     * answer 404 for an unknown or forged key rather than pretending it worked.
     */
    public function revokeByKey(User $user, string $opaqueKey): bool
    {
        if (! $this->isDatabaseDriven()) {
            return false;
        }

        $table = config('session.table', 'sessions');

        $sessionId = DB::table($table)
            ->where('user_id', $user->getKey())
            ->pluck('id')
            ->first(fn (string $id): bool => hash_equals($this->opaqueKey($id), $opaqueKey));

        if ($sessionId === null) {
            return false;
        }

        return DB::table($table)->where('id', $sessionId)->delete() > 0;
    }

    /**
     * Revoke every session for a user, optionally sparing one.
     *
     * `$exceptSessionId` keeps the caller signed in when they are ending their
     * *other* sessions. Administrative revocation and account disabling pass
     * null, ending everything.
     */
    public function revokeAll(User $user, ?string $exceptSessionId = null): int
    {
        if (! $this->isDatabaseDriven()) {
            return 0;
        }

        $query = DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey());

        if ($exceptSessionId !== null) {
            $query->where('id', '!=', $exceptSessionId);
        }

        return $query->delete();
    }

    /**
     * A short, human-readable device label.
     *
     * Deliberately coarse. The goal is "was that me?", which a browser and
     * platform answer; a full user-agent string in the interface is noise that
     * also happens to be a fingerprint.
     */
    private function describeDevice(?string $userAgent): ?string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return null;
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };

        $platform = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return match (true) {
            $browser !== null && $platform !== null => "{$browser} — {$platform}",
            $browser !== null => $browser,
            $platform !== null => $platform,
            default => null,
        };
    }
}
