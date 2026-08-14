<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LessonActivity extends Model
{
    use BelongsToTenant;

    protected $fillable = ['lesson_experience_id', 'source_lesson_section_id', 'sequence', 'activity_type', 'display_title', 'student_instructions', 'content', 'interaction_data', 'answer_data', 'hints', 'feedback', 'completion_condition', 'reward_label', 'theme_key', 'requires_teacher_review'];

    protected function casts(): array { return ['sequence' => 'integer', 'interaction_data' => 'array', 'answer_data' => 'array', 'hints' => 'array', 'feedback' => 'array', 'completion_condition' => 'array', 'requires_teacher_review' => 'boolean']; }
    protected static function booted(): void
    {
        $approved = fn (self $activity): bool => $activity->experience()->whereHas('lesson', fn ($query) => $query->where('status', 'approved'))->exists();
        static::creating(fn (self $activity) => LessonExperience::query()->whereKey($activity->lesson_experience_id)
            ->whereHas('lesson', fn ($query) => $query->where('status', 'approved'))->exists()
            ? throw \Illuminate\Validation\ValidationException::withMessages(['lesson' => 'Approved student activities are immutable. Create a new lesson-plan revision instead.'])
            : null);
        static::updating(fn (self $activity) => $approved($activity)
            ? throw \Illuminate\Validation\ValidationException::withMessages(['lesson' => 'Approved student activities are immutable. Create a new lesson-plan revision instead.'])
            : null);
        static::deleting(fn (self $activity) => $approved($activity)
            ? throw \Illuminate\Validation\ValidationException::withMessages(['lesson' => 'Approved student activities are immutable. Create a new lesson-plan revision instead.'])
            : null);
    }
    public function experience(): BelongsTo { return $this->belongsTo(LessonExperience::class, 'lesson_experience_id'); }
    public function sourceSection(): BelongsTo { return $this->belongsTo(LessonSection::class, 'source_lesson_section_id'); }
    public function responses(): HasMany { return $this->hasMany(StudentActivityResponse::class); }
}
