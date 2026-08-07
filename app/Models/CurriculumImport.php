<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CurriculumImport extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'academic_source_id', 'academic_source_file_id', 'curriculum_package_id',
        'curriculum_package_course_id', 'subject_id', 'grade_level_id', 'school_year_id',
        'standards_framework_id', 'import_type', 'import_context_key', 'created_by_user_id', 'approved_by_user_id', 'status',
        'parser_key', 'parser_version', 'extraction_method', 'source_title',
        'source_revision_date', 'document_section', 'adopted_label', 'introduction_text', 'document_metadata',
        'diagnostic', 'review_version', 'started_at',
        'completed_at', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'source_revision_date' => 'date:Y-m-d', 'document_metadata' => 'array', 'review_version' => 'integer',
            'started_at' => 'datetime', 'completed_at' => 'datetime', 'approved_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo { return $this->belongsTo(AcademicSource::class, 'academic_source_id'); }
    public function sourceFile(): BelongsTo { return $this->belongsTo(AcademicSourceFile::class, 'academic_source_file_id'); }
    public function curriculumPackage(): BelongsTo { return $this->belongsTo(CurriculumPackage::class); }
    public function packageCourse(): BelongsTo { return $this->belongsTo(CurriculumPackageCourse::class, 'curriculum_package_course_id'); }
    public function subject(): BelongsTo { return $this->belongsTo(Subject::class); }
    public function gradeLevel(): BelongsTo { return $this->belongsTo(GradeLevel::class); }
    public function schoolYear(): BelongsTo { return $this->belongsTo(SchoolYear::class); }
    public function standardsFramework(): BelongsTo { return $this->belongsTo(StandardsFramework::class); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by_user_id'); }
    public function proposals(): HasMany { return $this->hasMany(CurriculumImportProposal::class)->orderBy('sequence')->orderBy('id'); }
    public function periods(): HasMany { return $this->hasMany(CurriculumPeriod::class); }
    public function units(): HasMany { return $this->hasMany(CurriculumUnit::class); }
    public function standardAlignments(): HasMany { return $this->hasMany(CurriculumUnitStandardAlignment::class); }
    public function standards(): HasMany { return $this->hasMany(Standard::class); }
}
