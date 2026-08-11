<?php

namespace App\Domains\Authorization;

use App\Domains\Authorization\Enums\AccessSource;
use App\Domains\Authorization\Enums\DataScope;

/**
 * What one user may do with one permission, and across which records.
 *
 * The answer to "does this user hold `ppat.deeds.approve`, and at which
 * scopes" — deliberately *not* the answer to "may this user approve deed
 * 01JD…". That second question needs ownership fields, assignment
 * relationships, record state, and legal workflow rules, and belongs to the
 * domain Policies that will consume this object once those entities exist.
 *
 * Immutable, and constructed only through the named factories so one rule
 * always holds: **a denied result carries no scopes.** There is no way to build
 * an instance that is denied yet looks partially permissive.
 */
final class EffectiveAccess
{
    /**
     * @param  list<DataScope>  $scopes
     */
    private function __construct(
        public readonly bool $granted,
        public readonly array $scopes,
        public readonly AccessSource $source,
    ) {}

    /**
     * No usable access. Every fail-closed path in the resolver ends here.
     */
    public static function denied(AccessSource $source = AccessSource::NONE): self
    {
        return new self(false, [], $source);
    }

    /**
     * Granted through roles: the distinct union of every qualifying role's
     * scope for this permission (D-028), in canonical order.
     *
     * An empty set means no role qualified, which is a denial rather than a
     * grant with nothing in it — so the factory returns one, and there is no
     * way to end up holding a "granted" result with no reach.
     *
     * @param  iterable<DataScope>  $scopes
     */
    public static function fromRoles(iterable $scopes): self
    {
        $distinct = [];

        foreach ($scopes as $scope) {
            $distinct[$scope->value] = $scope;
        }

        if ($distinct === []) {
            return self::denied();
        }

        return new self(true, DataScope::orderCanonically($distinct), AccessSource::ROLE);
    }

    /**
     * Granted by an active ALLOW override. Its scope *replaces* whatever the
     * roles would have produced — it is never unioned with them (D-029).
     */
    public static function fromOverride(DataScope $scope): self
    {
        return new self(true, [$scope], AccessSource::OVERRIDE);
    }

    public function hasScope(DataScope $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * The scope names, in canonical order. For assertions and future
     * serialization; authorization decisions should use the enum cases.
     *
     * @return list<string>
     */
    public function scopeValues(): array
    {
        return array_map(fn (DataScope $scope): string => $scope->value, $this->scopes);
    }
}
