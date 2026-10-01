<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Monitor;
use App\Models\MonitorGroup;
use App\Models\Server;
use App\Services\GroupHealth;
use App\Services\MailHealth;
use App\Services\MonitorList;
use App\Services\Uptime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GroupController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $groups = MonitorGroup::visibleTo($user)
            ->with(['server:id,name'])
            ->withCount('monitors')
            ->orderBy('sort')->orderBy('name')
            ->get();
        $health = GroupHealth::for($groups);

        $status = $request->query('status');
        $kind = $request->query('kind');
        $q = mb_strtolower((string) $request->query('q'));
        $filtered = $kind || $status || $q !== '';

        $matches = fn (MonitorGroup $g) => (! $kind || $g->kind === $kind)
            && (! $status || ($status === 'issues' ? in_array($health[$g->id]['status'], ['down', 'warning'], true) : $health[$g->id]['status'] === $status))
            && ($q === '' || str_contains(mb_strtolower($g->name.' '.$g->domain), $q));

        // Filtering shows a flat list; otherwise a tree (roots first, children nested).
        $roots = $filtered ? $groups->filter($matches)->values() : $groups->filter(fn ($g) => ! $g->parent_id || ! $groups->contains('id', $g->parent_id))->values();

        $summary = collect($health)->countBy('status');

        return view('groups.index', [
            'roots' => $roots,
            'byParent' => $filtered ? collect() : $groups->groupBy('parent_id'),
            'health' => $health,
            'summary' => $summary,
            'filtered' => $filtered,
            'total' => $groups->count(),
        ]);
    }

    public function create(Request $request)
    {
        $group = new MonitorGroup([
            'kind' => $request->query('kind', 'general'),
            'parent_id' => $request->query('parent'),
            'domain' => $request->query('domain'),
            'name' => $request->query('domain'),
        ]);

        return view('groups.form', $this->formData($request, $group));
    }

    public function store(Request $request)
    {
        $group = new MonitorGroup($this->validated($request));
        $group->user_id = $request->user()->id;
        $group->save();
        $this->syncMonitors($request, $group);
        AuditLog::record('group.created', $group, ['name' => $group->name]);

        return redirect()->route('groups.show', $group)->with('success', __('Group created.'));
    }

    public function show(Request $request, MonitorGroup $group)
    {
        $this->authorizeOwner($group);
        $group->load(['parent', 'children', 'server', 'monitors:id']);

        $ids = $group->allMonitorIds();
        $monitors = MonitorList::decorate(Monitor::whereIn('id', $ids)->with('groups:id,name')->orderByRaw("CASE status WHEN 'down' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")->orderBy('name')->get());
        $health = GroupHealth::for(collect([$group])->merge($group->children));
        $domain = $group->domainRecord();

        $mailGroups = $group->kind === 'mail' ? collect([$group]) : $group->children->where('kind', 'mail');
        $mail = null;
        if ($mailGroups->isNotEmpty()) {
            $mailMonitors = Monitor::whereIn('id', $mailGroups->flatMap(fn ($g) => $g->allMonitorIds())->unique())->get();
            $mailDomain = $domain ?? $mailGroups->first()->domainRecord();
            $components = MailHealth::components($mailMonitors, $mailDomain);
            $mail = ['components' => $components, 'overall' => MailHealth::overall($components), 'score' => MailHealth::score($components)];
        }

        return view('groups.show', [
            'group' => $group,
            'monitors' => $monitors,
            'health' => $health,
            'domain' => $domain,
            'mail' => $mail,
            'periods' => $this->periods($ids),
            'available' => Monitor::visibleTo($request->user())->whereNotIn('id', $group->monitors()->pluck('monitors.id'))->orderBy('name')->get(['id', 'name', 'type']),
        ]);
    }

    public function edit(Request $request, MonitorGroup $group)
    {
        $this->authorizeOwner($group);

        return view('groups.form', $this->formData($request, $group));
    }

    public function update(Request $request, MonitorGroup $group)
    {
        $this->authorizeOwner($group);
        $group->update($this->validated($request, $group));
        $this->syncMonitors($request, $group);
        AuditLog::record('group.updated', $group);

        return redirect()->route('groups.show', $group)->with('success', __('Group updated.'));
    }

    public function destroy(MonitorGroup $group)
    {
        $this->authorizeOwner($group);
        MonitorGroup::where('parent_id', $group->id)->update(['parent_id' => $group->parent_id]);
        AuditLog::record('group.deleted', $group, ['name' => $group->name]);
        $group->delete();

        return redirect()->route('groups.index')->with('success', __('Group deleted. Its monitors were kept.'));
    }

    /** Add or remove single monitors without opening the full form. */
    public function members(Request $request, MonitorGroup $group)
    {
        $this->authorizeOwner($group);
        $data = $request->validate(['add' => ['nullable', 'array'], 'add.*' => ['integer'], 'remove' => ['nullable', 'integer']]);

        if (! empty($data['add'])) {
            $group->monitors()->syncWithoutDetaching(Monitor::manageableBy($request->user())->whereIn('id', $data['add'])->pluck('id'));
        }
        if (! empty($data['remove'])) {
            $group->monitors()->detach($data['remove']);
        }

        return back()->with('success', __('Group members updated.'));
    }

    private function periods(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $out = ['24h' => collect(Uptime::last24h($ids))->avg('uptime')];
        foreach ([7 => '7d', 30 => '30d', 90 => '90d'] as $days => $key) {
            $out[$key] = Uptime::overall($ids, $days);
        }

        return $out;
    }

    private function formData(Request $request, MonitorGroup $group): array
    {
        $user = $request->user();

        return [
            'group' => $group,
            'parents' => MonitorGroup::visibleTo($user)->when($group->exists, fn ($q) => $q->whereNotIn('id', $group->descendantIds()))->orderBy('name')->get(['id', 'name', 'kind']),
            'monitors' => Monitor::visibleTo($user)->orderBy('name')->get(['id', 'name', 'type', 'target', 'port']),
            'selected' => $group->exists ? $group->monitors()->pluck('monitors.id')->all() : [],
            'servers' => Server::visibleTo($user)->orderBy('name')->get(['id', 'name']),
            'teams' => $this->assignableTeams(),
        ];
    }

    private function validated(Request $request, ?MonitorGroup $group = null): array
    {
        $user = $request->user();
        $request->merge(['domain' => $request->filled('domain') ? strtolower(trim((string) $request->input('domain'))) : null]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(array_keys(MonitorGroup::KINDS))],
            'parent_id' => ['nullable', 'integer', Rule::in(MonitorGroup::visibleTo($user)->pluck('id')->all()), function ($attr, $value, $fail) use ($group) {
                if ($value && $group && MonitorGroup::createsCycle($group->id, (int) $value)) {
                    $fail(__('This would create a circular group structure.'));
                }
            }],
            'server_id' => ['nullable', 'integer', Rule::in(Server::visibleTo($user)->pluck('id')->all())],
            'domain' => ['nullable', 'string', 'max:253', 'regex:/^([a-z0-9-]+\.)+[a-z]{2,}$/'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort' => ['nullable', 'integer', 'between:0,100000'],
            'team_id' => $this->teamRule(),
        ]);
        $data['sort'] ??= 0;

        return $data;
    }

    private function syncMonitors(Request $request, MonitorGroup $group): void
    {
        if ($request->has('monitors_present')) {
            $group->monitors()->sync(Monitor::visibleTo($request->user())->whereIn('id', (array) $request->input('monitors', []))->pluck('id'));
        }
    }
}
