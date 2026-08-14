<?php

namespace App\Services;

use App\Models\AcademicSource;
use App\Models\AcademicSourceFile;
use App\Models\AcademicSourceLink;
use App\Models\AcademicYearConfiguration;
use App\Models\AuditLog;
use App\Models\CalendarEvent;
use App\Models\CalendarImport;
use App\Models\CalendarImportProposal;
use App\Models\CalendarProfile;
use App\Models\Course;
use App\Models\CurriculumPackage;
use App\Models\CurriculumPackageCourse;
use App\Models\CurriculumImport;
use App\Models\CurriculumFormatProfile;
use App\Models\CurriculumImportProposal;
use App\Models\CurriculumPeriod;
use App\Models\CurriculumUnit;
use App\Models\CurriculumUnitComponent;
use App\Models\CurriculumUnitStandardAlignment;
use App\Models\Lesson;
use App\Models\LessonPlan;
use App\Models\LessonSection;
use App\Models\LessonActivity;
use App\Models\LessonExperience;
use App\Models\LessonResource;
use App\Models\StudentActivityResponse;
use App\Models\StudentLessonProgress;
use App\Models\Standard;
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
        CalendarEvent::class => ['calendar_profile_id', 'calendar_import_id', 'calendar_import_proposal_id', 'event_date', 'end_date', 'event_type', 'name', 'instructional_effect', 'status', 'source_reference'],
        CalendarImport::class => ['academic_source_id', 'academic_source_file_id', 'school_year_id', 'calendar_profile_id', 'status', 'extraction_method', 'parser_version', 'proposed_first_day', 'proposed_last_day', 'update_school_year_dates', 'approved_by_user_id', 'approved_at'],
        CalendarImportProposal::class => ['calendar_import_id', 'event_date', 'end_date', 'name', 'event_type', 'instructional_effect', 'confidence', 'source_page', 'included', 'manually_edited'],
        StandardsFramework::class => ['education_provider_id', 'name', 'short_name', 'jurisdiction', 'version_label', 'effective_start_date', 'effective_end_date', 'status', 'source_url'],
        Subject::class => ['name', 'code', 'sort_order', 'status'],
        Course::class => ['subject_id', 'standards_framework_id', 'education_provider_id', 'name', 'code', 'minimum_grade_level_id', 'maximum_grade_level_id', 'status'],
        CurriculumPackage::class => ['education_provider_id', 'standards_framework_id', 'name', 'version_label', 'status', 'effective_start_date', 'effective_end_date', 'source_url'],
        CurriculumPackageCourse::class => ['curriculum_package_id', 'course_id', 'grade_level_id', 'sort_order', 'required'],
        CurriculumImport::class => ['academic_source_id', 'academic_source_file_id', 'curriculum_package_id', 'curriculum_package_course_id', 'subject_id', 'grade_level_id', 'school_year_id', 'standards_framework_id', 'import_type', 'import_context_key', 'status', 'parser_key', 'parser_version', 'extraction_method', 'source_title', 'source_revision_date', 'document_section', 'adopted_label', 'introduction_text', 'document_metadata', 'diagnostic', 'review_version', 'approved_by_user_id', 'approved_at'],
        CurriculumFormatProfile::class => ['tenant_id', 'ownership_scope', 'education_provider_id', 'subject_id', 'minimum_grade_level_id', 'maximum_grade_level_id', 'example_academic_source_id', 'example_academic_source_file_id', 'name', 'document_family', 'file_type', 'recognition_fingerprints', 'mapping_rules', 'profile_version', 'status', 'created_by_user_id', 'reviewed_by_user_id', 'activated_at'],
        CurriculumImportProposal::class => ['curriculum_import_id', 'extraction_generation', 'parent_proposal_id', 'proposal_type', 'included', 'sequence', 'name', 'description', 'summary', 'planned_start_date', 'planned_end_date', 'estimated_days', 'unit_type', 'component_type', 'reporting_period', 'standard_codes', 'strand', 'standard_code', 'normalized_code', 'statement', 'confidence', 'source_page', 'parser_metadata', 'manually_edited', 'superseded_at'],
        CurriculumPeriod::class => ['curriculum_package_course_id', 'name', 'sequence', 'planned_start_date', 'planned_end_date', 'period_type', 'status', 'academic_source_id', 'academic_source_file_id', 'curriculum_import_id', 'curriculum_import_proposal_id'],
        CurriculumUnit::class => ['curriculum_period_id', 'curriculum_package_course_id', 'name', 'summary', 'sequence', 'planned_start_date', 'planned_end_date', 'estimated_days', 'unit_type', 'included', 'metadata', 'academic_source_id', 'academic_source_file_id', 'curriculum_import_id', 'curriculum_import_proposal_id'],
        CurriculumUnitComponent::class => ['curriculum_unit_id', 'parent_component_id', 'component_type', 'name', 'description', 'sequence', 'planned_start_date', 'planned_end_date', 'academic_source_id', 'academic_source_file_id', 'curriculum_import_id', 'curriculum_import_proposal_id'],
        CurriculumUnitStandardAlignment::class => ['curriculum_unit_id', 'standards_framework_id', 'standard_id', 'standard_code', 'normalized_code', 'academic_source_id', 'academic_source_file_id', 'curriculum_import_id', 'curriculum_import_proposal_id'],
        LessonPlan::class => ['student_enrollment_id', 'curriculum_import_id', 'curriculum_package_course_id', 'status', 'revision', 'generator_key', 'generator_version', 'generation_metadata', 'failure_diagnostic', 'generated_at', 'reviewed_at', 'approved_at', 'created_by_user_id', 'reviewed_by_user_id', 'approved_by_user_id', 'superseded_by_lesson_plan_id'],
        Lesson::class => ['lesson_plan_id', 'curriculum_unit_id', 'sequence', 'title', 'lesson_mode', 'status', 'learning_objective', 'completion_criteria', 'estimated_minutes', 'estimated_preparation_minutes', 'suggested_sessions', 'generator_key', 'generator_version', 'generation_metadata', 'approved_at', 'approved_by_user_id'],
        LessonSection::class => ['lesson_id', 'parent_section_id', 'section_type', 'sequence', 'title', 'content', 'audience', 'estimated_minutes', 'metadata'],
        LessonExperience::class => ['lesson_id', 'status', 'theme_key', 'mission_title', 'mission_brief', 'completion_title', 'completion_message', 'source_version'],
        LessonResource::class => ['lesson_id', 'category', 'resource_type', 'title', 'description', 'delivery_type', 'availability_status', 'fulfillment_strategy', 'fulfillment_provider', 'source_url', 'source_attribution', 'license_name', 'license_url', 'sort_order', 'original_filename', 'mime_type', 'checksum_sha256', 'file_size', 'generated_by', 'generated_at', 'fulfillment_attempted_at'],
        LessonActivity::class => ['lesson_experience_id', 'source_lesson_section_id', 'sequence', 'activity_type', 'display_title', 'student_instructions', 'requires_teacher_review', 'reward_label', 'theme_key'],
        StudentLessonProgress::class => ['lesson_experience_id', 'student_enrollment_id', 'previewed_by_user_id', 'current_activity_id', 'is_preview', 'status', 'started_at', 'last_activity_at', 'completed_at'],
        StudentActivityResponse::class => ['student_lesson_progress_id', 'lesson_activity_id', 'status', 'is_correct', 'teacher_review_status', 'completed_at'],
        Standard::class => ['tenant_id', 'ownership_key', 'standards_framework_id', 'subject_id', 'grade_level_id', 'parent_standard_id', 'record_type', 'title', 'standard_code', 'normalized_code', 'strand', 'statement', 'sequence', 'version_label', 'adopted_label', 'effective_label', 'status', 'academic_source_id', 'academic_source_file_id', 'curriculum_import_id', 'curriculum_import_proposal_id'],
        AcademicYearConfiguration::class => ['school_year_id', 'education_provider_id', 'calendar_profile_id', 'standards_framework_id', 'curriculum_package_id', 'status', 'configured_by_user_id', 'configured_at'],
        AcademicSource::class => [
            'education_provider_id', 'school_year_id', 'grade_level_id', 'title', 'source_kind',
            'source_category', 'authority_level', 'review_status', 'processing_status',
            'publication_date', 'retrieved_at', 'version_label', 'academic_year_label', 'archived_at',
        ],
        AcademicSourceFile::class => [
            'academic_source_id', 'uploaded_by_user_id', 'version_number', 'is_current',
            'original_filename', 'mime_type', 'extension', 'file_size', 'checksum_sha256', 'uploaded_at',
        ],
        AcademicSourceLink::class => ['academic_source_id', 'link_type', 'link_id'],
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
