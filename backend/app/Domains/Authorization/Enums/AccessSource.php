<?php

namespace App\Domains\Authorization\Enums;

/**
 * Which rule produced an EffectiveAccess result.
 *
 * Diagnostic, not authoritative — `EffectiveAccess::$granted` and its scopes
 * are what decides anything. This exists so a caller (and a test) can tell
 * "denied because an override said so" apart from "denied because no role
 * granted it", which are very different administrative situations that look
 * identical from the outside.
 */
enum AccessSource: string
{
    /** Nothing granted it: not canonical, not in the database, or no qualifying role. */
    case NONE = 'NONE';

    /** Decided by role grants and their Data Scope metadata. */
    case ROLE = 'ROLE';

    /** Decided by an active user_permission_overrides row. */
    case OVERRIDE = 'OVERRIDE';
}
