<?php

namespace App\Livewire\Auth;

use App\Enums\UserLevel;
use App\Livewire\Concerns\ValidatesOnBlur;
use App\Models\User;
use App\Support\AccountRules;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.guest')]
#[Title('Create an account')]
class Register extends Component
{
    use ValidatesOnBlur;

    public string $first_name = '';

    public string $middle_name = '';

    public string $last_name = '';

    public string $email = '';

    public string $mobile_number = '';

    public string $password = '';

    public string $password_confirmation = '';

    protected function rules(): array
    {
        return [
            ...AccountRules::names(),
            'email' => AccountRules::email(),
            'mobile_number' => AccountRules::mobile(),
            'password' => AccountRules::newPassword(),
        ];
    }

    protected function blurValidated(): array
    {
        return ['first_name', 'middle_name', 'last_name', 'email', 'mobile_number'];
    }

    public function register()
    {
        $this->email = Str::lower(trim($this->email));

        $validated = $this->validate();

        // Every self-registered account starts as a Client; only an
        // administrator can raise the level from System Users
        $user = User::create([
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?: null,
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'mobile_number' => $validated['mobile_number'] ?: null,
            'level' => UserLevel::Client,
            'password' => $validated['password'],
        ]);

        Auth::login($user);
        session()->regenerate();

        // Sends the verification email. An SMTP failure must not lose the
        // account that was just created - the user can resend from the next page.
        try {
            event(new Registered($user));
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', 'Your account was created, but we could not send the verification email. Use "Resend" below.');
        }

        return $this->redirectRoute('verification.notice');
    }

    public function render()
    {
        return view('livewire.auth.register');
    }
}
