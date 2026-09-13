<?php

namespace App\Http\Requests\Office;

use App\Domains\Professional\Enums\ProfessionalRelationshipType;
use App\Domains\Professional\Enums\ProfessionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProfessionalAppointmentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'individual_id' => ['required', 'string', 'ulid'],
            'profession_type' => ['required', Rule::enum(ProfessionType::class)],
            'relationship_type' => ['required', Rule::enum(ProfessionalRelationshipType::class)],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'appointed_at' => ['sometimes', 'nullable', 'date'],
            'professional_office_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'office_city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'province' => ['sometimes', 'nullable', 'string', 'max:255'],
            'jurisdiction' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ended_at' => ['prohibited'],
            'is_active' => ['prohibited'],
            'office_id' => ['prohibited'],
            'party_id' => ['prohibited'],
            'party_type' => ['prohibited'],
            'id' => ['prohibited'],
            'created_by' => ['prohibited'],
        ];
    }

    public function appointmentAttributes(): array
    {
        return array_intersect_key($this->validated(), array_flip([
            'registration_number', 'appointed_at', 'professional_office_name',
            'office_city', 'province', 'jurisdiction',
        ]));
    }
}
