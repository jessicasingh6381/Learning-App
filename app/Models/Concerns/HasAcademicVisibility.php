<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasAcademicVisibility
{
    protected static function bootHasAcademicVisibility(): void
    {
        static::addGlobalScope('academic_visibility', function (Builder $builder): void {
            $tenantId = app(TenantContext::class)->tenantId();

            if ($tenantId === null) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where(function (Builder $query) use ($tenantId): void {
                $query->whereNull($query->qualifyColumn('tenant_id'))
                    ->orWhere($query->qualifyColumn('tenant_id'), $tenantId);
            });
        });

        static::creating(function ($model): void {
            if (app(TenantContext::class)->hasTenant()) {
                $model->tenant_id = app(TenantContext::class)->tenantId();
                $model->ownership_key = 'tenant:'.$model->tenant_id;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isShared(): bool
    {
        return $this->tenant_id === null;
    }
}
