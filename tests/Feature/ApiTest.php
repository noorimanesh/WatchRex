<?php

namespace Tests\Feature;

use App\Enums\MonitorStatus;
use App\Models\ApiToken;
use App\Models\Monitor;
use App\Models\Server;
use App\Models\User;
use App\Services\ServerHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_push_monitor_records_heartbeats(): void
    {
        $user = User::factory()->create();
        $m = new Monitor(['name' => 'Backup', 'type' => 'push', 'interval' => 3600, 'timeout' => 5, 'retries' => 0]);
        $m->user_id = $user->id;
        $m->save();

        $this->getJson("/api/push/{$m->push_token}?status=up&msg=backup+ok&ping=42")->assertOk()->assertJson(['ok' => true]);
        $m->refresh();
        $this->assertSame(MonitorStatus::Up, $m->status);
        $this->assertSame(42, $m->last_response_ms);

        $this->getJson('/api/push/'.str_repeat('x', 40))->assertNotFound();
    }

    public function test_agent_report_is_authenticated_and_stored(): void
    {
        $user = User::factory()->create();
        $server = new Server(['name' => 'cpanel-01', 'report_interval' => 60]);
        $server->user_id = $user->id;
        $token = $server->rotateToken();
        $server->save();

        $this->postJson('/api/agent/report', ['cpu' => 10])->assertUnauthorized();

        $this->withToken($token)->postJson('/api/agent/report', [
            'version' => '1.0.0', 'hostname' => 'srv1.example.com', 'panel' => 'cpanel', 'cpu' => 42.5, 'ram' => 71, 'disk' => 83,
            'load' => [1.2, 0.8, 0.5], 'services' => ['exim' => 'active', 'dovecot' => 'failed'],
            'mail' => ['mta' => 'exim', 'queue' => 12, 'sent' => 100, 'bounced' => 3, 'deferred' => 4, 'login_ok' => 50, 'login_failed' => 9,
                'failed_ips' => [['ip' => '1.2.3.4', 'count' => 7]]],
            'accounts' => [['user' => 'shop', 'domain' => 'shop.com', 'disk_used_mb' => 900, 'disk_limit_mb' => 1000, 'suspended' => false]],
            'containers' => [['name' => 'kuma', 'state' => 'running', 'cpu' => 2.4, 'mem' => '312MiB', 'restarts' => 0]],
        ])->assertOk();

        $server->refresh();
        $this->assertTrue($server->isOnline());
        $this->assertSame('cpanel', $server->panel);
        $this->assertSame(12, $server->stat('mail.queue'));
        $this->assertSame(1, $server->metrics()->count());
        $this->assertContains('Service dovecot is failed', ServerHealth::problems($server));

        $this->actingAs($user)->get("/servers/{$server->id}")->assertOk()->assertSee('1.2.3.4')->assertSee('shop.com')->assertSee('kuma');
    }

    public function test_rest_api_requires_token_and_scopes_to_owner(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = new Monitor(['name' => 'Mine', 'type' => 'http', 'target' => 'https://a.com', 'interval' => 60, 'timeout' => 5, 'retries' => 0]);
        $mine->user_id = $user->id;
        $mine->save();
        $theirs = new Monitor(['name' => 'Theirs', 'type' => 'http', 'target' => 'https://b.com', 'interval' => 60, 'timeout' => 5, 'retries' => 0]);
        $theirs->user_id = $other->id;
        $theirs->save();

        $this->getJson('/api/v1/monitors')->assertUnauthorized();

        [, $plain] = ApiToken::issue($user, 'ci');
        $this->withToken($plain)->getJson('/api/v1/monitors')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Mine');
        $this->withToken($plain)->getJson("/api/v1/monitors/{$theirs->id}")->assertNotFound();
        $this->withToken($plain)->postJson("/api/v1/monitors/{$mine->id}/toggle", ['active' => false])->assertForbidden();
    }

    public function test_public_status_page_json_and_badge(): void
    {
        $user = User::factory()->create();
        $m = new Monitor(['name' => 'Website', 'type' => 'http', 'target' => 'https://a.com', 'interval' => 60, 'timeout' => 5, 'retries' => 0]);
        $m->user_id = $user->id;
        $m->status = MonitorStatus::Up;
        $m->save();
        $page = $user->statusPages()->create(['slug' => 'acme', 'title' => 'Acme Status', 'is_public' => true, 'show_uptime' => true, 'show_response' => true, 'accent' => '#10b981']);
        $page->monitors()->attach($m->id, ['sort' => 0, 'display_name' => 'Public Website']);

        $this->get('/status/acme')->assertOk()->assertSee('Acme Status')->assertSee('Public Website');
        $this->getJson('/status/acme/json')->assertOk()->assertJsonPath('status', 'operational');
        $this->get('/status/acme/rss')->assertOk();
        $this->get("/badge/{$m->uuid}/status.svg")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');

        $page->update(['is_public' => false]);
        \Cache::flush();
        $this->get('/status/acme')->assertNotFound();
    }

    public function test_windows_agent_report_with_security_block(): void
    {
        $user = User::factory()->create();
        $server = new Server(['name' => 'WIN-IIS-01', 'report_interval' => 60]);
        $server->user_id = $user->id;
        $token = $server->rotateToken();
        $server->save();

        $this->withToken($token)->postJson('/api/agent/report', [
            'platform' => 'windows', 'os' => 'Microsoft Windows Server 2022', 'cpu' => 37, 'ram' => 75, 'disk' => 85, 'load' => [2],
            'services' => ['W3SVC' => 'active', 'SMTPSVC' => 'inactive', 'iis/shop' => 'active'],
            'security' => ['source' => 'windows', 'failed_logons' => 150, 'remote_logons' => 1,
                'failed_ips' => [['ip' => '185.220.101.4', 'count' => 140]], 'failed_users' => [['user' => 'administrator', 'count' => 150]]],
        ])->assertOk();

        $server->refresh();
        $this->assertSame('windows', $server->stat('platform'));
        $problems = ServerHealth::problems($server);
        $this->assertContains('Service SMTPSVC is inactive', $problems);
        $this->assertTrue(collect($problems)->contains(fn ($p) => str_contains($p, 'Failed logins 150')));

        $this->actingAs($user)->get("/servers/{$server->id}")->assertOk()->assertSee('185.220.101.4')->assertSee('administrator');
    }

    public function test_windows_agent_scripts_are_served_with_hub_url(): void
    {
        $this->get('/agent/install.ps1')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee("\$Url = '".rtrim(config('app.url'), '/')."'", false)
            ->assertDontSee('__WATCHREX_URL__');
        $this->get('/agent/watchrex-agent.ps1')->assertOk()->assertSee('Win32_OperatingSystem');
    }
}
