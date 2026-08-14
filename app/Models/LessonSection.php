<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LessonSection extends Model
{
    use BelongsToTenant;

    public const AUDIENCES = ['teacher', 'student', 'shared'];

    protected $fillable = [
        'lesson_id', 'parent_section_id', 'section_type', 'sequence', 'title', 'content',
        'audience', 'estimated_minutes', 'metadata',
    ];

    protected $attributes = ['audience' => 'shared'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'estimated_minutes' => 'integer', 'metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $section) => Lesson::query()->whereKey($section->lesson_id)->where('status', 'approved')->exists()
            ? throw \Illuminate\Validation\ValidationException::withMessages(['lesson' => 'Approved lesson content is immutable. Create a new lesson-plan revision instead.'])
            : null);
        static::updating(fn (self $section) => $section->lesson()->where('status', 'approved')->exists()
            ? throw \Illuminate\Validation\ValidationException::withMessages(['lesson' => 'Approved lesson content is immutable. Create a new lesson-plan revision instead.'])
            : null);
        static::deleting(fn (self $section) => $section->lesson()->where('status', 'approved')->exists()
            ? throw \Illuminate\Validation\ValidationException::withMessages(['lesson' => 'Approved lesson content is immutable. Create a new lesson-plan revision instead.'])
            : null);
    }

    public function lesson(): BelongsTo { return $this->belongsTo(Lesson::class); }
    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_section_id'); }
    public function children(): HasMany { return $this->hasMany(self::class, 'parent_section_id')->orderBy('sequence'); }
    public function descendants(): HasMany { return $this->children()->with('descendants'); }
}
