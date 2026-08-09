<?php

namespace App\Domains\Authorization\Enums;

/**
 * How far a permission reaches across records.
 *
 * The five canonical values from `02_MENU_AND_PERMISSIONS.md` section 22 and
 * `07_SECURITY_RULES.md` section 10. A user may hold a permission and still be
 * restricted by scope — the permission answers *what*, the scope answers
 * *which records*.
 *
 * **These are predicates, not privilege levels.** There is deliberately no
 * `widest()`, `max()`, `rank()`, or `higherThan()` anywhere in this class, and
 * a test asserts their absence. `OWN` and `ASSIGNED` describe different
 * relationships to a record, not different amounts of power: a notary may own a
 * matter without being assigned to it, or the reverse. Collapsing a user's
 * scopes to the "widest" one would silently discard access an administrator
 * actually granted (D-028).
 *
 * Meanings, per `07_SECURITY_RULES.md` section 10:
 *
 *   OWN       resource-specific ownership relation; the owning field is defined
 *             by that resource's own Policy
 *   ASSIGNED  resource-specific assignment / PIC relation; likewise Policy-defined
 *   TEAM      reserved vocabulary — no Team entity exists yet
 *   OFFICE    the record's office_id matches the user's primary office
 *   ALL       no office restriction within the deployment's Organization
 *
 * `ALL` is a Data Scope and nothing more. It does not bypass permission checks,
 * record state, FINALIZED / LOCKED rules, sensitive-data permissions, legal
 * workflow, or business validation.
 */
enum DataScope: string
{
    case OWN = 'OWN';
    case ASSIGNED = 'ASSIGNED';
    case TEAM = 'TEAM';
    case OFFICE = 'OFFICE';
    case ALL = 'ALL';

    /**
     * Sorts scopes into the order the documentation lists them.
     *
     * Presentation only — it exists so resolver output, assertions, and any
     * future serialization are stable regardless of which role happened to be
     * read first. Declaration order carries **no** authorization meaning, and
     * nothing may treat a later case as stronger than an earlier one.
     *
     * @param  iterable<DataScope>  $scopes
     * @return list<DataScope>
     */
    public static function orderCanonically(iterable $scopes): array
    {
        $positions = array_flip(array_column(self::cases(), 'value'));

        $ordered = is_array($scopes) ? array_values($scopes) : iterator_to_array($scopes, false);

        usort($ordered, fn (self $a, self $b): int => $positions[$a->value] <=> $positions[$b->value]);

        return $ordered;
    }
}
