<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class AcademicSource extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'created_by_user_id', 'education_provider_id', 'school_year_id', 'grade_level_id',
        'title', 'description', 'source_kind', 'source_category', 'authority_level',
        'review_status', 'processing_status', 'source_url', 'publication_date',
        'retrieved_at', 'version_label', 'academic_year_label', 'notes', 'archived_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (AcademicSource $source): void {
            $source->uuid ??= (string) Str::uuid();
            $source->created_by_user_id ??= auth()->id();
        });
    }

    protected function casts(): array
    {
        return [
            'publication_date' => 'date:Y-m-d',
            'retrieved_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function educationProvider(): BelongsTo
    {
        return $this->belongsTo(EducationProvider::class);
    }

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(AcademicSourceFile::class)->orderByDesc('version_number');
    }

    public function currentFile(): HasOne
    {
        return $this->hasOne(AcademicSourceFile::class)->where('is_current', true);
    }

    public function links(): HasMany
    {
        return $this->hasMany(AcademicSourceLink::class);
    }
}
