<?php

namespace App\Models;

use App\Models\Concerns\HasAcademicVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CalendarProfile extends Model
{
    use HasAcademicVisibility;

    protected $fillable = [
        'education_provider_id', 'name', 'academic_year_label', 'start_date', 'end_date',
        'timezone', 'status', 'source_type', 'source_url', 'source_version', 'notes',
    ];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'];
    }

    public function educationProvider(): BelongsTo
    {
        return $this->belongsTo(EducationProvider::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CalendarEvent::class)->orderBy('event_date');
    }
}
