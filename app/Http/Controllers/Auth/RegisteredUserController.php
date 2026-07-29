<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'tenant_name' => ['required', 'string', 'max:150'],
            'tenant_type' => ['required', Rule::in(['homeschool_family', 'co_op', 'microschool', 'tutoring_organization', 'private_school'])],
            'timezone' => ['required', 'timezone'],
        ]);

        [$user, $tenant] = DB::transaction(function () use ($request) {
            $user = User::create(['name' => $request->name, 'email' => $request->email, 'password' => Hash::make($request->password)]);
            $tenant = Tenant::create(['name' => $request->tenant_name, 'type' => $request->tenant_type, 'timezone' => $request->timezone, 'locale' => 'en', 'status' => 'active']);
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);

            return [$user, $tenant];
        });

        event(new Registered($user));

        Auth::login($user);
        $request->session()->put('active_tenant_id', $tenant->id);

        return redirect(route('dashboard', absolute: false));
    }
}
