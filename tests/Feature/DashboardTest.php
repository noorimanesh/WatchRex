<?php

namespace Tests\Feature;

use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\MonitorGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_group_health_issues_and_trend(): void
    {
        $user = User::factory()->create();
        $m = new Monitor(['name' => 'Shop website', 'type' => 'http', 'target' => 'https://shop.test', 'interval' => 60, 'timeout' => 5, 'retries' => 0, 'is_active' => true]);
        $m->user_id = $user->id;
        $m->status = MonitorStatus::Down;
        $m->last_message = 'Connection refused';
        $m->save();

        $g = new MonitorGroup(['name' => 'shop.test', 'kind' => 'website']);
        $g->user_id = $user->id;
        $g->save();
        $g->monitors()->attach($m->id);

        foreach ([3, 2, 1] as $h) {
            DB::table('heartbeats')->insert(['monitor_id' => $m->id, 'status' => $h === 1 ? 0 : 1, 'response_ms' => $h === 1 ? null : 120 * $h, 'created_at' => now()->subHours($h)]);
        }

        $this->actingAs($user)->get('/')->assertOk()
            ->assertSee('Needs attention')->assertSee('Connection refused')
            ->assertSee('Group health')->assertSee('shop.test')
            ->assertSee('Response time — last 24 hours')->assertSee('Mail services');
    }
}
