<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LessonPlan extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['generating', 'draft', 'reviewed', 'approved', 'failed'];

    protected $fillable = [
        'student_enrollment_id', 'curriculum_import_id', 'curriculum_package_course_id',
        'status', 'revision', 'generator_key', 'generator_version', 'generation_metadata',
        'failure_diagnostic', 'generated_at', 'reviewed_at', 'approved_at',
        'created_by_user_id', 'reviewed_by_user_id', 'approved_by_user_id',
        'superseded_by_lesson_plan_id',
    ];

    protected function casts(): array
    {
        return [
            'revision' => 'integer', 'generation_metadata' => 'array',
            'generated_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo { return $this->belongsTo(StudentEnrollment::class, 'student_enrollment_id'); }
    public function curriculumImport(): BelongsTo { return $this->belongsTo(CurriculumImport::class); }
    public function packageCourse(): BelongsTo { return $this->belongsTo(CurriculumPackageCourse::class, 'curriculum_package_course_id'); }
    public function lessons(): HasMany { return $this->hasMany(Lesson::class)->orderBy('sequence'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function reviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by_user_id'); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by_user_id'); }
    public function supersededBy(): BelongsTo { return $this->belongsTo(self::class, 'superseded_by_lesson_plan_id'); }
}
