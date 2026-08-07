<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurriculumParserCapability extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'academic_source_id', 'academic_source_file_id', 'file_checksum', 'registry_signature',
        'state', 'parser_key', 'parser_version', 'extraction_method', 'recognition_score',
        'user_message', 'internal_diagnostic', 'candidate_parsers', 'document_family', 'assessed_at',
    ];

    protected function casts(): array
    {
        return [
            'recognition_score' => 'float',
            'candidate_parsers' => 'array',
            'assessed_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo { return $this->belongsTo(AcademicSource::class, 'academic_source_id'); }
    public function sourceFile(): BelongsTo { return $this->belongsTo(AcademicSourceFile::class, 'academic_source_file_id'); }
}
