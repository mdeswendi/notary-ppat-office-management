<?php

namespace App\Domains\Authorization\Enums;

/**
 * What a per-user permission override does (D-029).
 *
 * `DENY` wins outright — role grants are not consulted at all. `ALLOW`
 * *replaces* the role-derived result rather than adding to it, so an override
 * can narrow access as well as widen it, and its scope becomes the authoritative
 * one.
 */
enum UserPermissionEffect: string
{
    case ALLOW = 'ALLOW';
    case DENY = 'DENY';
}
