<?php

namespace App\Livewire\Auth;

use App\Livewire\Concerns\ValidatesOnBlur;
use App\Support\AccountRules;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.guest')]
#[Title('Forgot password')]
class ForgotPassword extends Component
{
    use ValidatesOnBlur;

    public string $email = '';

    public bool $sent = false;

    protected function blurValidated(): array
    {
        return ['email'];
    }

    protected function rulesForBlur(): array
    {
        return ['email' => AccountRules::emailFormat()];
    }

    public function sendResetLink(): void
    {
        $this->validate(['email' => AccountRules::emailFormat()]);

        try {
            $status = Password::sendResetLink(['email' => Str::lower(trim($this->email))]);
        } catch (\Throwable $e) {
            report($e);
            $this->addError('email', 'We could not send the email right now. Please try again in a few minutes.');

            return;
        }

        if ($status === Password::RESET_THROTTLED) {
            $this->addError('email', 'A reset link was sent recently. Please check your inbox, or wait a minute and try again.');

            return;
        }

        // Same answer whether or not the address has an account, so this form
        // can't be used to find out who is registered
        $this->sent = true;
    }

    public function render()
    {
        return view('livewire.auth.forgot-password');
    }
}
