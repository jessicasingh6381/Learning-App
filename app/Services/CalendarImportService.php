<?php

namespace App\Services;

use App\Contracts\PdfTextExtractor;
use App\Models\AcademicSource;
use App\Models\AcademicSourceFile;
use App\Models\AcademicYearConfiguration;
use App\Models\CalendarEvent;
use App\Models\CalendarImport;
use App\Models\CalendarImportProposal;
use App\Models\CalendarProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CalendarImportService
{
    public const EVENT_TYPES = [
        'first_day', 'last_day', 'holiday', 'break', 'student_holiday', 'teacher_workday',
        'staff_development', 'professional_development', 'weather_closure', 'school_closure',
        'tenant_day_off', 'district_closure', 'early_release', 'instructional_makeup_day',
        'instructional_override', 'makeup_day', 'other',
    ];

    public function __construct(
        private PdfTextExtractor $extractor,
        private CalendarProposalParserRegistry $parsers,
        private AuditService $audit,
    ) {}

    public function start(AcademicSource $source): CalendarImport
    {
        $year = $source->schoolYear;
        $file = $source->currentFile;
        if ($source->source_category !== 'calendar' || $source->source_kind !== 'upload' || ! $year) {
            throw ValidationException::withMessages(['source' => 'A calendar PDF must be assigned to a school year before extraction.']);
        }
        if (! $file || $file->mime_type !== 'application/pdf' || $file->extension !== 'pdf') {
            throw ValidationException::withMessages(['source' => 'The current source file must be a validated PDF.']);
        }

        $profileId = $year->academicConfiguration?->calendar_profile_id
            ?? $source->links()->where('link_type', 'calendar_profile')->value('link_id');
        $import = CalendarImport::create([
            'academic_source_id' => $source->id, 'academic_source_file_id' => $file->id,
            'school_year_id' => $year->id, 'calendar_profile_id' => $profileId,
            'created_by_user_id' => auth()->id(), 'status' => 'extracting',
            'extraction_method' => 'pdf_text', 'parser_version' => DistrictCalendarProposalParser::VERSION,
        ]);
        $source->update(['processing_status' => 'processing']);

        try {
            $pages = $this->extractPages($file);
            if (collect($pages)->every(fn ($page) => trim($page['text']) === '')) {
                throw new \RuntimeException('The PDF has no usable text layer. OCR is not enabled; upload a text-based PDF or enter dates manually.');
            }
            $parser = $this->parsers->select($pages, $source);
            $proposals = $parser->parse($pages, $year);
            $quality = $this->quality($proposals, $parser->version());

            DB::transaction(function () use ($import, $source, $proposals, $parser, $quality): void {
                foreach ($proposals as $proposal) {
                    if (! $proposal['event_date']) {
                        continue;
                    }
                    $import->proposals()->create($proposal);
                }
                $first = $import->proposals()->where('event_type', 'first_day')->whereNotNull('event_date')->value('event_date');
                $last = $import->proposals()->where('event_type', 'last_day')->whereNotNull('event_date')->value('event_date');
                $import->update([
                    'status' => $quality['acceptable'] ? 'review_required' : 'manual_handling',
                    'extraction_method' => $parser->extractionMethod(),
                    'parser_version' => $parser->version(),
                    'diagnostic' => $quality['acceptable'] ? null : $quality['diagnostic'],
                    'proposed_first_day' => $first,
                    'proposed_last_day' => $last,
                ]);
                $source->update(['processing_status' => $quality['acceptable'] ? 'completed' : 'failed']);
                $this->audit->record('calendar-import.extracted', $import);
            });
        } catch (Throwable $exception) {
            Log::warning('Calendar PDF extraction failed.', ['calendar_import_id' => $import->id, 'exception' => $exception]);
            $safeMessages = [
                'The PDF could not be read. Confirm that it is a valid, unencrypted PDF.',
                'The PDF has no usable text layer. OCR is not enabled; upload a text-based PDF or enter dates manually.',
                'No recognizable school-calendar dates were found. Try another PDF or add proposals manually.',
            ];
            $message = in_array($exception->getMessage(), $safeMessages, true)
                ? $exception->getMessage()
                : 'Calendar extraction failed. Verify the PDF and try again; technical details were recorded for troubleshooting.';
            $import->update(['status' => 'failed', 'diagnostic' => $message]);
            $source->update(['processing_status' => 'failed']);
            throw ValidationException::withMessages(['source' => $message]);
        }

        return $import->fresh('proposals');
    }

    /** @param array<int|string, array<string, mixed>> $submitted */
    public function bulkUpdate(CalendarImport $import, array $submitted): CalendarImport
    {
        return DB::transaction(function () use ($import, $submitted) {
            $locked = CalendarImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['review_required', 'manual_handling'], true)) {
                throw ValidationException::withMessages([
                    'review' => $locked->status === 'approved'
                        ? 'This calendar import has already been approved and cannot be edited.'
                        : 'This calendar import is no longer editable.',
                ]);
            }

            $submittedById = collect($submitted)->mapWithKeys(
                fn (array $values, $key) => [(int) ($values['id'] ?? $key) => $values],
            );
            $proposals = $locked->proposals()->whereIn('id', $submittedById->keys())
                ->lockForUpdate()->get()->keyBy('id');
            $missing = $submittedById->keys()->diff($proposals->keys());
            if ($missing->isNotEmpty()) {
                $errors = ['review' => 'One or more proposals no longer belongs to this import. Reload the review and try again.'];
                foreach ($missing as $id) {
                    $errors["proposals.{$id}.id"] = 'This proposal does not belong to the selected import or is no longer available.';
                }
                throw ValidationException::withMessages($errors);
            }

            foreach ($submittedById as $id => $values) {
                $proposal = $proposals->get($id);
                $before = $proposal->toArray();
                $proposal->fill([
                    'included' => (bool) $values['included'],
                    'event_date' => ($values['event_date'] ?? null) ?: null,
                    'end_date' => ($values['end_date'] ?? null) ?: null,
                    'name' => $values['name'],
                    'event_type' => $values['event_type'],
                    'instructional_effect' => $values['instructional_effect'],
                ]);
                if ($proposal->isDirty()) {
                    $proposal->manually_edited = true;
                    $proposal->save();
                    $this->audit->record('calendar-import.proposal-updated', $proposal, $before, $proposal->fresh()->toArray());
                }
            }

            $locked->update([
                'proposed_first_day' => $locked->proposals()->where('included', true)->where('event_type', 'first_day')->whereNotNull('event_date')->value('event_date'),
                'proposed_last_day' => $locked->proposals()->where('included', true)->where('event_type', 'last_day')->whereNotNull('event_date')->value('event_date'),
            ]);

            return $locked->fresh('proposals');
        });
    }

    public function approve(
        CalendarImport $import,
        bool $replacePrevious,
        bool $updateDates,
        array $includedProposalIds,
    ): CalendarImport {
        return DB::transaction(function () use ($import, $replacePrevious, $updateDates, $includedProposalIds) {
            $locked = CalendarImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['review_required', 'manual_handling'], true)) {
                throw ValidationException::withMessages([
                    'approval' => $locked->status === 'approved'
                        ? 'This calendar import has already been approved.'
                        : 'Only an import awaiting review or manual correction can be approved.',
                ]);
            }

            $allProposals = $locked->proposals()->lockForUpdate()->get();
            $includedProposalIds = collect($includedProposalIds)->map(fn ($id) => (int) $id)->unique()->values();
            if ($includedProposalIds->diff($allProposals->pluck('id'))->isNotEmpty()) {
                throw ValidationException::withMessages(['approval' => 'One or more selected proposals do not belong to this import.']);
            }
            $locked->proposals()->update(['included' => false]);
            if ($includedProposalIds->isNotEmpty()) {
                $locked->proposals()->whereIn('id', $includedProposalIds->all())->update(['included' => true]);
            }
            $proposals = $allProposals->whereIn('id', $includedProposalIds)->values();
            $year = $locked->schoolYear()->lockForUpdate()->firstOrFail();
            [$selectedFirst, $selectedLast] = $this->validateIncludedProposals($proposals, $year, $updateDates);

            $previous = CalendarImport::query()->where('academic_source_id', $locked->academic_source_id)
                ->where('status', 'approved')->whereKeyNot($locked->id)->lockForUpdate()->get();
            if ($previous->isNotEmpty() && ! $replacePrevious) {
                throw ValidationException::withMessages(['replace_previous' => 'Confirm replacement of this source’s earlier approved import.']);
            }

            $configuration = $year->academicConfiguration;
            if ($configuration && in_array($configuration->status, ['closed', 'archived'], true)) {
                throw ValidationException::withMessages(['approval' => 'A closed or archived school-year configuration cannot be changed by an import.']);
            }
            $profile = $locked->calendarProfile ?: $this->calendarProfile($locked);
            if ($profile->isShared() || $profile->status === 'archived') {
                $profile = $this->calendarProfile($locked);
            }
            $locked->source->links()->firstOrCreate(['link_type' => 'calendar_profile', 'link_id' => $profile->id]);
            $this->ensureConfiguration($locked, $profile);

            if ($replacePrevious) {
                foreach ($previous as $prior) {
                    foreach ($prior->events()->where('status', 'active')->with('importProposal')->get() as $event) {
                        if ($this->unchangedSinceImport($event)) {
                            $event->update(['status' => 'archived']);
                        }
                    }
                    $prior->update(['status' => 'superseded']);
                }
            }

            foreach ($proposals as $proposal) {
                $duplicate = $profile->events()->where('status', 'active')
                    ->whereDate('event_date', $proposal->event_date->format('Y-m-d'))
                    ->where('name', $proposal->name)->exists();
                if ($duplicate) {
                    $this->proposalError($proposal, "'{$proposal->name}' duplicates an existing calendar event.");
                }
                $conflict = $profile->events()->where('status', 'active')
                    ->whereDate('event_date', $proposal->event_date->format('Y-m-d'))
                    ->whereIn('instructional_effect', ['instructional', 'non_instructional'])
                    ->where('instructional_effect', '!=', $proposal->instructional_effect)->exists();
                if ($conflict && $proposal->instructional_effect !== 'informational') {
                    $this->proposalError($proposal, "The instructional status conflicts with an existing event on {$proposal->event_date->format('Y-m-d')}.");
                }
                $event = $profile->events()->create([
                    'calendar_import_id' => $locked->id, 'calendar_import_proposal_id' => $proposal->id,
                    'event_date' => $proposal->event_date->format('Y-m-d'),
                    'end_date' => $proposal->end_date?->format('Y-m-d'), 'event_type' => $proposal->event_type,
                    'name' => $proposal->name, 'instructional_effect' => $proposal->instructional_effect,
                    'status' => 'active', 'notes' => $proposal->parser_note,
                    'source_reference' => 'Academic source #'.$locked->academic_source_id.', page '.($proposal->source_page ?? 'unknown'),
                ]);
                $this->audit->record('calendar-event.imported', $event, [], $event->toArray());
            }

            if ($updateDates && $selectedFirst && $selectedLast) {
                $before = $year->toArray();
                $year->update([
                    'start_date' => $selectedFirst->event_date->format('Y-m-d'),
                    'end_date' => $selectedLast->event_date->format('Y-m-d'),
                ]);
                $profile->update([
                    'start_date' => min($profile->start_date->format('Y-m-d'), $selectedFirst->event_date->format('Y-m-d')),
                    'end_date' => max($profile->end_date->format('Y-m-d'), $selectedLast->event_date->format('Y-m-d')),
                ]);
                $this->audit->record('school-year.dates-updated-from-calendar-import', $year, $before, $year->fresh()->toArray());
            }

            $locked->update([
                'calendar_profile_id' => $profile->id, 'status' => 'approved',
                'approved_by_user_id' => auth()->id(), 'approved_at' => now(),
                'update_school_year_dates' => $updateDates,
                'proposed_first_day' => $selectedFirst?->event_date?->format('Y-m-d'),
                'proposed_last_day' => $selectedLast?->event_date?->format('Y-m-d'),
            ]);
            $locked->source->update(['processing_status' => 'completed']);
            $this->audit->record('calendar-import.approved', $locked);

            return $locked->fresh(['proposals', 'calendarProfile']);
        });
    }

    private function validateIncludedProposals($proposals, $year, bool $updateDates): array
    {
        if ($proposals->isEmpty()) {
            throw ValidationException::withMessages(['approval' => 'No events are selected for approval.']);
        }

        $errors = [];
        foreach ($proposals as $proposal) {
            $message = match (true) {
                ! $proposal->event_date => 'This included proposal needs a valid start date.',
                $proposal->end_date && $proposal->end_date->lt($proposal->event_date) => 'The end date must be on or after the start date.',
                ! in_array($proposal->event_type, self::EVENT_TYPES, true) => 'Select a valid event type.',
                ! in_array($proposal->instructional_effect, ['non_instructional', 'instructional', 'informational'], true) => 'Select a valid instructional status.',
                default => null,
            };
            if ($message) {
                $errors['proposals.'.$proposal->id] = $message;
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['approval' => count($errors).' included proposal(s) need correction before approval.', ...$errors]);
        }

        $duplicateGroups = $proposals->groupBy(fn ($item) => $item->event_date->format('Y-m-d').'|'.mb_strtolower($item->name))
            ->filter(fn ($items) => $items->count() > 1);
        foreach ($duplicateGroups as $items) {
            foreach ($items as $proposal) {
                $errors['proposals.'.$proposal->id] = 'This duplicates another included proposal.';
            }
        }
        $conflictGroups = $proposals->groupBy(fn ($item) => $item->event_date->format('Y-m-d'))
            ->filter(fn ($items) => $items->pluck('instructional_effect')->intersect(['instructional', 'non_instructional'])->unique()->count() > 1);
        foreach ($conflictGroups as $items) {
            foreach ($items as $proposal) {
                $errors['proposals.'.$proposal->id] = 'Included proposals on this date have conflicting instructional statuses.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['approval' => 'Resolve the highlighted included proposals before approval.', ...$errors]);
        }

        $selectedFirst = $proposals->firstWhere('event_type', 'first_day');
        $selectedLast = $proposals->firstWhere('event_type', 'last_day');
        if ($updateDates && (! $selectedFirst || ! $selectedLast)) {
            throw ValidationException::withMessages(['approval' => 'Include both the first and last day proposals to update the school-year dates.']);
        }
        $rangeStart = $updateDates ? $selectedFirst->event_date->format('Y-m-d') : $year->start_date->format('Y-m-d');
        $rangeEnd = $updateDates ? $selectedLast->event_date->format('Y-m-d') : $year->end_date->format('Y-m-d');
        if ($rangeStart > $rangeEnd) {
            throw ValidationException::withMessages(['approval' => 'The selected school-year date range is invalid.']);
        }
        foreach ($proposals as $proposal) {
            if ($proposal->event_date->format('Y-m-d') < $rangeStart
                || ($proposal->end_date ?? $proposal->event_date)->format('Y-m-d') > $rangeEnd) {
                $errors['proposals.'.$proposal->id] = 'This included proposal is outside the selected school-year range.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['approval' => 'Exclude or correct the highlighted out-of-range proposals.', ...$errors]);
        }

        return [$selectedFirst, $selectedLast];
    }

    private function proposalError($proposal, string $message): never
    {
        throw ValidationException::withMessages([
            'approval' => 'A selected proposal conflicts with the live calendar. Review the highlighted row.',
            'proposals.'.$proposal->id => $message,
        ]);
    }

    private function calendarProfile(CalendarImport $import): CalendarProfile
    {
        $source = $import->source;
        $year = $import->schoolYear;
        $name = $source->title;
        if (CalendarProfile::query()->where('name', $name)->where('academic_year_label', $source->academic_year_label ?: $year->name)->exists()) {
            $name .= ' (Import '.$import->id.')';
        }
        $profile = CalendarProfile::create([
            'education_provider_id' => $source->education_provider_id, 'name' => $name,
            'academic_year_label' => $source->academic_year_label ?: $year->name,
            'start_date' => $year->start_date->format('Y-m-d'), 'end_date' => $year->end_date->format('Y-m-d'),
            'timezone' => $year->timezone, 'status' => 'draft', 'source_type' => 'pdf_import',
            'source_version' => $source->version_label, 'notes' => 'Created from an approved, human-reviewed PDF calendar import.',
        ]);
        $this->audit->record('calendar-profile.created', $profile, [], $profile->toArray());
        $source->links()->firstOrCreate(['link_type' => 'calendar_profile', 'link_id' => $profile->id]);
        return $profile;
    }

    /** @return array<int, array{page: int, text: string}> */
    private function extractPages(AcademicSourceFile $file): array
    {
        $disk = Storage::disk($file->disk);
        $temporaryPath = null;

        try {
            try {
                $path = $disk->path($file->stored_path);
            } catch (Throwable) {
                $temporaryPath = tempnam(sys_get_temp_dir(), 'calendar-pdf-');
                if ($temporaryPath === false) {
                    throw new \RuntimeException('The PDF could not be read. Confirm that it is a valid, unencrypted PDF.');
                }
                $source = $disk->readStream($file->stored_path);
                $target = fopen($temporaryPath, 'wb');
                if ($source === false || $target === false) {
                    throw new \RuntimeException('The PDF could not be read. Confirm that it is a valid, unencrypted PDF.');
                }
                try {
                    stream_copy_to_stream($source, $target);
                } finally {
                    fclose($source);
                    fclose($target);
                }
                $path = $temporaryPath;
            }

            return $this->extractor->extract($path);
        } finally {
            if ($temporaryPath && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function ensureConfiguration(CalendarImport $import, CalendarProfile $profile): void
    {
        $configuration = AcademicYearConfiguration::query()->firstOrNew(['school_year_id' => $import->school_year_id]);
        $configuration->calendar_profile_id = $profile->id;
        $configuration->education_provider_id ??= $import->source->education_provider_id;
        $configuration->status ??= 'draft';
        $configuration->configured_by_user_id = auth()->id();
        $configuration->save();
    }

    private function unchangedSinceImport(CalendarEvent $event): bool
    {
        $proposal = $event->importProposal;
        return $proposal
            && $event->event_date->format('Y-m-d') === $proposal->event_date?->format('Y-m-d')
            && $event->end_date?->format('Y-m-d') === $proposal->end_date?->format('Y-m-d')
            && $event->name === $proposal->name
            && $event->event_type === $proposal->event_type
            && $event->instructional_effect === $proposal->instructional_effect;
    }

    /** @return array{acceptable: bool, diagnostic: string} */
    private function quality(array $proposals, string $parserVersion): array
    {
        $total = count($proposals);
        $dated = collect($proposals)->whereNotNull('event_date')->count();
        $ratio = $total > 0 ? $dated / $total : 0;
        $first = collect($proposals)->contains(fn (array $proposal) => $proposal['event_type'] === 'first_day' && $proposal['event_date']);
        $last = collect($proposals)->contains(fn (array $proposal) => $proposal['event_type'] === 'last_day' && $proposal['event_date']);
        $cyFair = str_starts_with($parserVersion, 'cy-fair-');
        $acceptable = $dated > 0 && $ratio >= 0.6 && (! $cyFair || ($first && $last));

        return [
            'acceptable' => $acceptable,
            'diagnostic' => "The PDF text was read, but dates could not be reliably mapped to calendar events. {$dated} of {$total} extracted entries had usable dates"
                .($cyFair && (! $first || ! $last) ? '; the first or last day of school was not recognized' : '').'. Retry extraction or enter events manually.',
        ];
    }
}
