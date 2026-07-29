<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicYearConfiguration extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_year_id', 'education_provider_id', 'calendar_profile_id',
        'standards_framework_id', 'curriculum_package_id', 'status', 'notes',
        'configured_by_user_id', 'configured_at',
    ];

    protected function casts(): array
    {
        return ['configured_at' => 'datetime'];
    }

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function educationProvider(): BelongsTo
    {
        return $this->belongsTo(EducationProvider::class);
    }

    public function calendarProfile(): BelongsTo
    {
        return $this->belongsTo(CalendarProfile::class);
    }

    public function standardsFramework(): BelongsTo
    {
        return $this->belongsTo(StandardsFramework::class);
    }

    public function curriculumPackage(): BelongsTo
    {
        return $this->belongsTo(CurriculumPackage::class);
    }

    public function configuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by_user_id');
    }
}
