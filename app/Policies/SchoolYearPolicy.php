<?php

namespace App\Policies;

use App\Models\SchoolYear;
use App\Models\User;
use App\Services\PermissionService;
use App\Tenancy\TenantContext;

class SchoolYearPolicy
{
    public function viewAny(User $user): bool
    {
        return app(PermissionService::class)->allows('school-years.view');
    }

    public function create(User $user): bool
    {
        return app(PermissionService::class)->allows('school-years.manage');
    }

    public function update(User $user, SchoolYear $year): bool
    {
        return $this->create($user) && $year->tenant_id === app(TenantContext::class)->tenantId();
    }
}
