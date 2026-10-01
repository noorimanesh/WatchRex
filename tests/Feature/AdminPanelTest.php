<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['role' => UserRole::Admin])->save();

        return $u;
    }

    public function test_overview_and_filters_render_for_admins_only(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['name' => 'Customer One', 'plan' => 'free']);

        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Customer One');
        $this->actingAs($admin)->get('/admin/users?plan=free&sort=newest&state=active')->assertOk()->assertSee('Customer One')->assertDontSee($admin->email);
    }

    public function test_toggle_disables_account_but_not_self(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)->post("/admin/users/{$user->id}/toggle")->assertRedirect();
        $this->assertFalse($user->fresh()->is_active);
        $this->actingAs($admin)->post("/admin/users/{$admin->id}/toggle")->assertStatus(422);
    }

    public function test_impersonation_round_trip_is_audited_and_restricted(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $user = User::factory()->create(['name' => 'Target User']);

        $this->actingAs($admin)->post("/admin/users/{$other->id}/impersonate")->assertForbidden();

        $this->actingAs($admin)->post("/admin/users/{$user->id}/impersonate")->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertOk()->assertSee('impersonation');
        $this->get('/admin')->assertForbidden();
        $this->put('/profile/password', ['current_password' => 'x', 'password' => 'y'])->assertForbidden();

        $this->post('/impersonate/stop')->assertRedirect(route('admin.users.index'));
        $this->assertAuthenticatedAs($admin);
        $this->assertTrue(AuditLog::where('action', 'admin.impersonation_started')->exists());
        $this->assertTrue(AuditLog::where('action', 'admin.impersonation_stopped')->exists());
    }

    public function test_stop_without_impersonation_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create())->post('/impersonate/stop')->assertForbidden();
    }
}
