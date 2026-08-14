<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CurriculumUnitStandardAlignment extends Model
{
    protected $fillable = [
        'curriculum_unit_id', 'standards_framework_id', 'standard_id', 'standard_code', 'normalized_code',
        'academic_source_id', 'academic_source_file_id', 'curriculum_import_id',
        'curriculum_import_proposal_id', 'source_page', 'source_raw_text', 'parser_key',
        'parser_version', 'source_confidence', 'source_note',
    ];

    protected static function booted(): void { static::addGlobalScope('unit_tenant', fn (Builder $query) => $query->whereHas('curriculumUnit')); }
    protected function casts(): array { return ['source_confidence' => 'float']; }
    public function curriculumUnit(): BelongsTo { return $this->belongsTo(CurriculumUnit::class); }
    public function standardsFramework(): BelongsTo { return $this->belongsTo(StandardsFramework::class); }
    public function standard(): BelongsTo { return $this->belongsTo(Standard::class); }
    public function lessons(): BelongsToMany
    {
        return $this->belongsToMany(
            Lesson::class,
            'lesson_standard_alignments',
            'curriculum_unit_standard_alignment_id',
            'lesson_id'
        )->withPivot('tenant_id')->withTimestamps();
    }
}
