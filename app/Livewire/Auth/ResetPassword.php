<?php

namespace App\Livewire\Auth;

use App\Livewire\Concerns\ValidatesOnBlur;
use App\Models\User;
use App\Support\AccountRules;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Serves both the "forgot password" email and the "set your password" email
 * an administrator's new account sends.
 */
#[Layout('components.layouts.guest')]
#[Title('Set a new password')]
class ResetPassword extends Component
{
    use ValidatesOnBlur;

    #[Locked]
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request()->query('email', '');
    }

    protected function blurValidated(): array
    {
        return ['email'];
    }

    protected function rulesForBlur(): array
    {
        return ['email' => AccountRules::emailFormat()];
    }

    public function resetPassword()
    {
        $this->validate([
            'email' => AccountRules::emailFormat(),
            'password' => AccountRules::newPassword(),
        ]);

        $status = Password::reset(
            [
                'email' => Str::lower(trim($this->email)),
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
                'token' => $this->token,
            ],
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                // The link reached this inbox, which proves the address too
                if (!$user->hasVerifiedEmail()) {
                    $user->markEmailAsVerified();
                }

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->addError('email', match ($status) {
                Password::INVALID_TOKEN => 'This link is invalid or has expired. Request a new one from "Forgot password?".',
                default => 'We could not reset the password for this email address.',
            });

            return;
        }

        session()->flash('status', 'Your password has been set. You can now sign in.');

        return $this->redirectRoute('login');
    }

    public function render()
    {
        return view('livewire.auth.reset-password');
    }
}
