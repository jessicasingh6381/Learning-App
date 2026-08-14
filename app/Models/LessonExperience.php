<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LessonExperience extends Model
{
    use BelongsToTenant;

    protected $fillable = ['lesson_id', 'status', 'theme_key', 'mission_title', 'mission_brief', 'completion_title', 'completion_message', 'source_version'];

    protected static function booted(): void
    {
        static::updating(function (self $experience): void {
            $instructional = ['theme_key', 'mission_title', 'mission_brief', 'completion_title', 'completion_message', 'source_version'];
            if ($experience->isDirty($instructional) && $experience->lesson()->where('status', 'approved')->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'lesson' => 'Approved student experience content is immutable. Create a new lesson-plan revision instead.',
                ]);
            }
        });
        static::deleting(function (self $experience): void {
            if ($experience->lesson()->where('status', 'approved')->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'lesson' => 'Approved student experience content is immutable. Create a new lesson-plan revision instead.',
                ]);
            }
        });
    }

    public function lesson(): BelongsTo { return $this->belongsTo(Lesson::class); }
    public function activities(): HasMany { return $this->hasMany(LessonActivity::class)->orderBy('sequence'); }
    public function progresses(): HasMany { return $this->hasMany(StudentLessonProgress::class); }
}
