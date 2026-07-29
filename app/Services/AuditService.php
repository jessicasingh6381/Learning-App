<?php

namespace App\Services;

use App\Models\AcademicYearConfiguration;
use App\Models\AuditLog;
use App\Models\CalendarEvent;
use App\Models\CalendarProfile;
use App\Models\Course;
use App\Models\CurriculumPackage;
use App\Models\CurriculumPackageCourse;
use App\Models\EducationProvider;
use App\Models\SchoolYear;
use App\Models\StandardsFramework;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditService
{
    private const FIELDS = [
        Student::class => ['user_id', 'student_access_enabled_at', 'first_name', 'middle_name', 'last_name', 'preferred_name', 'status', 'archived_at'],
        User::class => ['username', 'must_change_password', 'last_login_at'],
        SchoolYear::class => [
            'name',
            'start_date',
            'end_date',
            'timezone',
            'status',
            'instructional_day_target',
            'instructional_week_type',
            'instructional_weekdays',
        ],
        StudentEnrollment::class => ['student_id', 'school_year_id', 'grade_level_id', 'enrollment_date', 'completion_date', 'status'],
        TenantMembership::class => ['user_id', 'role', 'status'],
        EducationProvider::class => ['name', 'short_name', 'provider_type', 'state_or_region', 'country_code', 'website_url', 'status'],
        CalendarProfile::class => ['education_provider_id', 'name', 'academic_year_label', 'start_date', 'end_date', 'timezone', 'status', 'source_type', 'source_url', 'source_version'],
        CalendarEvent::class => ['calendar_profile_id', 'event_date', 'end_date', 'event_type', 'name', 'instructional_effect', 'status', 'source_reference'],
        StandardsFramework::class => ['education_provider_id', 'name', 'short_name', 'jurisdiction', 'version_label', 'effective_start_date', 'effective_end_date', 'status', 'source_url'],
        Subject::class => ['name', 'code', 'sort_order', 'status'],
        Course::class => ['subject_id', 'standards_framework_id', 'education_provider_id', 'name', 'code', 'minimum_grade_level_id', 'maximum_grade_level_id', 'status'],
        CurriculumPackage::class => ['education_provider_id', 'standards_framework_id', 'name', 'version_label', 'status', 'effective_start_date', 'effective_end_date', 'source_url'],
        CurriculumPackageCourse::class => ['curriculum_package_id', 'course_id', 'grade_level_id', 'sort_order', 'required'],
        AcademicYearConfiguration::class => ['school_year_id', 'education_provider_id', 'calendar_profile_id', 'standards_framework_id', 'curriculum_package_id', 'status', 'configured_by_user_id', 'configured_at'],
    ];

    public function record(string $action, Model $model, array $before = [], array $after = []): void
    {
        if (! app(TenantContext::class)->hasTenant()) {
            throw new LogicException('Tenant administrative audit records require an active tenant context.');
        }

        $allowed = self::FIELDS[$model::class] ?? [];
        $clean = fn (array $values) => collect($values)->only($allowed)->all();

        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $model::class,
            'auditable_id' => (string) $model->getKey(),
            'old_values' => $clean($before) ?: null,
            'new_values' => $clean($after) ?: null,
        ]);
    }
}
