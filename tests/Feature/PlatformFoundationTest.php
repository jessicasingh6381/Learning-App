<?php

namespace Tests\Feature;

use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\StudentEnrollment;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Database\Seeders\GradeLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GradeLevelSeeder::class);
    }

    private function owner(string $email = 'owner@example.test'): array
    {
        $user = User::factory()->create(['email' => $email]);
        $tenant = Tenant::create(['name' => 'Family', 'type' => 'homeschool_family', 'timezone' => 'America/Chicago', 'locale' => 'en', 'status' => 'active']);
        $membership = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);

        return [$user, $tenant, $membership];
    }

    private function asTenant(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);
    }

    public function test_registration_creates_tenant_and_active_owner(): void
    {
        $this->post('/register', ['name' => 'Jess', 'email' => 'jess@example.test', 'password' => 'password123', 'password_confirmation' => 'password123', 'tenant_name' => 'Home Academy', 'tenant_type' => 'homeschool_family', 'timezone' => 'America/Chicago'])->assertRedirect('/dashboard');
        $this->assertDatabaseHas('tenants', ['name' => 'Home Academy']);
        $this->assertDatabaseHas('tenant_memberships', ['role' => 'owner', 'status' => 'active']);
    }

    public function test_owner_creates_student_without_email(): void
    {
        [$user, $tenant] = $this->owner();
        $this->asTenant($user, $tenant)->post('/students', ['first_name' => 'Kai', 'last_name' => 'Learner', 'status' => 'active'])->assertRedirect();
        $this->assertDatabaseHas('students', ['tenant_id' => $tenant->id, 'first_name' => 'Kai', 'user_id' => null]);
    }

    public function test_school_year_validation_and_single_active_rule(): void
    {
        [$user, $tenant] = $this->owner();
        $this->asTenant($user, $tenant)->post('/school-years', ['name' => 'Bad', 'start_date' => '2027-06-01', 'end_date' => '2026-08-01', 'timezone' => 'America/Chicago', 'status' => 'draft'])->assertSessionHasErrors('end_date');
        foreach ([2026, 2027] as $year) {
            $this->asTenant($user, $tenant)->post('/school-years', ['name' => (string) $year, 'start_date' => "{$year}-08-01", 'end_date' => ($year + 1).'-06-01', 'timezone' => 'America/Chicago', 'status' => 'active']);
        }
        $this->assertSame(1, SchoolYear::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', 'active')->count());
        $this->assertDatabaseHas('school_years', ['name' => '2026', 'status' => 'closed']);
    }

    public function test_enrollment_history_supports_different_grades_across_years(): void
    {
        [$user, $tenant] = $this->owner();
        $student = $tenant->students()->create(['first_name' => 'Kai', 'last_name' => 'Learner', 'status' => 'active']);
        foreach ([2026 => 'G5', 2027 => 'G6'] as $year => $gradeCode) {
            $schoolYear = $tenant->schoolYears()->create(['name' => (string) $year, 'start_date' => "{$year}-08-01", 'end_date' => ($year + 1).'-06-01', 'timezone' => 'America/Chicago', 'status' => 'draft']);
            $this->asTenant($user, $tenant)->post('/enrollments', ['student_id' => $student->id, 'school_year_id' => $schoolYear->id, 'grade_level_id' => GradeLevel::where('code', $gradeCode)->value('id'), 'enrollment_date' => "{$year}-08-01", 'status' => 'completed'])->assertRedirect();
        }
        $this->assertSame(2, StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->distinct()->count('grade_level_id'));
    }

    public function test_cross_tenant_view_update_and_relationship_manipulation_are_blocked(): void
    {
        [$aUser, $a] = $this->owner('a@example.test');
        [, $b] = $this->owner('b@example.test');
        $studentB = $b->students()->create(['first_name' => 'B', 'last_name' => 'Student', 'status' => 'active']);
        $yearB = $b->schoolYears()->create(['name' => 'B Year', 'start_date' => '2026-08-01', 'end_date' => '2027-06-01', 'timezone' => 'UTC', 'status' => 'draft']);
        $this->asTenant($aUser, $a)->get("/students/{$studentB->id}")->assertNotFound();
        $this->asTenant($aUser, $a)->patch("/school-years/{$yearB->id}", ['name' => 'Hacked', 'start_date' => '2026-08-01', 'end_date' => '2027-06-01', 'timezone' => 'UTC', 'status' => 'draft'])->assertNotFound();
        $this->asTenant($aUser, $a)->post('/enrollments', ['student_id' => $studentB->id, 'school_year_id' => $yearB->id, 'grade_level_id' => GradeLevel::first()->id, 'enrollment_date' => '2026-08-01', 'status' => 'active'])->assertSessionHasErrors(['student_id', 'school_year_id']);
    }

    public function test_switching_requires_an_active_membership(): void
    {
        [$user, $a] = $this->owner();
        $b = Tenant::create(['name' => 'B', 'type' => 'co_op', 'timezone' => 'UTC', 'locale' => 'en', 'status' => 'active']);
        $this->actingAs($user)->post("/tenants/{$b->id}/switch")->assertForbidden();
        $member = TenantMembership::create(['tenant_id' => $b->id, 'user_id' => $user->id, 'role' => 'teacher', 'status' => 'inactive']);
        $this->actingAs($user)->post("/tenants/{$b->id}/switch")->assertForbidden();
        $member->update(['status' => 'active']);
        $this->actingAs($user)->post("/tenants/{$b->id}/switch")->assertRedirect();
    }

    public function test_final_active_owner_is_protected(): void
    {
        [$user, $tenant, $membership] = $this->owner();
        $this->asTenant($user, $tenant)->patch("/members/{$membership->id}", ['role' => 'teacher', 'status' => 'active'])->assertSessionHasErrors('role');
        $this->asTenant($user, $tenant)->patch("/members/{$membership->id}", ['role' => 'owner', 'status' => 'inactive'])->assertSessionHasErrors('role');
    }

    public function test_final_active_owner_cannot_delete_their_account(): void
    {
        [$user] = $this->owner();
        $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertSessionHasErrors('password');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_unauthorized_role_is_denied_and_archival_retains_history(): void
    {
        [$owner, $tenant] = $this->owner();
        $parent = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $parent->id, 'role' => 'parent', 'status' => 'active']);
        $this->asTenant($parent, $tenant)->post('/students', ['first_name' => 'No', 'last_name' => 'Access'])->assertForbidden();
        $student = $tenant->students()->create(['first_name' => 'Kai', 'last_name' => 'Learner', 'status' => 'active']);
        $year = $tenant->schoolYears()->create(['name' => 'Year', 'start_date' => '2026-08-01', 'end_date' => '2027-06-01', 'timezone' => 'UTC', 'status' => 'active']);
        $tenant->enrollments()->create(['student_id' => $student->id, 'school_year_id' => $year->id, 'grade_level_id' => GradeLevel::first()->id, 'enrollment_date' => '2026-08-01', 'status' => 'active']);
        $this->asTenant($owner, $tenant)->patch("/students/{$student->id}/archive")->assertRedirect();
        $this->assertDatabaseHas('students', ['id' => $student->id, 'status' => 'archived']);
        $this->assertDatabaseHas('student_enrollments', ['student_id' => $student->id]);
    }

    public function test_duplicate_active_or_planned_enrollment_is_rejected(): void
    {
        [$user, $tenant] = $this->owner();
        $student = $tenant->students()->create(['first_name' => 'K', 'last_name' => 'L', 'status' => 'active']);
        $year = $tenant->schoolYears()->create(['name' => 'Year', 'start_date' => '2026-08-01', 'end_date' => '2027-06-01', 'timezone' => 'UTC', 'status' => 'active']);
        $payload = ['student_id' => $student->id, 'school_year_id' => $year->id, 'grade_level_id' => GradeLevel::first()->id, 'enrollment_date' => '2026-08-01', 'status' => 'active'];
        $this->asTenant($user, $tenant)->post('/enrollments', $payload)->assertRedirect();
        $this->asTenant($user, $tenant)->post('/enrollments', $payload)->assertSessionHasErrors('student_id');
    }
}
