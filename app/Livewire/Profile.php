<?php

namespace App\Livewire;

use App\Livewire\Concerns\ValidatesOnBlur;
use App\Support\AccountRules;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('My Profile')]
class Profile extends Component
{
    use ValidatesOnBlur;

    public string $first_name = '';

    public string $middle_name = '';

    public string $last_name = '';

    public string $email = '';

    public string $mobile_number = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $this->fillFromUser();
    }

    private function fillFromUser(): void
    {
        $user = Auth::user();

        $this->first_name = $user->first_name;
        $this->middle_name = (string) $user->middle_name;
        $this->last_name = $user->last_name;
        $this->email = $user->email;
        $this->mobile_number = (string) $user->mobile_number;
    }

    protected function blurValidated(): array
    {
        return ['first_name', 'middle_name', 'last_name', 'email', 'mobile_number'];
    }

    /** The account-detail rules (the password form validates separately). */
    protected function rulesForBlur(): array
    {
        return [
            ...AccountRules::names(),
            'email' => AccountRules::email(Auth::id()),
            'mobile_number' => AccountRules::mobile(),
        ];
    }

    public function saveDetails()
    {
        $user = Auth::user();
        $this->email = Str::lower(trim($this->email));

        $validated = $this->validate($this->rulesForBlur());

        $emailChanged = $validated['email'] !== $user->email;

        $user->fill([
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?: null,
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'mobile_number' => $validated['mobile_number'] ?: null,
        ]);

        // A new address has to be confirmed before it can be used to sign in
        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            try {
                $user->sendEmailVerificationNotification();
            } catch (\Throwable $e) {
                report($e);
                session()->flash('error', 'Your email was changed, but we could not send the verification link. Use "Resend" below.');
            }

            return $this->redirectRoute('verification.notice');
        }

        $this->dispatch('toast', message: 'Your account details have been saved.');
    }

    public function updatePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => [...AccountRules::newPassword(), 'different:current_password'],
        ], [
            'current_password.current_password' => 'Your current password is incorrect.',
            'password.different' => 'The new password must be different from your current one.',
        ]);

        Auth::user()->update(['password' => $this->password]);

        $this->reset('current_password', 'password', 'password_confirmation');
        $this->dispatch('toast', message: 'Your password has been updated.');
    }

    public function render()
    {
        return view('livewire.profile', ['user' => Auth::user()]);
    }
}
