<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Livewire\SystemUsers;
use App\Models\User;
use App\Notifications\AccountCreatedNotification;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class SystemUsersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->administrator()->create(['first_name' => 'Ana', 'last_name' => 'Reyes', 'middle_name' => null]);
    }

    public function test_non_administrators_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->validator()->create());

        Livewire::test(SystemUsers::class)->assertForbidden();
    }

    public function test_an_administrator_adds_a_user_who_gets_one_welcome_email(): void
    {
        Notification::fake();
        $this->actingAs($this->admin);

        Livewire::test(SystemUsers::class)
            ->call('create')
            ->set('form.first_name', 'Carlo')
            ->set('form.last_name', 'Mendoza')
            ->set('form.email', 'Carlo@Example.com')
            ->set('form.mobile_number', '09171234567')
            ->set('form.level', UserLevel::Validator->value)
            ->assertSet('form.modules', UserLevel::Validator->defaultModules())
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $user = User::where('email', 'carlo@example.com')->firstOrFail();
        $this->assertSame(UserLevel::Validator, $user->level);
        $this->assertSame($this->admin->id, $user->created_by);
        $this->assertTrue($user->hasVerifiedEmail());

        Notification::assertSentTo($user, AccountCreatedNotification::class, function ($notification) {
            return $notification->createdBy === 'Ana Reyes';
        });
        Notification::assertCount(1);
    }

    public function test_an_administrator_can_add_another_administrator(): void
    {
        Notification::fake();
        $this->actingAs($this->admin);

        Livewire::test(SystemUsers::class)
            ->call('create')
            ->set('form.first_name', 'New')
            ->set('form.last_name', 'Admin')
            ->set('form.email', 'new.admin@example.com')
            ->set('form.level', UserLevel::Administrator->value)
            ->call('save')
            ->assertHasNoErrors();

        $user = User::where('email', 'new.admin@example.com')->firstOrFail();
        $this->assertTrue($user->isAdministrator());
        $this->assertSame(Modules::keys(), $user->modules);
    }

    public function test_editing_a_user_sends_no_email(): void
    {
        Notification::fake();
        $this->actingAs($this->admin);
        $user = User::factory()->create();

        Livewire::test(SystemUsers::class)
            ->call('edit', $user->id)
            ->set('form.first_name', 'Renamed')
            ->set('form.is_active', false)
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertSame('Renamed', $user->first_name);
        $this->assertFalse($user->is_active);
        Notification::assertNothingSent();
    }

    public function test_an_administrator_cannot_demote_or_deactivate_themselves(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(SystemUsers::class)
            ->call('edit', $this->admin->id)
            ->set('form.level', UserLevel::Client->value)
            ->call('save')
            ->assertHasErrors('form.level');

        Livewire::test(SystemUsers::class)
            ->call('edit', $this->admin->id)
            ->set('form.is_active', false)
            ->call('save')
            ->assertHasErrors('form.is_active');

        $this->assertTrue($this->admin->fresh()->isAdministrator());
        $this->assertTrue($this->admin->fresh()->is_active);
    }

    public function test_the_checkbox_grid_toggles_one_module(): void
    {
        $this->actingAs($this->admin);
        $client = User::factory()->create();

        Livewire::test(SystemUsers::class)->call('toggleModule', $client->id, Modules::F11);
        $this->assertTrue($client->fresh()->canAccess(Modules::F11));

        // Clients don't get F72 by default - the toggle switches it on
        Livewire::test(SystemUsers::class)->call('toggleModule', $client->id, Modules::F72);
        $this->assertTrue($client->fresh()->canAccess(Modules::F72));
    }

    public function test_the_checkbox_grid_cannot_grant_system_users_or_touch_administrators(): void
    {
        $this->actingAs($this->admin);
        $client = User::factory()->create();
        $otherAdmin = User::factory()->administrator()->create();

        Livewire::test(SystemUsers::class)->call('toggleModule', $client->id, Modules::USERS);
        $this->assertFalse($client->fresh()->canAccess(Modules::USERS));

        Livewire::test(SystemUsers::class)->call('toggleModule', $otherAdmin->id, Modules::F11);
        $this->assertTrue($otherAdmin->fresh()->canAccess(Modules::F11));
    }

    public function test_the_list_can_be_searched_and_filtered(): void
    {
        $this->actingAs($this->admin);
        User::factory()->create(['first_name' => 'Zenaida', 'email' => 'zen@example.com']);
        User::factory()->validator()->create(['first_name' => 'Valentin']);

        Livewire::test(SystemUsers::class)
            ->set('search', 'Zenaida')
            ->assertSee('zen@example.com')
            ->assertDontSee('Valentin')
            ->set('search', '')
            ->set('levelFilter', UserLevel::Validator->value)
            ->assertSee('Valentin')
            ->assertDontSee('Zenaida');
    }
}
