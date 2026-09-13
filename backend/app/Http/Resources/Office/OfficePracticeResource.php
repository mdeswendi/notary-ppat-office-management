<?php

namespace App\Http\Resources\Office;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfficePracticeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'practice_type' => $this->practice_type?->value,
            'jurisdiction' => $this->jurisdiction,
            'can_update' => $request->user()?->can('update', $this->resource) ?? false,
            'professionals' => ProfessionalAppointmentResource::collection(
                $this->whenLoaded('professionalAppointments')
            ),
        ];
    }
}
