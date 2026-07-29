<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantId = app(TenantContext::class)->tenantId();
            $tenantId === null
                ? $builder->whereRaw('1 = 0')
                : $builder->where($builder->qualifyColumn('tenant_id'), $tenantId);
        });
        static::creating(function ($model): void {
            if (app(TenantContext::class)->hasTenant()) {
                $model->tenant_id = app(TenantContext::class)->tenantId();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
