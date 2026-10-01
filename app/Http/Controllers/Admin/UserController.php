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
            ->withCount(['monitors', 'servers', 'domains', 'statusPages', 'monitors as down_count' => fn ($q) => $q->where('is_active', true)->where('status', 'down')])
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($q) => $q->where('name', 'like', "%{$t}%")->orWhere('email', 'like', "%{$t}%")))
            ->when(UserRole::tryFrom((string) $request->query('role')), fn ($q, $role) => $q->where('role', $role->value))
            ->when(array_key_exists((string) $request->query('plan'), config('watchrex.plans')), fn ($q) => $q->where('plan', $request->query('plan')))
            ->when($request->query('state'), fn ($q, $state) => match ($state) {
                'active' => $q->where('is_active', true),
                'inactive' => $q->where('is_active', false),
                'expiring' => $q->whereNotNull('plan_expires_at')->where('plan_expires_at', '<=', now()->addDays(7)),
                'no2fa' => $q->whereNull('two_factor_confirmed_at'),
                default => $q,
            })
            ->tap(fn ($q) => match ($request->query('sort')) {
                'newest' => $q->latest(),
                'login' => $q->orderByDesc('last_login_at'),
                'monitors' => $q->orderByDesc('monitors_count'),
                default => $q->orderBy('name'),
            })
            ->paginate(30)
            ->withQueryString();

        return view('admin.users.index', ['users' => $users, 'roles' => UserRole::cases()]);
    }

    /** Enable / disable an account without opening the edit form. */
    public function toggle(Request $request, User $user)
    {
        abort_if($user->is($request->user()), 422, __('You cannot disable yourself.'));
        $user->forceFill(['is_active' => ! $user->is_active])->save();
        AuditLog::record($user->is_active ? 'admin.user_enabled' : 'admin.user_disabled', $user, ['email' => $user->email]);

        return back()->with('success', $user->is_active ? __('Account enabled.') : __('Account disabled.'));
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
            'plan_expires_at' => ['nullable', 'date'],
            'is_active' => ['boolean'],
            'locale' => ['required', Rule::in(array_keys(config('watchrex.locales')))],
            'timezone' => ['required', 'timezone:all'],
        ]);
    }
}
