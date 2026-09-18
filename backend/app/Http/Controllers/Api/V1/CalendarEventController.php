<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Calendar\CalendarVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Calendar\StoreCalendarEventRequest;
use App\Http\Resources\CalendarEventResource;
use App\Models\CalendarEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CalendarEventController extends Controller
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly CalendarVisibility $visibility,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $request->user();
        $access = $this->resolver->resolve($actor, 'calendar.view');

        abort_unless($this->visibility->hasUsableScope($access), 403);

        $events = $this->visibility
            ->scope(CalendarEvent::query(), $actor, $access)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(100)
            ->get();

        return CalendarEventResource::collection($events);
    }

    public function store(StoreCalendarEventRequest $request): CalendarEventResource
    {
        $actor = $request->user();
        $access = $this->resolver->resolve($actor, 'calendar.create');

        abort_unless($this->visibility->hasUsableScope($access), 403);

        $event = new CalendarEvent;
        $event->office_id = $actor->office_id;
        $event->created_by = $actor->getKey();
        $event->fill($request->eventAttributes());
        $event->save();

        return new CalendarEventResource($event);
    }
}
