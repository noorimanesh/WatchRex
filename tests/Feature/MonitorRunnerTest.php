<?php

namespace Tests\Feature;

use App\Enums\MonitorStatus;
use App\Jobs\SendNotification;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Checks\CheckResult;
use App\Services\MonitorRunner;
use App\Services\Uptime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonitorRunnerTest extends TestCase
{
    use RefreshDatabase;

    private Monitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $user = User::factory()->create();
        $channel = new NotificationChannel(['name' => 'TG', 'type' => 'webhook', 'config' => ['url' => 'https://hooks.example.com'], 'is_default' => true, 'is_active' => true]);
        $channel->user_id = $user->id;
        $channel->save();

        $this->monitor = new Monitor(['name' => 'API', 'type' => 'http', 'target' => 'https://api.example.com', 'interval' => 60, 'timeout' => 5, 'retries' => 1, 'settings' => ['notify_warning' => true, 'anomaly' => true]]);
        $this->monitor->user_id = $user->id;
        $this->monitor->save();
    }

    private function feed(CheckResult $r): Monitor
    {
        app(MonitorRunner::class)->process($this->monitor->fresh(), $r);

        return $this->monitor->fresh();
    }

    public function test_retries_then_down_then_recovery_with_incident_and_alerts(): void
    {
        $this->assertSame(MonitorStatus::Up, $this->feed(CheckResult::up(120))->status);

        // First failure is only a retry.
        $m = $this->feed(CheckResult::down('Connection refused'));
        $this->assertSame(MonitorStatus::Up, $m->status);
        $this->assertStringContainsString('Retrying', $m->last_message);
        Queue::assertNothingPushed();

        // Second consecutive failure confirms the outage.
        $m = $this->feed(CheckResult::down('Connection refused'));
        $this->assertSame(MonitorStatus::Down, $m->status);
        $this->assertDatabaseHas('incidents', ['monitor_id' => $m->id, 'resolved_at' => null, 'severity' => 'critical']);
        Queue::assertPushed(SendNotification::class, fn ($job) => $job->payload['event'] === 'down');

        $m = $this->feed(CheckResult::up(130));
        $this->assertSame(MonitorStatus::Up, $m->status);
        $this->assertNotNull($m->incidents()->first()->resolved_at);
        Queue::assertPushed(SendNotification::class, fn ($job) => $job->payload['event'] === 'recovered');

        $this->assertSame(4, $m->heartbeats()->count());
        $stat = $m->dailyStats()->first();
        $this->assertSame(2, $stat->up);
        $this->assertSame(2, $stat->down);
        $this->assertEquals(50.0, Uptime::periods($m)['24h']);
    }

    public function test_response_threshold_marks_degraded(): void
    {
        $this->monitor->update(['settings' => ['response_warn_ms' => 500, 'notify_warning' => true]]);
        $m = $this->feed(CheckResult::up(1500));
        $this->assertSame(MonitorStatus::Warning, $m->status);
        $this->assertStringContainsString('Slow response', $m->last_message);
    }

    public function test_anomaly_detection_flags_spikes_after_learning(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->feed(CheckResult::up(200 + ($i % 5) * 5));
        }
        $m = $this->feed(CheckResult::up(2400));
        $this->assertSame(MonitorStatus::Warning, $m->status);
        $this->assertStringContainsString('anomaly', strtolower($m->last_message));
    }

    public function test_maintenance_suppresses_alerts(): void
    {
        $window = $this->monitor->user->maintenanceWindows()->create(['title' => 'Upgrade', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addHour()]);
        $window->monitors()->attach($this->monitor->id);

        $this->feed(CheckResult::down('boom'));
        $m = $this->feed(CheckResult::down('boom'));
        $this->assertSame(MonitorStatus::Maintenance, $m->status);
        Queue::assertNothingPushed();
        $this->assertSame(0, $m->incidents()->count());
    }

    public function test_dependency_down_suppresses_child_alerts(): void
    {
        $parent = new Monitor(['name' => 'DB', 'type' => 'tcp', 'target' => 'db.local', 'port' => 3306, 'interval' => 60, 'timeout' => 5, 'retries' => 0]);
        $parent->user_id = $this->monitor->user_id;
        $parent->status = MonitorStatus::Down;
        $parent->save();
        $this->monitor->update(['parent_id' => $parent->id, 'retries' => 0]);

        $m = $this->feed(CheckResult::down('HTTP 502'));
        $this->assertSame(MonitorStatus::Down, $m->status);
        $this->assertStringContainsString('dependency', strtolower($m->last_message));
        Queue::assertNotPushed(SendNotification::class);
    }
}
