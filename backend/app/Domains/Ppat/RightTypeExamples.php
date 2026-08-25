<?php

namespace App\Domains\Ppat;

/**
 * The right-type codes `03_DATABASE_ERD.md` section 16 offers as examples (M7.3).
 *
 * **Deliberately not an enum, and deliberately not a validation rule.** The ERD's
 * wording is *"Right type **may** use stable machine codes, **for example**"* — which
 * is why M7.1 stored `right_type` as a CHECK-free `VARCHAR` while `property_type`,
 * given as a flat closed list, got a CHECK. Constraining this to five or six codes
 * would assert that Indonesian land law has that many kinds of right, and
 * `11_LEGAL_REFERENCES.md` exists as a statutory register precisely because nobody
 * here may decide that (`CLAUDE.md` section 62).
 *
 * So this class is a **suggestion list**, exposed through `GET /properties/options`
 * and rendered by the interface as an HTML `datalist` — typeahead over these codes,
 * with any other value accepted. A `<select>` would present the same six values as a
 * closed vocabulary, which is the thing the ERD's hedge rules out.
 *
 * Never translated in the database (`CLAUDE.md` section 12). The interface renders
 * whatever the office typed, verbatim, exactly as M6.2 and M7.2 render
 * `deed_type_code`.
 */
final class RightTypeExamples
{
    /**
     * Transcribed verbatim from the ERD, in its order.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            'HAK_MILIK',
            'HGB',
            'HGU',
            'HAK_PAKAI',
            'STRATA_TITLE',
            'OTHER',
        ];
    }
}
