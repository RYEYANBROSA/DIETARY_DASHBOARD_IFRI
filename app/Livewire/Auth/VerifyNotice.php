<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.guest')]
#[Title('Verify your email')]
class VerifyNotice extends Component
{
    public function mount()
    {
        if (Auth::user()->hasVerifiedEmail()) {
            return $this->redirectRoute(Auth::user()->homeRoute());
        }
    }

    public function resend(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectRoute($user->homeRoute());

            return;
        }

        $key = 'verify-resend:' . $user->id;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            $this->dispatch('toast', message: 'Please wait ' . RateLimiter::availableIn($key) . ' seconds before asking for another email.', type: 'error');

            return;
        }

        try {
            $user->sendEmailVerificationNotification();
            RateLimiter::hit($key, 60);
            $this->dispatch('toast', message: 'A new verification link has been sent to ' . $user->email . '.');
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: 'We could not send the email right now. Please try again in a few minutes.', type: 'error');
        }
    }

    public function render()
    {
        return view('livewire.auth.verify-notice');
    }
}
