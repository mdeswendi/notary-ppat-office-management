<?php

namespace App\Domains\Project\Enums;

/**
 * How urgent a Project is, as the office judges it.
 *
 * **Where these values come from, stated plainly because it matters.**
 * `03_DATABASE_ERD.md` lists a `priority` column on `projects` (section 7),
 * `matters` (section 9), and `tasks` (section 23), and defines the vocabulary
 * exactly once — under tasks:
 *
 * ```text
 * LOW  NORMAL  HIGH  URGENT
 * ```
 *
 * M3.1 reads that as one shared vocabulary rather than a task-only one, because
 * the document names the same column three times and gives one set of values. No
 * competing vocabulary appears anywhere in the repository.
 *
 * That is a **transcription with a named source, not an invention** — but it is
 * also the one Project field whose vocabulary was not written next to the column
 * it governs, so it is flagged here rather than left to be discovered. If the
 * office needs different Project priorities, that is a domain decision and a
 * forward migration, not a silent edit.
 *
 * Operational only. Priority carries no legal meaning, gates no workflow, and
 * orders nothing automatically.
 */
enum ProjectPriority: string
{
    case LOW = 'LOW';
    case NORMAL = 'NORMAL';
    case HIGH = 'HIGH';
    case URGENT = 'URGENT';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
