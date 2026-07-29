<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditService
{
    private const FIELDS = [
        Student::class => ['user_id', 'student_access_enabled_at', 'first_name', 'middle_name', 'last_name', 'preferred_name', 'status', 'archived_at'],
        User::class => ['username', 'must_change_password', 'last_login_at'],
        SchoolYear::class => [
            'name',
            'start_date',
            'end_date',
            'timezone',
            'status',
            'instructional_day_target',
            'instructional_week_type',
            'instructional_weekdays',
        ],
        StudentEnrollment::class => ['student_id', 'school_year_id', 'grade_level_id', 'enrollment_date', 'completion_date', 'status'],
        TenantMembership::class => ['user_id', 'role', 'status'],
    ];

    public function record(string $action, Model $model, array $before = [], array $after = []): void
    {
        if (! app(TenantContext::class)->hasTenant()) {
            throw new LogicException('Tenant administrative audit records require an active tenant context.');
        }

        $allowed = self::FIELDS[$model::class] ?? [];
        $clean = fn (array $values) => collect($values)->only($allowed)->all();

        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $model::class,
            'auditable_id' => (string) $model->getKey(),
            'old_values' => $clean($before) ?: null,
            'new_values' => $clean($after) ?: null,
        ]);
    }
}
