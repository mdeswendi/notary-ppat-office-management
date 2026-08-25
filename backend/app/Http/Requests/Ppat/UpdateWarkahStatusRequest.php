<?php

namespace App\Http\Requests\Ppat;

use App\Domains\Ppat\Actions\SetWarkahStatus;
use App\Domains\Ppat\Enums\PpatWarkahStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for setting a Warkah's status (M7.4, D-121).
 *
 * **Two values, not five.** `Rule::in` names only what {@see SetWarkahStatus} accepts:
 *
 * ```text
 * INCOMPLETE    accepted
 * UNDER_REVIEW  accepted
 *
 * COMPLETE      422 — answers to ppat.warkah.verify on its own endpoint
 * FINALIZED     422 — registered, unimplemented (open question eight)
 * ARCHIVED      422 — registered, unimplemented (open question eight)
 * ```
 *
 * A 422 naming the field is the right answer for all three refusals, and better than
 * silently ignoring the value: a caller who sends `FINALIZED` and gets 200 back
 * believes the bundle was finalized.
 *
 * `COMPLETE` is refused **here** rather than in the Action so the message points at
 * the field. That it belongs to a different capability is the D-091 point
 * `SetWarkahStatus` makes at length.
 *
 * **There is no transition matrix**, so the request does not read the current status.
 * The M7 lock section 8.2: *"Status is settable and not gated."* The two gates the
 * M7.4 brief proposed — a minimum item count, and every item verified — are
 * verification rules, and open question three does not answer them. D-102 refused the
 * same shape of rule on `MatterStatus`.
 *
 * `notes` rides along because the ERD gives the bundle the column and an office
 * explaining why it sent something back is the ordinary use of it.
 */
class UpdateWarkahStatusRequest extends FormRequest
{
    private const FORBIDDEN = [
        'id',
        'office_id',
        'ppat_deed_id',

        // Written only by the acts that own them. `verified_*` is
        // `ppat.warkah.verify`'s; `finalized_*` is nobody's in M7.
        'verified_at',
        'verified_by',
        'finalized_at',
        'finalized_by',

        // Arithmetic, derived from the items. Never accepted from a caller.
        'completeness_percentage',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'status' => ['required', 'string', Rule::in(SetWarkahStatus::settableValues())],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];

        foreach (self::FORBIDDEN as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public function status(): PpatWarkahStatus
    {
        return PpatWarkahStatus::from((string) $this->validated('status'));
    }

    public function notes(): ?string
    {
        $value = $this->validated('notes');

        return $value === null || $value === '' ? null : (string) $value;
    }
}
