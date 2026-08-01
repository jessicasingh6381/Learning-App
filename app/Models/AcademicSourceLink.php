<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicSourceLink extends Model
{
    protected $fillable = ['academic_source_id', 'link_type', 'link_id'];

    protected static function booted(): void
    {
        static::addGlobalScope('source_tenant', fn (Builder $builder) => $builder->whereHas('source'));
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(AcademicSource::class, 'academic_source_id');
    }
}
