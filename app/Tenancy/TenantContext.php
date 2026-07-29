<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Models\TenantMembership;
use LogicException;

class TenantContext
{
    private ?Tenant $tenant = null;

    private ?TenantMembership $membership = null;

    public function set(Tenant $tenant, TenantMembership $membership): void
    {
        if ($membership->tenant_id !== $tenant->id || $membership->status !== 'active') {
            throw new LogicException('The active tenant must be backed by an active membership.');
        }
        $this->tenant = $tenant;
        $this->membership = $membership;
    }

    public function tenant(): Tenant
    {
        return $this->tenant ?? throw new LogicException('No active tenant.');
    }

    public function tenantId(): ?int
    {
        return $this->tenant?->id;
    }

    public function membership(): TenantMembership
    {
        return $this->membership ?? throw new LogicException('No active membership.');
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }
}
