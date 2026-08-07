<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentEnrollment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['student_id', 'school_year_id', 'grade_level_id', 'enrollment_date', 'completion_date', 'status'];

    protected function casts(): array
    {
        return ['enrollment_date' => 'date', 'completion_date' => 'date'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    public function subjectPreferences(): HasMany
    {
        return $this->hasMany(LearningPlanSubjectPreference::class);
    }
}
