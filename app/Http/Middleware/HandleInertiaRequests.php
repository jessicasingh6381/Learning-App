<?php

namespace App\Http\Middleware;

use App\Models\TenantMembership;
use App\Services\PermissionService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                'permissions' => fn () => $request->user() && app(TenantContext::class)->hasTenant() ? app(PermissionService::class)->permissions() : [],
                'membershipRole' => fn () => app(TenantContext::class)->hasTenant() ? app(TenantContext::class)->membership()->role : null,
            ],
            'tenant' => fn () => app(TenantContext::class)->hasTenant() ? app(TenantContext::class)->tenant() : null,
            'tenants' => fn () => $request->user() ? TenantMembership::query()->with('tenant:id,name,status')
                ->where('user_id', $request->user()->id)->where('status', 'active')
                ->whereHas('tenant', fn ($query) => $query->where('status', 'active'))
                ->get()->pluck('tenant')->filter()->values() : [],
            'flash' => ['success' => fn () => $request->session()->get('success')],
        ];
    }
}
