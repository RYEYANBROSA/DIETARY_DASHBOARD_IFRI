<?php

namespace App\Livewire\Auth;

use App\Livewire\Concerns\ValidatesOnBlur;
use App\Support\AccountRules;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.guest')]
#[Title('Sign in')]
class Login extends Component
{
    use ValidatesOnBlur;

    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    protected function blurValidated(): array
    {
        return ['email'];
    }

    protected function rulesForBlur(): array
    {
        return ['email' => AccountRules::emailFormat()];
    }

    public function login()
    {
        $this->validate([
            'email' => AccountRules::emailFormat(),
            'password' => ['required', 'string'],
        ]);

        $key = Str::transliterate(Str::lower($this->email) . '|' . request()->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many sign-in attempts. Try again in ' . RateLimiter::availableIn($key) . ' seconds.',
            ]);
        }

        if (!Auth::attempt(['email' => Str::lower($this->email), 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($key);

        $user = Auth::user();

        if (!$user->is_active) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated. Please contact an administrator.',
            ]);
        }

        session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        if (!$user->hasVerifiedEmail()) {
            return $this->redirectRoute('verification.notice');
        }

        return redirect()->intended(route($user->homeRoute()));
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
