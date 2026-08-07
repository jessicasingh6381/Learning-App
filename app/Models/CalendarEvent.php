<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEvent extends Model
{
    protected $fillable = [
        'calendar_profile_id', 'calendar_import_id', 'calendar_import_proposal_id', 'event_date', 'end_date', 'event_type', 'name',
        'instructional_effect', 'status', 'notes', 'source_reference',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('calendar_visibility', fn (Builder $builder) => $builder->whereHas('calendarProfile'));
    }

    protected function casts(): array
    {
        return ['event_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'];
    }

    public function calendarProfile(): BelongsTo
    {
        return $this->belongsTo(CalendarProfile::class);
    }

    public function calendarImport(): BelongsTo
    {
        return $this->belongsTo(CalendarImport::class);
    }

    public function importProposal(): BelongsTo
    {
        return $this->belongsTo(CalendarImportProposal::class, 'calendar_import_proposal_id');
    }
}
