<?php

namespace Tests\Feature;

use App\Jobs\ImportServerSites;
use App\Jobs\RefreshDomain;
use App\Models\Monitor;
use App\Models\MonitorGroup;
use App\Models\Server;
use App\Models\ServerSite;
use App\Models\User;
use App\Services\SiteImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SiteImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Server $server;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['plan' => 'business']);
        $this->server = new Server(['name' => 'cpanel-01', 'report_interval' => 60]);
        $this->server->user_id = $this->user->id;
        $this->token = $this->server->rotateToken();
        $this->server->save();
        SiteImporter::$mxResolver = fn (string $d) => $d === 'nomail.com' ? null : 'mail.hosting.net';
    }

    protected function tearDown(): void
    {
        SiteImporter::$mxResolver = null;
        parent::tearDown();
    }

    private function report(array $sites): void
    {
        $this->withToken($this->token)->postJson('/api/agent/report', ['cpu' => 1, 'sites' => $sites])->assertOk();
    }

    public function test_agent_sites_are_stored_validated_and_updated(): void
    {
        $this->report([
            ['domain' => 'Shop.com', 'account' => 'shop', 'kind' => 'main'],
            ['domain' => 'blog.shop.com', 'account' => 'shop', 'kind' => 'sub'],
            ['domain' => 'old.org', 'account' => 'x', 'kind' => 'parked'],
            ['domain' => '*.wild.com', 'account' => 'x'],
            ['domain' => '1.2.3.4', 'account' => 'x'],
        ]);

        $sites = ServerSite::orderBy('domain')->get();
        $this->assertSame(['blog.shop.com', 'old.org', 'shop.com'], $sites->pluck('domain')->all());
        $this->assertSame('alias', $sites->firstWhere('domain', 'old.org')->kind);

        $first = $sites->firstWhere('domain', 'shop.com')->first_seen_at;
        $this->travel(5)->minutes();
        $this->report([['domain' => 'shop.com', 'account' => 'shop2', 'kind' => 'main']]);
        $site = ServerSite::where('domain', 'shop.com')->first();
        $this->assertSame('shop2', $site->account);
        $this->assertEquals($first, $site->first_seen_at);
        $this->assertTrue($site->last_seen_at->gt($first));
    }

    public function test_import_builds_groups_and_deduplicates_shared_mail(): void
    {
        Queue::fake([RefreshDomain::class]);
        foreach (['shop.com' => 'main', 'blog.shop.com' => 'sub', 'client.com' => 'main', 'nomail.com' => 'main'] as $d => $k) {
            $this->server->sites()->create(['domain' => $d, 'kind' => $k, 'account' => 'u']);
        }

        $stats = app(SiteImporter::class)->import($this->server->fresh('user'), $this->server->sites()->get());

        // 4 HTTP monitors + one shared SMTP/IMAP/POP3 trio for mail.hosting.net.
        $this->assertSame(4, Monitor::where('type', 'http')->count());
        $this->assertSame(3, Monitor::whereIn('type', ['smtp', 'imap', 'pop3'])->count());
        $this->assertSame(7, $stats['monitors_created']);
        $this->assertSame(1, $stats['no_mx']);
        $this->assertSame(3, $stats['domains']); // roots only, not the subdomain
        Queue::assertPushed(RefreshDomain::class, 3);

        $serverGroup = MonitorGroup::where('kind', 'server')->first();
        $shop = MonitorGroup::where('kind', 'website')->where('domain', 'shop.com')->first();
        $this->assertSame($serverGroup->id, $shop->parent_id);
        $mailShop = MonitorGroup::where('kind', 'mail')->where('domain', 'shop.com')->first();
        $mailClient = MonitorGroup::where('kind', 'mail')->where('domain', 'client.com')->first();
        $this->assertSame($shop->id, $mailShop->parent_id);
        $this->assertEqualsCanonicalizing($mailShop->monitors->pluck('id')->all(), $mailClient->monitors->pluck('id')->all());
        $this->assertNull(MonitorGroup::where('kind', 'mail')->where('domain', 'blog.shop.com')->first());

        $this->assertSame(4, $this->server->sites()->whereNotNull('monitor_group_id')->count());

        // Re-import is idempotent.
        $again = app(SiteImporter::class)->import($this->server->fresh('user'), $this->server->sites()->get());
        $this->assertSame(0, $again['monitors_created']);
        $this->assertSame(7, Monitor::count());
    }

    public function test_plan_limit_is_respected(): void
    {
        Queue::fake([RefreshDomain::class]);
        $this->user->update(['plan' => 'free']); // 5 monitors
        foreach (range(1, 4) as $i) {
            $this->server->sites()->create(['domain' => "site{$i}.com", 'kind' => 'main']);
        }

        $stats = app(SiteImporter::class)->import($this->server->fresh('user'), $this->server->sites()->get(), ['domain' => false]);

        $this->assertSame(5, Monitor::count());
        $this->assertGreaterThan(0, $stats['skipped_limit']);
    }

    public function test_ui_actions_and_auto_import(): void
    {
        Queue::fake();
        $a = $this->server->sites()->create(['domain' => 'a.com', 'kind' => 'main']);
        $b = $this->server->sites()->create(['domain' => 'b.com', 'kind' => 'main']);

        $this->actingAs($this->user)->get("/servers/{$this->server->id}")->assertOk()->assertSee('a.com')->assertSee('Not monitored');

        $this->actingAs($this->user)->post("/servers/{$this->server->id}/sites", ['action' => 'ignore', 'sites' => [$b->id]])->assertRedirect();
        $this->assertTrue($b->fresh()->ignored);

        $this->actingAs($this->user)->post("/servers/{$this->server->id}/sites", ['action' => 'import', 'sites' => [$a->id], 'website' => 1])->assertRedirect();
        Queue::assertPushed(ImportServerSites::class, fn ($j) => $j->siteIds === [$a->id] && $j->options['website'] === true && $j->options['mail'] === false);

        $this->actingAs($this->user)->put("/servers/{$this->server->id}/sites/settings", ['auto_import' => 1, 'website' => 1, 'interval' => 120])->assertRedirect();
        $this->report([['domain' => 'new-site.com', 'kind' => 'main']]);
        Queue::assertPushed(ImportServerSites::class, fn ($j) => $j->siteIds === null && $j->options['interval'] === 120);

        $this->actingAs(User::factory()->create())->post("/servers/{$this->server->id}/sites", ['action' => 'import_all'])->assertNotFound();
    }
}
