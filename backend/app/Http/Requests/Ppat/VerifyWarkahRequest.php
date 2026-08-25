<?php

namespace App\Http\Requests\Ppat;

use App\Domains\Ppat\Actions\VerifyWarkah;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for marking a Warkah verified (M7.4, D-121).
 *
 * **Notes and nothing else.** Verification is an act, not a form: who verified and
 * when are stamped by the server, and `status` is not accepted because this endpoint
 * sets exactly one value.
 *
 * Whether the bundle is *eligible* is deliberately not asked — see {@see VerifyWarkah}
 * for the three checks it declines to make and why each would be an invented rule.
 */
class VerifyWarkahRequest extends FormRequest
{
    private const FORBIDDEN = [
        'id',
        'office_id',
        'ppat_deed_id',
        'status',
        'verified_at',
        'verified_by',
        'finalized_at',
        'finalized_by',
        'completeness_percentage',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'notes' => ['nullable', 'string', 'max:5000'],
        ];

        foreach (self::FORBIDDEN as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public function notes(): ?string
    {
        $value = $this->validated('notes');

        return $value === null || $value === '' ? null : (string) $value;
    }
}
