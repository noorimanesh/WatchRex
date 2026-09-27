<?php

namespace Tests\Feature;

use App\Enums\MonitorStatus;
use App\Jobs\NotifySubscribers;
use App\Models\Monitor;
use App\Models\User;
use App\Services\Checks\CheckResult;
use App\Services\ContentWatcher;
use App\Services\MonitorRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ChangeDetectionAndSubscribersTest extends TestCase
{
    use RefreshDatabase;

    private function monitor(array $settings = []): Monitor
    {
        $m = new Monitor(['name' => 'Home', 'type' => 'http', 'target' => 'https://a.com', 'interval' => 60, 'timeout' => 5, 'retries' => 0, 'settings' => $settings]);
        $m->user_id = User::factory()->create()->id;
        $m->save();

        return $m;
    }

    private function page(string $html): CheckResult
    {
        return CheckResult::up(100, 'HTTP 200', ['_content' => ContentWatcher::normalize($html)]);
    }

    public function test_normalize_keeps_visible_text_only(): void
    {
        $text = ContentWatcher::normalize('<html><head><style>.a{}</style><script>var x=1</script></head><body><h1>Hello</h1><p>World &amp; more</p></body></html>');
        $this->assertSame("Hello\nWorld & more", $text);
    }

    public function test_content_change_raises_warning_with_diff(): void
    {
        Queue::fake();
        $m = $this->monitor(['detect_changes' => true, 'change_threshold' => 10, 'notify_warning' => true]);
        $runner = app(MonitorRunner::class);

        $runner->process($m, $this->page('<p>Welcome</p><p>Products</p><p>Contact</p>'));
        $this->assertSame(MonitorStatus::Up, $m->fresh()->status);

        $runner->process($m, $this->page('<p>Welcome</p><p>Products</p><p>Contact</p>'));
        $this->assertSame(MonitorStatus::Up, $m->fresh()->status);

        $runner->process($m, $this->page('<p>HACKED BY X</p>'));
        $m->refresh();
        $this->assertSame(MonitorStatus::Warning, $m->status);
        $this->assertStringContainsString('content changed', strtolower($m->last_message));
        $this->assertSame(2, $m->snapshots()->count());
        $this->assertContains('HACKED BY X', $m->snapshots()->latest('id')->first()->diff['added']);
    }

    public function test_ignore_pattern_suppresses_noise(): void
    {
        Queue::fake();
        $m = $this->monitor(['detect_changes' => true, 'change_threshold' => 1, 'ignore_pattern' => 'Time: \d\d:\d\d']);
        $runner = app(MonitorRunner::class);

        $runner->process($m, $this->page('<p>Shop</p><p>Time: 10:00</p>'));
        $runner->process($m, $this->page('<p>Shop</p><p>Time: 11:42</p>'));
        $this->assertSame(MonitorStatus::Up, $m->fresh()->status);
    }

    public function test_pixel_diff(): void
    {
        $a = tempnam(sys_get_temp_dir(), 'a').'.png';
        $b = tempnam(sys_get_temp_dir(), 'b').'.png';
        $img = imagecreatetruecolor(200, 100);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagepng($img, $a);
        imagefilledrectangle($img, 0, 0, 99, 99, imagecolorallocate($img, 0, 0, 0));
        imagepng($img, $b);

        $this->assertSame(0.0, ContentWatcher::pixelDiff($a, $a));
        $this->assertEqualsWithDelta(50.0, ContentWatcher::pixelDiff($a, $b), 2.0);
    }

    public function test_status_page_subscription_double_opt_in_and_notices(): void
    {
        Mail::fake();
        $m = $this->monitor();
        $page = $m->user->statusPages()->create(['slug' => 'acme', 'title' => 'Acme', 'is_public' => true, 'allow_subscribers' => true, 'accent' => '#10b981']);
        $page->monitors()->attach($m->id);

        $this->post('/status/acme/subscribe', ['email' => 'Client@Example.com'])->assertRedirect();
        $sub = $page->subscribers()->first();
        $this->assertSame('client@example.com', $sub->email);
        $this->assertNull($sub->verified_at);

        $this->get("/status/acme/confirm/{$sub->token}")->assertOk();
        $this->assertNotNull($sub->fresh()->verified_at);

        Queue::fake();
        $runner = app(MonitorRunner::class);
        $runner->process($m->fresh(), CheckResult::down('boom'));
        Queue::assertPushed(NotifySubscribers::class, fn ($j) => $j->event === 'down');

        (new NotifySubscribers($m->incidents()->first()->id, 'down'))->handle();
        Mail::assertSentCount(2); // confirmation + incident notice

        $this->get("/status/acme/unsubscribe/{$sub->token}")->assertOk();
        $this->assertDatabaseCount('status_page_subscribers', 0);
    }

    public function test_visual_capture_with_sync_queue_does_not_deadlock(): void
    {
        config(['watchrex.screenshots.chrome' => '/bin/false', 'queue.default' => 'sync']);
        $m = $this->monitor(['visual' => true]);
        $m->user->update(['role' => 'admin']);

        app(MonitorRunner::class)->process($m->fresh(), CheckResult::up(50, 'HTTP 200'));

        $m->refresh();
        $this->assertSame(MonitorStatus::Up, $m->status);
        $this->assertNotNull($m->metaValue('visual.at'));
        $this->assertNotNull($m->metaValue('visual.error'));
    }
}
