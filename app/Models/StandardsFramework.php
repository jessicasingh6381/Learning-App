<?php

namespace App\Models;

use App\Models\Concerns\HasAcademicVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StandardsFramework extends Model
{
    use HasAcademicVisibility;

    protected $fillable = [
        'education_provider_id', 'name', 'short_name', 'jurisdiction', 'version_label',
        'effective_start_date', 'effective_end_date', 'status', 'source_url', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'effective_start_date' => 'date:Y-m-d',
            'effective_end_date' => 'date:Y-m-d',
        ];
    }

    public function educationProvider(): BelongsTo
    {
        return $this->belongsTo(EducationProvider::class);
    }
}
