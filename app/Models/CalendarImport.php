<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CalendarImport extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'academic_source_id', 'academic_source_file_id', 'school_year_id', 'calendar_profile_id',
        'created_by_user_id', 'approved_by_user_id', 'status', 'extraction_method',
        'parser_version', 'diagnostic', 'proposed_first_day', 'proposed_last_day',
        'update_school_year_dates', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'proposed_first_day' => 'date:Y-m-d', 'proposed_last_day' => 'date:Y-m-d',
            'update_school_year_dates' => 'boolean', 'approved_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(AcademicSource::class, 'academic_source_id');
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(AcademicSourceFile::class, 'academic_source_file_id');
    }

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function calendarProfile(): BelongsTo
    {
        return $this->belongsTo(CalendarProfile::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(CalendarImportProposal::class)->orderBy('event_date')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }
}
