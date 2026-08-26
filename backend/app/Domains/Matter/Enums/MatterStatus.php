<?php

namespace App\Domains\Matter\Enums;

/**
 * The business status of a Matter (M4.2).
 *
 * Transcribed exactly from `03_DATABASE_ERD.md` section 9 — stable machine
 * codes, never translated labels (CLAUDE.md section 12). Nothing is added and
 * nothing is renamed.
 *
 * **This is business status, and only that.** It is not a workflow stage, which
 * belongs to M4.7 and stays a separate concept for good (`CLAUDE.md` section 18,
 * and section 4 of both workflow drafts). And it is not the persistence state of
 * the record: `matters.deleted_at` exists as reserved schema capability with no
 * lifecycle reaching it, so `ARCHIVED` here and a soft-deleted row are
 * **different states with unfortunately similar names** — the awkwardness D-093
 * named for Project, restated because Matter carries the same trap and has no
 * restore path to recover from a wrong answer (D-102).
 *
 * **No transition matrix exists, deliberately** (D-102, following D-091). Which
 * status may follow which is an operational rule no canonical document defines,
 * so M4 authorizes *who* may change, complete, or cancel a Matter — three
 * separate canonical capabilities — and never encodes *which* changes are legal.
 * There is no `canTransitionTo()` here, and adding one from memory would be the
 * failure `CLAUDE.md` section 62 prohibits, one domain removed from the legal
 * rules it names.
 *
 * M4.2 defines the vocabulary. `OPEN` is the initial status a create path will
 * set (D-107), but no database default encodes it: the application decides an
 * initial state, not the schema.
 */
enum MatterStatus: string
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

    /**
     * Matters being worked on right now (M8.1).
     *
     * **`WAITING` and `ON_HOLD` are deliberately excluded.** They are live rather
     * than finished, but they are not being *advanced* — something is blocking
     * them, and the Dashboard surfaces them in the "needs attention" panel
     * instead. Between them the two panels partition the unfinished work rather
     * than counting a stalled Matter twice, once as progress and once as a
     * problem.
     *
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return [self::OPEN->value, self::IN_PROGRESS->value];
    }
}
