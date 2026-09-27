<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MaintenanceController extends Controller
{
    public function index(Request $request)
    {
        return view('maintenance.index', [
            'windows' => MaintenanceWindow::visibleTo($request->user())->withCount('monitors')->latest('starts_at')->paginate(30),
        ]);
    }

    public function create(Request $request)
    {
        $window = new MaintenanceWindow(['starts_at' => now()->addHour()->startOfHour(), 'ends_at' => now()->addHours(2)->startOfHour()]);

        return view('maintenance.form', ['window' => $window, 'monitors' => Monitor::visibleTo($request->user())->orderBy('name')->get(['id', 'name']), 'selected' => []]);
    }

    public function store(Request $request)
    {
        [$data, $ids] = $this->validated($request);
        $window = $request->user()->maintenanceWindows()->create($data);
        $window->monitors()->sync($ids);
        AuditLog::record('maintenance.created', $window);

        return redirect()->route('maintenance.index')->with('success', __('Maintenance window scheduled.'));
    }

    public function edit(Request $request, MaintenanceWindow $maintenance)
    {
        $this->authorizeOwner($maintenance);

        return view('maintenance.form', [
            'window' => $maintenance,
            'monitors' => Monitor::where('user_id', $maintenance->user_id)->orderBy('name')->get(['id', 'name']),
            'selected' => $maintenance->monitors()->pluck('monitors.id')->all(),
        ]);
    }

    public function update(Request $request, MaintenanceWindow $maintenance)
    {
        $this->authorizeOwner($maintenance);
        [$data, $ids] = $this->validated($request, $maintenance->user_id);
        $maintenance->update($data);
        $maintenance->monitors()->sync($ids);

        return redirect()->route('maintenance.index')->with('success', __('Maintenance window updated.'));
    }

    public function destroy(MaintenanceWindow $maintenance)
    {
        $this->authorizeOwner($maintenance);
        $maintenance->delete();

        return redirect()->route('maintenance.index')->with('success', __('Maintenance window deleted.'));
    }

    private function validated(Request $request, ?int $ownerId = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'monitors' => ['required', 'array', 'min:1'],
            'monitors.*' => ['integer'],
        ]);

        $tz = $request->user()->timezone ?: config('app.timezone');
        $data['starts_at'] = Carbon::parse($data['starts_at'], $tz)->utc();
        $data['ends_at'] = Carbon::parse($data['ends_at'], $tz)->utc();
        $ids = ($ownerId ? Monitor::where('user_id', $ownerId) : Monitor::visibleTo($request->user()))->whereIn('id', $data['monitors'])->pluck('id')->all();
        unset($data['monitors']);

        return [$data, $ids];
    }
}
