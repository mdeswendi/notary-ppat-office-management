<?php

namespace App\Domains\Demo\Exceptions;

use RuntimeException;

/**
 * No role exists yet — or the role that exists cannot pass the product's own
 * Policy checks yet — for the demo dataset's primary actor to hold.
 *
 * `demo:seed` never creates a Role and never grants a permission itself.
 * Doing either silently would fabricate authorization state nobody
 * configured, which is exactly what `CLAUDE.md` section 24 and
 * `DefaultRoleRegistry` (D-045, D-057) forbid: a role, and the canonical
 * permissions it needs to actually grant anything, can only come from
 * `permissions:sync` and `app:bootstrap` (or equivalent manual Role
 * Management configuration) — both deliberately outside this command's
 * reach. Finding neither in place means that prerequisite has not run yet,
 * and `demo:seed` refuses rather than inventing a shortcut around it.
 */
class DemoRolePrerequisiteMissing extends RuntimeException
{
    public static function roleNotFound(string $roleName): self
    {
        return new self(
            "Refusing to proceed: no \"{$roleName}\" role exists yet. demo:seed does not create roles "
            .'or grant permissions itself. Run `php artisan app:bootstrap` (or otherwise provision the '
            .'canonical roles and permissions) first, so at least one demo user can be assigned a role that '
            .'the product\'s own authorization policy actually honours.'
        );
    }

    public static function policyUnreachable(string $roleName, string $surface): self
    {
        return new self(
            "Refusing to proceed: the \"{$roleName}\" role exists but does not currently grant access to "
            .'"'.$surface.'" under the product\'s own authorization policy. demo:seed does not grant '
            .'permissions itself. Run `php artisan permissions:sync` and configure that role\'s permissions '
            .'(via `php artisan app:bootstrap` on a fresh deployment, or Role Management otherwise) first.'
        );
    }
}
