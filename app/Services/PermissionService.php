<?php

namespace App\Services;

use App\Tenancy\TenantContext;

class PermissionService
{
    private const PERMISSIONS = [
        'owner' => [
            'workspace.view', 'advanced-academic.view', 'dashboard.view', 'tenant.manage', 'members.view', 'members.manage',
            'students.view', 'students.manage', 'school-years.view', 'school-years.manage',
            'enrollments.view', 'enrollments.manage', 'academic-config.view', 'academic-config.manage',
            'providers.view', 'providers.manage', 'calendars.view', 'calendars.manage',
            'standards.view', 'standards.manage', 'subjects.view', 'subjects.manage',
            'courses.view', 'courses.manage', 'curriculum.view', 'curriculum.manage',
            'lesson-plans.view', 'lesson-plans.manage',
            'academic-sources.view', 'academic-sources.create', 'academic-sources.manage',
            'academic-sources.review', 'academic-sources.download',
        ],
        'administrator' => [
            'workspace.view', 'advanced-academic.view', 'dashboard.view', 'tenant.manage', 'members.view', 'members.manage',
            'students.view', 'students.manage', 'school-years.view', 'school-years.manage',
            'enrollments.view', 'enrollments.manage', 'academic-config.view', 'academic-config.manage',
            'providers.view', 'providers.manage', 'calendars.view', 'calendars.manage',
            'standards.view', 'standards.manage', 'subjects.view', 'subjects.manage',
            'courses.view', 'courses.manage', 'curriculum.view', 'curriculum.manage',
            'lesson-plans.view', 'lesson-plans.manage',
            'academic-sources.view', 'academic-sources.create', 'academic-sources.manage',
            'academic-sources.review', 'academic-sources.download',
        ],
        'teacher' => [
            'workspace.view', 'dashboard.view', 'students.view', 'students.manage', 'school-years.view',
            'enrollments.view', 'enrollments.manage', 'academic-config.view',
            'providers.view', 'calendars.view', 'standards.view', 'subjects.view',
            'courses.view', 'courses.manage', 'curriculum.view', 'curriculum.manage',
            'lesson-plans.view', 'lesson-plans.manage',
            'academic-sources.view', 'academic-sources.create', 'academic-sources.manage',
            'academic-sources.review', 'academic-sources.download',
        ],
        'parent' => [
            'workspace.view', 'students.view', 'school-years.view', 'enrollments.view', 'academic-config.view',
            'providers.view', 'calendars.view', 'standards.view', 'subjects.view',
            'courses.view', 'curriculum.view',
            'lesson-plans.view', 'lesson-plans.manage',
            'academic-sources.view', 'academic-sources.create', 'academic-sources.download',
        ],
        'tutor' => [
            'workspace.view', 'students.view', 'school-years.view', 'enrollments.view', 'academic-config.view',
            'providers.view', 'calendars.view', 'standards.view', 'subjects.view',
            'courses.view', 'curriculum.view',
            'lesson-plans.view',
            'academic-sources.view', 'academic-sources.download',
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
