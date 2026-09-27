<?php

namespace Tests\Feature;

use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\User;
use App\Services\DependencyMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DependencyMapTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function m(string $name, ?Monitor $parent = null, string $status = 'up'): Monitor
    {
        $m = new Monitor(['name' => $name, 'type' => 'tcp', 'target' => 'h.example', 'port' => 1, 'interval' => 60, 'timeout' => 5, 'retries' => 0, 'parent_id' => $parent?->id]);
        $m->user_id = $this->user->id;
        $m->status = MonitorStatus::from($status);
        $m->save();

        return $m;
    }

    public function test_layout_and_impact_analysis(): void
    {
        $server = $this->m('Server');
        $db = $this->m('MySQL', $server, 'down');
        $site = $this->m('Website', $db);
        $api = $this->m('API', $db);
        $this->m('Lonely');

        $map = DependencyMap::build(Monitor::all());

        $this->assertCount(4, $map['nodes']);
        $this->assertCount(3, $map['edges']);
        $this->assertSame(1, $map['independent']);
        $this->assertSame(0, $map['nodes'][$server->id]['depth']);
        $this->assertSame(2, $map['nodes'][$site->id]['depth']);
        // Parent is vertically centred between its children.
        $this->assertEquals(($map['nodes'][$site->id]['y'] + $map['nodes'][$api->id]['y']) / 2, $map['nodes'][$db->id]['y']);

        $this->assertCount(1, $map['impacts']);
        $this->assertEqualsCanonicalizing([$site->id, $api->id], $map['impacts'][0]['affected']->pluck('id')->all());
        $this->assertTrue($map['nodes'][$site->id]['impacted']);
        $this->assertFalse($map['nodes'][$server->id]['impacted']);
    }

    public function test_circular_dependencies_are_rejected(): void
    {
        $a = $this->m('A');
        $b = $this->m('B', $a);
        $c = $this->m('C', $b);

        $this->assertTrue(DependencyMap::createsCycle($a->id, $c->id));
        $this->assertFalse(DependencyMap::createsCycle($c->id, $a->id));

        $this->actingAs($this->user)->put("/monitors/{$a->id}", [
            'name' => 'A', 'type' => 'tcp', 'target' => 'h.example', 'port' => 1, 'interval' => 60, 'timeout' => 5, 'retries' => 0, 'parent_id' => $c->id,
        ])->assertSessionHasErrors('parent_id');
    }

    public function test_pages_render_and_respect_tenancy(): void
    {
        $server = $this->m('Private server');
        $this->m('Private site', $server);
        $other = User::factory()->create();

        $this->actingAs($this->user)->get('/dependencies')->assertOk()->assertSee('Private site');
        $this->actingAs($this->user)->get("/monitors/{$server->id}")->assertOk()->assertSee('depmap', false);
        $this->actingAs($other)->get('/dependencies')->assertOk()->assertDontSee('Private site');
    }
}
