<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SsoTest extends TestCase
{
    use RefreshDatabase;

    private string $nonce = '';

    private string $email = 'ali@fabapars.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['watchrex.sso' => array_merge(config('watchrex.sso'), [
            'enabled' => true, 'issuer' => 'https://idp.example.com', 'client_id' => 'watchrex', 'client_secret' => 'secret',
            'auto_create' => true, 'allowed_domains' => ['fabapars.com'],
        ])]);
    }

    private function fakeIdp(): void
    {
        $sub = 'sub-123';
        Http::fake([
            'idp.example.com/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://idp.example.com', 'authorization_endpoint' => 'https://idp.example.com/auth',
                'token_endpoint' => 'https://idp.example.com/token', 'userinfo_endpoint' => 'https://idp.example.com/userinfo',
            ]),
            'idp.example.com/token' => fn () => Http::response(['access_token' => 'at', 'id_token' => 'x.'.rtrim(strtr(base64_encode(json_encode(['iss' => 'https://idp.example.com', 'aud' => 'watchrex', 'sub' => $sub, 'nonce' => $this->nonce, 'exp' => time() + 300])), '+/', '-_'), '=').'.sig']),
            'idp.example.com/userinfo' => fn () => Http::response(['sub' => $sub, 'email' => $this->email, 'email_verified' => true, 'name' => 'Ali']),
        ]);
    }

    public function test_full_oidc_login_provisions_user(): void
    {
        $this->fakeIdp();
        $redirect = $this->get('/auth/sso');
        $redirect->assertRedirect();
        $this->assertStringContainsString('code_challenge_method=S256', $redirect->headers->get('Location'));
        $flow = session('sso');
        $this->nonce = $flow['nonce'];

        $this->get('/auth/sso/callback?code=abc&state='.$flow['state'])->assertRedirect('/');

        $user = User::where('email', 'ali@fabapars.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('sub-123', $user->oidc_sub);
        $this->assertAuthenticatedAs($user);
    }

    public function test_state_nonce_and_domain_are_enforced(): void
    {
        $this->fakeIdp();
        $this->get('/auth/sso');
        $this->get('/auth/sso/callback?code=abc&state=forged')->assertRedirect('/login');
        $this->assertGuest();

        $this->get('/auth/sso');
        $flow = session('sso');
        $this->nonce = 'wrong-nonce';
        $this->get('/auth/sso/callback?code=abc&state='.$flow['state'])->assertRedirect('/login');
        $this->assertGuest();

        $this->get('/auth/sso');
        $flow = session('sso');
        $this->nonce = $flow['nonce'];
        $this->email = 'eve@evil.com';
        $this->get('/auth/sso/callback?code=abc&state='.$flow['state'])->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'eve@evil.com']);
    }

    public function test_sso_is_hidden_when_disabled(): void
    {
        config(['watchrex.sso.enabled' => false]);
        $this->get('/auth/sso')->assertNotFound();
    }
}
