<?php

namespace Tests\Feature;

use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\AuditService;
use Database\Seeders\GradeLevelSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StudentAccessPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GradeLevelSeeder::class);
    }

    private function tenant(string $name = 'Cosmic Quest Academy'): Tenant
    {
        return Tenant::create([
            'name' => $name,
            'type' => 'homeschool_family',
            'timezone' => 'America/Chicago',
            'locale' => 'en',
            'status' => 'active',
        ]);
    }

    private function member(Tenant $tenant, string $role = 'owner'): User
    {
        $user = User::factory()->create();
        TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
        ]);

        return $user;
    }

    private function student(Tenant $tenant, string $firstName = 'Kai'): Student
    {
        return $tenant->students()->create([
            'first_name' => $firstName,
            'last_name' => 'Singh',
            'preferred_name' => $firstName === 'Kai' ? 'K' : null,
            'status' => 'active',
        ]);
    }

    private function actingIn(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id]);
    }

    private function enable(
        User $actor,
        Tenant $tenant,
        Student $student,
        string $username = 'kai.singh',
        string $password = 'OrbitPass42',
        bool $mustChange = true,
    ): User {
        $this->actingIn($actor, $tenant)
            ->post(route('students.access.enable', $student), [
                'username' => $username,
                'password' => $password,
                'password_confirmation' => $password,
                'must_change_password' => $mustChange,
            ])
            ->assertRedirect(route('students.access.show', $student));

        return User::query()->where('username', strtolower($username))->firstOrFail();
    }

    private function enroll(Tenant $tenant, Student $student): void
    {
        $year = $tenant->schoolYears()->create([
            'name' => '2026-2027',
            'start_date' => '2026-08-12',
            'end_date' => '2027-05-27',
            'timezone' => 'America/Chicago',
            'status' => 'active',
        ]);
        $tenant->enrollments()->create([
            'student_id' => $student->id,
            'school_year_id' => $year->id,
            'grade_level_id' => GradeLevel::query()->where('code', 'G5')->value('id'),
            'enrollment_date' => '2026-08-12',
            'status' => 'active',
        ]);
    }

    public function test_student_profile_and_enrollment_exist_without_creating_a_login(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        $student = $this->student($tenant);
        $year = $tenant->schoolYears()->create([
            'name' => '2026-2027',
            'start_date' => '2026-08-12',
            'end_date' => '2027-05-27',
            'timezone' => 'America/Chicago',
            'status' => 'active',
        ]);

        $this->actingIn($owner, $tenant)->post('/enrollments', [
            'student_id' => $student->id,
            'school_year_id' => $year->id,
            'grade_level_id' => GradeLevel::query()->where('code', 'G5')->value('id'),
            'enrollment_date' => '2026-08-12',
            'status' => 'active',
        ])->assertRedirect();

        $this->assertDatabaseCount('users', 1);
        $this->assertNull($student->fresh()->user_id);
        $this->assertDatabaseHas('student_enrollments', ['student_id' => $student->id]);
    }

    public function test_owner_enables_access_transactionally_with_null_email_and_safe_audits(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        $student = $this->student($tenant);

        $studentUser = $this->enable($owner, $tenant, $student, 'Kai.SINGH');

        $this->assertSame('kai.singh', $studentUser->username);
        $this->assertNull($studentUser->email);
        $this->assertTrue($studentUser->must_change_password);
        $this->assertTrue(Hash::check('OrbitPass42', $studentUser->password));
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'user_id' => $studentUser->id,
        ]);
        $this->assertNotNull($student->fresh()->student_access_enabled_at);
        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenant->id,
            'user_id' => $studentUser->id,
            'role' => 'student',
            'status' => 'active',
        ]);

        $auditPayload = json_encode(DB::table('audit_logs')->get(['old_values', 'new_values'])->toArray());
        $this->assertStringNotContainsString('OrbitPass42', $auditPayload);
        $this->assertStringNotContainsString($studentUser->password, $auditPayload);
        $this->assertStringNotContainsString('"password"', $auditPayload);
    }

    public function test_teacher_can_enable_access_but_parent_cannot(): void
    {
        $tenant = $this->tenant();
        $teacher = $this->member($tenant, 'teacher');
        $parent = $this->member($tenant, 'parent');
        $teacherStudent = $this->student($tenant);
        $parentStudent = $this->student($tenant, 'Mina');

        $this->enable($teacher, $tenant, $teacherStudent, 'teacher.enabled');

        $this->actingIn($parent, $tenant)
            ->post(route('students.access.enable', $parentStudent), [
                'username' => 'parent.denied',
                'password' => 'OrbitPass42',
                'password_confirmation' => 'OrbitPass42',
                'must_change_password' => true,
            ])
            ->assertForbidden();

        $this->assertNull($parentStudent->fresh()->user_id);
    }

    public function test_failed_audit_rolls_back_user_membership_and_student_link(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        $student = $this->student($tenant);
        $audit = Mockery::mock(AuditService::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        $this->app->instance(AuditService::class, $audit);
        $this->withoutExceptionHandling();

        try {
            $this->actingIn($owner, $tenant)->post(route('students.access.enable', $student), [
                'username' => 'rollback.student',
                'password' => 'OrbitPass42',
                'password_confirmation' => 'OrbitPass42',
                'must_change_password' => true,
            ]);
            $this->fail('The simulated audit failure should escape the request.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('tenant_memberships', 1);
        $this->assertNull($student->fresh()->user_id);
    }

    public function test_username_validation_rejects_duplicates_invalid_characters_and_reserved_names(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        User::factory()->create(['username' => 'taken.name']);

        foreach ([
            ['Taken.Name', 'username'],
            ['bad name', 'username'],
            ['bad@name', 'username'],
            ['ab', 'username'],
            ['ADMIN', 'username'],
        ] as [$username, $field]) {
            $student = $this->student($tenant, 'Student'.random_int(100, 999));
            $this->actingIn($owner, $tenant)
                ->post(route('students.access.enable', $student), [
                    'username' => $username,
                    'password' => 'OrbitPass42',
                    'password_confirmation' => 'OrbitPass42',
                    'must_change_password' => true,
                ])
                ->assertSessionHasErrors($field);
            $this->assertNull($student->fresh()->user_id);
        }
    }

    public function test_a_student_cannot_receive_a_second_account_and_a_user_cannot_link_twice(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        $student = $this->student($tenant);
        $studentUser = $this->enable($owner, $tenant, $student);

        $this->actingIn($owner, $tenant)
            ->post(route('students.access.enable', $student), [
                'username' => 'second.account',
                'password' => 'OrbitPass42',
                'password_confirmation' => 'OrbitPass42',
                'must_change_password' => true,
            ])
            ->assertSessionHasErrors('username');
        $this->assertDatabaseCount('users', 2);

        $secondStudent = $this->student($tenant, 'Mina');
        $this->expectException(QueryException::class);
        $secondStudent->forceFill(['user_id' => $studentUser->id])->save();
    }

    public function test_database_rejects_cross_tenant_student_user_linking(): void
    {
        $tenantA = $this->tenant('Academy A');
        $ownerA = $this->member($tenantA);
        $studentA = $this->student($tenantA);
        $studentUser = $this->enable($ownerA, $tenantA, $studentA, 'academy.a.student');
        $tenantB = $this->tenant('Academy B');
        $this->member($tenantB);
        $studentB = $this->student($tenantB, 'Mina');

        $this->expectException(QueryException::class);
        $studentB->forceFill(['user_id' => $studentUser->id])->save();
    }

    public function test_adult_email_and_student_username_authentication_both_work_and_track_login(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        $student = $this->student($tenant);
        $studentUser = $this->enable($owner, $tenant, $student, 'Kai.Login', mustChange: false);
        $this->post('/logout');

        $this->post('/login', [
            'login' => 'KAI.LOGIN',
            'password' => 'OrbitPass42',
        ])->assertRedirect('/student');
        $this->assertAuthenticatedAs($studentUser);
        $this->assertNotNull($studentUser->fresh()->last_login_at);

        $this->post('/logout');
        $this->post('/login', [
            'login' => strtoupper((string) $owner->email),
            'password' => 'password',
        ])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($owner);
    }

    public function test_unknown_and_disabled_student_logins_return_the_same_generic_error(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        $student = $this->student($tenant);
        $this->enable($owner, $tenant, $student, 'disabled.student', mustChange: false);
        $this->actingIn($owner, $tenant)
            ->patch(route('students.access.disable', $student), ['confirm' => true])
            ->assertRedirect();
        $this->post('/logout');

        foreach ([
            ['missing.student', 'OrbitPass42'],
            ['disabled.student', 'OrbitPass42'],
        ] as [$login, $password]) {
            $response = $this->post('/login', compact('login', 'password'));
            $response->assertSessionHasErrors('login');
            $this->assertSame(trans('auth.failed'), session('errors')->get('login')[0]);
            $this->assertGuest();
        }
    }

    public function test_username_login_is_throttled(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['login' => 'unknown.student', 'password' => 'wrong']);
        }

        $this->post('/login', ['login' => 'unknown.student', 'password' => 'wrong'])
            ->assertSessionHasErrors('login');
        $this->assertStringContainsString('Too many login attempts', session('errors')->get('login')[0]);
    }

    public function test_temporary_password_blocks_portal_until_changed_and_regenerates_session(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        $student = $this->student($tenant);
        $studentUser = $this->enable($owner, $tenant, $student);
        $this->post('/logout');

        $this->post('/login', [
            'login' => 'kai.singh',
            'password' => 'OrbitPass42',
        ])->assertRedirect('/student/password/change');
        $this->get('/student')->assertRedirect('/student/password/change');
        $oldSessionId = session()->getId();

        $this->put('/student/password/change', [
            'password' => 'NewOrbit84',
            'password_confirmation' => 'NewOrbit84',
        ])->assertRedirect('/student');

        $this->assertFalse($studentUser->fresh()->must_change_password);
        $this->assertTrue(Hash::check('NewOrbit84', $studentUser->fresh()->password));
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->get('/student')->assertOk();
    }

    public function test_authorized_password_reset_requires_change_and_never_audits_credentials(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        $parent = $this->member($tenant, 'parent');
        $student = $this->student($tenant);
        $studentUser = $this->enable($owner, $tenant, $student, mustChange: false);

        $this->actingIn($parent, $tenant)
            ->put(route('students.access.password', $student), [
                'password' => 'DeniedPass24',
                'password_confirmation' => 'DeniedPass24',
            ])
            ->assertForbidden();

        $this->actingIn($owner, $tenant)
            ->put(route('students.access.password', $student), [
                'password' => 'ResetOrbit73',
                'password_confirmation' => 'ResetOrbit73',
            ])
            ->assertRedirect();

        $this->assertTrue(Hash::check('ResetOrbit73', $studentUser->fresh()->password));
        $this->assertTrue($studentUser->fresh()->must_change_password);
        $payload = json_encode(DB::table('audit_logs')->where('action', 'student.password_reset')->first());
        $this->assertStringNotContainsString('ResetOrbit73', $payload);
        $this->assertStringNotContainsString($studentUser->fresh()->password, $payload);
    }

    public function test_disable_invalidates_stale_access_and_reenable_preserves_account(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        $student = $this->student($tenant);
        $studentUser = $this->enable($owner, $tenant, $student, mustChange: false);

        $this->actingIn($studentUser, $tenant)->get('/student')->assertOk();
        $this->actingIn($owner, $tenant)
            ->patch(route('students.access.disable', $student), ['confirm' => true])
            ->assertRedirect();
        $this->actingIn($studentUser, $tenant)->get('/student')->assertRedirect('/login');
        $this->assertGuest();

        $this->actingIn($owner, $tenant)
            ->patch(route('students.access.reenable', $student))
            ->assertRedirect();

        $this->assertDatabaseHas('students', ['id' => $student->id, 'user_id' => $studentUser->id]);
        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenant->id,
            'user_id' => $studentUser->id,
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_username_update_is_normalized_unique_audited_and_student_cannot_self_update(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        $student = $this->student($tenant);
        $studentUser = $this->enable($owner, $tenant, $student, mustChange: false);
        User::factory()->create(['username' => 'already.used']);

        $this->actingIn($owner, $tenant)
            ->patch(route('students.access.username', $student), ['username' => 'NEW.Name'])
            ->assertRedirect();
        $this->assertSame('new.name', $studentUser->fresh()->username);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student.username_updated']);

        $this->actingIn($owner, $tenant)
            ->patch(route('students.access.username', $student), ['username' => 'ALREADY.USED'])
            ->assertSessionHasErrors('username');
        $this->actingIn($studentUser, $tenant)
            ->patch(route('students.access.username', $student), ['username' => 'self.changed'])
            ->assertForbidden();
    }

    public function test_portal_resolves_own_active_enrollment_and_profile_exposes_only_safe_props(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        $student = $this->student($tenant);
        $this->enroll($tenant, $student);
        $studentUser = $this->enable($owner, $tenant, $student, mustChange: false);

        $this->actingIn($studentUser, $tenant)->get('/student')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('StudentPortal/Home')
                ->where('student.preferred_name', 'K')
                ->where('academy', 'Cosmic Quest Academy')
                ->where('enrollment.school_year', '2026-2027')
                ->where('enrollment.grade_level', 'Grade 5')
                ->where('enrollment.status', 'active')
                ->missing('student.id')
                ->missing('student.tenant_id'));

        $this->actingIn($studentUser, $tenant)->get('/student/profile')
            ->assertInertia(fn (Assert $page) => $page
                ->component('StudentPortal/Profile')
                ->where('username', 'kai.singh')
                ->missing('student.user_id')
                ->missing('student.archived_at')
                ->missing('audit'));
    }

    public function test_portal_handles_no_active_enrollment_safely(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        $student = $this->student($tenant);
        $studentUser = $this->enable($owner, $tenant, $student, mustChange: false);

        $this->actingIn($studentUser, $tenant)->get('/student')
            ->assertInertia(fn (Assert $page) => $page
                ->component('StudentPortal/Home')
                ->where('enrollment', null));
    }

    public function test_student_linked_user_is_blocked_from_all_administrative_routes_and_other_students(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        $student = $this->student($tenant);
        $otherStudent = $this->student($tenant, 'Mina');
        $studentUser = $this->enable($owner, $tenant, $student, mustChange: false);

        foreach ([
            '/dashboard',
            '/students',
            "/students/{$otherStudent->id}",
            '/school-years',
            '/members',
            '/enrollments/create',
            '/profile',
            '/tenants/create',
        ] as $uri) {
            $this->actingIn($studentUser, $tenant)->get($uri)->assertForbidden();
        }

        $otherTenant = $this->tenant('Other Academy');
        $this->actingIn($studentUser, $tenant)
            ->post(route('tenants.switch', $otherTenant))
            ->assertForbidden();
    }

    public function test_generic_membership_management_cannot_change_a_linked_student_role(): void
    {
        $tenant = $this->tenant();
        $owner = $this->member($tenant);
        $student = $this->student($tenant);
        $studentUser = $this->enable($owner, $tenant, $student, mustChange: false);
        $membership = TenantMembership::query()
            ->where('user_id', $studentUser->id)
            ->firstOrFail();

        $this->actingIn($owner, $tenant)
            ->patch(route('members.update', $membership), [
                'role' => 'administrator',
                'status' => 'active',
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame('student', $membership->fresh()->role);
    }
}
