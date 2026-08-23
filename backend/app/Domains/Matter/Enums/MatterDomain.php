<?php

namespace App\Domains\Matter\Enums;

/**
 * Which business domain a Matter belongs to (M4.2).
 *
 * Transcribed exactly from `03_DATABASE_ERD.md` section 9 — stable machine
 * codes, never translated labels (CLAUDE.md section 12).
 *
 * **A Matter belongs to exactly one domain.** No `BOTH`, no alias, no
 * domain-neutral case. The canonical registry splits the Matter capability
 * surface into `notary.matters.*` and `ppat.matters.*` with no generic namespace,
 * and a Matter belonging to both could not be authorized without deciding which
 * of the two governs it.
 *
 * **Deliberately its own enum rather than a reuse of `ServiceTypeDomain`**, even
 * though the two value lists are identical. Matter is not a master-data concept,
 * and naming its domain after the Service Type type would make the Matter
 * aggregate depend on a master-data implementation detail — backwards, given that
 * `service_type_id` is nullable and a Matter may have no Service Type at all. The
 * repository's convention is domain-namespaced enums (`ProjectStatus`,
 * `PartyType`, `ServiceTypeDomain`), and this follows it.
 *
 * The duplication is not left to drift: a parity test asserts the two lists stay
 * identical, so a future divergence has to be deliberate rather than accidental.
 *
 * {@see permissionNamespace()} is the one place the domain becomes a permission
 * prefix. It is called with a domain supplied by the **caller's route context**,
 * never one read from a persisted row — D-101 forbids a Policy choosing its
 * namespace from row data, and the Matter Policy is where that rule is enforced.
 */
enum MatterDomain: string
{
    case NOTARY = 'NOTARY';
    case PPAT = 'PPAT';

    /**
     * The canonical permission namespace this domain authorizes through.
     *
     * ```text
     * NOTARY  ->  notary.matters.*
     * PPAT    ->  ppat.matters.*
     * ```
     *
     * There is no generic `matters.*` namespace and none may be invented.
     */
    public function permissionNamespace(): string
    {
        return match ($this) {
            self::NOTARY => 'notary.matters',
            self::PPAT => 'ppat.matters',
        };
    }

    /**
     * The canonical permission code for one Matter capability in this domain.
     */
    public function permission(string $capability): string
    {
        return $this->permissionNamespace().'.'.$capability;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
