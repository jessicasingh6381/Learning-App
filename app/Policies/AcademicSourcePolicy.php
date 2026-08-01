<?php

namespace App\Policies;

use App\Models\AcademicSource;
use App\Models\User;
use App\Services\PermissionService;
use App\Tenancy\TenantContext;

class AcademicSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return app(PermissionService::class)->allows('academic-sources.view');
    }

    public function view(User $user, AcademicSource $source): bool
    {
        return $this->viewAny($user) && $source->tenant_id === app(TenantContext::class)->tenantId();
    }

    public function create(User $user): bool
    {
        return app(PermissionService::class)->allows('academic-sources.create');
    }

    public function update(User $user, AcademicSource $source): bool
    {
        return app(PermissionService::class)->allows('academic-sources.manage')
            && $source->tenant_id === app(TenantContext::class)->tenantId();
    }

    public function review(User $user, AcademicSource $source): bool
    {
        return app(PermissionService::class)->allows('academic-sources.review')
            && $source->tenant_id === app(TenantContext::class)->tenantId();
    }

    public function download(User $user, AcademicSource $source): bool
    {
        return app(PermissionService::class)->allows('academic-sources.download')
            && $source->tenant_id === app(TenantContext::class)->tenantId();
    }
}
