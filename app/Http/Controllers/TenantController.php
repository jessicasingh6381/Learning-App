<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Tenants/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'type' => ['required', Rule::in(['homeschool_family', 'co_op', 'microschool', 'tutoring_organization', 'private_school'])],
            'timezone' => ['required', 'timezone'], 'locale' => ['required', 'string', 'max:12'],
        ]);
        $tenant = DB::transaction(function () use ($data, $request) {
            $tenant = Tenant::create($data + ['status' => 'active']);
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $request->user()->id, 'role' => 'owner', 'status' => 'active']);

            return $tenant;
        });
        $request->session()->put('active_tenant_id', $tenant->id);

        return redirect()->route('dashboard')->with('success', 'Your learning space is ready.');
    }

    public function switch(Request $request, Tenant $tenant): RedirectResponse
    {
        $allowed = TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)->where('status', 'active')->exists();
        abort_unless($allowed && $tenant->status === 'active', 403);
        $request->session()->put('active_tenant_id', $tenant->id);
        $request->session()->regenerate();

        return back()->with('success', 'Active tenant changed.');
    }
}
