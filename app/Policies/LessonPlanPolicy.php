<?php

namespace App\Policies;

use App\Models\LessonPlan;
use App\Models\User;
use App\Services\PermissionService;
use App\Tenancy\TenantContext;

class LessonPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return app(PermissionService::class)->allows('lesson-plans.view');
    }

    public function view(User $user, LessonPlan $lessonPlan): bool
    {
        return $lessonPlan->tenant_id === app(TenantContext::class)->tenantId()
            && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return app(PermissionService::class)->allows('lesson-plans.manage');
    }

    public function update(User $user, LessonPlan $lessonPlan): bool
    {
        return $lessonPlan->tenant_id === app(TenantContext::class)->tenantId()
            && app(PermissionService::class)->allows('lesson-plans.manage');
    }
}
