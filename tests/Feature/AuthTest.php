<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('WatchRex');
    }

    public function test_user_can_sign_in_and_see_dashboard(): void
    {
        $user = User::factory()->create(['password' => 'Secret-Pass-123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'Secret-Pass-123'])->assertRedirect('/');
        $this->get('/')->assertOk()->assertSee('Overview');
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
    }

    public function test_wrong_password_and_disabled_accounts_are_rejected(): void
    {
        $user = User::factory()->create(['password' => 'Secret-Pass-123']);
        $this->post('/login', ['email' => $user->email, 'password' => 'nope'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $user->update(['is_active' => false]);
        $this->post('/login', ['email' => $user->email, 'password' => 'Secret-Pass-123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'bad']);
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many login attempts', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_two_factor_challenge(): void
    {
        $secret = Totp::generateSecret();
        $user = User::factory()->create(['password' => 'Secret-Pass-123']);
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['aaaa1111-bbbb2222']])->save();

        $this->post('/login', ['email' => $user->email, 'password' => 'Secret-Pass-123'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();

        $this->post('/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/two-factor', ['code' => Totp::code($secret)])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_recovery_code_is_single_use(): void
    {
        $user = User::factory()->create(['password' => 'Secret-Pass-123']);
        $user->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['aaaa1111-bbbb2222']])->save();

        $this->post('/login', ['email' => $user->email, 'password' => 'Secret-Pass-123']);
        $this->post('/two-factor', ['code' => 'aaaa1111-bbbb2222'])->assertRedirect('/');
        $this->assertSame([], $user->fresh()->two_factor_recovery_codes);
    }

    public function test_security_headers_are_sent(): void
    {
        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('X-Powered-By');
    }

    public function test_persian_users_get_rtl_translated_ui(): void
    {
        $user = User::factory()->create(['locale' => 'fa']);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('داشبورد')
            ->assertSee('نمای کلی');
    }
}
