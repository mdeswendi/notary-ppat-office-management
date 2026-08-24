<?php

namespace App\Domains\Document\Enums;

use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterDocument;
use App\Models\Party;
use App\Models\PartyDocument;
use App\Models\Project;
use App\Models\ProjectDocument;

/**
 * What a Document may be attached to (M5.3, D-118).
 *
 * **Three of seven, and the other four are blocked rather than deferred.**
 * `03_DATABASE_ERD.md` section 14 recommends seven junction tables; M5.1 built
 * the three whose targets exist and stubbed none of the rest (D-115). Nothing has
 * changed since:
 *
 * ```text
 * party_documents                parties              built
 * project_documents              projects             built
 * matter_documents               matters              built
 * property_documents             properties           blocked — batch 8,  M7
 * notary_deed_documents          notary_deeds         blocked — batch 9,  M6
 * ppat_deed_documents            ppat_deeds           blocked — batch 10, M7
 * matter_requirement_documents   matter_requirements  blocked — deferred with the
 *                                                     legal content that justifies it
 * ```
 *
 * A composite foreign key cannot point at a table that does not exist, so the
 * blocked four are not a matter of preference: their migrations would fail. They
 * are named here rather than left out so that adding one later is **adding a case
 * and a migration, not redesigning this enum** — the extension point is visible.
 *
 * **The Matter case carries no domain.** A Matter is reached under
 * `notary.matters.view` or `ppat.matters.view` depending on its own `domain`
 * column, and that selection belongs to the authorization step rather than to
 * this vocabulary — see `DocumentRelationController`. Splitting this enum into
 * `NOTARY_MATTER` and `PPAT_MATTER` would put the permission namespace under the
 * caller's control, which is exactly what D-101 forbids.
 */
enum DocumentRelationType: string
{
    case PARTY = 'party';
    case PROJECT = 'project';
    case MATTER = 'matter';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The model this type attaches to.
     *
     * @return class-string<Party|Project|Matter>
     */
    public function target(): string
    {
        return match ($this) {
            self::PARTY => Party::class,
            self::PROJECT => Project::class,
            self::MATTER => Matter::class,
        };
    }

    /**
     * The junction model that records the attachment.
     *
     * @return class-string<PartyDocument|ProjectDocument|MatterDocument>
     */
    public function junction(): string
    {
        return match ($this) {
            self::PARTY => PartyDocument::class,
            self::PROJECT => ProjectDocument::class,
            self::MATTER => MatterDocument::class,
        };
    }

    /**
     * The junction column naming the attached record.
     */
    public function foreignKey(): string
    {
        return match ($this) {
            self::PARTY => 'party_id',
            self::PROJECT => 'project_id',
            self::MATTER => 'matter_id',
        };
    }

    /**
     * The relation on {@see Document} that reads this type.
     */
    public function relation(): string
    {
        return match ($this) {
            self::PARTY => 'parties',
            self::PROJECT => 'projects',
            self::MATTER => 'matters',
        };
    }

    /**
     * The capability that reaches the attached record.
     *
     * **Matter is deliberately absent**, and its absence is the point: a Matter's
     * namespace depends on the row's own `domain`, so it cannot be answered by a
     * constant. The caller resolves it from the record — never from the request.
     */
    public function viewPermission(): ?string
    {
        return match ($this) {
            self::PARTY => 'parties.view',
            self::PROJECT => 'projects.view',
            self::MATTER => null,
        };
    }
}
