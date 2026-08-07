<?php

namespace App\Models;

use App\Models\Concerns\HasAcademicVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Standard extends Model
{
    use HasAcademicVisibility;

    protected $fillable = [
        'ownership_key', 'standards_framework_id', 'subject_id', 'grade_level_id', 'parent_standard_id',
        'record_type', 'title', 'standard_code', 'normalized_code', 'strand', 'statement', 'sequence',
        'version_label', 'adopted_label', 'effective_label', 'status', 'academic_source_id',
        'academic_source_file_id', 'curriculum_import_id', 'curriculum_import_proposal_id', 'source_page',
        'source_raw_text', 'parser_key', 'parser_version', 'source_confidence', 'source_note',
    ];

    protected function casts(): array { return ['sequence' => 'integer', 'source_confidence' => 'float']; }
    public function framework(): BelongsTo { return $this->belongsTo(StandardsFramework::class, 'standards_framework_id'); }
    public function subject(): BelongsTo { return $this->belongsTo(Subject::class); }
    public function gradeLevel(): BelongsTo { return $this->belongsTo(GradeLevel::class); }
    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_standard_id'); }
    public function children(): HasMany { return $this->hasMany(self::class, 'parent_standard_id')->orderBy('sequence'); }
    public function source(): BelongsTo { return $this->belongsTo(AcademicSource::class, 'academic_source_id'); }
    public function sourceFile(): BelongsTo { return $this->belongsTo(AcademicSourceFile::class, 'academic_source_file_id'); }
    public function curriculumImport(): BelongsTo { return $this->belongsTo(CurriculumImport::class); }
    public function proposal(): BelongsTo { return $this->belongsTo(CurriculumImportProposal::class, 'curriculum_import_proposal_id'); }
}
