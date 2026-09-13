<?php

namespace App\Domains\Professional\Actions;

use App\Models\ProfessionalAppointment;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class EndProfessionalAppointment
{
    public function handle(ProfessionalAppointment $appointment, string $endedAt): ProfessionalAppointment
    {
        return DB::transaction(function () use ($appointment, $endedAt): ProfessionalAppointment {
            $locked = ProfessionalAppointment::query()
                ->whereKey($appointment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->is_active || $locked->ended_at !== null) {
                throw new ConflictHttpException('This professional appointment has already ended.');
            }

            $locked->ended_at = $endedAt;
            $locked->is_active = false;
            $locked->save();

            return $locked->fresh(['party' => fn ($query) => $query->withTrashed()]);
        });
    }
}
