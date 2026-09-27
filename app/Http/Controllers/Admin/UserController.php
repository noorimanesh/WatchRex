<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::query()
            ->withCount(['monitors', 'servers', 'domains'])
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($q) => $q->where('name', 'like', "%{$t}%")->orWhere('email', 'like', "%{$t}%")))
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return view('admin.users.index', ['users' => $users]);
    }

    public function create()
    {
        return view('admin.users.form', ['user' => new User(['role' => UserRole::User, 'plan' => config('watchrex.default_plan'), 'is_active' => true, 'locale' => 'fa', 'timezone' => 'Asia/Tehran'])]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $user = User::create($data);
        AuditLog::record('admin.user_created', $user, ['email' => $user->email]);

        return redirect()->route('admin.users.index')->with('success', __('User created.'));
    }

    public function edit(User $user)
    {
        return view('admin.users.form', ['user' => $user]);
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);

        if ($user->is($request->user())) {
            // Never lock yourself out.
            $data['role'] = UserRole::Admin->value;
            $data['is_active'] = true;
        }

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);

        if ($request->boolean('reset_2fa')) {
            $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
        }

        AuditLog::record('admin.user_updated', $user, ['email' => $user->email, 'reset_2fa' => $request->boolean('reset_2fa')]);

        return redirect()->route('admin.users.index')->with('success', __('User updated.'));
    }

    public function destroy(Request $request, User $user)
    {
        abort_if($user->is($request->user()), 422, __('You cannot delete yourself.'));
        AuditLog::record('admin.user_deleted', $user, ['email' => $user->email]);
        $user->delete();

        return redirect()->route('admin.users.index')->with('success', __('User and all their data deleted.'));
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $request->merge(['is_active' => $request->boolean('is_active'), 'email' => strtolower((string) $request->input('email'))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', Password::min(10)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'plan' => ['required', Rule::in(array_keys(config('watchrex.plans')))],
            'max_monitors' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'min_interval' => ['nullable', 'integer', 'min:10', 'max:86400'],
            'is_active' => ['boolean'],
            'locale' => ['required', Rule::in(array_keys(config('watchrex.locales')))],
            'timezone' => ['required', 'timezone:all'],
        ]);
    }
}
