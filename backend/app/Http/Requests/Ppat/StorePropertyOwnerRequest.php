<?php

namespace App\Http\Requests\Ppat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for adding a link to a chain of title (M7.3, D-121).
 *
 * ## `supersedes_current` is the field the brief did not have, and the schema requires
 *
 * The brief described one act — *"tambah owner baru (set `is_current` = true, update
 * yang lama)"* — and its constraints added *"hanya satu owner yang bisa `is_current`
 * = true per property."* The M7 lock section 7.2 rules that out by name: a Property
 * legitimately has **several** current owners at once, and M7.1 ships a test asserting
 * two at 50% each.
 *
 * So there are two acts and the caller says which:
 *
 * ```text
 * false (default)   add a co-owner beside the existing ones
 * true              record a transfer: close the current links at effective_from
 * ```
 *
 * Default `false`, because it is the choice that ends nobody's recorded ownership.
 *
 * ## Percentage
 *
 * `nullable`, and 0–100 when present. **Nullable because a share is not always known**
 * — an office recording inherited title may have a name and no figure — and because
 * `property_owners.ownership_percentage` is nullable in the ERD.
 *
 * **No sum across co-owners is validated.** Whether shares must total 100 is a rule
 * about Indonesian co-ownership; `CLAUDE.md` section 62 forbids inventing it, and the
 * M7 lock records it as an open question. 0–100 per row is arithmetic, not law.
 *
 * ## What is refused
 *
 * `property_id` comes from the address and `office_id` from the Property, so neither is
 * accepted. `is_current` **is** accepted — an office may record a link that is already
 * historical, which is exactly what importing an old title deed looks like — but the
 * model refuses `is_current` together with an `effective_until`.
 */
class StorePropertyOwnerRequest extends FormRequest
{
    private const FORBIDDEN = [
        'id',
        'property_id',
        'office_id',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'party_id' => ['required', 'string', 'size:26'],

            // See the class docblock: nullable, 0-100, no sum.
            'ownership_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_current' => ['nullable', 'boolean'],

            // The transfer that produced this link. Resolved through canonical Matter
            // visibility by the controller, so recording a transfer never becomes a
            // way to discover which Matters exist.
            'source_matter_id' => ['nullable', 'string', 'size:26'],

            'supersedes_current' => ['nullable', 'boolean'],
        ];

        foreach (self::FORBIDDEN as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public function partyId(): string
    {
        return (string) $this->validated('party_id');
    }

    public function sourceMatterId(): ?string
    {
        $value = $this->validated('source_matter_id');

        return $value === null ? null : (string) $value;
    }

    public function supersedesCurrent(): bool
    {
        return (bool) ($this->validated('supersedes_current') ?? false);
    }

    /**
     * The link's own fields, without the keys resolved separately.
     *
     * @return array<string, mixed>
     */
    public function ownerAttributes(): array
    {
        return collect($this->validated())
            ->only(['ownership_percentage', 'effective_from', 'effective_until', 'is_current'])
            ->all();
    }
}
