<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Monitor;
use App\Models\MonitorGroup;
use App\Models\StatusPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StatusPageController extends Controller
{
    public function index(Request $request)
    {
        return view('status-pages.index', ['pages' => StatusPage::visibleTo($request->user())->withCount(['monitors', 'groups'])->orderBy('title')->get()]);
    }

    public function create(Request $request)
    {
        $page = new StatusPage(['is_public' => true, 'show_uptime' => true, 'show_response' => true, 'allow_subscribers' => true, 'accent' => '#10b981']);
        $selectedGroups = collect();

        // Prefill from a group: "status page for this website / mail service".
        if ($group = MonitorGroup::visibleTo($request->user())->find((int) $request->query('group'))) {
            $page->title = $group->name;
            $page->slug = Str::slug($group->domain ?: $group->name) ?: null;
            $page->custom_domain = $group->domain && ! StatusPage::where('custom_domain', 'status.'.$group->domain)->exists() ? 'status.'.$group->domain : null;
            $selectedGroups = collect([$group->id => (object) ['pivot' => (object) ['sort' => 0, 'display_name' => null, 'expanded' => true]]]);
        }

        return view('status-pages.form', $this->formData($request, $page) + ['selected' => collect(), 'selectedGroups' => $selectedGroups]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user->withinLimit('max_status_pages', $user->statusPages()->count())) {
            return back()->withInput()->withErrors(['title' => __('Your plan status page limit has been reached.')]);
        }

        $page = $user->statusPages()->create($this->validated($request));
        $this->syncMonitors($request, $page);
        $this->syncGroups($request, $page);
        AuditLog::record('status_page.created', $page);

        return redirect()->route('status-pages.index')->with('success', __('Status page created.'));
    }

    public function edit(Request $request, StatusPage $statusPage)
    {
        $this->authorizeOwner($statusPage);

        return view('status-pages.form', $this->formData($request, $statusPage) + [
            'subscriberCount' => $statusPage->subscribers()->whereNotNull('verified_at')->count(),
            'selected' => $statusPage->monitors->keyBy('id'),
            'selectedGroups' => $statusPage->groups->keyBy('id'),
        ]);
    }

    public function update(Request $request, StatusPage $statusPage)
    {
        $this->authorizeOwner($statusPage);
        $old = $statusPage->custom_domain;
        $statusPage->update($this->validated($request, $statusPage));
        $statusPage->touch(); // new cache key even when only groups/monitors changed
        $this->syncMonitors($request, $statusPage);
        $this->syncGroups($request, $statusPage);
        Cache::forget("status-domain:{$old}");
        Cache::forget("status-domain:{$statusPage->custom_domain}");

        return redirect()->route('status-pages.index')->with('success', __('Status page updated.'));
    }

    public function destroy(StatusPage $statusPage)
    {
        $this->authorizeOwner($statusPage);
        Cache::forget("status-domain:{$statusPage->custom_domain}");
        $statusPage->delete();

        return redirect()->route('status-pages.index')->with('success', __('Status page deleted.'));
    }

    private function validated(Request $request, ?StatusPage $page = null): array
    {
        $request->merge([
            'slug' => strtolower((string) $request->input('slug')),
            'custom_domain' => $request->filled('custom_domain') ? strtolower(trim((string) $request->input('custom_domain'))) : null,
        ]);
        foreach (['is_public', 'show_uptime', 'show_response', 'hide_branding', 'allow_subscribers'] as $flag) {
            $request->merge([$flag => $request->boolean($flag)]);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('status_pages')->ignore($page?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'custom_domain' => ['nullable', 'string', 'max:253', 'regex:/^([a-z0-9-]+\.)+[a-z]{2,}$/', Rule::unique('status_pages')->ignore($page?->id)],
            'logo_url' => ['nullable', 'url:https', 'max:500'],
            'accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'footer_text' => ['nullable', 'string', 'max:500'],
            'is_public' => ['boolean'],
            'show_uptime' => ['boolean'],
            'show_response' => ['boolean'],
            'hide_branding' => ['boolean'],
            'allow_subscribers' => ['boolean'],
            'team_id' => $this->teamRule(),
            'settings.layout' => ['nullable', Rule::in(['list', 'cards'])],
            'settings.theme' => ['nullable', Rule::in(['auto', 'light', 'dark'])],
            'settings.history_days' => ['nullable', Rule::in(['30', '60', '90'])],
            'settings.incident_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'settings.announcement' => ['nullable', 'string', 'max:500'],
            'settings.announcement_level' => ['nullable', Rule::in(['info', 'warn', 'success', 'error'])],
            'settings.support_url' => ['nullable', 'url:https,http', 'max:300'],
        ]);

        $settings = [];
        foreach (StatusPage::DEFAULTS as $key => $default) {
            $settings[$key] = is_bool($default)
                ? $request->boolean("settings.$key")
                : ($data['settings'][$key] ?? $default);
        }
        $settings['history_days'] = (int) $settings['history_days'];
        $settings['incident_days'] = (int) $settings['incident_days'];
        unset($data['settings']);

        return $data + ['settings' => $settings];
    }

    private function formData(Request $request, StatusPage $page): array
    {
        $user = $request->user();

        return [
            'page' => $page,
            'monitors' => Monitor::visibleTo($user)->orderBy('name')->get(['id', 'name', 'type']),
            'groups' => MonitorGroup::visibleTo($user)->withCount('monitors')->orderBy('name')->get(['id', 'name', 'kind', 'domain', 'parent_id']),
            'teams' => $this->assignableTeams(),
        ];
    }

    private function syncGroups(Request $request, StatusPage $page): void
    {
        $allowed = MonitorGroup::visibleTo($request->user())->pluck('id')->flip();
        $sync = [];
        foreach ((array) $request->input('groups', []) as $id => $row) {
            if (isset($allowed[(int) $id]) && is_array($row) && ! empty($row['enabled'])) {
                $sync[(int) $id] = [
                    'sort' => (int) ($row['sort'] ?? 0),
                    'display_name' => isset($row['display_name']) ? mb_substr(trim((string) $row['display_name']), 0, 120) ?: null : null,
                    'expanded' => ! empty($row['expanded']),
                ];
            }
        }
        $page->groups()->sync($sync);
    }

    private function syncMonitors(Request $request, StatusPage $page): void
    {
        $allowed = Monitor::visibleTo($request->user())->pluck('id')->flip();
        $sync = [];
        foreach ((array) $request->input('monitors', []) as $id => $row) {
            if (isset($allowed[(int) $id]) && is_array($row) && ! empty($row['enabled'])) {
                $sync[(int) $id] = ['sort' => (int) ($row['sort'] ?? 0), 'display_name' => isset($row['display_name']) ? mb_substr(trim($row['display_name']), 0, 120) ?: null : null];
            }
        }
        $page->monitors()->sync($sync);
    }
}
