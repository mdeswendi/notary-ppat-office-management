<?php

namespace App\Domains\Ppat;

/**
 * Formats the office's internal Property reference.
 *
 * `PROP-000001` is an application reference only. It is not a certificate number,
 * deed number, or entry in a government land register.
 */
final class PropertyReference
{
    public const PREFIX = 'PROP';

    public const SEQUENCE_DIGITS = 6;

    public static function format(int $sequence): string
    {
        return sprintf(
            '%s-%s',
            self::PREFIX,
            str_pad((string) $sequence, self::SEQUENCE_DIGITS, '0', STR_PAD_LEFT),
        );
    }

    public static function matchesFormat(string $value): bool
    {
        return preg_match('/^'.self::PREFIX.'-\d{'.self::SEQUENCE_DIGITS.',}$/', $value) === 1;
    }
}
