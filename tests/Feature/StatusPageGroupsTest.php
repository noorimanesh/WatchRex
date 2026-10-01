<?php

namespace Tests\Feature;

use App\Enums\MonitorStatus;
use App\Models\Domain;
use App\Models\Monitor;
use App\Models\MonitorGroup;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusPageGroupsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function monitor(string $name, string $type = 'http', string $status = 'up'): Monitor
    {
        $m = new Monitor(['name' => $name, 'type' => $type, 'target' => $type === 'http' ? 'https://'.$name : $name, 'port' => $type === 'http' ? null : 587, 'interval' => 60, 'timeout' => 5, 'retries' => 0]);
        $m->user_id = $this->user->id;
        $m->status = MonitorStatus::from($status);
        $m->last_message = 'secret internal error 10.0.0.5';
        $m->save();

        return $m;
    }

    private function group(string $name, string $kind, ?MonitorGroup $parent = null, ?string $domain = null): MonitorGroup
    {
        $g = new MonitorGroup(['name' => $name, 'kind' => $kind, 'parent_id' => $parent?->id, 'domain' => $domain]);
        $g->user_id = $this->user->id;
        $g->save();

        return $g;
    }

    private function page(array $settings = []): StatusPage
    {
        return $this->user->statusPages()->create(['slug' => 'shop', 'title' => 'Shop Status', 'is_public' => true, 'show_uptime' => true, 'show_response' => true, 'accent' => '#10b981', 'settings' => $settings]);
    }

    public function test_group_section_shows_nested_monitors_and_mail_components(): void
    {
        $site = $this->group('shop.com', 'website', null, 'shop.com');
        $mail = $this->group('Mail · shop.com', 'mail', $site, 'shop.com');
        $site->monitors()->attach($this->monitor('Website')->id);
        $mail->monitors()->attach($this->monitor('Outgoing mail', 'smtp', 'down')->id);

        $d = new Domain(['name' => 'shop.com']);
        $d->user_id = $this->user->id;
        $d->forceFill(['expires_at' => now()->addDays(200), 'email_security' => ['mx' => [['host' => 'mail.shop.com', 'ptr_ok' => true]], 'spf' => 'v=spf1 mx -all', 'dmarc' => 'v=DMARC1; p=reject', 'dmarc_policy' => 'reject', 'dkim_selectors' => ['default']]])->save();

        $page = $this->page();
        $page->groups()->attach($site->id, ['sort' => 0, 'display_name' => 'Online shop', 'expanded' => true]);

        $this->get('/status/shop')->assertOk()
            ->assertSee('Online shop')->assertSee('Website')->assertSee('Outgoing mail')
            ->assertSee('DMARC')->assertSee('SPF')
            ->assertDontSee('secret internal error');

        $json = $this->getJson('/status/shop/json')->assertOk()->json();
        $this->assertSame('major_outage', $json['status']);
        $this->assertSame('Online shop', $json['groups'][0]['name']);
        $this->assertSame('down', $json['groups'][0]['status']);
        $this->assertCount(2, $json['groups'][0]['monitors']);
        $this->assertContains('dmarc', array_column($json['groups'][0]['mail']['components'], 'key'));
        $this->assertSame('domain', $json['groups'][0]['details'][0]['key']);
    }

    public function test_loose_monitors_and_options(): void
    {
        $page = $this->page(['layout' => 'cards', 'theme' => 'dark', 'announcement' => 'Planned upgrade tonight', 'show_filters' => false]);
        $page->monitors()->attach($this->monitor('API')->id, ['sort' => 0, 'display_name' => 'Public API']);

        $this->get('/status/shop')->assertOk()
            ->assertSee('Public API')->assertSee('Planned upgrade tonight')
            ->assertSee('data-theme="dark"', false)->assertSee('sp-sections cards', false)
            ->assertDontSee('data-sp-filters', false);
    }

    public function test_owner_saves_groups_and_settings_and_cannot_attach_foreign_groups(): void
    {
        $mine = $this->group('mine.com', 'website');
        $other = User::factory()->create();
        $foreign = new MonitorGroup(['name' => 'foreign', 'kind' => 'general']);
        $foreign->user_id = $other->id;
        $foreign->save();

        $this->actingAs($this->user)->post('/status-pages', [
            'title' => 'Mine', 'slug' => 'mine', 'accent' => '#112233', 'is_public' => 1,
            'groups' => [$mine->id => ['enabled' => 1, 'sort' => 2, 'expanded' => 1], $foreign->id => ['enabled' => 1]],
            'settings' => ['layout' => 'cards', 'history_days' => '30', 'incident_days' => 7, 'show_chart' => 0, 'show_filters' => 1, 'announcement_level' => 'warn'],
        ])->assertRedirect();

        $page = StatusPage::where('slug', 'mine')->firstOrFail();
        $this->assertSame([$mine->id], $page->groups()->pluck('monitor_groups.id')->all());
        $this->assertSame('cards', $page->option('layout'));
        $this->assertSame(30, $page->option('history_days'));
        $this->assertFalse($page->option('show_chart'));
        $this->assertTrue($page->option('show_filters'));
    }

    public function test_create_prefills_from_group(): void
    {
        $g = $this->group('fabapars.com', 'website', null, 'fabapars.com');

        $this->actingAs($this->user)->get('/status-pages/create?group='.$g->id)->assertOk()
            ->assertSee('status.fabapars.com')->assertSee('name="groups['.$g->id.'][enabled]" value="1" checked', false);
    }
}
