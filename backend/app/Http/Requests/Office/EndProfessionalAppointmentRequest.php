<?php

namespace App\Http\Requests\Office;

use Illuminate\Foundation\Http\FormRequest;

class EndProfessionalAppointmentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'ended_at' => ['required', 'date'],
            'individual_id' => ['prohibited'],
            'profession_type' => ['prohibited'],
            'relationship_type' => ['prohibited'],
            'office_id' => ['prohibited'],
        ];
    }
}
