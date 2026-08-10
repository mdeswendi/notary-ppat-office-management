<?php

namespace App\Domains\Authorization;

/**
 * What a permission synchronization did.
 *
 * `unmanaged` names rows present in the table that the registry does not
 * declare. They are preserved rather than pruned (D-036) — the sync cannot tell
 * an obsolete leftover from something an operator added deliberately, and a role
 * may already depend on it. Reporting them is how a human gets to decide.
 */
final class SyncCanonicalPermissionsResult
{
    /**
     * @param  array<int, string>  $canonical
     * @param  array<int, string>  $created
     * @param  array<int, string>  $unmanaged
     */
    public function __construct(
        public readonly string $guard,
        public readonly array $canonical,
        public readonly array $created,
        public readonly array $unmanaged,
    ) {}

    public function alreadyPresent(): int
    {
        return count($this->canonical) - count($this->created);
    }
}
