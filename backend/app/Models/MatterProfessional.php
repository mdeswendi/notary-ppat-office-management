<?php

namespace App\Models;

use Database\Factories\MatterProfessionalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

#[Fillable(['role_code', 'notes'])]
class MatterProfessional extends Model
{
    /** @use HasFactory<MatterProfessionalFactory> */
    use HasFactory;

    use HasUlids;

    protected static function booted(): void
    {
        static::updating(function (self $matterProfessional): void {
            foreach (['office_id', 'matter_id', 'professional_appointment_id', 'created_by'] as $attribute) {
                if ($matterProfessional->isDirty($attribute)) {
                    throw new RuntimeException(
                        "matter_professionals.{$attribute} is immutable (D-133). "
                        .'Remove and recreate the assignment rather than moving its security boundary or provenance.'
                    );
                }
            }
        });
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function professionalAppointment(): BelongsTo
    {
        return $this->belongsTo(ProfessionalAppointment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
