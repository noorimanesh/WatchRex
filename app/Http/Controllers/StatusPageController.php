<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Monitor;
use App\Models\StatusPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class StatusPageController extends Controller
{
    public function index(Request $request)
    {
        return view('status-pages.index', ['pages' => StatusPage::visibleTo($request->user())->withCount('monitors')->orderBy('title')->get()]);
    }

    public function create(Request $request)
    {
        return view('status-pages.form', [
            'page' => new StatusPage(['is_public' => true, 'show_uptime' => true, 'show_response' => true, 'accent' => '#10b981']),
            'monitors' => Monitor::where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name', 'type']),
            'selected' => collect(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user->withinLimit('max_status_pages', $user->statusPages()->count())) {
            return back()->withInput()->withErrors(['title' => __('Your plan status page limit has been reached.')]);
        }

        $page = $user->statusPages()->create($this->validated($request));
        $this->syncMonitors($request, $page);
        AuditLog::record('status_page.created', $page);

        return redirect()->route('status-pages.index')->with('success', __('Status page created.'));
    }

    public function edit(StatusPage $statusPage)
    {
        $this->authorizeOwner($statusPage);

        return view('status-pages.form', [
            'page' => $statusPage,
            'monitors' => Monitor::where('user_id', $statusPage->user_id)->orderBy('name')->get(['id', 'name', 'type']),
            'selected' => $statusPage->monitors->keyBy('id'),
        ]);
    }

    public function update(Request $request, StatusPage $statusPage)
    {
        $this->authorizeOwner($statusPage);
        $old = $statusPage->custom_domain;
        $statusPage->update($this->validated($request, $statusPage));
        $this->syncMonitors($request, $statusPage);
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
        foreach (['is_public', 'show_uptime', 'show_response', 'hide_branding'] as $flag) {
            $request->merge([$flag => $request->boolean($flag)]);
        }

        return $request->validate([
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
        ]);
    }

    private function syncMonitors(Request $request, StatusPage $page): void
    {
        $allowed = Monitor::where('user_id', $page->user_id)->pluck('id')->flip();
        $sync = [];
        foreach ((array) $request->input('monitors', []) as $id => $row) {
            if (isset($allowed[(int) $id]) && ! empty($row['enabled'])) {
                $sync[(int) $id] = ['sort' => (int) ($row['sort'] ?? 0), 'display_name' => isset($row['display_name']) ? mb_substr(trim($row['display_name']), 0, 120) ?: null : null];
            }
        }
        $page->monitors()->sync($sync);
    }
}
