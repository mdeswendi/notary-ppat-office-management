<?php

namespace App\Domains\MasterData\Enums;

/**
 * Which business domain a Service Type belongs to.
 *
 * Transcribed exactly from `03_DATABASE_ERD.md` section 8 — stable machine
 * codes, never translated labels (CLAUDE.md section 12). Nothing is added and
 * nothing is renamed.
 *
 * **A Service Type belongs to exactly one domain.** There is no `BOTH`, no
 * alias, and no domain-neutral case. The canonical registry splits the Matter
 * capability surface into `notary.matters.*` and `ppat.matters.*` with no generic
 * namespace, and D-101 makes the domain a property of the route; a service that
 * belonged to both could not be offered to either surface without deciding which
 * permission governs it. An office needing the same service in both domains
 * records two entries.
 *
 * **This vocabulary is shared with `matters.domain` (D-102), but the enum is
 * not.** M4.2 decides for itself whether to reuse this type or declare its own —
 * naming a Service Type enum as though it already governed Matter would be a
 * dependency M4.1 has no authority to create. The two lists must agree; that they
 * do is worth a test when Matter exists.
 *
 * Notary and PPAT are separate business domains sharing common infrastructure
 * (CLAUDE.md section 16). Nothing here implies a legal rule about either: the
 * *content* of each domain's catalogue is unvalidated and deliberately empty
 * (D-102).
 */
enum ServiceTypeDomain: string
{
    case NOTARY = 'NOTARY';
    case PPAT = 'PPAT';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
