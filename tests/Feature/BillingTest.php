<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\Order;
use App\Models\User;
use App\Services\Billing\Billing;
use App\Services\Billing\Plans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private int $verifyCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'watchrex.billing.enabled' => true,
            'watchrex.billing.vat_percent' => 10,
            'watchrex.billing.zarinpal' => ['merchant_id' => 'test-merchant', 'sandbox' => true],
            'watchrex.plans.pro.price' => 290000,
        ]);
        Http::fake([
            'sandbox.zarinpal.com/pg/v4/payment/request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'A0000000000000000000000000000123456'], 'errors' => []]),
            'sandbox.zarinpal.com/pg/v4/payment/verify.json' => function () {
                $this->verifyCalls++;

                return Http::response(['data' => ['code' => $this->verifyCalls === 1 ? 100 : 101, 'ref_id' => 987654, 'card_pan' => '6037**********1234'], 'errors' => []]);
            },
        ]);
    }

    public function test_quote_includes_vat_and_yearly_discount(): void
    {
        $this->assertSame(['subtotal' => 2900000, 'tax' => 290000, 'amount' => 3190000, 'months' => 1], Plans::quote('pro', 'monthly'));
        $this->assertSame(29000000, Plans::quote('pro', 'yearly')['subtotal']); // 10 months billed for 12
    }

    public function test_full_checkout_and_callback_flow_is_idempotent(): void
    {
        $user = User::factory()->create(['plan' => 'free']);

        $this->actingAs($user)->post('/billing/checkout', ['plan' => 'pro', 'period' => 'monthly'])
            ->assertRedirect('https://sandbox.zarinpal.com/pg/StartPay/A0000000000000000000000000000123456');

        $order = Order::first();
        $this->assertSame(3190000, $order->amount);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'request.json') && $r['amount'] === 3190000 && $r['merchant_id'] === 'test-merchant');

        // Tampered authority is rejected.
        $this->get("/billing/callback/{$order->number}?Authority=WRONG&Status=OK")->assertNotFound();

        $this->get("/billing/callback/{$order->number}?Authority={$order->authority}&Status=OK")->assertRedirect('/billing');
        $user->refresh();
        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertSame('987654', $order->ref_id);
        $this->assertSame('pro', $user->plan);
        $expires = $user->plan_expires_at;
        $this->assertTrue($expires->between(now()->addMonth()->subMinute(), now()->addMonth()->addMinute()));

        // A replayed callback does not extend the plan twice.
        $this->get("/billing/callback/{$order->number}?Authority={$order->authority}&Status=OK")->assertRedirect('/billing');
        $this->assertEquals($expires, $user->fresh()->plan_expires_at);
        $this->assertSame(1, $this->verifyCalls);

        $this->actingAs($user)->get("/billing/invoices/{$order->id}")->assertOk()->assertSee($order->number)->assertSee('319,000');
        $this->actingAs(User::factory()->create())->get("/billing/invoices/{$order->id}")->assertNotFound();
    }

    public function test_cancelled_payment_is_not_activated(): void
    {
        $user = User::factory()->create(['plan' => 'free']);
        $this->actingAs($user)->post('/billing/checkout', ['plan' => 'pro', 'period' => 'monthly']);
        $order = Order::first();

        $this->get("/billing/callback/{$order->number}?Authority={$order->authority}&Status=NOK")->assertRedirect('/billing');
        $this->assertSame('canceled', $order->fresh()->status);
        $this->assertSame('free', $user->fresh()->plan);
        $this->assertSame(0, $this->verifyCalls);
    }

    public function test_renewal_extends_from_current_expiry(): void
    {
        $user = User::factory()->create(['plan' => 'pro', 'plan_expires_at' => now()->addDays(10)]);
        $order = app(Billing::class)->createOrder($user, 'pro', 'yearly');
        app(Billing::class)->activate($order->load('user'), 'x');

        $this->assertTrue($user->fresh()->plan_expires_at->between(now()->addDays(10)->addYear()->subMinute(), now()->addDays(10)->addYear()->addMinute()));
    }

    public function test_expiry_downgrades_and_pauses_excess_monitors(): void
    {
        Queue::fake();
        $user = User::factory()->create(['plan' => 'pro', 'plan_expires_at' => now()->subDays(5)]);
        for ($i = 1; $i <= 7; $i++) {
            $m = new Monitor(['name' => "M{$i}", 'type' => 'http', 'target' => 'https://a.com', 'interval' => 300, 'timeout' => 5, 'retries' => 0]);
            $m->user_id = $user->id;
            $m->save();
        }

        $stats = app(Billing::class)->enforceExpiry();

        $this->assertSame(1, $stats['downgraded']);
        $this->assertSame('free', $user->fresh()->plan);
        $this->assertSame(5, $user->monitors()->where('is_active', true)->count());
        $this->assertSame(['M6', 'M7'], $user->monitors()->where('is_active', false)->orderBy('id')->pluck('name')->all());
    }

    public function test_checkout_requires_billing_and_valid_plan(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/billing/checkout', ['plan' => 'enterprise', 'period' => 'monthly'])->assertSessionHasErrors('plan');

        config(['watchrex.billing.enabled' => false]);
        $this->actingAs($user)->post('/billing/checkout', ['plan' => 'pro', 'period' => 'monthly'])->assertNotFound();
        $this->actingAs($user)->get('/billing')->assertOk();
    }

    public function test_admin_can_mark_offline_payment_as_paid(): void
    {
        $user = User::factory()->create(['plan' => 'free']);
        $order = app(Billing::class)->createOrder($user, 'pro', 'monthly');

        $this->actingAs(User::factory()->admin()->create())->post("/admin/billing/orders/{$order->id}/paid", ['reference' => 'BANK-42'])->assertRedirect();
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('pro', $user->fresh()->plan);
        $this->actingAs($user)->post("/admin/billing/orders/{$order->id}/paid", ['reference' => 'x'])->assertForbidden();
    }
}
