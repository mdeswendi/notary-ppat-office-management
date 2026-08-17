<?php

namespace App\Domains\Authorization;

/**
 * The nine role names a fresh deployment starts with.
 *
 * Transcribed from `02_MENU_AND_PERMISSIONS.md` section 4. Note
 * **`ARCHIVE_STAFF`**, not `ARCHIVE` — the document is explicit and the
 * shortened form has been mistaken for it before.
 *
 * **This is initial configuration, never authorization logic** (D-045). Nothing
 * in the application may branch on these names; a test greps for exactly that.
 * An office may rename them, delete the unassigned ones, or create its own, and
 * nothing resynchronizes them back — the bootstrap command runs once, and there
 * is no recurring job that would resurrect a role somebody deliberately removed
 * (D-058).
 *
 * Only `SUPER_ADMIN` receives permissions at bootstrap, and it receives every
 * canonical one explicitly (D-057). The other eight are created empty: the
 * high-level matrix in section 5 grades modules as F / V / A / —, which cannot
 * be translated into the canonical permission codes and their Data Scopes
 * without inventing the mapping. They are shells to configure through the Permission
 * Matrix, not guesses.
 */
class DefaultRoleRegistry
{
    /**
     * The role that receives the full canonical permission set at bootstrap.
     */
    public const ADMINISTRATOR = 'SUPER_ADMIN';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::ADMINISTRATOR,
            'PRINCIPAL',
            'OFFICE_MANAGER',
            'NOTARY_STAFF',
            'PPAT_STAFF',
            'FRONT_OFFICE',
            'FINANCE',
            'ARCHIVE_STAFF',
            'AUDITOR',
        ];
    }

    /**
     * The eight created without any permission grant.
     *
     * @return array<int, string>
     */
    public static function withoutPermissions(): array
    {
        return array_values(array_filter(
            self::all(),
            fn (string $name): bool => $name !== self::ADMINISTRATOR,
        ));
    }
}
