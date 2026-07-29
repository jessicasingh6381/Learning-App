<?php

namespace App\Policies;

use App\Models\CalendarProfile;
use App\Models\Course;
use App\Models\CurriculumPackage;
use App\Models\EducationProvider;
use App\Models\StandardsFramework;
use App\Models\Subject;
use App\Models\User;
use App\Services\PermissionService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

class AcademicResourcePolicy
{
    private const PERMISSIONS = [
        EducationProvider::class => 'providers',
        CalendarProfile::class => 'calendars',
        StandardsFramework::class => 'standards',
        Subject::class => 'subjects',
        Course::class => 'courses',
        CurriculumPackage::class => 'curriculum',
    ];

    public function view(User $user, Model $resource): bool
    {
        $permission = self::PERMISSIONS[$resource::class] ?? 'academic-config';

        return app(PermissionService::class)->allows($permission.'.view');
    }

    public function update(User $user, Model $resource): bool
    {
        $permission = self::PERMISSIONS[$resource::class] ?? 'academic-config';

        return $resource->tenant_id === app(TenantContext::class)->tenantId()
            && app(PermissionService::class)->allows($permission.'.manage');
    }
}
