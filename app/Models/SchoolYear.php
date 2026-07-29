<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolYear extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'instructional_week_type' => 'five_day',
        'instructional_weekdays' => '[1,2,3,4,5]',
    ];

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'timezone',
        'status',
        'instructional_day_target',
        'instructional_week_type',
        'instructional_weekdays',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'instructional_day_target' => 'integer',
            'instructional_weekdays' => 'array',
        ];
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }
}
