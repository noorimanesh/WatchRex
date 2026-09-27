<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Probe;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProbeController extends Controller
{
    public function index()
    {
        return view('admin.probes.index', [
            'probes' => Probe::withCount('monitors')->orderBy('name')->get(),
            'token' => session('probe_token'),
            'tokenProbe' => session('probe_id') ? Probe::find(session('probe_id')) : null,
        ]);
    }

    public function store(Request $request)
    {
        $probe = new Probe($this->validated($request));
        $token = $probe->rotateToken();
        $probe->save();
        AuditLog::record('probe.created', $probe, ['location' => $probe->location]);

        return redirect()->route('admin.probes.index')->with(['probe_token' => $token, 'probe_id' => $probe->id]);
    }

    public function update(Request $request, Probe $probe)
    {
        $probe->update($this->validated($request, $probe));
        AuditLog::record('probe.updated', $probe);

        return back()->with('success', __('Probe updated.'));
    }

    public function token(Probe $probe)
    {
        $token = $probe->rotateToken();
        $probe->save();
        AuditLog::record('probe.token_rotated', $probe);

        return redirect()->route('admin.probes.index')->with(['probe_token' => $token, 'probe_id' => $probe->id]);
    }

    public function destroy(Probe $probe)
    {
        AuditLog::record('probe.deleted', $probe, ['location' => $probe->location]);
        $probe->delete();

        return back()->with('success', __('Probe removed.'));
    }

    private function validated(Request $request, ?Probe $probe = null): array
    {
        $request->merge(['location' => strtolower((string) $request->input('location')), 'country_code' => strtoupper((string) $request->input('country_code')) ?: null, 'is_active' => $request->boolean('is_active', true)]);

        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'location' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9-]+$/', Rule::notIn([config('watchrex.location')]), Rule::unique('probes')->ignore($probe?->id)],
            'country_code' => ['nullable', 'string', 'size:2', 'alpha'],
            'is_active' => ['boolean'],
        ]);
    }
}
