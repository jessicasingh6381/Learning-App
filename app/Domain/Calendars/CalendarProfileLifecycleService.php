<?php

namespace App\Domain\Calendars;

use App\Models\AcademicSourceLink;
use App\Models\AcademicYearConfiguration;
use App\Models\CalendarProfile;
use App\Services\AuditService;
use App\Services\PermissionService;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CalendarProfileLifecycleService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly PermissionService $permissions,
    ) {}

    public function inspect(CalendarProfile $calendar): array
    {
        $configurations = AcademicYearConfiguration::query()
            ->with('schoolYear:id,name')
            ->where('calendar_profile_id', $calendar->id)
            ->get();
        $currentConfigurations = $configurations->whereIn('status', ['draft', 'active']);
        $eventCount = $calendar->events()->count();
        $linkedSourceCount = AcademicSourceLink::query()
            ->where('link_type', 'calendar_profile')
            ->where('link_id', $calendar->id)
            ->count();
        $owned = ! $calendar->isShared()
            && $calendar->tenant_id === app(TenantContext::class)->tenantId();
        $canManage = $owned && $this->permissions->allows('calendars.manage');

        $archiveBlockers = collect();
        if (! $owned) {
            $archiveBlockers->push('Platform-shared Calendar Profiles cannot be archived by a tenant.');
        }
        if ($currentConfigurations->isNotEmpty()) {
            $years = $currentConfigurations->pluck('schoolYear.name')->filter()->unique()->join(', ');
            $archiveBlockers->push('Selected in current Academic Setup'.($years ? ' for '.$years : '').'. Choose another Calendar Profile or clear the selection first.');
        }

        $deletionBlockers = collect();
        if (! $owned) {
            $deletionBlockers->push('Platform-shared Calendar Profiles cannot be permanently deleted by a tenant.');
        }
        if ($currentConfigurations->isNotEmpty()) {
            $deletionBlockers->push('Selected by '.$currentConfigurations->count().' current Academic Setup configuration'.($currentConfigurations->count() === 1 ? '.' : 's.'));
        }
        $historicalCount = $configurations->whereIn('status', ['closed', 'archived'])->count();
        if ($historicalCount > 0) {
            $deletionBlockers->push('Referenced by '.$historicalCount.' historical school-year configuration'.($historicalCount === 1 ? '.' : 's.'));
        }
        if ($eventCount > 0) {
            $deletionBlockers->push('Contains '.$eventCount.' Calendar Event'.($eventCount === 1 ? '.' : 's.'));
        }
        if ($linkedSourceCount > 0) {
            $deletionBlockers->push('Has '.$linkedSourceCount.' linked source document'.($linkedSourceCount === 1 ? '. Unlink it first.' : 's. Unlink them first.'));
        }

        return [
            'academic_configuration_count' => $configurations->count(),
            'active_configuration_count' => $currentConfigurations->count(),
            'event_count' => $eventCount,
            'linked_source_count' => $linkedSourceCount,
            'is_in_use' => $configurations->isNotEmpty(),
            'usage' => $configurations->map(fn (AcademicYearConfiguration $configuration) => [
                'school_year' => $configuration->schoolYear?->name ?? 'Unavailable school year',
                'status' => $configuration->status,
            ])->values(),
            'can_manage' => $canManage,
            'can_edit' => $canManage && $calendar->status !== 'archived',
            'can_archive' => $canManage && $calendar->status !== 'archived' && $archiveBlockers->isEmpty(),
            'can_restore' => $canManage && $calendar->status === 'archived',
            'can_delete' => $canManage && $deletionBlockers->isEmpty(),
            'archive_blockers' => $archiveBlockers->values()->all(),
            'deletion_blockers' => $deletionBlockers->values()->all(),
        ];
    }

    public function archive(CalendarProfile $calendar): CalendarProfile
    {
        $this->authorize($calendar);

        return DB::transaction(function () use ($calendar) {
            $locked = CalendarProfile::query()->whereKey($calendar->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'archived') {
                throw ValidationException::withMessages(['lifecycle' => 'This Calendar Profile is already archived.']);
            }
            $lifecycle = $this->inspect($locked);
            if (! $lifecycle['can_archive']) {
                throw ValidationException::withMessages(['lifecycle' => $lifecycle['archive_blockers']]);
            }

            $before = $locked->toArray();
            $locked->update(['status' => 'archived']);
            $this->audit->record('calendar-profile.archived', $locked, $before, $locked->fresh()->toArray());

            return $locked->fresh();
        });
    }

    public function restore(CalendarProfile $calendar): CalendarProfile
    {
        $this->authorize($calendar);

        return DB::transaction(function () use ($calendar) {
            $locked = CalendarProfile::query()->whereKey($calendar->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'archived') {
                throw ValidationException::withMessages(['lifecycle' => 'Only an archived Calendar Profile can be restored.']);
            }

            $before = $locked->toArray();
            $locked->update(['status' => 'draft']);
            $this->audit->record('calendar-profile.restored', $locked, $before, $locked->fresh()->toArray());

            return $locked->fresh();
        });
    }

    public function deletePermanently(CalendarProfile $calendar): string
    {
        $this->authorize($calendar);

        return DB::transaction(function () use ($calendar) {
            $locked = CalendarProfile::query()->whereKey($calendar->id)->lockForUpdate()->firstOrFail();
            $lifecycle = $this->inspect($locked);
            if (! $lifecycle['can_delete']) {
                throw ValidationException::withMessages(['lifecycle' => $lifecycle['deletion_blockers']]);
            }

            $name = $locked->name;
            $this->audit->record('calendar-profile.deleted', $locked, $locked->only([
                'name', 'academic_year_label', 'status',
            ]), []);
            $locked->delete();

            return $name;
        });
    }

    private function authorize(CalendarProfile $calendar): void
    {
        if ($calendar->isShared()
            || $calendar->tenant_id !== app(TenantContext::class)->tenantId()
            || ! $this->permissions->allows('calendars.manage')) {
            throw new AuthorizationException;
        }
    }
}
