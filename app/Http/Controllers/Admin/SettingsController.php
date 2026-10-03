<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Settings\UpdateSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Models\Setting;
use App\Support\DropOffTiming;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    /**
     * Show the business rules with how long customers take to drop parcels
     * off, so the unclaimed-order limit can be set from real data.
     */
    public function edit(Settings $settings, DropOffTiming $timing): Response
    {
        Gate::authorize('viewAny', Setting::class);

        return Inertia::render('admin/Settings', [
            'settings' => $settings->all(),
            'limits' => Settings::LIMITS,
            'timing' => fn () => $timing->summary(),
        ]);
    }

    /**
     * Save the business rules. Every screen and job uses the new values
     * straight away, except that a new drop-off limit only applies to
     * orders placed from now on: waiting orders keep their deadline.
     */
    public function update(UpdateSettingsRequest $request, UpdateSettings $updateSettings): RedirectResponse
    {
        $updateSettings->handle($request->user(), $request->settings());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Site settings saved.']);

        return to_route('admin.settings.edit');
    }
}
