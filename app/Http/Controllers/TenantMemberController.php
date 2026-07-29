<?php

namespace App\Http\Controllers;

use App\Models\TenantMembership;
use App\Services\AuditService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TenantMemberController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', TenantMembership::class);

        return Inertia::render('Members/Index', ['members' => TenantMembership::query()
            ->with('user:id,name,email')->where('tenant_id', app(TenantContext::class)->tenantId())->orderBy('id')->get()]);
    }

    public function update(Request $request, TenantMembership $membership, AuditService $audit): RedirectResponse
    {
        Gate::authorize('update', $membership);
        abort_unless($membership->tenant_id === app(TenantContext::class)->tenantId(), 404);
        $data = $request->validate([
            'role' => ['required', Rule::in(['owner', 'administrator', 'teacher', 'parent', 'tutor', 'student'])],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);
        $before = $membership->toArray();
        DB::transaction(function () use ($membership, $data) {
            $locked = TenantMembership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $removingOwner = $locked->role === 'owner' && $locked->status === 'active'
                && ($data['role'] !== 'owner' || $data['status'] !== 'active');
            if ($removingOwner) {
                $otherOwners = TenantMembership::query()->where('tenant_id', $locked->tenant_id)->whereKeyNot($locked->id)
                    ->where('role', 'owner')->where('status', 'active')->lockForUpdate()->exists();
                if (! $otherOwners) {
                    throw ValidationException::withMessages(['role' => 'The final active owner cannot be demoted or deactivated.']);
                }
            }
            $locked->update($data);
        });
        $audit->record('membership.updated', $membership, $before, $membership->fresh()->toArray());

        return back()->with('success', 'Membership updated.');
    }
}
