<?php

namespace App\Models;

use App\Services\TenantOwnershipService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantMembership extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'role', 'status'];

    protected static function booted(): void
    {
        static::updating(function (TenantMembership $membership): void {
            app(TenantOwnershipService::class)->assertMembershipMutationRetainsOwner(
                $membership,
                (string) $membership->getAttribute('role'),
                (string) $membership->getAttribute('status'),
            );
        });
        static::deleting(fn (TenantMembership $membership) => app(TenantOwnershipService::class)
            ->assertMembershipMutationRetainsOwner($membership, null, null));
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $context = app(TenantContext::class);
        if (! $context->hasTenant()) {
            return null;
        }

        return $this->newQuery()
            ->where('tenant_id', $context->tenantId())
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
