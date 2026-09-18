<?php

namespace App\Http\Requests\Calendar;

use App\Domains\Calendar\Enums\CalendarEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCalendarEventRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'event_type' => ['required', Rule::enum(CalendarEventType::class)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, mixed> */
    public function eventAttributes(): array
    {
        return array_intersect_key($this->validated(), array_flip([
            'title',
            'description',
            'event_type',
            'starts_at',
            'ends_at',
            'location',
        ]));
    }
}
