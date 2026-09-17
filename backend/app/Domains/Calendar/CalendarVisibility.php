<?php

namespace App\Domains\Calendar;

use App\Domains\Authorization\EffectiveAccess;
use App\Domains\Authorization\Enums\DataScope;
use App\Models\CalendarEvent;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;

/** Applies the calendar's office/all data scope to every calendar read. */
class CalendarVisibility
{
    /** @param Builder<CalendarEvent> $query */
    public function scope(Builder $query, User $actor, EffectiveAccess $access): Builder
    {
        if (! $access->granted) {
            return $query->whereRaw('1 = 0');
        }

        $scopes = array_filter(
            $access->scopes,
            static fn (DataScope $scope): bool => in_array(
                $scope,
                [DataScope::ALL, DataScope::OFFICE],
                true,
            ),
        );

        if ($scopes === []) {
            return $query->whereRaw('1 = 0');
        }

        if (in_array(DataScope::ALL, $scopes, true)) {
            return $query;
        }

        return $query->where(function (BuilderContract $inner) use ($actor): void {
            $inner->where('calendar_events.office_id', $actor->office_id);
        });
    }

    public function hasUsableScope(EffectiveAccess $access): bool
    {
        return $access->granted && array_filter(
            $access->scopes,
            static fn (DataScope $scope): bool => in_array($scope, [DataScope::ALL, DataScope::OFFICE], true),
        ) !== [];
    }
}
