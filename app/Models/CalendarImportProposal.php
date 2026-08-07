<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarImportProposal extends Model
{
    protected $fillable = [
        'calendar_import_id', 'event_date', 'end_date', 'name', 'event_type',
        'instructional_effect', 'confidence', 'source_page', 'raw_text', 'parser_note',
        'included', 'manually_edited',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('import_tenant', fn (Builder $builder) => $builder->whereHas('calendarImport'));
    }

    protected function casts(): array
    {
        return [
            'event_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d',
            'confidence' => 'float', 'included' => 'boolean', 'manually_edited' => 'boolean',
        ];
    }

    public function calendarImport(): BelongsTo
    {
        return $this->belongsTo(CalendarImport::class);
    }
}
