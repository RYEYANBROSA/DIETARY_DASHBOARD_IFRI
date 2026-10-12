<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Auth\ResetPassword;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $overrides = []): array
    {
        return [
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'email' => 'Juan@Example.com',
            'mobile_number' => '09171234567',
            'password' => 'Strong#Pass1',
            'password_confirmation' => 'Strong#Pass1',
            ...$overrides,
        ];
    }

    public function test_registration_creates_an_unverified_client_and_emails_a_verification_link(): void
    {
        Notification::fake();

        $component = Livewire::test(Register::class);
        foreach ($this->registration() as $field => $value) {
            $component->set($field, $value);
        }
        $component->call('register')->assertHasNoErrors()->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'juan@example.com')->firstOrFail();

        $this->assertSame(UserLevel::Client, $user->level);
        $this->assertSame([Modules::DASHBOARD, Modules::F72, Modules::PROFILE], $user->modules);
        $this->assertNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        $component = Livewire::test(Register::class);
        foreach ($this->registration(['password' => 'password', 'password_confirmation' => 'password']) as $field => $value) {
            $component->set($field, $value);
        }

        $component->call('register')->assertHasErrors('password');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_rejects_a_taken_email_and_a_bad_mobile_number(): void
    {
        User::factory()->create(['email' => 'juan@example.com']);

        $component = Livewire::test(Register::class);
        foreach ($this->registration(['mobile_number' => '12345']) as $field => $value) {
            $component->set($field, $value);
        }

        $component->call('register')->assertHasErrors(['email', 'mobile_number']);
    }

    public function test_login_sends_an_unverified_account_to_the_verification_page(): void
    {
        $user = User::factory()->unverified()->create(['password' => 'Strong#Pass1']);

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'Strong#Pass1')
            ->call('login')
            ->assertRedirect(route('verification.notice'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_lands_a_verified_account_on_its_first_module(): void
    {
        $user = User::factory()->validator()->create(['password' => 'Strong#Pass1']);

        Livewire::test(Login::class)
            ->set('email', strtoupper($user->email))
            ->set('password', 'Strong#Pass1')
            ->call('login')
            ->assertRedirect(route('dashboard'));
    }

    public function test_login_refuses_a_wrong_password_and_a_deactivated_account(): void
    {
        $user = User::factory()->create(['password' => 'Strong#Pass1']);
        $inactive = User::factory()->inactive()->create(['password' => 'Strong#Pass1']);

        Livewire::test(Login::class)
            ->set('email', $user->email)->set('password', 'Wrong#Pass1')
            ->call('login')->assertHasErrors('email');

        Livewire::test(Login::class)
            ->set('email', $inactive->email)->set('password', 'Strong#Pass1')
            ->call('login')->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_verification_link_works_without_being_signed_in(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->get($url)->assertRedirect(route('login'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_tampered_verification_link_is_refused(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1('someone-else@example.com'),
        ]);

        $this->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_forgot_password_emails_a_reset_link_and_does_not_reveal_unknown_emails(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        Livewire::test(ForgotPassword::class)->set('email', $user->email)->call('sendResetLink')->assertSet('sent', true);
        Notification::assertSentTo($user, ResetPasswordNotification::class);

        Livewire::test(ForgotPassword::class)->set('email', 'nobody@example.com')->call('sendResetLink')
            ->assertSet('sent', true)->assertHasNoErrors();
    }

    public function test_reset_password_sets_the_new_password(): void
    {
        $user = User::factory()->unverified()->create();
        $token = Password::broker()->createToken($user);

        Livewire::withQueryParams(['email' => $user->email])
            ->test(ResetPassword::class, ['token' => $token])
            ->assertSet('email', $user->email)
            ->set('password', 'Brand#New2026')
            ->set('password_confirmation', 'Brand#New2026')
            ->call('resetPassword')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue(Hash::check('Brand#New2026', $user->password));
        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_reset_password_rejects_a_bad_token(): void
    {
        $user = User::factory()->create();

        Livewire::test(ResetPassword::class, ['token' => 'not-a-real-token'])
            ->set('email', $user->email)
            ->set('password', 'Brand#New2026')
            ->set('password_confirmation', 'Brand#New2026')
            ->call('resetPassword')
            ->assertHasErrors('email');
    }

    public function test_emails_render_in_the_branded_template(): void
    {
        $user = User::factory()->unverified()->create(['first_name' => 'Maria']);

        $html = (string) (new VerifyEmailNotification)->toMail($user)->render();

        $this->assertStringContainsString('Hello Maria,', $html);
        $this->assertStringContainsString('Verify email address', $html);
        $this->assertStringContainsString('/email/verify/' . $user->id . '/', $html);
    }
}
