<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentActivityResponse extends Model
{
    use BelongsToTenant;

    protected $fillable = ['student_lesson_progress_id', 'lesson_activity_id', 'status', 'response', 'is_correct', 'feedback', 'teacher_review_status', 'completed_at'];
    protected function casts(): array { return ['response' => 'array', 'is_correct' => 'boolean', 'completed_at' => 'datetime']; }
    public function progress(): BelongsTo { return $this->belongsTo(StudentLessonProgress::class, 'student_lesson_progress_id'); }
    public function activity(): BelongsTo { return $this->belongsTo(LessonActivity::class, 'lesson_activity_id'); }
}
