<?php

namespace App\Services;

final class AcademyMigrationManifest
{
    /** Application data in foreign-key-safe order. Framework/runtime tables are absent. @var list<string> */
    public const TABLES = [
        'users', 'tenants', 'grade_levels', 'tenant_memberships', 'students', 'school_years',
        'student_enrollments', 'education_providers', 'standards_frameworks', 'subjects',
        'calendar_profiles', 'courses', 'curriculum_packages', 'curriculum_package_courses',
        'academic_year_configurations', 'academic_sources', 'academic_source_files',
        'academic_source_links', 'calendar_imports', 'calendar_import_proposals', 'calendar_events',
        'curriculum_format_profiles', 'curriculum_parser_capabilities', 'curriculum_imports',
        'curriculum_import_proposals', 'curriculum_periods', 'curriculum_units',
        'curriculum_unit_components', 'standards', 'curriculum_unit_standard_alignments',
        'learning_plan_subject_preferences', 'lesson_plans', 'lessons', 'lesson_sections',
        'lesson_curriculum_components', 'lesson_standard_alignments', 'lesson_resources',
        'lesson_experiences', 'lesson_activities', 'student_lesson_progress',
        'student_activity_responses', 'creative_writing_prompts', 'creative_writing_entries', 'audit_logs',
    ];

    /** @var array<string, list<string>> */
    public const DEFERRED_SELF_REFERENCES = [
        'curriculum_import_proposals' => ['parent_proposal_id'],
        'curriculum_unit_components' => ['parent_component_id'],
        'standards' => ['parent_standard_id'],
        'lesson_plans' => ['superseded_by_lesson_plan_id'],
        'lesson_sections' => ['parent_section_id'],
    ];

    /** @var list<string> */
    public const EXCLUDED_RUNTIME_TABLES = [
        'cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'migrations',
        'password_reset_tokens', 'sessions',
    ];

    /** @var list<string> */
    public const KEY_TABLES = [
        'users', 'tenants', 'students', 'school_years', 'calendar_events', 'curriculum_imports',
        'curriculum_units', 'lesson_plans', 'lessons', 'lesson_resources', 'student_lesson_progress',
        'creative_writing_prompts', 'creative_writing_entries',
    ];
}
