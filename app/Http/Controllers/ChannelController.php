<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\NotificationChannel;
use App\Services\Alerting\Notifier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class ChannelController extends Controller
{
    public function index(Request $request)
    {
        return view('channels.index', ['channels' => NotificationChannel::visibleTo($request->user())->withCount('monitors')->orderBy('name')->get()]);
    }

    public function create(Request $request)
    {
        $type = array_key_exists($request->query('type'), NotificationChannel::TYPES) ? $request->query('type') : 'telegram';

        return view('channels.form', ['channel' => new NotificationChannel(['type' => $type, 'is_active' => true, 'is_default' => true, 'config' => []]), 'teams' => $this->assignableTeams()]);
    }

    public function store(Request $request)
    {
        $channel = new NotificationChannel($this->validated($request));
        $channel->user_id = $request->user()->id;
        $channel->save();
        AuditLog::record('channel.created', $channel, ['type' => $channel->type]);

        return redirect()->route('channels.index')->with('success', __('Channel saved. Use "Test" to verify it.'));
    }

    public function edit(NotificationChannel $channel)
    {
        $this->authorizeOwner($channel);

        return view('channels.form', ['channel' => $channel, 'teams' => $this->assignableTeams()]);
    }

    public function update(Request $request, NotificationChannel $channel)
    {
        $this->authorizeOwner($channel);
        $channel->update($this->validated($request, $channel));
        AuditLog::record('channel.updated', $channel);

        return redirect()->route('channels.index')->with('success', __('Channel updated.'));
    }

    public function destroy(NotificationChannel $channel)
    {
        $this->authorizeOwner($channel);
        AuditLog::record('channel.deleted', $channel, ['name' => $channel->name]);
        $channel->delete();

        return redirect()->route('channels.index')->with('success', __('Channel deleted.'));
    }

    public function test(NotificationChannel $channel, Notifier $notifier)
    {
        $this->authorizeOwner($channel);

        try {
            $notifier->test($channel);
        } catch (Throwable $e) {
            return back()->with('error', __('Test failed: :e', ['e' => mb_substr($e->getMessage(), 0, 250)]));
        }

        return back()->with('success', __('Test notification sent.'));
    }

    private function validated(Request $request, ?NotificationChannel $existing = null): array
    {
        $type = (string) $request->input('type', $existing?->type);
        $fields = NotificationChannel::FIELDS[$type] ?? [];

        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(array_keys(NotificationChannel::TYPES))],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'team_id' => $this->teamRule(),
        ];
        foreach ($fields as $key => [$label, $secret]) {
            $optional = str_contains($label, 'optional') || ($secret && $existing);
            $rule = [$optional ? 'nullable' : 'required', 'string', 'max:1000'];
            if (in_array($key, ['webhook_url', 'url', 'api_base'], true)) {
                $rule[] = 'url:https,http';
            }
            if ($key === 'to' && $type === 'email') {
                $rule[] = function ($attr, $value, $fail) {
                    foreach (explode(',', (string) $value) as $mail) {
                        if (! filter_var(trim($mail), FILTER_VALIDATE_EMAIL)) {
                            $fail(__('Invalid e-mail: :m', ['m' => trim($mail)]));
                        }
                    }
                };
            }
            $rules["config.{$key}"] = $rule;
        }

        $request->merge(['is_default' => $request->boolean('is_default'), 'is_active' => $request->boolean('is_active')]);
        $data = $request->validate($rules);

        // Secrets left blank on edit keep their stored value.
        $config = [];
        foreach ($fields as $key => [$label, $secret]) {
            $value = $data['config'][$key] ?? null;
            $config[$key] = ($value === null || $value === '') && $secret && $existing ? ($existing->config[$key] ?? null) : $value;
        }
        $data['config'] = array_filter($config, fn ($v) => $v !== null && $v !== '');

        return $data;
    }
}
