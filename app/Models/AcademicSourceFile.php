<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicSourceFile extends Model
{
    protected $hidden = ['disk', 'stored_path', 'stored_filename'];

    protected $fillable = [
        'academic_source_id', 'uploaded_by_user_id', 'version_number', 'current_key',
        'is_current', 'disk', 'stored_path', 'stored_filename', 'original_filename',
        'mime_type', 'extension', 'file_size', 'checksum_sha256', 'uploaded_at',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('source_tenant', fn (Builder $builder) => $builder->whereHas('source'));
    }

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'file_size' => 'integer', 'uploaded_at' => 'datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(AcademicSource::class, 'academic_source_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
