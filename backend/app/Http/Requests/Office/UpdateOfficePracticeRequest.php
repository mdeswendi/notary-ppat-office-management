<?php

namespace App\Http\Requests\Office;

use App\Domains\Office\Enums\OfficePracticeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOfficePracticeRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            'practice_type' => ['required', Rule::enum(OfficePracticeType::class)],
            'jurisdiction' => ['required', 'string', 'max:255'],
        ];

        foreach (['id', 'organization_id', 'code', 'name', 'is_active', 'address', 'city', 'province'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
