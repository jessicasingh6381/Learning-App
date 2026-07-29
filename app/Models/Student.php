<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    use BelongsToTenant;

    protected $fillable = ['user_id', 'student_access_enabled_at', 'first_name', 'middle_name', 'last_name', 'preferred_name', 'status', 'archived_at'];

    protected function casts(): array
    {
        return [
            'student_access_enabled_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class)->latest('enrollment_date');
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->preferred_name ?: $this->first_name.' '.$this->last_name;
    }
}
