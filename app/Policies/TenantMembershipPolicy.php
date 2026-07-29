<?php

namespace App\Policies;

use App\Models\TenantMembership;
use App\Models\User;
use App\Services\PermissionService;
use App\Tenancy\TenantContext;

class TenantMembershipPolicy
{
    public function viewAny(User $user): bool
    {
        return app(PermissionService::class)->allows('members.view');
    }

    public function update(User $user, TenantMembership $membership): bool
    {
        return app(PermissionService::class)->allows('members.manage') && $membership->tenant_id === app(TenantContext::class)->tenantId();
    }
}
