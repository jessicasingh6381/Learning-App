<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningPlanSubjectPreference extends Model
{
    use BelongsToTenant;

    protected $fillable = ['student_enrollment_id', 'subject_id', 'is_hidden', 'hidden_at', 'hidden_by_user_id'];

    protected function casts(): array
    {
        return ['is_hidden' => 'boolean', 'hidden_at' => 'datetime'];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'student_enrollment_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_by_user_id');
    }
}
