<?php

namespace Tests\Feature;

use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\Probe;
use App\Models\User;
use App\Services\Checks\CheckResult;
use App\Services\MonitorRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProbeTest extends TestCase
{
    use RefreshDatabase;

    private function setupProbe(): array
    {
        Queue::fake();
        $user = User::factory()->create();
        $probe = new Probe(['name' => 'Iran', 'location' => 'nl-amsterdam', 'country_code' => 'IR']);
        $token = $probe->rotateToken();
        $probe->save();

        $m = new Monitor(['name' => 'Shop', 'type' => 'http', 'target' => 'https://shop.example', 'interval' => 60, 'timeout' => 5, 'retries' => 0, 'settings' => ['quorum' => 'majority', 'check_local' => true], 'credentials' => ['username' => 'u', 'password' => 'p']]);
        $m->user_id = $user->id;
        $m->save();
        $m->probes()->attach($probe->id);

        return [$probe, $token, $m];
    }

    public function test_probe_pulls_jobs_and_pushes_results(): void
    {
        [$probe, $token, $m] = $this->setupProbe();

        $this->getJson('/api/probe/jobs')->assertUnauthorized();
        $this->withToken($token)->getJson('/api/probe/jobs')->assertOk()
            ->assertJsonPath('location', 'nl-amsterdam')
            ->assertJsonPath('jobs.0.id', $m->id)
            ->assertJsonPath('jobs.0.credentials.password', 'p')
            ->assertJsonPath('jobs.0.guard', true);

        $this->withToken($token)->postJson('/api/probe/results', ['results' => [
            ['monitor_id' => $m->id, 'status' => 'up', 'response_ms' => 80, 'message' => 'HTTP 200'],
            ['monitor_id' => 999999, 'status' => 'down', 'message' => 'ignored'],
        ]])->assertOk()->assertJsonPath('accepted', 1);

        $this->assertDatabaseHas('heartbeats', ['monitor_id' => $m->id, 'location' => 'nl-amsterdam', 'status' => 1]);
        $this->assertNotNull($probe->fresh()->last_seen_at);
    }

    public function test_geo_specific_failure_is_degraded_not_down(): void
    {
        [$probe, , $m] = $this->setupProbe();
        $runner = app(MonitorRunner::class);

        $runner->process($m, CheckResult::up(120, 'HTTP 200'), config('watchrex.location'));
        $runner->process($m, CheckResult::down('Connection timed out'), 'nl-amsterdam');

        $m->refresh();
        $this->assertSame(MonitorStatus::Warning, $m->status);
        $this->assertStringContainsString('geo-specific', $m->last_message);

        // Both locations down → majority reached → down.
        $runner->process($m, CheckResult::down('Connection refused'), config('watchrex.location'));
        $this->assertSame(MonitorStatus::Down, $m->fresh()->status);
    }

    public function test_admin_can_manage_probes(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/admin/probes', ['name' => 'Germany', 'location' => 'de-fsn', 'country_code' => 'de'])->assertRedirect();
        $this->assertDatabaseHas('probes', ['location' => 'de-fsn', 'country_code' => 'DE']);
        $this->actingAs($admin)->get('/admin/probes')->assertOk()->assertSee('WATCHREX_PROBE_TOKEN');
        $this->actingAs(User::factory()->create())->get('/admin/probes')->assertForbidden();
    }
}
