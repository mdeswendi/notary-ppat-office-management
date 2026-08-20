<?php

namespace App\Domains\Matter\Enums;

/**
 * The state of one stage inside a running Matter workflow (M4.7, D-104, D-112).
 *
 * Transcribed verbatim from `03_DATABASE_ERD.md` section 11, which both workflow
 * drafts list in their "Already-Established Structure" as **architectural facts,
 * not legal rules**. Nothing here was invented and nothing may be added: a sixth
 * status would be workflow content, which D-104 forbids.
 *
 * **Three of these are reachable in M4.7 and two are not**, and the gap is
 * recorded rather than hidden — the same shape M4.4 left for the unreachable
 * Matter statuses (D-109):
 *
 *   PENDING    the initial state of every stage but the first
 *   ACTIVE     exactly one per workflow; the current stage
 *   COMPLETED  set by moving on from a stage, or by completing the Matter
 *   SKIPPED    vocabulary only — nothing sets it
 *   BLOCKED    vocabulary only — nothing sets it
 *
 * `SKIPPED` stays unreachable deliberately. A forward jump leaves the stages it
 * passed as `PENDING`, because skipping is a decision somebody makes and moving
 * on is not that decision (D-112). Marking them skipped would infer an intent
 * from a navigation. `BLOCKED` needs a blocking rule to exist, and no canonical
 * document defines one.
 *
 * There is **no transition matrix**. M4 authorizes *who* may change a stage and
 * never encodes *which* stage may follow which (D-104).
 */
enum MatterStageStatus: string
{
    case PENDING = 'PENDING';
    case ACTIVE = 'ACTIVE';
    case COMPLETED = 'COMPLETED';
    case SKIPPED = 'SKIPPED';
    case BLOCKED = 'BLOCKED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Whether a stage in this state may be moved to.
     *
     * A stage that is finished, skipped, or blocked is not somewhere to go. This
     * is **not** a transition rule — it says nothing about which stage may follow
     * which, only that a destination must be open (D-104).
     */
    public function isSelectableAsTarget(): bool
    {
        return $this === self::PENDING || $this === self::ACTIVE;
    }
}
