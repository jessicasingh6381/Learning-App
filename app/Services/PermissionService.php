<?php

namespace App\Services;

use App\Tenancy\TenantContext;

class PermissionService
{
    private const PERMISSIONS = [
        'owner' => ['dashboard.view', 'tenant.manage', 'members.view', 'members.manage', 'students.view', 'students.manage', 'school-years.view', 'school-years.manage', 'enrollments.view', 'enrollments.manage'],
        'administrator' => ['dashboard.view', 'tenant.manage', 'members.view', 'members.manage', 'students.view', 'students.manage', 'school-years.view', 'school-years.manage', 'enrollments.view', 'enrollments.manage'],
        'teacher' => ['dashboard.view', 'students.view', 'students.manage', 'school-years.view', 'enrollments.view', 'enrollments.manage'],
        'parent' => ['students.view', 'school-years.view', 'enrollments.view'],
        'tutor' => ['students.view', 'school-years.view', 'enrollments.view'],
        'student' => [],
    ];

    public function allows(string $permission): bool
    {
        $context = app(TenantContext::class);

        return $context->hasTenant() && in_array($permission, self::PERMISSIONS[$context->membership()->role] ?? [], true);
    }

    public function permissions(): array
    {
        $context = app(TenantContext::class);

        return $context->hasTenant() ? (self::PERMISSIONS[$context->membership()->role] ?? []) : [];
    }
}
