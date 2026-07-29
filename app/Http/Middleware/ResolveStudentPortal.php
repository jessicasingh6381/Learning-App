<?php

namespace App\Http\Middleware;

use App\Models\Student;
use App\Models\TenantMembership;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ResolveStudentPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $student = Student::withoutGlobalScopes()
            ->where('user_id', $request->user()->id)
            ->first();

        abort_unless($student, 403);

        $membership = TenantMembership::query()
            ->with('tenant')
            ->where('tenant_id', $student->tenant_id)
            ->where('user_id', $request->user()->id)
            ->where('role', 'student')
            ->where('status', 'active')
            ->whereHas('tenant', fn ($query) => $query->where('status', 'active'))
            ->first();

        if (! $membership || ! $student->student_access_enabled_at || $student->status !== 'active') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'login' => trans('auth.failed'),
            ]);
        }

        $request->session()->put('active_tenant_id', $student->tenant_id);
        app(TenantContext::class)->set($membership->tenant, $membership);

        return $next($request);
    }
}
