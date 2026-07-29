<?php

namespace App\Policies;

use App\Models\AcademicYearConfiguration;
use App\Models\User;
use App\Services\PermissionService;
use App\Tenancy\TenantContext;

class AcademicYearConfigurationPolicy
{
    public function view(User $user, AcademicYearConfiguration $configuration): bool
    {
        return app(PermissionService::class)->allows('academic-config.view')
            && $configuration->tenant_id === app(TenantContext::class)->tenantId();
    }

    public function update(User $user, AcademicYearConfiguration $configuration): bool
    {
        return app(PermissionService::class)->allows('academic-config.manage')
            && $configuration->tenant_id === app(TenantContext::class)->tenantId();
    }
}
