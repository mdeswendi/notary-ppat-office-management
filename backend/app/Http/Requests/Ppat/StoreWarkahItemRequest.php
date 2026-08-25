<?php

namespace App\Http\Requests\Ppat;

use App\Domains\Ppat\Actions\AddWarkahItem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for adding a line to a Warkah (M7.4, D-121).
 *
 * ## Both titles are required and `requirement_code` is not
 *
 * `title_id` and `title_en` are **bilingual database fields**, which `CLAUDE.md`
 * section 10 permits for business data — the pattern `service_types` uses. A line with
 * one language filled in renders blank for half the office, so both are required.
 * They are *not* UI strings and must never move to the message files.
 *
 * **`requirement_code` is optional**, which inverts the M7.4 brief. It is stored and
 * matched against nothing: what it would match is a requirement template, and D-104
 * keeps those unbuilt. Requiring a code that refers to no catalogue would make an
 * office invent one to get past validation — the argument D-102 made for
 * `matters.service_type_id`.
 *
 * ## `status` is prohibited, because the column has no vocabulary
 *
 * The brief specified six values and a default of `MISSING`. `03_DATABASE_ERD.md`
 * gives `ppat_warkah_items.status` **no values at all**, which is why M7.1 built no
 * enum, left the column nullable with no default and no CHECK, and left it out of the
 * fillable set (O-041).
 *
 * An item-status vocabulary *is* the verification rule, and *"what is the mandatory
 * Warkah composition per deed type?"* is open question three. It is refused on
 * **presence** rather than silently dropped (the D-097 pattern): an interface that
 * appears to accept `MISSING` and then ignores it is worse than one that says no.
 *
 * What replaces it is the fact the interface shows anyway — whether a document is
 * attached — which is what completeness counts and needs no vocabulary.
 *
 * `party_id` is optional and resolved through canonical Party visibility by the
 * controller, so composing a Warkah never becomes a way to discover which Parties
 * exist. `sequence_no` defaults to the end of the list in {@see AddWarkahItem}.
 */
class StoreWarkahItemRequest extends FormRequest
{
    private const FORBIDDEN = [
        'id',
        'office_id',
        'warkah_id',

        // No canonical vocabulary — see the class docblock.
        'status',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'requirement_code' => ['nullable', 'string', 'max:100'],
            'title_id' => ['required', 'string', 'max:255'],
            'title_en' => ['required', 'string', 'max:255'],
            'party_id' => ['nullable', 'string', 'size:26'],
            'sequence_no' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];

        foreach (self::FORBIDDEN as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public function partyId(): ?string
    {
        $value = $this->validated('party_id');

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * The line's own fields, without the keys resolved separately.
     *
     * @return array<string, mixed>
     */
    public function itemAttributes(): array
    {
        return collect($this->validated())
            ->only(['requirement_code', 'title_id', 'title_en', 'sequence_no', 'notes'])
            ->all();
    }
}
