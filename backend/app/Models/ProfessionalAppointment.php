<?php

namespace App\Models;

use App\Domains\Party\Enums\PartyType;
use App\Domains\Professional\Enums\ProfessionalRelationshipType;
use App\Domains\Professional\Enums\ProfessionType;
use Database\Factories\ProfessionalAppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

#[Fillable([
    'registration_number', 'appointed_at', 'ended_at', 'professional_office_name',
    'office_city', 'province', 'jurisdiction', 'is_active',
])]
class ProfessionalAppointment extends Model
{
    /** @use HasFactory<ProfessionalAppointmentFactory> */
    use HasFactory;

    use HasUlids;

    protected static function booted(): void
    {
        static::updating(function (self $appointment): void {
            foreach (['office_id', 'party_id', 'party_type', 'profession_type', 'relationship_type'] as $attribute) {
                if ($appointment->isDirty($attribute)) {
                    throw new RuntimeException(
                        "professional_appointments.{$attribute} is immutable (D-133). "
                        .'End the existing appointment and create a new one when its professional identity changes.'
                    );
                }
            }
        });
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function matterProfessionals(): HasMany
    {
        return $this->hasMany(MatterProfessional::class);
    }

    protected function casts(): array
    {
        return [
            'party_type' => PartyType::class,
            'profession_type' => ProfessionType::class,
            'relationship_type' => ProfessionalRelationshipType::class,
            'appointed_at' => 'date',
            'ended_at' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
