<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    private function team(User $owner, array $members = []): Team
    {
        $team = new Team(['name' => 'NOC']);
        $team->owner_id = $owner->id;
        $team->save();
        $team->members()->attach($owner->id, ['role' => 'owner']);
        foreach ($members as [$user, $role]) {
            $team->members()->attach($user->id, ['role' => $role]);
        }

        return $team;
    }

    private function monitor(User $user, ?Team $team = null): Monitor
    {
        $m = new Monitor(['name' => 'Shared site', 'type' => 'http', 'target' => 'https://a.com', 'interval' => 60, 'timeout' => 5, 'retries' => 0, 'team_id' => $team?->id]);
        $m->user_id = $user->id;
        $m->save();

        return $m;
    }

    public function test_team_members_share_resources_according_to_role(): void
    {
        $owner = User::factory()->create();
        $dev = User::factory()->create();
        $viewer = User::factory()->create();
        $outsider = User::factory()->create();
        $team = $this->team($owner, [[$dev, 'developer'], [$viewer, 'viewer']]);
        $m = $this->monitor($owner, $team);

        $this->actingAs($dev)->get("/monitors/{$m->id}")->assertOk();
        $this->actingAs($dev)->get("/monitors/{$m->id}/edit")->assertOk();
        $this->actingAs($viewer)->get("/monitors/{$m->id}")->assertOk();
        $this->actingAs($viewer)->post("/monitors/{$m->id}/toggle")->assertForbidden();
        $this->actingAs($outsider)->get("/monitors/{$m->id}")->assertNotFound();

        $this->actingAs($dev)->post("/monitors/{$m->id}/toggle")->assertRedirect();
        $this->assertFalse($m->fresh()->is_active);
    }

    public function test_private_monitors_stay_private_inside_a_team(): void
    {
        $owner = User::factory()->create();
        $dev = User::factory()->create();
        $this->team($owner, [[$dev, 'developer']]);
        $private = $this->monitor($owner);

        $this->actingAs($dev)->get("/monitors/{$private->id}")->assertNotFound();
    }

    public function test_member_management(): void
    {
        $owner = User::factory()->create();
        $new = User::factory()->create(['email' => 'new@example.com']);
        $dev = User::factory()->create();
        $team = $this->team($owner, [[$dev, 'developer']]);

        $this->actingAs($dev)->post("/teams/{$team->id}/members", ['email' => 'new@example.com', 'role' => 'viewer'])->assertForbidden();
        $this->actingAs($owner)->post("/teams/{$team->id}/members", ['email' => 'new@example.com', 'role' => 'viewer'])->assertRedirect();
        $this->assertSame('viewer', $team->fresh()->members->firstWhere('id', $new->id)->pivot->role);

        // Users cannot assign resources to teams they only view.
        $this->actingAs($new)->post('/monitors', ['name' => 'X', 'type' => 'http', 'target' => 'https://x.com', 'interval' => 300, 'timeout' => 10, 'retries' => 1, 'team_id' => $team->id])
            ->assertSessionHasErrors('team_id');
    }
}
