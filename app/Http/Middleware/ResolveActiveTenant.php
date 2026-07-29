<?php

namespace App\Http\Middleware;

use App\Models\TenantMembership;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveActiveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $query = TenantMembership::query()->with('tenant')
            ->where('user_id', $request->user()->id)->where('status', 'active')
            ->whereHas('tenant', fn ($q) => $q->where('status', 'active'));
        $requestedId = $request->session()->get('active_tenant_id');
        $membership = (clone $query)->when($requestedId, fn ($q) => $q->where('tenant_id', $requestedId))->first();
        $membership ??= $query->orderBy('id')->first();
        if (! $membership) {
            $request->session()->forget('active_tenant_id');

            return redirect()->route('tenants.create');
        }
        $request->session()->put('active_tenant_id', $membership->tenant_id);
        app(TenantContext::class)->set($membership->tenant, $membership);

        return $next($request);
    }
}
