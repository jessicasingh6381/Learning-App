<?php

namespace App\Http\Controllers;

use App\Http\Requests\TenantMembershipRequest;
use App\Models\TenantMembership;
use App\Services\AuditService;
use App\Services\TenantOwnershipService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
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

    public function update(
        TenantMembershipRequest $request,
        TenantMembership $membership,
        AuditService $audit,
        TenantOwnershipService $ownership,
    ): RedirectResponse {
        $ownership->updateMembership($membership, $request->validated(), $audit);

        return back()->with('success', 'Membership updated.');
    }
}
