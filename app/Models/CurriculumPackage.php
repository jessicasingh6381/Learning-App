<?php

namespace App\Models;

use App\Models\Concerns\HasAcademicVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CurriculumPackage extends Model
{
    use HasAcademicVisibility;

    protected $fillable = [
        'education_provider_id', 'standards_framework_id', 'name', 'version_label',
        'description', 'status', 'effective_start_date', 'effective_end_date', 'source_url',
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

    public function standardsFramework(): BelongsTo
    {
        return $this->belongsTo(StandardsFramework::class);
    }

    public function courseMappings(): HasMany
    {
        return $this->hasMany(CurriculumPackageCourse::class)->orderBy('sort_order');
    }

    public function curriculumImports(): HasMany
    {
        return $this->hasMany(CurriculumImport::class);
    }
}
