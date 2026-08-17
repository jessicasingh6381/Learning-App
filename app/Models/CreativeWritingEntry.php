<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class CreativeWritingEntry extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['assigned','in_progress','submitted'];
    protected $fillable = ['student_id','student_enrollment_id','school_year_id','creative_writing_prompt_id','instructional_date','prompt_title_snapshot','prompt_snapshot','include_hints_snapshot','category_snapshot','response','status','word_count','assigned_at','started_at','last_saved_at','submitted_at','teacher_feedback','feedback_by_user_id','feedback_at'];
    protected function casts(): array { return ['instructional_date'=>'date:Y-m-d','include_hints_snapshot'=>'array','word_count'=>'integer','assigned_at'=>'datetime','started_at'=>'datetime','last_saved_at'=>'datetime','submitted_at'=>'datetime','feedback_at'=>'datetime']; }

    protected static function booted(): void
    {
        static::updating(function (self $entry): void {
            $snapshotFields=['student_id','student_enrollment_id','school_year_id','creative_writing_prompt_id','instructional_date','prompt_title_snapshot','prompt_snapshot','include_hints_snapshot','category_snapshot','assigned_at'];
            if ($entry->isDirty($snapshotFields)) throw ValidationException::withMessages(['journal'=>'An assigned journal prompt cannot be replaced or rewritten.']);
            if ($entry->getOriginal('status')==='submitted' && $entry->isDirty(['response','status','word_count','started_at','last_saved_at','submitted_at'])) throw ValidationException::withMessages(['journal'=>'A submitted journal entry cannot be silently changed.']);
        });
    }

    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function enrollment(): BelongsTo { return $this->belongsTo(StudentEnrollment::class, 'student_enrollment_id'); }
    public function schoolYear(): BelongsTo { return $this->belongsTo(SchoolYear::class); }
    public function prompt(): BelongsTo { return $this->belongsTo(CreativeWritingPrompt::class, 'creative_writing_prompt_id'); }
    public function feedbackBy(): BelongsTo { return $this->belongsTo(User::class, 'feedback_by_user_id'); }
}
