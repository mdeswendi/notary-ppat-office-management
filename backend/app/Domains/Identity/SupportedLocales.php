<?php

namespace App\Domains\Identity;

/**
 * The interface languages this deployment stores.
 *
 * Exactly the two codes `CLAUDE.md` section 6 names, stored bare: `id` and `en`,
 * never `id-ID`, `en-US`, or a display name (D-068). The frontend's
 * `src/i18n/routing.ts` carries the same pair for routing; this is the backend's
 * validation boundary, and the two are asserted to match by test rather than by
 * hoping.
 *
 * Indonesian is the default — the canonical primary language, and the fallback
 * whenever a stored preference cannot be trusted.
 */
final class SupportedLocales
{
    public const DEFAULT = 'id';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return ['id', 'en'];
    }

    public static function supports(?string $locale): bool
    {
        return $locale !== null && in_array($locale, self::all(), true);
    }
}
