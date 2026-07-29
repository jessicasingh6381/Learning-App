<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurriculumPackageCourse extends Model
{
    protected $fillable = [
        'curriculum_package_id', 'course_id', 'grade_level_id', 'grade_context_key',
        'sort_order', 'required',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('package_visibility', fn (Builder $builder) => $builder->whereHas('curriculumPackage'));
        static::saving(function (CurriculumPackageCourse $mapping): void {
            $mapping->grade_context_key = $mapping->grade_level_id
                ? 'grade:'.$mapping->grade_level_id
                : 'all';
        });
    }

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'required' => 'boolean'];
    }

    public function curriculumPackage(): BelongsTo
    {
        return $this->belongsTo(CurriculumPackage::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }
}
