<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\CalendarEventRequest;
use App\Models\AcademicYearConfiguration;
use App\Models\CalendarEvent;
use App\Models\CalendarProfile;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class CalendarEventController extends Controller
{
    public function store(CalendarEventRequest $request, CalendarProfile $calendar, AuditService $audit): RedirectResponse
    {
        if ($calendar->status === 'archived') {
            throw ValidationException::withMessages([
                'name' => 'Restore this Calendar Profile before adding events.',
            ]);
        }

        if ($this->isHistorical($calendar)) {
            throw ValidationException::withMessages([
                'name' => 'Events cannot be added to a calendar used by a closed or archived configuration.',
            ]);
        }

        $event = $calendar->events()->create($request->validated());
        $audit->record('calendar-event.created', $event, [], $event->toArray());

        return back()->with('success', 'Calendar event added.');
    }

    public function update(
        CalendarEventRequest $request,
        CalendarProfile $calendar,
        CalendarEvent $event,
        AuditService $audit,
    ): RedirectResponse {
        abort_unless($event->calendar_profile_id === $calendar->id, 404);

        if ($calendar->status === 'archived') {
            throw ValidationException::withMessages([
                'name' => 'Restore this Calendar Profile before changing events.',
            ]);
        }

        if ($this->isHistorical($calendar)) {
            throw ValidationException::withMessages([
                'name' => 'Events in a calendar used by a closed or archived configuration cannot be changed.',
            ]);
        }

        $before = $event->toArray();
        $event->update($request->validated());
        $audit->record(
            $event->status === 'archived' ? 'calendar-event.archived' : 'calendar-event.updated',
            $event,
            $before,
            $event->fresh()->toArray(),
        );

        return back()->with('success', $event->status === 'archived' ? 'Calendar event archived.' : 'Calendar event updated.');
    }

    private function isHistorical(CalendarProfile $calendar): bool
    {
        return AcademicYearConfiguration::query()
            ->where('calendar_profile_id', $calendar->id)
            ->whereIn('status', ['closed', 'archived'])
            ->exists();
    }
}
