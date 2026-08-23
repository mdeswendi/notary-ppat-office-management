<?php

namespace App\Domains\Document\Enums;

/**
 * The lifecycle state of a Document (M5.1, D-116).
 *
 * Transcribed verbatim from `03_DATABASE_ERD.md` section 13. Seven values, and no
 * eighth may be added: a status the canonical list does not name would be a
 * lifecycle rule invented here.
 *
 * **M5.1 reaches only `DRAFT`.** There is no HTTP surface yet, so nothing sets
 * anything else — the same honest position M4.2 held for `MatterStatus` before
 * M4.4 gave it routes. The gap is recorded rather than implied, and the milestone
 * that builds verification and archiving is the one that makes `UNDER_REVIEW`,
 * `VERIFIED` and `ARCHIVED` reachable.
 *
 * **There is no transition matrix and M5 invents none.** Which status may follow
 * which is an operational rule no canonical document defines; `15_M5_DOCUMENT_TASK_ARCHITECTURE.md`
 * section 10.2 records that M5 authorizes *who* may act, never *which* change is
 * legal. `FINAL` and `VOID` in particular carry legal weight that no document in
 * this repository defines, and guessing at it is what `CLAUDE.md` section 62
 * prohibits.
 */
enum DocumentStatus: string
{
    case DRAFT = 'DRAFT';
    case RECEIVED = 'RECEIVED';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case VERIFIED = 'VERIFIED';
    case FINAL = 'FINAL';
    case ARCHIVED = 'ARCHIVED';
    case VOID = 'VOID';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
