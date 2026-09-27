<?php

namespace App\Http\Requests;

use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\Server;
use App\Models\Team;
use App\Services\Domain\DnsLookup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MonitorRequest extends FormRequest
{
    /** Whitelisted type-specific settings stored in the JSON column. */
    public const SETTINGS = [
        'expected_status', 'keyword', 'keyword_invert', 'keyword_case', 'json_path', 'json_expected',
        'headers', 'body', 'verify_ssl', 'follow_redirects', 'response_warn_ms', 'ssl_warn_days',
        'record_type', 'expected', 'alert_on_change', 'security', 'require_tls', 'open_relay_test',
        'rbl_check', 'quota_warn', 'database', 'payload', 'expect_response', 'count', 'grace',
        'steps', 'anomaly', 'notify_warning', 'remind_minutes',
        'check_local', 'quorum', 'detect_changes', 'change_threshold', 'ignore_pattern', 'visual', 'visual_hours',
    ];

    public const BOOLEAN_SETTINGS = [
        'keyword_invert', 'keyword_case', 'verify_ssl', 'follow_redirects', 'alert_on_change',
        'require_tls', 'open_relay_test', 'rbl_check', 'expect_response', 'anomaly', 'notify_warning',
        'check_local', 'detect_changes', 'visual',
    ];

    public function authorize(): bool
    {
        return (bool) $this->user()?->canWrite();
    }

    protected function prepareForValidation(): void
    {
        $settings = (array) $this->input('settings', []);
        foreach (self::BOOLEAN_SETTINGS as $key) {
            $settings[$key] = filter_var($settings[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
        }
        $this->merge([
            'settings' => $settings,
            'is_active' => $this->boolean('is_active', true),
            'target' => is_string($this->target) ? trim($this->target) : $this->target,
        ]);
    }

    public function rules(): array
    {
        $type = MonitorType::tryFrom((string) $this->input('type'));
        $minInterval = $this->user()->limit('min_interval') ?? 20;

        $target = match (true) {
            $type === null, $type->isPassive() => ['nullable', 'string', 'max:2048'],
            $type === MonitorType::WebSocket => ['required', 'string', 'max:2048', 'regex:#^wss?://#i'],
            $type->usesUrl() => ['required', 'url:http,https', 'max:2048'],
            default => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:\[\]_]+$/'],
        };

        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(MonitorType::class)],
            'target' => $target,
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'method' => ['nullable', Rule::in(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])],
            'interval' => ['required', 'integer', "min:{$minInterval}", 'max:86400'],
            'timeout' => ['required', 'integer', 'between:1,60'],
            'retries' => ['required', 'integer', 'between:0,10'],
            'group' => ['nullable', 'string', 'max:60'],
            'tags' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', Rule::in(Monitor::visibleTo($this->user())->pluck('id')->all())],
            'server_id' => [$type === MonitorType::Server ? 'required' : 'nullable', 'integer', Rule::in(Server::visibleTo($this->user())->pluck('id')->all())],
            'team_id' => ['nullable', 'integer', Rule::in($this->user()->isAdmin() ? Team::pluck('id')->all() : $this->user()->manageableTeamIds())],
            'probes' => ['nullable', 'array'],
            'probes.*' => ['integer', Rule::exists('probes', 'id')->where('is_active', true)],
            'settings.check_local' => ['nullable', 'boolean'],
            'settings.quorum' => ['nullable', Rule::in(['any', 'majority', 'all'])],
            'settings.change_threshold' => ['nullable', 'numeric', 'between:0.1,100'],
            'settings.ignore_pattern' => ['nullable', 'string', 'max:300', function ($attr, $value, $fail) {
                if ($value !== null && $value !== '' && @preg_match('~'.str_replace('~', '\\~', $value).'~u', '') === false) {
                    $fail(__('Invalid regular expression.'));
                }
            }],
            'settings.visual_hours' => ['nullable', 'integer', 'between:1,168'],
            'is_active' => ['boolean'],
            'settings' => ['array'],
            'settings.expected_status' => ['nullable', 'string', 'max:60', 'regex:/^[0-9,\- ]*$/'],
            'settings.keyword' => ['nullable', 'string', 'max:500', Rule::requiredIf($type === MonitorType::Keyword)],
            'settings.json_path' => ['nullable', 'string', 'max:200'],
            'settings.json_expected' => ['nullable', 'string', 'max:500'],
            'settings.headers' => ['nullable', 'string', 'max:4000'],
            'settings.body' => ['nullable', 'string', 'max:20000'],
            'settings.response_warn_ms' => ['nullable', 'integer', 'min:0', 'max:120000'],
            'settings.ssl_warn_days' => ['nullable', 'integer', 'between:1,120'],
            'settings.record_type' => ['nullable', Rule::in(DnsLookup::TYPES)],
            'settings.expected' => ['nullable', 'string', 'max:500'],
            'settings.security' => ['nullable', Rule::in(['auto', 'ssl', 'starttls', 'none'])],
            'settings.quota_warn' => ['nullable', 'numeric', 'between:1,100'],
            'settings.database' => ['nullable', 'string', 'max:64'],
            'settings.payload' => ['nullable', 'string', 'max:1000'],
            'settings.count' => ['nullable', 'integer', 'between:1,5'],
            'settings.grace' => ['nullable', 'integer', 'between:0,86400'],
            'settings.steps' => ['nullable', 'json', 'max:20000'],
            'settings.remind_minutes' => ['nullable', 'integer', 'between:0,1440'],
            'credentials.username' => ['nullable', 'string', 'max:255'],
            'credentials.password' => ['nullable', 'string', 'max:255'],
            'credentials.bearer_token' => ['nullable', 'string', 'max:2000'],
            'clear_credentials' => ['nullable', 'boolean'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['integer'],
        ];
    }

    /** @return array<string, mixed> monitor attributes ready to fill */
    public function monitorData(?Monitor $existing = null): array
    {
        $data = $this->safe()->only(['name', 'type', 'target', 'port', 'method', 'interval', 'timeout', 'retries', 'group', 'parent_id', 'server_id', 'is_active', 'team_id']);
        $data['method'] = $data['method'] ?? 'GET';
        $data['settings'] = array_filter(
            array_intersect_key((array) $this->validated('settings', []), array_flip(self::SETTINGS)),
            fn ($v) => $v !== null && $v !== '',
        );
        if (! empty($data['settings']['visual']) && ! ($this->user()->isAdmin() || config('watchrex.screenshots.tenants'))) {
            $data['settings']['visual'] = false;
        }
        $data['tags'] = array_values(array_unique(array_filter(array_map(fn ($t) => mb_substr(trim($t), 0, 30), explode(',', (string) $this->input('tags', ''))))));

        if ($existing && (int) $data['parent_id'] === $existing->id) {
            $data['parent_id'] = null;
        }

        $credentials = $this->boolean('clear_credentials') ? [] : ($existing?->credentials ?? []);
        foreach (['username', 'password', 'bearer_token'] as $key) {
            $value = $this->input("credentials.{$key}");
            if ($value !== null && $value !== '') {
                $credentials[$key] = $value;
            }
        }
        $data['credentials'] = $credentials ?: null;

        return $data;
    }
}
