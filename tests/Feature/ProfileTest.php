<?php

namespace Tests\Feature;

use App\Livewire\Profile;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_details_can_be_updated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('first_name', 'Maria')
            ->set('middle_name', 'Lopez')
            ->set('last_name', 'Santos')
            ->set('mobile_number', '09181234567')
            ->call('saveDetails')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $user->refresh();
        $this->assertSame('Maria L. Santos', $user->name);
        $this->assertSame('09181234567', $user->mobile_number);
        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_changing_the_email_requires_verifying_the_new_address(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('email', 'New.Address@Example.com')
            ->call('saveDetails')
            ->assertRedirect(route('verification.notice'));

        $user->refresh();
        $this->assertSame('new.address@example.com', $user->email);
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_the_password_changes_only_with_the_current_password_and_a_strong_new_one(): void
    {
        $user = User::factory()->create(['password' => 'Old#Pass2025']);
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('current_password', 'wrong')
            ->set('password', 'New#Pass2026')
            ->set('password_confirmation', 'New#Pass2026')
            ->call('updatePassword')
            ->assertHasErrors('current_password');

        Livewire::test(Profile::class)
            ->set('current_password', 'Old#Pass2025')
            ->set('password', 'weakpass')
            ->set('password_confirmation', 'weakpass')
            ->call('updatePassword')
            ->assertHasErrors('password');

        Livewire::test(Profile::class)
            ->set('current_password', 'Old#Pass2025')
            ->set('password', 'New#Pass2026')
            ->set('password_confirmation', 'New#Pass2026')
            ->call('updatePassword')
            ->assertHasNoErrors()
            ->assertSet('password', '');

        $this->assertTrue(Hash::check('New#Pass2026', $user->fresh()->password));
    }
}
