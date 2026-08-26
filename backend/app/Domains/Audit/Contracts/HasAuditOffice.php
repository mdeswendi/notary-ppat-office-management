<?php

namespace App\Domains\Audit\Contracts;

/**
 * A record whose Office is not on the record itself (M8.1).
 *
 * Most auditable models carry `office_id` directly and need none of this.
 * `Individual` and `Company` do not: their Office lives on the parent `Party`,
 * because M2 put identity on the subtype and ownership on the Party.
 *
 * **Without this, an audit row for a NIK reveal would be filed against the
 * actor's Office rather than the record's.** For the ordinary case those are the
 * same, so the bug would be invisible — until an actor holding Data Scope `ALL`
 * revealed an identifier belonging to another Office, and the row landed where
 * the people entitled to read it would never look for it.
 *
 * Implemented explicitly rather than inferred by walking relations, so the extra
 * query is a decision each model makes rather than a surprise the logger springs.
 */
interface HasAuditOffice
{
    /**
     * The Office this record belongs to, for audit filing.
     */
    public function auditOfficeId(): ?string;
}
