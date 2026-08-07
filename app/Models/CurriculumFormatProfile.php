<?php

namespace App\Models;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurriculumFormatProfile extends Model
{
    protected $fillable = [
        'tenant_id', 'ownership_scope', 'education_provider_id', 'subject_id', 'minimum_grade_level_id', 'maximum_grade_level_id',
        'example_academic_source_id', 'example_academic_source_file_id', 'name', 'document_family', 'file_type',
        'recognition_fingerprints', 'mapping_rules', 'detected_structure', 'profile_version', 'status',
        'created_by_user_id', 'reviewed_by_user_id', 'activated_at',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('visible_profile', function (Builder $query): void {
            $tenantId = app(TenantContext::class)->tenantId();
            $query->where(fn ($visible) => $visible->where('tenant_id', $tenantId)->orWhere(fn ($shared) => $shared->whereNull('tenant_id')->where('ownership_scope', 'shared')));
        });
        static::creating(function (self $profile): void {
            if ($profile->ownership_scope !== 'shared') $profile->tenant_id = app(TenantContext::class)->tenantId();
        });
    }

    protected function casts(): array
    {
        return ['recognition_fingerprints' => 'array', 'mapping_rules' => 'array', 'detected_structure' => 'array', 'profile_version' => 'integer', 'activated_at' => 'datetime'];
    }

    public function source(): BelongsTo { return $this->belongsTo(AcademicSource::class, 'example_academic_source_id'); }
    public function sourceFile(): BelongsTo { return $this->belongsTo(AcademicSourceFile::class, 'example_academic_source_file_id'); }
    public function provider(): BelongsTo { return $this->belongsTo(EducationProvider::class, 'education_provider_id'); }
    public function subject(): BelongsTo { return $this->belongsTo(Subject::class); }
    public function minimumGrade(): BelongsTo { return $this->belongsTo(GradeLevel::class, 'minimum_grade_level_id'); }
    public function maximumGrade(): BelongsTo { return $this->belongsTo(GradeLevel::class, 'maximum_grade_level_id'); }
}
