<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\F11Encoding;
use App\Livewire\Profile;
use App\Livewire\SystemUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ValidationMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_required_field_says_this_field_is_required(): void
    {
        $component = Livewire::test(Register::class)->call('register');

        foreach (['first_name', 'last_name', 'email', 'password'] as $field) {
            $this->assertSame(['This field is required.'], $component->errors()->get($field), $field);
        }

        $this->actingAs(User::factory()->administrator()->create());

        $users = Livewire::test(SystemUsers::class)->call('create')->call('save');
        foreach (['form.first_name', 'form.last_name', 'form.email'] as $field) {
            $this->assertSame(['This field is required.'], $users->errors()->get($field), $field);
        }

        // F11 encoding is switched off for now; check its messages with it on
        F11Encoding::$editing = true;
        try {
            $f11 = Livewire::test(F11Encoding::class)->call('save');
            foreach (['member.province', 'member.surname', 'member.sex'] as $field) {
                $this->assertSame(['This field is required.'], $f11->errors()->get($field), $field);
            }
        } finally {
            F11Encoding::$editing = false;
        }
    }

    public function test_an_invalid_email_is_flagged_when_leaving_the_field(): void
    {
        Livewire::test(Register::class)
            ->set('email', 'juan@')
            ->assertHasErrors(['email' => 'email'])
            ->assertSee('Please enter a valid email address.')
            ->assertSet('validFields', []);

        Livewire::test(Login::class)
            ->set('email', 'not-an-email')
            ->assertSee('Please enter a valid email address.');

        // No dot in the domain - the plain RFC check alone would let this through
        Livewire::test(Register::class)
            ->set('email', 'juan@example')
            ->assertSee('Please enter a valid email address.');
    }

    public function test_a_valid_email_is_marked_valid_when_leaving_the_field(): void
    {
        Livewire::test(Register::class)
            ->set('email', ' Juan@Example.com ')
            ->assertHasNoErrors()
            ->assertSet('email', 'juan@example.com')
            ->assertSet('validFields', ['email'])
            ->assertSee('Valid email address.');
    }

    public function test_a_registered_email_is_flagged_when_leaving_the_field(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::test(Register::class)
            ->set('email', 'Taken@Example.com')
            ->assertSee('This email address is already registered.');
    }

    public function test_mobile_number_must_be_eleven_digits_starting_with_09(): void
    {
        $component = Livewire::test(Register::class);

        foreach (['0917123456', '091712345678', '0917-123-4567', '+639171234567'] as $bad) {
            $component->set('mobile_number', $bad)->assertHasErrors('mobile_number');
        }

        $component->set('mobile_number', '19171234567')
            ->assertSee('Mobile number must start with 09.');

        $component->set('mobile_number', '0917123456')
            ->assertSee('Mobile number must be exactly 11 digits (e.g. 09171234567).');

        $component->set('mobile_number', '09171234567')
            ->assertHasNoErrors('mobile_number')
            ->assertSee('Valid mobile number.');
    }

    public function test_the_same_mobile_rule_applies_on_profile_and_system_users(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin);

        Livewire::test(Profile::class)
            ->set('mobile_number', '0917123')
            ->assertSee('Mobile number must be exactly 11 digits (e.g. 09171234567).');

        Livewire::test(SystemUsers::class)
            ->call('create')
            ->set('form.mobile_number', '0917123')
            ->assertSee('Mobile number must be exactly 11 digits (e.g. 09171234567).');
    }
}
