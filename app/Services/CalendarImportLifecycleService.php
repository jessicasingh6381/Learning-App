<?php

namespace App\Services;

use App\Models\AcademicSource;
use App\Models\CalendarImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CalendarImportLifecycleService
{
    public const DELETABLE_STATUSES = [
        'extracting', 'draft', 'review_required', 'manual_handling', 'failed', 'cancelled',
    ];

    public function __construct(private AuditService $audit) {}

    public function deleteAttempt(AcademicSource $source, CalendarImport $import): void
    {
        abort_unless(
            $import->academic_source_id === $source->id
            && $import->school_year_id === $source->school_year_id,
            404,
        );

        DB::transaction(function () use ($source, $import): void {
            $locked = CalendarImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                $locked->academic_source_id === $source->id
                && $locked->school_year_id === $source->school_year_id,
                404,
            );

            $linkedEvents = $locked->events()->count();
            if (! in_array($locked->status, self::DELETABLE_STATUSES, true) || $linkedEvents > 0) {
                throw ValidationException::withMessages([
                    'calendar_import' => $linkedEvents > 0
                        ? "This import has {$linkedEvents} linked calendar event(s) and cannot be deleted from this screen."
                        : 'This import is approved or historical and cannot be deleted from this screen.',
                ]);
            }

            $before = $locked->toArray();
            $this->audit->record('calendar-import.deleted', $locked, $before);
            $locked->delete();
        });
    }

    public static function directlyDeletable(string $status, int $linkedEvents): bool
    {
        return in_array($status, self::DELETABLE_STATUSES, true) && $linkedEvents === 0;
    }
}
