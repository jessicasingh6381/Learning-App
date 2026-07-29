<?php

namespace App\Policies;

use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\PermissionService;
use App\Tenancy\TenantContext;

class StudentEnrollmentPolicy
{
    public function create(User $user): bool
    {
        return app(PermissionService::class)->allows('enrollments.manage');
    }

    public function view(User $user, StudentEnrollment $enrollment): bool
    {
        return app(PermissionService::class)->allows('enrollments.view') && $enrollment->tenant_id === app(TenantContext::class)->tenantId();
    }
}
