<?php

namespace App\Domain\AcademicSources;

use App\Models\AcademicYearConfiguration;
use App\Models\CalendarProfile;
use App\Models\Course;
use App\Models\CurriculumPackage;
use App\Models\EducationProvider;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\StandardsFramework;
use App\Models\Subject;

final class AcademicSourceOptions
{
    public const KINDS = ['upload', 'url', 'manual'];

    public const CATEGORIES = [
        'calendar', 'curriculum', 'pacing', 'standards', 'course_guide',
        'scope_and_sequence', 'handbook', 'policy', 'assessment', 'reference', 'other',
    ];

    public const AUTHORITY_LEVELS = [
        'official_provider', 'official_state', 'tenant_created', 'third_party', 'unknown',
    ];

    public const REVIEW_STATUSES = ['unreviewed', 'in_review', 'reviewed', 'rejected', 'archived'];

    public const PROCESSING_STATUSES = [
        'not_requested', 'pending', 'processing', 'completed', 'failed', 'not_applicable',
    ];

    public const REVIEW_TRANSITIONS = [
        'unreviewed' => ['in_review', 'rejected', 'archived'],
        'in_review' => ['reviewed', 'rejected', 'archived'],
        'reviewed' => ['rejected', 'archived'],
        'rejected' => ['in_review', 'archived'],
        'archived' => [],
    ];

    public const LINK_TYPES = [
        'education_provider' => EducationProvider::class,
        'school_year' => SchoolYear::class,
        'calendar_profile' => CalendarProfile::class,
        'standards_framework' => StandardsFramework::class,
        'grade_level' => GradeLevel::class,
        'subject' => Subject::class,
        'course' => Course::class,
        'curriculum_package' => CurriculumPackage::class,
        'academic_configuration' => AcademicYearConfiguration::class,
    ];
}
