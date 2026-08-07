<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Models\AcademicSource;
use App\Models\CalendarImport;
use App\Models\CalendarImportProposal;
use App\Services\AuditService;
use App\Services\CalendarImportLifecycleService;
use App\Services\CalendarImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CalendarImportController extends Controller
{
    public function store(AcademicSource $source, CalendarImportService $service): RedirectResponse
    {
        Gate::authorize('update', $source);
        $import = $service->start($source->load(['schoolYear.academicConfiguration', 'currentFile', 'links']));

        $message = $import->status === 'manual_handling'
            ? 'The PDF was read, but reliable date mapping needs manual attention.'
            : 'PDF dates extracted. Review every proposal before approval.';

        return redirect()->route('academic.calendar-imports.show', $import)->with('success', $message);
    }

    public function show(CalendarImport $calendarImport): Response
    {
        $calendarImport->load(['source.currentFile', 'sourceFile', 'schoolYear', 'calendarProfile', 'proposals', 'approvedBy:id,name']);
        Gate::authorize('view', $calendarImport->source);
        $year = $calendarImport->schoolYear;
        $proposals = $calendarImport->proposals;
        $datedProposalCount = $proposals->whereNotNull('event_date')->count();
        $mappingInadequate = $calendarImport->status === 'review_required'
            && $proposals->isNotEmpty()
            && ($datedProposalCount / $proposals->count()) < 0.6;
        $displayProposals = $mappingInadequate ? $proposals->whereNotNull('event_date')->values() : $proposals;
        $linkedEventsCount = $calendarImport->events()->count();
        $activeEvents = $calendarImport->calendarProfile?->events()->where('status', 'active')->get() ?? collect();
        $previousImport = CalendarImport::query()->with('proposals')->where('academic_source_id', $calendarImport->academic_source_id)
            ->where('status', 'approved')->whereKeyNot($calendarImport->id)->latest('approved_at')->first();

        return Inertia::render('Academic/CalendarImports/Show', [
            'calendarImport' => [
                'id' => $calendarImport->id, 'status' => $mappingInadequate ? 'manual_handling' : $calendarImport->status,
                'extraction_method' => $calendarImport->extraction_method, 'parser_version' => $calendarImport->parser_version,
                'diagnostic' => $calendarImport->diagnostic ?? ($mappingInadequate
                    ? "The PDF text was read, but dates could not be reliably mapped to calendar events. {$datedProposalCount} of {$proposals->count()} extracted entries had usable dates. Retry extraction or enter events manually."
                    : null),
                'proposed_first_day' => $calendarImport->proposed_first_day?->format('Y-m-d'),
                'proposed_last_day' => $calendarImport->proposed_last_day?->format('Y-m-d'),
                'previous_approved_count' => CalendarImport::query()->where('academic_source_id', $calendarImport->academic_source_id)
                    ->where('status', 'approved')->whereKeyNot($calendarImport->id)->count(),
                'comparison' => $this->comparison($proposals, $previousImport?->proposals),
                'approved_at' => $calendarImport->approved_at?->toIso8601String(),
                'approved_by' => $calendarImport->approvedBy?->name,
                'included_count' => $proposals->where('included', true)->count(),
                'excluded_count' => $proposals->where('included', false)->count(),
                'events_created_count' => $linkedEventsCount,
            ],
            'source' => [
                'id' => $calendarImport->source->id, 'title' => $calendarImport->source->title,
                'file' => ['id' => $calendarImport->sourceFile->id, 'name' => $calendarImport->sourceFile->original_filename],
            ],
            'schoolYear' => [
                'id' => $year->id, 'name' => $year->name,
                'start_date' => $year->start_date->format('Y-m-d'), 'end_date' => $year->end_date->format('Y-m-d'),
            ],
            'proposals' => $displayProposals->map(fn (CalendarImportProposal $proposal) => [
                ...$proposal->only(['id', 'name', 'event_type', 'instructional_effect', 'confidence', 'source_page', 'raw_text', 'parser_note', 'included', 'manually_edited']),
                'event_date' => $proposal->event_date?->format('Y-m-d'), 'end_date' => $proposal->end_date?->format('Y-m-d'),
                'warnings' => $this->warnings($proposal, $proposals, $activeEvents, $year->start_date->format('Y-m-d'), $year->end_date->format('Y-m-d')),
            ])->values(),
            'eventTypes' => CalendarImportService::EVENT_TYPES,
            'effects' => ['non_instructional', 'instructional', 'informational'],
            'canManage' => Gate::allows('update', $calendarImport->source)
                && in_array($calendarImport->status, ['review_required', 'manual_handling'], true),
            'canRetry' => Gate::allows('update', $calendarImport->source)
                && (in_array($calendarImport->status, ['failed', 'manual_handling'], true) || $mappingInadequate),
            'canDelete' => Gate::allows('update', $calendarImport->source)
                && CalendarImportLifecycleService::directlyDeletable($calendarImport->status, $linkedEventsCount),
            'linkedEventsCount' => $linkedEventsCount,
        ]);
    }

    public function destroy(
        AcademicSource $source,
        CalendarImport $calendarImport,
        CalendarImportLifecycleService $lifecycle,
    ): RedirectResponse {
        Gate::authorize('update', $source);
        $lifecycle->deleteAttempt($source, $calendarImport);

        return redirect()->route('academic.sources.show', $source)
            ->with('success', 'Calendar import attempt deleted. The source PDF and live calendar were not changed.');
    }

    public function storeProposal(Request $request, CalendarImport $calendarImport, AuditService $audit): RedirectResponse
    {
        $this->authorizeManage($calendarImport);
        $proposal = $calendarImport->proposals()->create([...$this->validatedProposal($request), 'manually_edited' => true, 'confidence' => 1]);
        $audit->record('calendar-import.proposal-added', $proposal, [], $proposal->toArray());
        $this->syncBoundaryDates($calendarImport);

        return back()->with('success', 'Proposal added for review.');
    }

    public function updateProposal(Request $request, CalendarImport $calendarImport, CalendarImportProposal $proposal, AuditService $audit): RedirectResponse
    {
        $this->authorizeManage($calendarImport);
        abort_unless($proposal->calendar_import_id === $calendarImport->id, 404);
        $before = $proposal->toArray();
        $proposal->update([...$this->validatedProposal($request), 'manually_edited' => true]);
        $audit->record('calendar-import.proposal-updated', $proposal, $before, $proposal->fresh()->toArray());
        $this->syncBoundaryDates($calendarImport);

        return back()->with('success', 'Proposal updated.');
    }

    public function bulkUpdateProposals(Request $request, CalendarImport $calendarImport, CalendarImportService $service): RedirectResponse
    {
        $calendarImport->loadMissing('source');
        Gate::authorize('update', $calendarImport->source);
        $validator = Validator::make($request->all(), [
            'proposals' => ['required', 'array', 'min:1'],
            'proposals.*.id' => ['required', 'integer', 'distinct'],
            'proposals.*.included' => ['required', 'boolean'],
            'proposals.*.event_date' => ['present', 'nullable', 'date_format:Y-m-d'],
            'proposals.*.end_date' => ['present', 'nullable', 'date_format:Y-m-d'],
            'proposals.*.name' => ['required', 'string', 'max:255'],
            'proposals.*.event_type' => ['required', Rule::in(CalendarImportService::EVENT_TYPES)],
            'proposals.*.instructional_effect' => ['required', Rule::in(['non_instructional', 'instructional', 'informational'])],
        ]);
        $validator->after(function ($validator) use ($request): void {
            foreach ($request->input('proposals', []) as $key => $proposal) {
                if (! is_array($proposal)) {
                    continue;
                }
                if ((string) $key !== (string) ($proposal['id'] ?? '')) {
                    $validator->errors()->add("proposals.{$key}.id", 'The proposal ID does not match its review row.');
                }
                if (($proposal['included'] ?? false) && empty($proposal['event_date'])) {
                    $validator->errors()->add("proposals.{$key}.event_date", 'An included proposal requires a valid start date.');
                }
                if (! empty($proposal['event_date']) && ! empty($proposal['end_date']) && $proposal['end_date'] < $proposal['event_date']) {
                    $validator->errors()->add("proposals.{$key}.end_date", 'The end date must be on or after the start date.');
                }
            }
        });

        $service->bulkUpdate($calendarImport, $validator->validate()['proposals']);

        return back()->with('success', 'Review changes saved.');
    }

    public function approve(Request $request, CalendarImport $calendarImport, CalendarImportService $service): RedirectResponse
    {
        $calendarImport->loadMissing('source');
        Gate::authorize('update', $calendarImport->source);
        $data = $request->validate([
            'replace_previous' => ['required', 'boolean'],
            'update_school_year_dates' => ['required', 'boolean'],
            'included_proposal_ids' => ['sometimes', 'array'],
            'included_proposal_ids.*' => [
                'integer', 'distinct',
                Rule::exists('calendar_import_proposals', 'id')->where('calendar_import_id', $calendarImport->id),
            ],
        ]);
        $approved = $service->approve(
            $calendarImport,
            $data['replace_previous'],
            $data['update_school_year_dates'],
            $data['included_proposal_ids'] ?? $calendarImport->proposals()->where('included', true)->pluck('id')->all(),
        );
        $eventCount = $approved->events()->count();

        return redirect()->route('workspace.calendar')->with(
            'success',
            "Calendar import approved. {$eventCount} events were added to the {$approved->schoolYear->name} calendar.",
        );
    }

    private function authorizeManage(CalendarImport $import): void
    {
        $import->loadMissing('source');
        Gate::authorize('update', $import->source);
        abort_unless(in_array($import->status, ['review_required', 'manual_handling'], true), 409, 'This import is no longer editable.');
    }

    private function validatedProposal(Request $request): array
    {
        return $request->validate([
            'event_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:event_date'],
            'name' => ['required', 'string', 'max:255'],
            'event_type' => ['required', Rule::in(CalendarImportService::EVENT_TYPES)],
            'instructional_effect' => ['required', Rule::in(['non_instructional', 'instructional', 'informational'])],
            'included' => ['required', 'boolean'],
            'parser_note' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function warnings(CalendarImportProposal $proposal, $proposals, $activeEvents, string $start, string $end): array
    {
        $warnings = collect();
        $date = $proposal->event_date?->format('Y-m-d');
        $endDate = ($proposal->end_date ?? $proposal->event_date)?->format('Y-m-d');
        if (! $date) {
            $warnings->push('Missing or ambiguous date');
        }
        if ($date && ($date < $start || $endDate > $end)) {
            $warnings->push('Outside the saved school-year dates');
        }
        if (($proposal->confidence ?? 0) < .6) {
            $warnings->push('Low-confidence extraction');
        }
        if ($date && $proposals->where('id', '!=', $proposal->id)->contains(fn ($other) => $other->event_date?->format('Y-m-d') === $date && mb_strtolower($other->name) === mb_strtolower($proposal->name))) {
            $warnings->push('Duplicate proposal');
        }
        if ($date && $proposals->where('id', '!=', $proposal->id)->contains(fn ($other) => $other->event_date?->format('Y-m-d') === $date && $other->instructional_effect !== $proposal->instructional_effect)) {
            $warnings->push('Conflicting instructional status');
        }
        if ($date && $activeEvents->contains(fn ($event) => $event->event_date->format('Y-m-d') === $date && mb_strtolower($event->name) === mb_strtolower($proposal->name))) {
            $warnings->push('Matches an existing calendar event');
        }

        return $warnings->values()->all();
    }

    private function syncBoundaryDates(CalendarImport $import): void
    {
        $import->update([
            'proposed_first_day' => $import->proposals()->where('included', true)->where('event_type', 'first_day')->whereNotNull('event_date')->value('event_date'),
            'proposed_last_day' => $import->proposals()->where('included', true)->where('event_type', 'last_day')->whereNotNull('event_date')->value('event_date'),
        ]);
    }

    private function comparison($current, $previous): ?array
    {
        if (! $previous) {
            return null;
        }
        $current = $current->where('included', true)->filter(fn ($item) => $item->event_date);
        $previous = $previous->where('included', true)->filter(fn ($item) => $item->event_date);
        $key = fn ($item) => implode('|', [
            $item->event_date?->format('Y-m-d'), $item->end_date?->format('Y-m-d'), mb_strtolower($item->name),
            $item->event_type, $item->instructional_effect,
        ]);
        $currentKeys = $current->map($key);
        $previousKeys = $previous->map($key);
        $currentDates = $current->groupBy(fn ($item) => $item->event_date->format('Y-m-d'));
        $previousDates = $previous->groupBy(fn ($item) => $item->event_date->format('Y-m-d'));
        $changed = $currentDates->keys()->intersect($previousDates->keys())->filter(function ($date) use ($currentDates, $previousDates, $key) {
            return $currentDates[$date]->map($key)->sort()->values()->all() !== $previousDates[$date]->map($key)->sort()->values()->all();
        })->count();

        return [
            'added' => $currentDates->keys()->diff($previousDates->keys())->count(),
            'changed' => $changed,
            'removed' => $previousDates->keys()->diff($currentDates->keys())->count(),
            'unchanged' => $currentKeys->intersect($previousKeys)->count(),
        ];
    }
}
