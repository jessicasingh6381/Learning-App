<?php

namespace App\Services;

use App\Tenancy\TenantContext;

class PermissionService
{
    private const PERMISSIONS = [
        'owner' => [
            'dashboard.view', 'tenant.manage', 'members.view', 'members.manage',
            'students.view', 'students.manage', 'school-years.view', 'school-years.manage',
            'enrollments.view', 'enrollments.manage', 'academic-config.view', 'academic-config.manage',
            'providers.view', 'providers.manage', 'calendars.view', 'calendars.manage',
            'standards.view', 'standards.manage', 'subjects.view', 'subjects.manage',
            'courses.view', 'courses.manage', 'curriculum.view', 'curriculum.manage',
        ],
        'administrator' => [
            'dashboard.view', 'tenant.manage', 'members.view', 'members.manage',
            'students.view', 'students.manage', 'school-years.view', 'school-years.manage',
            'enrollments.view', 'enrollments.manage', 'academic-config.view', 'academic-config.manage',
            'providers.view', 'providers.manage', 'calendars.view', 'calendars.manage',
            'standards.view', 'standards.manage', 'subjects.view', 'subjects.manage',
            'courses.view', 'courses.manage', 'curriculum.view', 'curriculum.manage',
        ],
        'teacher' => [
            'dashboard.view', 'students.view', 'students.manage', 'school-years.view',
            'enrollments.view', 'enrollments.manage', 'academic-config.view',
            'providers.view', 'calendars.view', 'standards.view', 'subjects.view',
            'courses.view', 'courses.manage', 'curriculum.view', 'curriculum.manage',
        ],
        'parent' => [
            'students.view', 'school-years.view', 'enrollments.view', 'academic-config.view',
            'providers.view', 'calendars.view', 'standards.view', 'subjects.view',
            'courses.view', 'curriculum.view',
        ],
        'tutor' => [
            'students.view', 'school-years.view', 'enrollments.view', 'academic-config.view',
            'providers.view', 'calendars.view', 'standards.view', 'subjects.view',
            'courses.view', 'curriculum.view',
        ],
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
