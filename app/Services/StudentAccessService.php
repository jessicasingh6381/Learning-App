<?php

namespace App\Services;

use App\Models\Student;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentAccessService
{
    public function enable(Student $student, array $data, AuditService $audit): User
    {
        return DB::transaction(function () use ($student, $data, $audit): User {
            $lockedStudent = $this->lockStudent($student);

            if ($lockedStudent->user_id !== null) {
                throw ValidationException::withMessages([
                    'username' => 'This student already has a linked portal account.',
                ]);
            }

            if ($lockedStudent->status !== 'active') {
                throw ValidationException::withMessages([
                    'username' => 'Portal access may be enabled only for an active student.',
                ]);
            }

            $user = User::create([
                'name' => trim($lockedStudent->first_name.' '.$lockedStudent->last_name),
                'email' => null,
                'username' => $data['username'],
                'password' => Hash::make($data['password']),
                'must_change_password' => (bool) $data['must_change_password'],
            ]);

            TenantMembership::create([
                'tenant_id' => $lockedStudent->tenant_id,
                'user_id' => $user->id,
                'role' => 'student',
                'status' => 'active',
            ]);

            $before = $lockedStudent->only(['user_id', 'student_access_enabled_at']);
            $lockedStudent->update([
                'user_id' => $user->id,
                'student_access_enabled_at' => now(),
            ]);

            $audit->record(
                'student.account_created',
                $user,
                [],
                $user->only(['username', 'must_change_password']),
            );
            $audit->record(
                'student.access_enabled',
                $lockedStudent,
                $before,
                $lockedStudent->fresh()->only(['user_id', 'student_access_enabled_at']),
            );

            return $user;
        });
    }

    public function updateUsername(Student $student, string $username, AuditService $audit): User
    {
        return DB::transaction(function () use ($student, $username, $audit): User {
            $lockedStudent = $this->lockStudent($student);
            $user = $this->lockLinkedUser($lockedStudent);
            $before = $user->only(['username']);

            $user->update(['username' => $username]);
            $audit->record('student.username_updated', $user, $before, $user->fresh()->only(['username']));

            return $user;
        });
    }

    public function resetPassword(Student $student, string $password, AuditService $audit): void
    {
        DB::transaction(function () use ($student, $password, $audit): void {
            $lockedStudent = $this->lockStudent($student);
            $user = $this->lockLinkedUser($lockedStudent);
            $before = $user->only(['must_change_password']);

            $user->forceFill([
                'password' => Hash::make($password),
                'must_change_password' => true,
                'remember_token' => Str::random(60),
            ])->save();

            $audit->record(
                'student.password_reset',
                $user,
                $before,
                $user->fresh()->only(['must_change_password']),
            );
            $this->invalidateSessions($user);
        });
    }

    public function disable(Student $student, AuditService $audit): void
    {
        DB::transaction(function () use ($student, $audit): void {
            $lockedStudent = $this->lockStudent($student);
            $user = $this->lockLinkedUser($lockedStudent);
            $membership = $this->lockMembership($lockedStudent, $user);
            $before = $membership->only(['user_id', 'role', 'status']);

            $membership->update(['status' => 'inactive']);
            $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
            $audit->record(
                'student.access_disabled',
                $membership,
                $before,
                $membership->fresh()->only(['user_id', 'role', 'status']),
            );
            $this->invalidateSessions($user);
        });
    }

    public function reenable(Student $student, AuditService $audit): void
    {
        DB::transaction(function () use ($student, $audit): void {
            $lockedStudent = $this->lockStudent($student);

            if ($lockedStudent->status !== 'active') {
                throw ValidationException::withMessages([
                    'username' => 'Portal access may be re-enabled only for an active student.',
                ]);
            }

            $user = $this->lockLinkedUser($lockedStudent);
            $membership = $this->lockMembership($lockedStudent, $user);
            $before = $membership->only(['user_id', 'role', 'status']);

            $membership->update(['role' => 'student', 'status' => 'active']);
            $audit->record(
                'student.access_reenabled',
                $membership,
                $before,
                $membership->fresh()->only(['user_id', 'role', 'status']),
            );
        });
    }

    public function changeOwnPassword(User $user, string $password, AuditService $audit): void
    {
        DB::transaction(function () use ($user, $password, $audit): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $before = $lockedUser->only(['must_change_password']);

            $lockedUser->forceFill([
                'password' => Hash::make($password),
                'must_change_password' => false,
                'remember_token' => Str::random(60),
            ])->save();

            $audit->record(
                'student.password_changed',
                $lockedUser,
                $before,
                $lockedUser->fresh()->only(['must_change_password']),
            );
        });
    }

    private function lockStudent(Student $student): Student
    {
        if ($student->tenant_id !== app(TenantContext::class)->tenantId()) {
            abort(404);
        }

        return Student::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();
    }

    private function lockLinkedUser(Student $student): User
    {
        if ($student->user_id === null) {
            throw ValidationException::withMessages([
                'username' => 'Student portal access has not been enabled.',
            ]);
        }

        return User::query()->lockForUpdate()->findOrFail($student->user_id);
    }

    private function lockMembership(Student $student, User $user): TenantMembership
    {
        return TenantMembership::query()
            ->where('tenant_id', $student->tenant_id)
            ->where('user_id', $user->id)
            ->where('role', 'student')
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function invalidateSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();
        }
    }
}
