<?php

namespace App\Http\Requests\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('login')) {
            $this->merge([
                'login' => Str::lower(trim((string) $this->input('login'))),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $mode = config('auth.login_field', 'both');
        $loginValue = (string) $this->input('login', '');

        $loginRules = match ($mode) {
            'email' => ['required', 'string', 'email', 'max:255'],
            'username' => ['required', 'string', 'max:10'],
            default => filter_var($loginValue, FILTER_VALIDATE_EMAIL)
                ? ['required', 'string', 'email', 'max:255']
                : ['required', 'string', 'max:10'],
        };

        return [
            'login' => $loginRules,
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $login = (string) $this->input('login');
        $password = (string) $this->input('password');
        $mode = config('auth.login_field', 'both');

        $field = match ($mode) {
            'email' => 'email',
            'username' => 'username',
            default => filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username',
        };

        $user = User::where($field, $login)->first();

        $hash = $user?->password
            ?? Cache::rememberForever('auth.dummy_hash', fn () => Hash::make(Str::random(40)));

        $passwordOk = Hash::check($password, $hash);

        if (! $user || ! $passwordOk) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => trans('auth.failed'),
            ]);
        }

        // Solo con credenciales válidas se informa si la cuenta está suspendida o dada de baja
        if ($user->status !== UserStatus::Activo) {
            RateLimiter::hit($this->throttleKey());

            $message = match ($user->status) {
                UserStatus::Baja => trans('account.baja'),
                UserStatus::Bloqueado => trans('account.bloqueado'),
                default => trans('account.inactivo'),
            };

            throw ValidationException::withMessages([
                'login' => $message,
            ]);
        }

        Auth::login($user, $this->boolean('remember'));

        RateLimiter::clear($this->throttleKey());
    }

    /**
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

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower((string) $this->string('login')).'|'.$this->ip());
    }
}
