<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $teams = ($user->isAdmin() ? Team::query() : $user->teams())->withCount(['members', 'monitors'])->with('owner:id,name')->orderBy('name')->get();

        return view('teams.index', ['teams' => $teams]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $team = new Team($data);
        $team->owner_id = $request->user()->id;
        $team->save();
        $team->members()->attach($request->user()->id, ['role' => 'owner']);
        AuditLog::record('team.created', $team, ['name' => $team->name]);

        return redirect()->route('teams.show', $team)->with('success', __('Team created.'));
    }

    public function show(Request $request, Team $team)
    {
        $team->load('members');
        abort_unless($request->user()->isAdmin() || $team->roleOf($request->user()), 404);

        return view('teams.show', [
            'team' => $team,
            'canManage' => $team->canManageMembers($request->user()),
            'role' => $team->roleOf($request->user()),
        ]);
    }

    public function update(Request $request, Team $team)
    {
        $this->authorizeMembers($request, $team);
        $team->update($request->validate(['name' => ['required', 'string', 'max:80']]));

        return back()->with('success', __('Saved.'));
    }

    public function destroy(Request $request, Team $team)
    {
        abort_unless($request->user()->isAdmin() || $team->owner_id === $request->user()->id, 403);
        AuditLog::record('team.deleted', $team, ['name' => $team->name]);
        // Resources stay with their creators; only the sharing is removed.
        $team->delete();

        return redirect()->route('teams.index')->with('success', __('Team deleted. Shared resources were returned to their creators.'));
    }

    public function addMember(Request $request, Team $team)
    {
        $this->authorizeMembers($request, $team);
        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', Rule::in(['admin', 'developer', 'viewer'])],
        ]);

        $member = User::where('email', strtolower($data['email']))->where('is_active', true)->first();
        if (! $member) {
            return back()->withErrors(['email' => __('No active user with this e-mail. Ask an administrator to create the account first.')]);
        }

        $team->members()->syncWithoutDetaching([$member->id => ['role' => $data['role']]]);
        AuditLog::record('team.member_added', $team, ['user' => $member->email, 'role' => $data['role']]);

        return back()->with('success', __(':n added to the team.', ['n' => $member->name]));
    }

    public function updateMember(Request $request, Team $team, User $member)
    {
        $this->authorizeMembers($request, $team);
        abort_if($member->id === $team->owner_id, 422, __('The owner role cannot be changed.'));
        $data = $request->validate(['role' => ['required', Rule::in(['admin', 'developer', 'viewer'])]]);
        $team->members()->updateExistingPivot($member->id, ['role' => $data['role']]);
        AuditLog::record('team.member_role', $team, ['user' => $member->email, 'role' => $data['role']]);

        return back()->with('success', __('Role updated.'));
    }

    public function removeMember(Request $request, Team $team, User $member)
    {
        $self = $member->id === $request->user()->id;
        if (! $self) {
            $this->authorizeMembers($request, $team);
        }
        abort_if($member->id === $team->owner_id, 422, __('The owner cannot leave the team; delete it instead.'));

        $team->members()->detach($member->id);
        AuditLog::record('team.member_removed', $team, ['user' => $member->email]);

        return $self ? redirect()->route('teams.index')->with('success', __('You left the team.')) : back()->with('success', __('Member removed.'));
    }

    private function authorizeMembers(Request $request, Team $team): void
    {
        $team->loadMissing('members');
        abort_unless($team->canManageMembers($request->user()), 403);
    }
}
