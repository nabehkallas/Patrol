<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\AuditLog;
use App\Models\TenantUserDirectory;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with('roles')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->getRoleNames()->first(),
                'created_at' => $user->created_at,
                'last_login_at' => $user->last_login_at,
                'disabled' => $user->disabled_at !== null,
                'is_me' => $user->id === $request->user()->id,
            ]);

        return Inertia::render('admin/users/index', [
            'users' => $users,
            'resetCredentials' => Session::get('employee_credentials'),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('admin/users/create', [
            'roles' => array_map(fn (UserRole $role) => $role->value, UserRole::cases()),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $user->syncRoles([$data['role']]);
        AuditLog::record('role.assigned', 'User', $user->id, null, ['role' => $data['role']]);
        $user->sendVerificationLink();

        // The central-only routing table login checks first to find which tenant database a
        // login email belongs to (see FortifyServiceProvider::authenticateUsing()) -- without
        // this, a freshly-created tenant user can never log in: their password is correct, but
        // the login flow has no way to know which station's database to even check it against.
        // updateOrCreate rather than create: a user deleted before this sync existed could have
        // left a stale orphaned directory row behind for this same email (destroy() now cleans
        // this up going forward, but a row from before that fix may still be sitting here).
        TenantUserDirectory::updateOrCreate(
            ['email' => $user->email],
            ['tenant_id' => tenant('id')],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User created.')]);

        return to_route('admin.users.index');
    }

    public function edit(User $user): Response
    {
        $this->authorize('update', $user);

        return Inertia::render('admin/users/edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->getRoleNames()->first(),
            ],
            'roles' => array_map(fn (UserRole $role) => $role->value, UserRole::cases()),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $data = $request->validated();
        $oldEmail = $user->email;

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
        ]);

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        // A new address has to be verified again before the account can be used.
        $emailChanged = $user->isDirty('email');
        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendVerificationLink();
        }

        // Roles live in a pivot table, which fires no model events, so log a change here.
        $oldRole = $user->getRoleNames()->first();

        // The station always keeps at least one admin who can sign in.
        if ($oldRole === UserRole::Admin->value && $data['role'] !== UserRole::Admin->value && $this->otherActiveAdmins($user) === 0) {
            throw ValidationException::withMessages(['role' => __('The station needs at least one admin. Make someone else an admin first.')]);
        }

        $user->syncRoles([$data['role']]);
        if ($oldRole !== $data['role']) {
            AuditLog::record('role.changed', 'User', $user->id, ['role' => $oldRole], ['role' => $data['role']]);
        }

        // Not just "if the email changed" -- a user saved before this directory sync existed
        // (or one whose row was otherwise lost) has no directory entry at all yet, so re-saving
        // them with an unchanged email needs to create one too, not just skip.
        if ($user->email !== $oldEmail) {
            TenantUserDirectory::where('email', $oldEmail)->delete();
        }

        TenantUserDirectory::updateOrCreate(
            ['email' => $user->email],
            ['tenant_id' => tenant('id')],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User updated.')]);

        return to_route('admin.users.index');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        if ($user->hasRole(UserRole::Admin->value) && $this->otherActiveAdmins($user) === 0) {
            return $this->refuse(__('The station needs at least one admin. Make someone else an admin first.'));
        }

        $user->delete();

        TenantUserDirectory::where('email', $user->email)->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User deleted.')]);

        return to_route('admin.users.index');
    }

    /**
     * A forgotten password, reset by the station admin: the employee gets a one-time temporary
     * password (shown once on the Employees page, to pass on in person) that they must change at
     * their next sign-in. Their open sessions end, since the password hash changes.
     */
    public function resetPassword(User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $temporaryPassword = Str::password(12, symbols: false);
        $user->forceFill(['password' => $temporaryPassword, 'must_change_password' => true])->save();
        AuditLog::record('password.admin_reset', 'User', $user->id);
        $user->notifyPasswordChanged();

        Session::flash('employee_credentials', [
            'name' => $user->name,
            'email' => $user->email,
            'password' => $temporaryPassword,
        ]);

        return to_route('admin.users.index');
    }

    /** Switches an employee's account off (they can't sign in) or back on. */
    public function toggleDisabled(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        if ($user->id === $request->user()->id) {
            return $this->refuse(__('You can\'t disable your own account.'));
        }

        if ($user->disabled_at === null && $user->hasRole(UserRole::Admin->value) && $this->otherActiveAdmins($user) === 0) {
            return $this->refuse(__('The station needs at least one admin. Make someone else an admin first.'));
        }

        $user->forceFill(['disabled_at' => $user->disabled_at === null ? now() : null])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => $user->disabled_at !== null
            ? __(':name can no longer sign in.', ['name' => $user->name])
            : __(':name can sign in again.', ['name' => $user->name])]);

        return to_route('admin.users.index');
    }

    /** Admins other than $user who can still sign in. */
    private function otherActiveAdmins(User $user): int
    {
        return User::role(UserRole::Admin->value)
            ->whereKeyNot($user->getKey())
            ->whereNull('disabled_at')
            ->count();
    }

    private function refuse(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back(303);
    }
}
