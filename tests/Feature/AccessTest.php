<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/profile')->assertRedirect(route('login'));
        $this->get('/users')->assertRedirect(route('login'));
    }

    public function test_default_module_access_per_level(): void
    {
        $admin = User::factory()->administrator()->create();
        $validator = User::factory()->validator()->create();
        $client = User::factory()->create();

        foreach (Modules::keys() as $module) {
            $this->assertTrue($admin->canAccess($module), "admin: {$module}");
        }

        $this->assertTrue($validator->canAccess(Modules::F11));
        $this->assertFalse($validator->canAccess(Modules::F72));
        $this->assertFalse($validator->canAccess(Modules::USERS));

        $this->assertTrue($client->canAccess(Modules::F72));
        $this->assertFalse($client->canAccess(Modules::F11));
        $this->assertFalse($client->canAccess(Modules::USERS));
    }

    public function test_routes_enforce_module_access(): void
    {
        $client = User::factory()->create();
        $validator = User::factory()->validator()->create();
        $admin = User::factory()->administrator()->create();

        $this->actingAs($client)->get('/users')->assertForbidden();
        $this->actingAs($client)->get('/f11')->assertForbidden();
        $this->actingAs($client)->get('/profile')->assertOk();

        $this->actingAs($validator)->get('/f72')->assertForbidden();
        $this->actingAs($validator)->get('/users')->assertForbidden();

        $this->actingAs($admin)->get('/users')->assertOk();
    }

    public function test_a_ticked_off_module_is_closed_even_for_its_default_level(): void
    {
        $client = User::factory()->create(['modules' => [Modules::DASHBOARD]]);

        $this->actingAs($client)->get('/profile')->assertForbidden();
    }

    public function test_users_module_cannot_be_held_by_a_non_administrator(): void
    {
        $client = User::factory()->create(['modules' => Modules::keys()]);

        $this->assertFalse($client->canAccess(Modules::USERS));
        $this->actingAs($client)->get('/users')->assertForbidden();
    }

    public function test_unverified_accounts_must_verify_first(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/profile')->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get('/email/verify')->assertOk();
    }

    public function test_a_deactivated_account_is_signed_out_on_its_next_request(): void
    {
        $user = User::factory()->inactive()->create();

        $this->actingAs($user)->get('/profile')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_account_with_no_modules_lands_on_the_no_access_page(): void
    {
        $user = User::factory()->create(['modules' => []]);

        $this->assertSame('no-access', $user->homeRoute());
        $this->actingAs($user)->get('/no-access')->assertOk()->assertSee('No modules assigned yet');
    }

    public function test_signed_in_users_are_redirected_away_from_guest_pages(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->get('/login')->assertRedirect(route('dashboard'));
    }
}
