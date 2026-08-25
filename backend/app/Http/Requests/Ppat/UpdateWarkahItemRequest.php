<?php

namespace App\Http\Requests\Ppat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for correcting a line of a Warkah (M7.4, D-121).
 *
 * **Every field is optional and the two titles stay required when sent.** A partial
 * update that blanks `title_id` leaves a line that renders empty for half the office,
 * so both are `sometimes|required` rather than `nullable` — sending one means meaning
 * it.
 *
 * **`status` is prohibited**, for the reason {@see StoreWarkahItemRequest} gives at
 * length: `ppat_warkah_items.status` has no canonical vocabulary, so there is nothing
 * to set it to. The M7.4 brief listed six values; an item-status vocabulary *is* the
 * verification rule, which is open question three (O-041).
 *
 * **`party_id` may be cleared**, which is how a line moves from belonging to one party
 * — a seller's identity document — to belonging to the transaction as a whole, like a
 * land certificate. The controller distinguishes *sent as null* from *not sent* with
 * `array_key_exists`, because `??` coalesces on a null value rather than a missing key
 * and cannot tell the two apart.
 *
 * `warkah_id` and `office_id` are refused here and refused again by
 * `PpatWarkahItem::booted()`; the rule turns a `RuntimeException` into a 422 that names
 * the field.
 */
class UpdateWarkahItemRequest extends FormRequest
{
    private const FORBIDDEN = [
        'id',
        'office_id',

        // A line belongs to one bundle; moving it would re-file evidence against
        // another transaction (M7.1).
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

            // `sometimes|required`: absent is fine, blank is not.
            'title_id' => ['sometimes', 'required', 'string', 'max:255'],
            'title_en' => ['sometimes', 'required', 'string', 'max:255'],

            'party_id' => ['nullable', 'string', 'size:26'],
            'sequence_no' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];

        foreach (self::FORBIDDEN as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * Whether the caller mentioned the party at all.
     *
     * **`array_key_exists`, never `??`.** A caller who sent `party_id: null` means
     * "clear it" and one who omitted the key means "leave it alone"; the coalescing
     * operator collapses both into the same branch, which is how a line quietly loses
     * its party on an unrelated edit.
     */
    public function partyGiven(): bool
    {
        return array_key_exists('party_id', $this->validated());
    }

    public function partyId(): ?string
    {
        $value = $this->validated('party_id');

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function itemAttributes(): array
    {
        return collect($this->validated())
            ->only(['requirement_code', 'title_id', 'title_en', 'sequence_no', 'notes'])
            ->all();
    }
}
