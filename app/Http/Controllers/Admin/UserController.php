<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * List accounts, filtered by role and a search on name or email.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        // Each filter is a single plain value. Unknown values are ignored rather than rejected.
        $request->validate([
            'role' => ['nullable', 'string'],
            'q' => ['nullable', 'string'],
        ]);

        $role = Role::tryFrom($request->string('role')->value());
        $search = $request->string('q')->trim()->substr(0, 100)->value();

        $users = User::query()
            ->with('branch')
            ->when($role, fn (Builder $users, Role $role) => $users->withRole($role))
            ->when($search !== '', fn (Builder $users) => $users->where(fn (Builder $users) => $users
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/users/Index', [
            'users' => UserResource::collection($users),
            'filters' => [
                'role' => $role?->value,
                'q' => $search !== '' ? $search : null,
            ],
            'roles' => Role::options(),
        ]);
    }

    /**
     * Show the form for creating an account.
     */
    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('admin/users/Form', [
            'user' => null,
            'roles' => Role::options(),
            'branches' => $this->branchOptions(),
        ]);
    }

    /**
     * Create an account and email the new user a link to choose their password.
     *
     * The admin never knows the password. The email address is treated as
     * verified because the admin created the account for a known person.
     */
    public function store(UserRequest $request): RedirectResponse
    {
        $user = new User;

        $user->forceFill([
            ...$request->accountData(),
            'password' => Str::password(64),
            'email_verified_at' => now(),
        ])->save();

        Password::sendResetLink(['email' => $user->email]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => sprintf('Account created. %s will receive an email to set their password.', $user->name),
        ]);

        return to_route('admin.users.index');
    }

    /**
     * Show the form for editing an account.
     */
    public function edit(User $user): Response
    {
        Gate::authorize('update', $user);

        return Inertia::render('admin/users/Form', [
            'user' => UserResource::make($user->load('branch')),
            'roles' => Role::options(),
            'branches' => $this->branchOptions(),
        ]);
    }

    /**
     * Update an account, including its role and whether it may sign in.
     *
     * A deactivated user is signed out on their next request (EnsureUserIsActive).
     */
    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $user->forceFill($request->accountData())->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Account updated.']);

        return to_route('admin.users.index');
    }

    /**
     * Get the active branches staff can be assigned to.
     *
     * @return Collection<int, array{id: int, code: string, name: string, city: string}>
     */
    private function branchOptions(): Collection
    {
        return Branch::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'city'])
            ->map(fn (Branch $branch): array => $branch->only(['id', 'code', 'name', 'city']))
            ->toBase();
    }
}
