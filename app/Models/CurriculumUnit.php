<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CurriculumUnit extends Model
{
    protected $fillable = [
        'curriculum_period_id', 'curriculum_package_course_id', 'name', 'summary', 'sequence',
        'planned_start_date', 'planned_end_date', 'estimated_days', 'unit_type', 'included',
        'academic_source_id', 'academic_source_file_id', 'curriculum_import_id',
        'curriculum_import_proposal_id', 'source_page', 'source_raw_text', 'parser_key',
        'parser_version', 'source_confidence', 'source_note',
    ];

    protected static function booted(): void { static::addGlobalScope('target_tenant', fn (Builder $query) => $query->whereHas('packageCourse')); }
    protected function casts(): array { return ['sequence' => 'integer', 'planned_start_date' => 'date:Y-m-d', 'planned_end_date' => 'date:Y-m-d', 'estimated_days' => 'integer', 'included' => 'boolean', 'source_confidence' => 'float']; }
    public function period(): BelongsTo { return $this->belongsTo(CurriculumPeriod::class, 'curriculum_period_id'); }
    public function packageCourse(): BelongsTo { return $this->belongsTo(CurriculumPackageCourse::class, 'curriculum_package_course_id'); }
    public function standardAlignments(): HasMany { return $this->hasMany(CurriculumUnitStandardAlignment::class); }
    public function components(): HasMany { return $this->hasMany(CurriculumUnitComponent::class)->whereNull('parent_component_id')->orderBy('sequence'); }
    public function allComponents(): HasMany { return $this->hasMany(CurriculumUnitComponent::class)->orderBy('sequence'); }
}
