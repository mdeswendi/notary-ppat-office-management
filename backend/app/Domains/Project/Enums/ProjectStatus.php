<?php

namespace App\Domains\Project\Enums;

/**
 * The business status of a Project.
 *
 * Transcribed exactly from `03_DATABASE_ERD.md` section 7 — stable machine
 * codes, never translated labels (CLAUDE.md section 12). Nothing is added and
 * nothing is renamed.
 *
 * **This is business status, and only that.** It is not a workflow stage, which
 * does not exist in M3 at all and belongs to M4, and it is not the archive state
 * of the record, which is `deleted_at` (D-093). `CLAUDE.md` section 18 and
 * `08_NOTARY_WORKFLOW.md` section 4 both insist the three stay apart, and the
 * awkward part is named rather than smoothed over: `ARCHIVED` here and a
 * soft-deleted row are **different states with similar names**.
 *
 * **No transition matrix exists, deliberately** (D-091). Which status may follow
 * which is an operational rule no canonical document defines, so M3 authorizes
 * *who* may change status through `projects.change_status` and never encodes
 * *which* changes are legal. There is no `canTransitionTo()` here, and adding one
 * from memory would be the failure CLAUDE.md section 62 prohibits, one domain
 * removed from the legal rules it names.
 */
enum ProjectStatus: string
{
    case OPEN = 'OPEN';
    case IN_PROGRESS = 'IN_PROGRESS';
    case WAITING = 'WAITING';
    case ON_HOLD = 'ON_HOLD';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case ARCHIVED = 'ARCHIVED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
