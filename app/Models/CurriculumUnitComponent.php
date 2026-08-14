<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CurriculumUnitComponent extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'curriculum_unit_id', 'parent_component_id', 'component_type', 'name', 'description',
        'sequence', 'planned_start_date', 'planned_end_date', 'metadata', 'academic_source_id',
        'academic_source_file_id', 'curriculum_import_id', 'curriculum_import_proposal_id',
        'source_page', 'source_raw_text', 'parser_key', 'parser_version', 'source_confidence', 'source_note',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer', 'planned_start_date' => 'date:Y-m-d',
            'planned_end_date' => 'date:Y-m-d', 'metadata' => 'array', 'source_confidence' => 'float',
        ];
    }

    public function unit(): BelongsTo { return $this->belongsTo(CurriculumUnit::class, 'curriculum_unit_id'); }
    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_component_id'); }
    public function children(): HasMany { return $this->hasMany(self::class, 'parent_component_id')->orderBy('sequence'); }
    public function descendants(): HasMany { return $this->children()->with('descendants'); }
    public function import(): BelongsTo { return $this->belongsTo(CurriculumImport::class, 'curriculum_import_id'); }
    public function proposal(): BelongsTo { return $this->belongsTo(CurriculumImportProposal::class, 'curriculum_import_proposal_id'); }
    public function lessons(): BelongsToMany
    {
        return $this->belongsToMany(Lesson::class, 'lesson_curriculum_components')
            ->withPivot(['tenant_id', 'role', 'sequence'])->withTimestamps();
    }
}
