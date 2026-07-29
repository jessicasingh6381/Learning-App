<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;
use App\Services\PermissionService;
use App\Tenancy\TenantContext;

class StudentPolicy
{
    public function viewAny(User $user): bool
    {
        return app(PermissionService::class)->allows('students.view');
    }

    public function view(User $user, Student $student): bool
    {
        return $this->viewAny($user) && $student->tenant_id === app(TenantContext::class)->tenantId();
    }

    public function create(User $user): bool
    {
        return app(PermissionService::class)->allows('students.manage');
    }

    public function update(User $user, Student $student): bool
    {
        return $this->create($user) && $student->tenant_id === app(TenantContext::class)->tenantId();
    }

    public function manageAccess(User $user, Student $student): bool
    {
        return $this->update($user, $student);
    }
}
