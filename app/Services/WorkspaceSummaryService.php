<?php

namespace App\Services;

use App\Domain\Calendars\CalendarProfileCompatibility;
use App\Domain\Calendars\ScheduledInstructionalDayCalculator;
use App\Models\CalendarEvent;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class WorkspaceSummaryService
{
    public function __construct(
        private ScheduledInstructionalDayCalculator $calculator,
        private CalendarProfileCompatibility $calendarCompatibility,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $tenant = app(TenantContext::class)->tenant();
        $year = SchoolYear::query()
            ->where('status', 'active')
            ->with(['academicConfiguration.educationProvider', 'academicConfiguration.calendarProfile.events',
                'academicConfiguration.standardsFramework',
                'academicConfiguration.curriculumPackage.courseMappings.course.subject'])
            ->first();
        $configuration = $year?->academicConfiguration;
        $calendar = $configuration?->calendarProfile;
        $calendarReady = $year && $calendar
            ? $this->calendarCompatibility->supports($calendar, $year, $configuration->education_provider_id)
            : false;
        $events = $calendarReady ? $calendar->events : collect();
        $summary = $year ? $this->calculator->summarize(
            $year->start_date->format('Y-m-d'),
            $year->end_date->format('Y-m-d'),
            $year->instructional_weekdays,
            $events,
        ) : null;
        $mappedCourses = $configuration?->curriculumPackage?->courseMappings ?? collect();
        $learningFoundationReady = $calendarReady
            && $configuration?->curriculum_package_id !== null
            && $mappedCourses->isNotEmpty();
        $students = Student::query()
            ->where('status', 'active')
            ->with(['user.memberships' => fn ($query) => $query->where('tenant_id', $tenant->id),
                'enrollments' => fn ($query) => $query->with(['schoolYear', 'gradeLevel'])])
            ->orderBy('last_name')->orderBy('first_name')->get();
        $studentSummaries = $students->map(fn (Student $student) => $this->student($student, $year, $learningFoundationReady));
        $hasEnrollment = $studentSummaries->contains(fn ($student) => $student['enrollment'] !== null);
        $hasEnabledLogin = $studentSummaries->contains(fn ($student) => $student['access']['status'] === 'enabled');
        $hasCurriculum = $configuration?->curriculum_package_id !== null;
        $hasCourses = $mappedCourses->isNotEmpty();
        $readyForLearning = $year !== null && $hasEnrollment && $calendarReady && $hasCurriculum && $hasCourses;
        $checklist = [
            ['key' => 'school_year', 'label' => 'Active school year', 'complete' => $year !== null, 'route' => 'school-years.index'],
            ['key' => 'enrollment', 'label' => 'Student enrollment', 'complete' => $hasEnrollment, 'route' => 'students.index'],
            ['key' => 'student_login', 'label' => 'Student login', 'complete' => $hasEnabledLogin, 'route' => 'students.index'],
            ['key' => 'calendar', 'label' => 'School calendar', 'complete' => $calendarReady, 'route' => 'workspace.calendar'],
            ['key' => 'courses', 'label' => 'Courses', 'complete' => $hasCourses, 'route' => 'workspace.learning-plan'],
            ['key' => 'curriculum', 'label' => 'Curriculum', 'complete' => $hasCurriculum, 'route' => 'workspace.learning-plan'],
            ['key' => 'ready', 'label' => 'Ready for learning', 'complete' => $readyForLearning, 'route' => 'workspace.learning-plan'],
        ];

        return [
            'academy' => ['id' => $tenant->id, 'name' => $tenant->name, 'timezone' => $tenant->timezone],
            'schoolYear' => $year ? $this->schoolYear($year) : null,
            'students' => $studentSummaries->values()->all(),
            'setup' => ['items' => $checklist, 'completed' => collect($checklist)->where('complete', true)->count(), 'total' => count($checklist)],
            'needsAttention' => $this->needsAttention($year, $studentSummaries, $configuration, $calendarReady, $summary),
            'today' => $this->today($tenant->timezone, $year, $events),
            'upcomingEvents' => $this->upcomingEvents($tenant->timezone, $year, $events),
            'learningPlan' => [
                'provider' => $configuration?->educationProvider?->name,
                'calendar' => $calendarReady ? $calendar?->name : null,
                'calendar_state' => $calendarReady ? 'ready' : ($calendar ? 'needs_review' : 'missing'),
                'standards' => $configuration?->standardsFramework?->name,
                'curriculum' => $configuration?->curriculumPackage?->name,
                'courses' => $mappedCourses->map(fn ($mapping) => [
                    'id' => $mapping->course->id,
                    'name' => $mapping->course->name,
                    'code' => $mapping->course->code,
                    'subject' => $mapping->course->subject?->name ?? 'Other',
                    'required' => $mapping->required,
                ])->groupBy('subject')->map(fn ($courses, $subject) => ['subject' => $subject, 'courses' => $courses->values()->all()])->values()->all(),
            ],
            'calendar' => [
                'profile' => $calendarReady ? ['id' => $calendar->id, 'name' => $calendar->name] : null,
                'state' => $calendarReady ? 'ready' : ($calendar ? 'needs_review' : 'missing'),
                'summary' => $summary,
                'target' => $year?->instructional_day_target,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function student(Student $student, ?SchoolYear $year, bool $learningFoundationReady): array
    {
        $enrollment = $year ? $student->enrollments->first(fn ($item) => $item->school_year_id === $year->id && in_array($item->status, ['planned', 'active'], true)) : null;
        $membership = $student->user?->memberships->first();
        $accessStatus = match (true) {
            $student->user_id === null => 'not_enabled',
            $membership?->status !== 'active' => 'disabled',
            default => 'enabled',
        };

        return [
            'id' => $student->id,
            'name' => $student->display_name,
            'status' => $student->status,
            'enrollment' => $enrollment ? [
                'id' => $enrollment->id,
                'status' => $enrollment->status,
                'grade' => $enrollment->gradeLevel->name,
                'school_year' => $enrollment->schoolYear->name,
                'enrollment_date' => $enrollment->enrollment_date->format('Y-m-d'),
            ] : null,
            'learning_plan_status' => $enrollment && $learningFoundationReady ? 'ready' : 'setup_needed',
            'access' => [
                'status' => $accessStatus,
                'username' => app(PermissionService::class)->allows('students.manage') ? $student->user?->username : null,
                'needs_password_change' => $accessStatus === 'enabled' && (bool) $student->user?->must_change_password,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function schoolYear(SchoolYear $year): array
    {
        return [
            'id' => $year->id, 'name' => $year->name, 'status' => $year->status,
            'start_date' => $year->start_date->format('Y-m-d'), 'end_date' => $year->end_date->format('Y-m-d'),
            'instructional_weekdays' => $year->instructional_weekdays,
            'instructional_day_target' => $year->instructional_day_target,
        ];
    }

    /** @return array<int, array<string, string>> */
    private function needsAttention(?SchoolYear $year, Collection $students, mixed $configuration, bool $calendarReady, ?array $summary): array
    {
        $items = collect();
        if (! $year) {
            $items->push(['key' => 'school_year', 'title' => 'Choose an active school year', 'detail' => 'A school year anchors enrollment, calendar, and learning plans.', 'route' => 'school-years.index']);
        }
        if ($year && ! $calendarReady) {
            $items->push(['key' => 'calendar', 'title' => 'Finish calendar setup', 'detail' => 'Choose a calendar that covers the active school year.', 'route' => 'workspace.calendar']);
        }
        if ($year && ! $configuration?->curriculum_package_id) {
            $items->push(['key' => 'curriculum', 'title' => 'Choose curriculum', 'detail' => 'Select curriculum before courses can appear in the learning plan.', 'route' => 'workspace.learning-plan']);
        } elseif ($year && $configuration?->curriculumPackage?->courseMappings->isEmpty()) {
            $items->push(['key' => 'courses', 'title' => 'Add courses to the learning plan', 'detail' => 'The selected curriculum does not include any mapped courses yet.', 'route' => 'workspace.learning-plan']);
        }
        if ($students->contains(fn ($student) => $student['enrollment'] === null)) {
            $items->push(['key' => 'enrollment', 'title' => 'Complete student enrollment', 'detail' => 'At least one active student is not enrolled in the active school year.', 'route' => 'students.index']);
        }
        if ($students->contains(fn ($student) => $student['access']['needs_password_change'])) {
            $items->push(['key' => 'student_access', 'title' => 'Student sign-in needs a password change', 'detail' => 'A student must finish their first password change before using the portal.', 'route' => 'students.index']);
        }
        if ($summary && $year?->instructional_day_target !== null && $year->instructional_day_target !== $summary['scheduled_days']) {
            $items->push(['key' => 'day_target', 'title' => 'Review the instructional-day target', 'detail' => "The saved target is {$year->instructional_day_target}; the current schedule calculates {$summary['scheduled_days']} days.", 'route' => 'workspace.calendar']);
        }

        return $items->take(5)->values()->all();
    }

    /** @return array<string, mixed> */
    private function today(string $timezone, ?SchoolYear $year, Collection $events): array
    {
        $today = CarbonImmutable::now($timezone)->format('Y-m-d');
        if (! $year) {
            return ['date' => $today, 'status' => 'not_configured', 'label' => 'No active school year'];
        }
        if ($today < $year->start_date->format('Y-m-d')) {
            return ['date' => $today, 'status' => 'before_year', 'label' => 'School year has not started'];
        }
        if ($today > $year->end_date->format('Y-m-d')) {
            return ['date' => $today, 'status' => 'after_year', 'label' => 'School year has ended'];
        }
        $oneDay = $this->calculator->summarize($today, $today, $year->instructional_weekdays, $events);

        return ['date' => $today, 'status' => $oneDay['scheduled_days'] === 1 ? 'instructional' : 'non_instructional', 'label' => $oneDay['scheduled_days'] === 1 ? 'Instructional day' : 'Non-instructional day'];
    }

    /** @return array<int, array<string, mixed>> */
    private function upcomingEvents(string $timezone, ?SchoolYear $year, Collection $events): array
    {
        if (! $year) {
            return [];
        }
        $today = CarbonImmutable::now($timezone)->format('Y-m-d');

        return $events->filter(fn (CalendarEvent $event) => $event->status === 'active'
                && ($event->end_date ?? $event->event_date)->format('Y-m-d') >= $today)
            ->sortBy('event_date')->take(6)->map(fn (CalendarEvent $event) => [
                'id' => $event->id, 'name' => $event->name, 'event_type' => $event->event_type,
                'event_date' => $event->event_date->format('Y-m-d'),
                'end_date' => $event->end_date?->format('Y-m-d'),
                'instructional_effect' => $event->instructional_effect,
            ])->values()->all();
    }
}
