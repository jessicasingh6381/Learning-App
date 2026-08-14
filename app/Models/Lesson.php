<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lesson extends Model
{
    use BelongsToTenant;

    public const MODES = ['full', 'review', 'practice', 'assessment', 'remediation'];
    public const STATUSES = ['generating', 'draft', 'reviewed', 'approved', 'failed'];

    protected $fillable = [
        'lesson_plan_id', 'curriculum_unit_id', 'sequence', 'title', 'lesson_mode', 'status',
        'learning_objective', 'completion_criteria', 'estimated_minutes',
        'estimated_preparation_minutes', 'suggested_sessions', 'generator_key', 'generator_version',
        'generation_metadata', 'approved_at', 'approved_by_user_id',
    ];

    protected $attributes = ['lesson_mode' => 'full', 'status' => 'draft'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer', 'estimated_minutes' => 'integer',
            'estimated_preparation_minutes' => 'integer', 'suggested_sessions' => 'integer',
            'generation_metadata' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $lesson): void {
            if ($lesson->getOriginal('status') === 'approved') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'lesson' => 'Approved lesson content is immutable. Create a new lesson-plan revision instead.',
                ]);
            }
        });
        static::deleting(function (self $lesson): void {
            if ($lesson->status === 'approved') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'lesson' => 'Approved lessons cannot be deleted. Create a new lesson-plan revision instead.',
                ]);
            }
        });
    }

    public function lessonPlan(): BelongsTo { return $this->belongsTo(LessonPlan::class); }
    public function curriculumUnit(): BelongsTo { return $this->belongsTo(CurriculumUnit::class); }
    public function sections(): HasMany { return $this->hasMany(LessonSection::class)->whereNull('parent_section_id')->orderBy('sequence'); }
    public function allSections(): HasMany { return $this->hasMany(LessonSection::class)->orderBy('sequence'); }
    public function resources(): HasMany { return $this->hasMany(LessonResource::class)->orderBy('category')->orderBy('sort_order'); }
    public function experience(): HasOne { return $this->hasOne(LessonExperience::class); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by_user_id'); }
    public function curriculumComponents(): BelongsToMany
    {
        return $this->belongsToMany(CurriculumUnitComponent::class, 'lesson_curriculum_components')
            ->withPivot(['tenant_id', 'role', 'sequence'])->withTimestamps()->orderByPivot('sequence');
    }
    public function standardAlignments(): BelongsToMany
    {
        return $this->belongsToMany(
            CurriculumUnitStandardAlignment::class,
            'lesson_standard_alignments',
            'lesson_id',
            'curriculum_unit_standard_alignment_id'
        )->withPivot('tenant_id')->withTimestamps();
    }
}
