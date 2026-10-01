<?php

namespace Tests\Feature;

use App\Enums\MonitorStatus;
use App\Models\Domain;
use App\Models\Monitor;
use App\Models\MonitorGroup;
use App\Models\User;
use App\Services\GroupHealth;
use App\Services\MailHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function monitor(string $name, string $type = 'http', string $status = 'up', array $meta = []): Monitor
    {
        $m = new Monitor(['name' => $name, 'type' => $type, 'target' => $type === 'http' ? 'https://'.$name : $name, 'port' => $type === 'http' ? null : 993, 'interval' => 60, 'timeout' => 5, 'retries' => 0]);
        $m->user_id = $this->user->id;
        $m->status = MonitorStatus::from($status);
        $m->meta = $meta;
        $m->save();

        return $m;
    }

    private function group(string $name, string $kind = 'general', ?MonitorGroup $parent = null, array $extra = []): MonitorGroup
    {
        $g = new MonitorGroup(['name' => $name, 'kind' => $kind, 'parent_id' => $parent?->id] + $extra);
        $g->user_id = $this->user->id;
        $g->save();

        return $g;
    }

    public function test_health_aggregates_nested_groups_with_worst_status(): void
    {
        $site = $this->group('shop.com', 'website');
        $mail = $this->group('Mail', 'mail', $site);
        $site->monitors()->attach($this->monitor('shop.com')->id);
        $mail->monitors()->attach($this->monitor('mail.shop.com', 'imap', 'down')->id);

        $h = GroupHealth::for(collect([$site, $mail]));

        $this->assertSame('down', $h[$site->id]['status']);
        $this->assertSame(2, $h[$site->id]['total']);
        $this->assertSame(1, $h[$mail->id]['total']);
        $this->assertEqualsCanonicalizing($site->allMonitorIds(), $h[$site->id]['monitor_ids']);
    }

    public function test_mail_health_checklist(): void
    {
        $smtp = $this->monitor('mail.shop.com', 'smtp', 'up', ['ssl' => ['days_left' => 9], 'rbl' => ['listed' => ['zen.spamhaus.org']], 'last_details' => ['auth' => 'success', 'open_relay' => false]]);
        $domain = new Domain(['name' => 'shop.com']);
        $domain->user_id = $this->user->id;
        $domain->email_security = ['spf' => 'v=spf1 mx +all', 'dmarc' => 'v=DMARC1; p=none', 'dmarc_policy' => 'none', 'dkim_selectors' => ['default'], 'mx' => [['host' => 'mail.shop.com', 'ptr_ok' => true]]];
        $domain->save();

        $c = collect(MailHealth::components(collect([$smtp]), $domain))->keyBy('key');

        $this->assertSame('up', $c['smtp']['status']);
        $this->assertSame('up', $c['smtp_auth']['status']);
        $this->assertSame('warning', $c['tls']['status']);
        $this->assertSame('down', $c['rbl']['status']);
        $this->assertSame('down', $c['spf']['status']);     // +all
        $this->assertSame('warning', $c['dmarc']['status']); // p=none
        $this->assertSame('up', $c['dkim']['status']);
        $this->assertSame('down', MailHealth::overall($c->values()->all()));
    }

    public function test_crud_cycle_protection_and_tenancy(): void
    {
        $this->actingAs($this->user)->post('/groups', ['name' => 'Customer A', 'kind' => 'general'])->assertRedirect();
        $parent = MonitorGroup::first();
        $this->actingAs($this->user)->post('/groups', ['name' => 'site.com', 'kind' => 'website', 'parent_id' => $parent->id, 'domain' => 'SITE.com'])->assertRedirect();
        $child = MonitorGroup::where('name', 'site.com')->first();
        $this->assertSame('site.com', $child->domain);

        $this->actingAs($this->user)->put("/groups/{$parent->id}", ['name' => 'Customer A', 'kind' => 'general', 'parent_id' => $child->id])->assertSessionHasErrors('parent_id');

        $this->actingAs($this->user)->get('/groups')->assertOk()->assertSee('Customer A')->assertSee('site.com');
        $this->actingAs($this->user)->get("/groups/{$child->id}")->assertOk();
        $this->actingAs(User::factory()->create())->get("/groups/{$child->id}")->assertNotFound();
    }

    public function test_monitor_form_syncs_groups_and_creates_new_one(): void
    {
        $existing = $this->group('Existing');
        $this->actingAs($this->user)->post('/monitors', [
            'name' => 'API', 'type' => 'http', 'target' => 'https://api.shop.com', 'interval' => 60, 'timeout' => 10, 'retries' => 1,
            'groups' => [$existing->id], 'new_group' => 'shop.com',
        ])->assertRedirect();

        $m = Monitor::where('name', 'API')->first();
        $this->assertEqualsCanonicalizing(['Existing', 'shop.com'], $m->groups->pluck('name')->all());
        $this->assertSame('website', MonitorGroup::where('name', 'shop.com')->value('kind'));
    }

    public function test_monitor_list_filters_by_group_including_subgroups(): void
    {
        $site = $this->group('shop.com', 'website');
        $mail = $this->group('Mail', 'mail', $site);
        $mail->monitors()->attach($this->monitor('imap-box', 'imap')->id);
        $this->monitor('other-site');

        $this->actingAs($this->user)->get("/monitors?group={$site->id}")->assertOk()->assertSee('imap-box')->assertDontSee('other-site');
    }
}
