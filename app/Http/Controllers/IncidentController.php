<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Incident;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public function index(Request $request)
    {
        $incidents = Incident::visibleTo($request->user())
            ->with('monitor:id,name,type')
            ->when($request->query('state') === 'open', fn ($q) => $q->whereNull('resolved_at'))
            ->when($request->query('state') === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
            ->when($request->query('severity'), fn ($q, $s) => $q->where('severity', $s))
            ->latest('started_at')
            ->paginate(30)
            ->withQueryString();

        return view('incidents.index', ['incidents' => $incidents]);
    }

    public function show(Incident $incident)
    {
        $this->authorizeOwner($incident);
        $incident->load(['monitor', 'updates.user']);

        return view('incidents.show', ['incident' => $incident]);
    }

    public function note(Request $request, Incident $incident)
    {
        $this->authorizeOwner($incident);
        $data = $request->validate(['message' => ['required', 'string', 'max:2000'], 'public' => ['nullable', 'boolean']]);
        $incident->timeline($request->boolean('public') ? 'public' : 'note', $data['message'], $request->user()->id);

        return back()->with('success', __('Update added.'));
    }

    public function acknowledge(Request $request, Incident $incident)
    {
        $this->authorizeOwner($incident);
        if (! $incident->acknowledged_at) {
            $incident->update(['acknowledged_at' => now(), 'status' => $incident->isOpen() ? 'acknowledged' : $incident->status]);
            $incident->timeline('acknowledged', __('Acknowledged by :u', ['u' => $request->user()->name]), $request->user()->id);
            AuditLog::record('incident.acknowledged', $incident);
        }

        return back();
    }

    public function resolve(Request $request, Incident $incident)
    {
        $this->authorizeOwner($incident);
        if ($incident->isOpen()) {
            $incident->update(['resolved_at' => now(), 'status' => 'resolved', 'duration' => (int) $incident->started_at->diffInSeconds(now())]);
            $incident->timeline('resolved', __('Manually resolved by :u', ['u' => $request->user()->name]), $request->user()->id);
            AuditLog::record('incident.resolved', $incident);
        }

        return back();
    }
}
