<?php

namespace App\Domains\Office\Actions;

use App\Models\Office;

class UpdateOfficePractice
{
    public function handle(Office $office, array $attributes): Office
    {
        $office->fill($attributes)->save();

        return $office->fresh();
    }
}
