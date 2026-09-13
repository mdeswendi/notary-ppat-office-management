<?php

namespace App\Http\Resources\Office;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfessionalAppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $party = $this->resource->party;

        return [
            'id' => $this->id,
            'profession_type' => $this->profession_type->value,
            'relationship_type' => $this->relationship_type->value,
            'registration_number' => $this->registration_number,
            'appointed_at' => $this->appointed_at?->toDateString(),
            'ended_at' => $this->ended_at?->toDateString(),
            'professional_office_name' => $this->professional_office_name,
            'office_city' => $this->office_city,
            'province' => $this->province,
            'jurisdiction' => $this->jurisdiction,
            'is_active' => $this->is_active,
            'individual' => [
                'id' => $this->party_id,
                'display_name' => $party?->display_name,
                'is_archived' => $party?->deleted_at !== null,
            ],
        ];
    }
}
