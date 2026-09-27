<?php

namespace App\Http\Controllers;

use App\Mail\ViewMail;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** Double opt-in e-mail subscriptions for public status pages. */
class SubscriberController extends Controller
{
    public function subscribe(Request $request, string $slug)
    {
        $page = StatusPage::where('slug', $slug)->where('is_public', true)->where('allow_subscribers', true)->firstOrFail();
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:190']]);
        $email = strtolower($data['email']);

        $subscriber = $page->subscribers()->firstOrCreate(['email' => $email], ['token' => Str::random(48)]);

        if (! $subscriber->verified_at) {
            Mail::to($email)->send(new ViewMail(__('Confirm your subscription to :t', ['t' => $page->title]), 'emails.subscribe-confirm', ['page' => $page, 'subscriber' => $subscriber]));
        }

        // Same answer whether or not the address was already subscribed (no enumeration).
        return back()->with('subscribed', __('Check your inbox to confirm the subscription.'));
    }

    public function confirm(string $slug, string $token)
    {
        $subscriber = $this->find($slug, $token);
        $subscriber->forceFill(['verified_at' => $subscriber->verified_at ?? now()])->save();

        return view('status.subscription', ['page' => $subscriber->statusPage, 'message' => __('Subscription confirmed. You will receive incident notifications by e-mail.')]);
    }

    public function unsubscribe(string $slug, string $token)
    {
        $subscriber = $this->find($slug, $token);
        $page = $subscriber->statusPage;
        $subscriber->delete();

        return view('status.subscription', ['page' => $page, 'message' => __('You have been unsubscribed.')]);
    }

    private function find(string $slug, string $token): StatusPageSubscriber
    {
        return StatusPageSubscriber::with('statusPage')
            ->where('token', $token)
            ->whereHas('statusPage', fn ($q) => $q->where('slug', $slug))
            ->firstOrFail();
    }
}
