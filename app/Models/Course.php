<?php

namespace App\Models;

use App\Models\Concerns\HasAcademicVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Course extends Model
{
    use HasAcademicVisibility;

    protected $fillable = [
        'subject_id', 'standards_framework_id', 'education_provider_id', 'name', 'code',
        'description', 'minimum_grade_level_id', 'maximum_grade_level_id', 'status',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function standardsFramework(): BelongsTo
    {
        return $this->belongsTo(StandardsFramework::class);
    }

    public function educationProvider(): BelongsTo
    {
        return $this->belongsTo(EducationProvider::class);
    }

    public function minimumGradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class, 'minimum_grade_level_id');
    }

    public function maximumGradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class, 'maximum_grade_level_id');
    }
}
