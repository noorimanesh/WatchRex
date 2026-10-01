<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Alerting\ChannelSender;
use App\Services\Checks\CheckerFactory;
use App\Services\Checks\CheckFailed;
use App\Services\Checks\TargetGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    private function monitorFor(User $user, array $attrs = []): Monitor
    {
        $m = new Monitor(array_merge(['name' => 'Site', 'type' => 'http', 'target' => 'https://example.com', 'interval' => 60, 'timeout' => 5, 'retries' => 0], $attrs));
        $m->user_id = $user->id;
        $m->save();

        return $m;
    }

    public function test_users_only_see_their_own_monitors(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $mine = $this->monitorFor($alice, ['name' => 'Alice site']);
        $theirs = $this->monitorFor($bob, ['name' => 'Bob site']);

        $this->actingAs($alice)->get('/monitors')->assertSee('Alice site')->assertDontSee('Bob site');
        $this->actingAs($alice)->get("/monitors/{$theirs->id}")->assertNotFound();
        $this->actingAs($alice)->delete("/monitors/{$theirs->id}")->assertNotFound();
        $this->actingAs($alice)->get("/monitors/{$mine->id}")->assertOk();
    }

    public function test_admin_sees_everything(): void
    {
        $admin = User::factory()->admin()->create();
        $m = $this->monitorFor(User::factory()->create(), ['name' => 'Customer site']);

        $this->actingAs($admin)->get('/monitors')->assertSee('Customer site');
        $this->actingAs($admin)->get("/monitors/{$m->id}")->assertOk();
    }

    public function test_viewer_is_read_only(): void
    {
        $viewer = User::factory()->viewer()->create();
        $this->actingAs($viewer)->get('/')->assertOk();
        $this->actingAs($viewer)->post('/monitors', ['name' => 'x', 'type' => 'http'])->assertForbidden();
    }

    public function test_non_admins_cannot_reach_admin_pages(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/users')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/users')->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/system')->assertOk();
    }

    public function test_create_monitor_and_enforce_plan_limits(): void
    {
        $user = User::factory()->create(['plan' => 'free']);
        $payload = ['name' => 'Shop', 'type' => 'http', 'target' => 'https://shop.example.com', 'interval' => 300, 'timeout' => 10, 'retries' => 1, 'method' => 'GET', 'settings' => ['expected_status' => '200-299']];

        $this->actingAs($user)->post('/monitors', $payload)->assertRedirect();
        $this->assertDatabaseHas('monitors', ['name' => 'Shop', 'user_id' => $user->id]);

        // Interval below the plan minimum is rejected.
        $this->actingAs($user)->post('/monitors', array_merge($payload, ['interval' => 30]))->assertSessionHasErrors('interval');

        for ($i = 0; $i < 4; $i++) {
            $this->monitorFor($user);
        }
        $this->actingAs($user)->post('/monitors', $payload)->assertSessionHasErrors('name');
        $this->assertSame(5, $user->monitors()->count());
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $user = User::factory()->create();
        $m = $this->monitorFor($user, ['type' => 'imap', 'target' => 'mail.example.com']);
        $m->credentials = ['username' => 'info@example.com', 'password' => 'super-secret'];
        $m->save();

        $raw = \DB::table('monitors')->where('id', $m->id)->value('credentials');
        $this->assertStringNotContainsString('super-secret', $raw);
        $this->assertSame('super-secret', $m->fresh()->credential('password'));
    }

    public function test_tenant_webhooks_cannot_target_internal_addresses(): void
    {
        $user = User::factory()->create();
        $channel = new NotificationChannel(['name' => 'Hook', 'type' => 'webhook', 'config' => ['url' => 'http://169.254.169.254/latest/meta-data'], 'is_active' => true]);
        $channel->user_id = $user->id;
        $channel->save();

        $this->expectException(CheckFailed::class);
        app(ChannelSender::class)->send($channel, ['event' => 'test', 'title' => 't', 'message' => 'm', 'time' => now()->toIso8601String(), 'color' => '#000']);
    }

    public function test_tenant_monitors_cannot_probe_private_networks(): void
    {
        $user = User::factory()->create();
        $m = $this->monitorFor($user, ['type' => 'tcp', 'target' => '127.0.0.1', 'port' => 22]);

        $result = CheckerFactory::for($m->type)->check($m->load('user'));
        $this->assertTrue($result->isDown());
        $this->assertStringContainsString('private or reserved', $result->message);
    }

    public function test_unresolvable_host_fails_cleanly_without_php_warnings(): void
    {
        // Laravel turns warnings into exceptions in tests, so only CheckFailed may surface here.
        $this->expectException(CheckFailed::class);
        TargetGuard::resolve('does-not-exist.invalid', true);
    }
}
