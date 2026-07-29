<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = ['name', 'type', 'timezone', 'locale', 'status', 'settings'];

    protected static function booted(): void
    {
        static::deleting(function (Tenant $tenant): void {
            if ($tenant->memberships()->exists()) {
                throw new DomainException('A tenant with memberships cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function schoolYears(): HasMany
    {
        return $this->hasMany(SchoolYear::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }
}
