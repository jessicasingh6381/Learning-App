<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentLessonProgress extends Model
{
    use BelongsToTenant;

    protected $table = 'student_lesson_progress';
    protected $fillable = ['lesson_experience_id', 'student_enrollment_id', 'previewed_by_user_id', 'current_activity_id', 'is_preview', 'status', 'started_at', 'last_activity_at', 'completed_at'];
    protected function casts(): array { return ['is_preview' => 'boolean', 'started_at' => 'datetime', 'last_activity_at' => 'datetime', 'completed_at' => 'datetime']; }
    public function experience(): BelongsTo { return $this->belongsTo(LessonExperience::class, 'lesson_experience_id'); }
    public function enrollment(): BelongsTo { return $this->belongsTo(StudentEnrollment::class, 'student_enrollment_id'); }
    public function currentActivity(): BelongsTo { return $this->belongsTo(LessonActivity::class, 'current_activity_id'); }
    public function responses(): HasMany { return $this->hasMany(StudentActivityResponse::class)->orderBy('id'); }
}
