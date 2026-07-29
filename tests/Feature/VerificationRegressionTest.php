<?php

namespace Tests\Feature;

use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\AuditService;
use Database\Seeders\GradeLevelSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class VerificationRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GradeLevelSeeder::class);
    }

    private function tenant(string $name = 'Family'): Tenant
    {
        return Tenant::create([
            'name' => $name,
            'type' => 'homeschool_family',
            'timezone' => 'America/Chicago',
            'locale' => 'en',
            'status' => 'active',
        ]);
    }

    private function membership(User $user, Tenant $tenant, string $role = 'owner', string $status = 'active'): TenantMembership
    {
        return TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => $status,
        ]);
    }

    private function actingIn(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);
    }

    public function test_stale_active_tenant_session_falls_back_after_membership_deactivation(): void
    {
        $user = User::factory()->create();
        $first = $this->tenant('First');
        $second = $this->tenant('Second');
        $stale = $this->membership($user, $first, 'teacher');
        $this->membership($user, $second);
        $stale->update(['status' => 'inactive']);

        $this->actingIn($user, $first)->get('/dashboard')
            ->assertOk()
            ->assertSessionHas('active_tenant_id', $second->id);
    }

    public function test_stale_session_with_no_remaining_membership_returns_to_onboarding(): void
    {
        $user = User::factory()->create();
        $tenant = $this->tenant();
        $membership = $this->membership($user, $tenant, 'teacher');
        $membership->update(['status' => 'inactive']);

        $this->actingIn($user, $tenant)->get('/dashboard')->assertRedirect('/tenants/create');
        $this->assertNull(session('active_tenant_id'));
    }

    public function test_tenant_models_fail_closed_without_context_and_route_binding_hides_foreign_records(): void
    {
        $userA = User::factory()->create();
        $tenantA = $this->tenant('A');
        $tenantB = $this->tenant('B');
        $this->membership($userA, $tenantA);
        $studentB = $tenantB->students()->create(['first_name' => 'Private', 'last_name' => 'Student', 'status' => 'active']);

        $this->assertSame(0, Student::query()->count());
        $this->actingIn($userA, $tenantA)->get("/students/{$studentB->id}")->assertNotFound();
    }

    public function test_client_tenant_id_cannot_override_student_assignment(): void
    {
        $user = User::factory()->create();
        $tenantA = $this->tenant('A');
        $tenantB = $this->tenant('B');
        $this->membership($user, $tenantA);

        $this->actingIn($user, $tenantA)->post('/students', [
            'tenant_id' => $tenantB->id,
            'first_name' => 'Kai',
            'last_name' => 'Learner',
            'status' => 'active',
        ])->assertRedirect();

        $this->assertDatabaseHas('students', ['tenant_id' => $tenantA->id, 'first_name' => 'Kai']);
        $this->assertDatabaseMissing('students', ['tenant_id' => $tenantB->id, 'first_name' => 'Kai']);
    }

    public function test_final_owner_is_protected_by_model_events_and_tenant_deletion_is_blocked(): void
    {
        $user = User::factory()->create();
        $tenant = $this->tenant();
        $membership = $this->membership($user, $tenant);

        try {
            $membership->delete();
            $this->fail('Final owner membership deletion should fail.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('tenant_memberships', ['id' => $membership->id]);
        }

        $this->expectException(DomainException::class);
        $tenant->delete();
    }

    public function test_administrator_cannot_promote_self_or_modify_an_owner(): void
    {
        $owner = User::factory()->create();
        $administrator = User::factory()->create();
        $tenant = $this->tenant();
        $ownerMembership = $this->membership($owner, $tenant);
        $adminMembership = $this->membership($administrator, $tenant, 'administrator');

        $this->actingIn($administrator, $tenant)->patch("/members/{$adminMembership->id}", [
            'role' => 'owner',
            'status' => 'active',
        ])->assertSessionHasErrors('role');
        $this->actingIn($administrator, $tenant)->patch("/members/{$ownerMembership->id}", [
            'role' => 'administrator',
            'status' => 'active',
        ])->assertSessionHasErrors('role');
        $this->assertDatabaseHas('tenant_memberships', ['id' => $adminMembership->id, 'role' => 'administrator']);
        $this->assertDatabaseHas('tenant_memberships', ['id' => $ownerMembership->id, 'role' => 'owner']);
    }

    public function test_permissions_change_with_the_active_tenant_membership(): void
    {
        $user = User::factory()->create();
        $ownerTenant = $this->tenant('Owned');
        $teacherTenant = $this->tenant('Taught');
        $this->membership($user, $ownerTenant, 'owner');
        $this->membership($user, $teacherTenant, 'teacher');

        $this->actingIn($user, $ownerTenant)->get('/members')->assertOk();
        $this->actingIn($user, $teacherTenant)->get('/members')->assertForbidden();
        $this->actingIn($user, $teacherTenant)->get('/students/create')->assertOk();
    }

    public function test_dashboard_counts_and_audit_activity_are_tenant_isolated(): void
    {
        $ownerA = User::factory()->create();
        $tenantA = $this->tenant('A');
        $tenantB = $this->tenant('B');
        $this->membership($ownerA, $tenantA);
        $studentA = $tenantA->students()->create(['first_name' => 'A', 'last_name' => 'Student', 'status' => 'active']);
        $tenantB->students()->create(['first_name' => 'B', 'last_name' => 'Student', 'status' => 'active']);
        $yearA = $tenantA->schoolYears()->create(['name' => 'A Year', 'start_date' => '2026-08-01', 'end_date' => '2027-06-01', 'timezone' => 'UTC', 'status' => 'active']);
        $tenantA->enrollments()->create(['student_id' => $studentA->id, 'school_year_id' => $yearA->id, 'grade_level_id' => GradeLevel::first()->id, 'enrollment_date' => '2026-08-01', 'status' => 'active']);
        DB::table('audit_logs')->insert([
            ['tenant_id' => $tenantA->id, 'user_id' => $ownerA->id, 'action' => 'a.action', 'auditable_type' => 'Test', 'auditable_id' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $tenantB->id, 'user_id' => null, 'action' => 'b.action', 'auditable_type' => 'Test', 'auditable_id' => '2', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingIn($ownerA, $tenantA)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('counts.activeStudents', 1)
            ->where('counts.currentEnrollments', 1)
            ->has('activity', 1)
            ->where('activity.0.action', 'a.action'));
    }

    public function test_audit_failure_rolls_back_the_primary_operation(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->tenant();
        $this->membership($owner, $tenant);
        $audit = Mockery::mock(AuditService::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Simulated audit failure.'));
        $this->app->instance(AuditService::class, $audit);

        try {
            $this->withoutExceptionHandling()->actingIn($owner, $tenant)->post('/students', [
                'first_name' => 'Must',
                'last_name' => 'Rollback',
                'status' => 'active',
            ]);
            $this->fail('The simulated audit failure should escape the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated audit failure.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('students', [
            'tenant_id' => $tenant->id,
            'last_name' => 'Rollback',
        ]);
    }

    public function test_grade_level_seeding_is_exact_and_idempotent(): void
    {
        $this->seed(GradeLevelSeeder::class);

        $this->assertSame(15, GradeLevel::query()->count());
        $this->assertSame(
            ['PK', 'K', 'G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8', 'G9', 'G10', 'G11', 'G12', 'UG'],
            GradeLevel::query()->orderBy('sort_order')->pluck('code')->all(),
        );
    }

    public function test_activating_a_year_closes_only_the_same_tenants_active_year(): void
    {
        $ownerA = User::factory()->create();
        $tenantA = $this->tenant('A');
        $tenantB = $this->tenant('B');
        $this->membership($ownerA, $tenantA);
        $oldA = $tenantA->schoolYears()->create(['name' => 'Old A', 'start_date' => '2025-08-01', 'end_date' => '2026-06-01', 'timezone' => 'UTC', 'status' => 'active']);
        $activeB = $tenantB->schoolYears()->create(['name' => 'Active B', 'start_date' => '2025-08-01', 'end_date' => '2026-06-01', 'timezone' => 'UTC', 'status' => 'active']);

        $this->actingIn($ownerA, $tenantA)->post('/school-years', [
            'name' => 'New A',
            'start_date' => '2026-08-01',
            'end_date' => '2027-06-01',
            'timezone' => 'UTC',
            'status' => 'active',
        ])->assertRedirect();

        $this->assertDatabaseHas('school_years', ['id' => $oldA->id, 'status' => 'closed']);
        $this->assertDatabaseHas('school_years', ['id' => $activeB->id, 'status' => 'active']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenantA->id, 'action' => 'school-year.closed-automatically', 'auditable_id' => (string) $oldA->id]);
    }

    public function test_school_year_update_ignores_its_own_name_and_rejects_invalid_transition(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->tenant();
        $this->membership($owner, $tenant);
        $year = $tenant->schoolYears()->create(['name' => '2026', 'start_date' => '2026-08-01', 'end_date' => '2027-06-01', 'timezone' => 'UTC', 'status' => 'closed']);

        $this->actingIn($owner, $tenant)->patch("/school-years/{$year->id}", [
            'name' => '2026',
            'start_date' => '2026-08-01',
            'end_date' => '2027-06-01',
            'timezone' => 'UTC',
            'status' => 'active',
        ])->assertSessionHasErrors('status')->assertSessionDoesntHaveErrors('name');
    }

    public function test_enrollment_validation_blocks_archived_students_closed_years_and_invalid_dates(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->tenant();
        $this->membership($owner, $tenant);
        $student = $tenant->students()->create(['first_name' => 'Archived', 'last_name' => 'Student', 'status' => 'archived', 'archived_at' => now()]);
        $year = $tenant->schoolYears()->create(['name' => 'Closed', 'start_date' => '2026-08-01', 'end_date' => '2027-06-01', 'timezone' => 'UTC', 'status' => 'closed']);

        $this->actingIn($owner, $tenant)->post('/enrollments', [
            'student_id' => $student->id,
            'school_year_id' => $year->id,
            'grade_level_id' => GradeLevel::first()->id,
            'enrollment_date' => '2026-07-01',
            'completion_date' => null,
            'status' => 'completed',
        ])->assertSessionHasErrors(['student_id', 'school_year_id', 'enrollment_date', 'completion_date']);
        $this->assertDatabaseCount('student_enrollments', 0);
    }

    public function test_database_constraints_reject_cross_tenant_enrollment_relationships(): void
    {
        $tenantA = $this->tenant('A');
        $tenantB = $this->tenant('B');
        $studentA = $tenantA->students()->create(['first_name' => 'A', 'last_name' => 'Student', 'status' => 'active']);
        $yearB = $tenantB->schoolYears()->create(['name' => 'B Year', 'start_date' => '2026-08-01', 'end_date' => '2027-06-01', 'timezone' => 'UTC', 'status' => 'active']);

        try {
            DB::table('student_enrollments')->insert([
                'tenant_id' => $tenantA->id,
                'student_id' => $studentA->id,
                'school_year_id' => $yearB->id,
                'grade_level_id' => GradeLevel::first()->id,
                'enrollment_date' => '2026-08-01',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('The composite school-year foreign key should reject a tenant mismatch.');
        } catch (QueryException) {
            $this->assertDatabaseCount('student_enrollments', 0);
        }
    }

    public function test_repeated_active_enrollment_submission_remains_unambiguous(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->tenant();
        $this->membership($owner, $tenant);
        $student = $tenant->students()->create(['first_name' => 'Kai', 'last_name' => 'Learner', 'status' => 'active']);
        $year = $tenant->schoolYears()->create(['name' => 'Year', 'start_date' => '2026-08-01', 'end_date' => '2027-06-01', 'timezone' => 'UTC', 'status' => 'active']);
        $payload = ['student_id' => $student->id, 'school_year_id' => $year->id, 'grade_level_id' => GradeLevel::first()->id, 'enrollment_date' => '2026-08-01', 'status' => 'active'];

        $this->actingIn($owner, $tenant)->post('/enrollments', $payload)->assertRedirect();
        $this->actingIn($owner, $tenant)->post('/enrollments', $payload)->assertSessionHasErrors('student_id');
        $this->assertDatabaseCount('student_enrollments', 1);
    }

    public function test_unauthenticated_and_student_roles_cannot_access_administrative_routes(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->post('/students', [])->assertRedirect('/login');

        $studentUser = User::factory()->create();
        $tenant = $this->tenant();
        $this->membership($studentUser, $tenant, 'student');
        $this->actingIn($studentUser, $tenant)->get('/dashboard')->assertForbidden();
        $this->actingIn($studentUser, $tenant)->get('/members')->assertForbidden();
        $this->actingIn($studentUser, $tenant)->post('/school-years', [])->assertForbidden();
    }
}
