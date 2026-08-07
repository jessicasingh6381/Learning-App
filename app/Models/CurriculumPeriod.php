<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CurriculumPeriod extends Model
{
    protected $fillable = [
        'curriculum_package_course_id', 'name', 'sequence', 'planned_start_date', 'planned_end_date',
        'period_type', 'status', 'academic_source_id', 'academic_source_file_id', 'curriculum_import_id',
        'curriculum_import_proposal_id', 'source_page', 'source_raw_text', 'parser_key', 'parser_version',
        'source_confidence', 'source_note',
    ];

    protected static function booted(): void { static::addGlobalScope('target_tenant', fn (Builder $query) => $query->whereHas('packageCourse')); }
    protected function casts(): array { return ['sequence' => 'integer', 'planned_start_date' => 'date:Y-m-d', 'planned_end_date' => 'date:Y-m-d', 'source_confidence' => 'float']; }
    public function packageCourse(): BelongsTo { return $this->belongsTo(CurriculumPackageCourse::class, 'curriculum_package_course_id'); }
    public function units(): HasMany { return $this->hasMany(CurriculumUnit::class)->orderBy('sequence'); }
}
