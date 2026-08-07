<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CurriculumImportProposal extends Model
{
    protected $fillable = [
        'curriculum_import_id', 'extraction_generation', 'parent_proposal_id', 'proposal_type', 'included', 'sequence',
        'name', 'description', 'summary', 'planned_start_date', 'planned_end_date', 'estimated_days', 'unit_type', 'component_type',
        'reporting_period', 'standard_codes', 'strand', 'standard_code', 'normalized_code', 'statement',
        'source_page', 'raw_text', 'parser_note',
        'confidence', 'manually_edited', 'superseded_at', 'original_values', 'parser_metadata',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('import_tenant', fn (Builder $query) => $query->whereHas('curriculumImport'));
        static::addGlobalScope('active_generation', fn (Builder $query) => $query->whereNull('superseded_at'));
    }

    protected function casts(): array
    {
        return [
            'included' => 'boolean', 'sequence' => 'integer', 'estimated_days' => 'integer',
            'planned_start_date' => 'date:Y-m-d', 'planned_end_date' => 'date:Y-m-d',
            'standard_codes' => 'array', 'confidence' => 'float', 'manually_edited' => 'boolean',
            'original_values' => 'array', 'parser_metadata' => 'array', 'superseded_at' => 'datetime',
        ];
    }

    public function curriculumImport(): BelongsTo { return $this->belongsTo(CurriculumImport::class); }
    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_proposal_id'); }
    public function children(): HasMany { return $this->hasMany(self::class, 'parent_proposal_id')->orderBy('sequence'); }
}
