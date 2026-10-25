<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_registration_is_closed(): void
    {
        $this->get('/admin/register')->assertNotFound();
    }

    public function test_user_without_two_factor_must_set_it_up_first(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertRedirect();
        $this->assertStringContainsString('multi-factor-authentication', $response->headers->get('Location'));
    }

    public function test_user_with_two_factor_can_open_the_dashboard(): void
    {
        $user = User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);

        $this->actingAs($user)->get('/admin')->assertOk();
    }

    public function test_two_factor_secret_is_stored_encrypted(): void
    {
        $user = User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);

        $raw = \DB::table('users')->where('id', $user->id)->value('app_authentication_secret');

        $this->assertNotSame('JBSWY3DPEHPK3PXP', $raw);
        $this->assertSame('JBSWY3DPEHPK3PXP', $user->fresh()->getAppAuthenticationSecret());
    }

    public function test_root_address_forwards_to_the_admin_panel(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }
}
