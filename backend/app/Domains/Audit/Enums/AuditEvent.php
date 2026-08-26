<?php

namespace App\Domains\Audit\Enums;

use App\Domains\Activity\Enums\ActivityType;

/**
 * What kind of thing an audit row records (M8.1, D-123).
 *
 * **Not a transcription.** `03_DATABASE_ERD.md` section 25 names the `event`
 * column and defines no vocabulary for it, unlike `matters.status` or
 * `tasks.status` where a canonical list exists. So this is the application's list,
 * and it is deliberately generic: seven verbs that describe *shapes of change*
 * rather than one constant per resource.
 *
 * The resource-specific vocabulary — `DEED_APPROVED`, `TASK_COMPLETED` and the
 * rest — belongs to {@see ActivityType}, which is what
 * users read. The two lists are different on purpose:
 *
 * ```text
 * audit_logs.event       CREATED  UPDATED  STATUS_CHANGED  ...   +  old/new values
 * activities.activity_type  DEED_APPROVED  TASK_COMPLETED  ...   +  description_key
 * ```
 *
 * An auditor asks *"what changed, and who did it"*; a user asks *"what happened"*.
 * A single deed approval writes one row to each, and neither row is redundant with
 * the other.
 *
 * **No CHECK constraint backs this enum**, because freezing a vocabulary the ERD
 * does not define would make every future milestone's new event a migration. The
 * same reasoning M7.1 applied to `ppat_warkah_items.status`.
 */
enum AuditEvent: string
{
    case CREATED = 'CREATED';
    case UPDATED = 'UPDATED';
    case DELETED = 'DELETED';
    case STATUS_CHANGED = 'STATUS_CHANGED';
    case LOGIN = 'LOGIN';
    case LOGOUT = 'LOGOUT';

    /**
     * Somebody read something the interface normally masks or withholds.
     *
     * The event D-115 exists for. Written when a NIK or NPWP is revealed, and
     * when a sensitive document is downloaded — recording the subject's primary
     * key and the actor, and **never the value that was revealed**.
     */
    case SENSITIVE_ACCESS = 'SENSITIVE_ACCESS';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
