<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\TenantUserDirectory;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                'role' => $user->roles->first()?->name,
                'created_at' => $user->created_at,
            ]);

        return Inertia::render('admin/users/index', [
            'users' => $users,
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
                'role' => $user->roles->first()?->name,
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

        $user->save();

        $user->syncRoles([$data['role']]);

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

        $user->delete();

        TenantUserDirectory::where('email', $user->email)->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User deleted.')]);

        return to_route('admin.users.index');
    }
}
