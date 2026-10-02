<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MalaysianState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    /**
     * List every branch, including deactivated ones.
     */
    public function index(): Response
    {
        Gate::authorize('viewAny', Branch::class);

        return Inertia::render('admin/branches/Index', [
            'branches' => BranchResource::collection(Branch::query()->orderBy('name')->get()),
        ]);
    }

    /**
     * Show the form for creating a branch.
     */
    public function create(): Response
    {
        Gate::authorize('create', Branch::class);

        return Inertia::render('admin/branches/Form', [
            'branch' => null,
            'states' => MalaysianState::options(),
        ]);
    }

    /**
     * Open a new branch.
     */
    public function store(BranchRequest $request): RedirectResponse
    {
        $branch = Branch::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf('Branch %s created.', $branch->code)]);

        return to_route('admin.branches.index');
    }

    /**
     * Show the form for editing a branch.
     */
    public function edit(Branch $branch): Response
    {
        Gate::authorize('update', $branch);

        return Inertia::render('admin/branches/Form', [
            'branch' => BranchResource::make($branch),
            'states' => MalaysianState::options(),
        ]);
    }

    /**
     * Update a branch. Deactivated branches stop accepting new orders but keep their history.
     */
    public function update(BranchRequest $request, Branch $branch): RedirectResponse
    {
        $branch->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf('Branch %s updated.', $branch->code)]);

        return to_route('admin.branches.index');
    }
}
