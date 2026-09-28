<?php

namespace App\Http\Controllers;

use App\Models\Monitor;
use App\Services\DependencyMap;
use Illuminate\Http\Request;

class DependencyController extends Controller
{
    public function index(Request $request)
    {
        return view('dependencies.index', ['map' => $this->map($request)]);
    }

    /** Partial for the 30s auto-refresh. */
    public function live(Request $request)
    {
        return view('dependencies._map', ['map' => $this->map($request)]);
    }

    private function map(Request $request): array
    {
        $monitors = Monitor::visibleTo($request->user())
            ->when($request->query('group'), fn ($q, $g) => $q->where('group', $g))
            ->get(['id', 'name', 'type', 'status', 'is_active', 'parent_id', 'last_response_ms', 'last_message', 'group']);

        return DependencyMap::build($monitors);
    }
}
