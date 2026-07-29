<?php

namespace App\Http\Requests\Auth;

use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $login = $this->input('login', $this->input('email'));
        $this->merge(['login' => Str::lower(trim((string) $login))]);
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $login = (string) $this->input('login');
        $column = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $user = User::query()->where($column, $login)->first();
        $passwordHash = $user?->password ?? Hash::make(Str::random(40));

        if (! $user || ! Hash::check((string) $this->input('password'), $passwordHash)) {
            $this->failAuthentication();
        }

        $student = $user->student()->first();
        if ($student) {
            $membership = TenantMembership::query()
                ->with('tenant')
                ->where('tenant_id', $student->tenant_id)
                ->where('user_id', $user->id)
                ->where('role', 'student')
                ->where('status', 'active')
                ->whereHas('tenant', fn ($query) => $query->where('status', 'active'))
                ->first();

            if (! $membership || ! $student->student_access_enabled_at || $student->status !== 'active') {
                $this->failAuthentication();
            }

            $this->session()->put('active_tenant_id', $student->tenant_id);
        }

        Auth::login($user, $this->boolean('remember'));
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        RateLimiter::clear($this->throttleKey());
    }

    private function failAuthentication(): never
    {
        RateLimiter::hit($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => trans('auth.failed'),
        ]);
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('login')).'|'.$this->ip());
    }
}
