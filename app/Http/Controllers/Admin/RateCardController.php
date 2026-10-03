<?php

namespace App\Http\Controllers\Admin;

use App\Actions\RateCards\CreateRateCardDraft;
use App\Actions\RateCards\DeleteRateCardDraft;
use App\Actions\RateCards\PublishRateCard;
use App\Actions\RateCards\UpdateRateCardDraft;
use App\Actions\RateCards\WithdrawRateCard;
use App\Enums\MalaysianState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PublishRateCardRequest;
use App\Http\Requests\Admin\StoreRateCardRequest;
use App\Http\Requests\Admin\UpdateRateCardRequest;
use App\Http\Resources\RateCardResource;
use App\Models\RateCard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RateCardController extends Controller
{
    /**
     * List every version of the rates: drafts first, then the published
     * ones from the last to take effect.
     */
    public function index(): Response
    {
        Gate::authorize('viewAny', RateCard::class);

        return Inertia::render('admin/rates/Index', [
            'rateCards' => RateCardResource::collection(
                RateCard::query()->with('publishedBy')->newestFirst()->get(),
            ),
        ]);
    }

    /**
     * Start a draft, copied from a version or blank, and open it for editing.
     */
    public function store(StoreRateCardRequest $request, CreateRateCardDraft $createDraft): RedirectResponse
    {
        $draft = $createDraft->handle($request->user(), $request->source());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft created. Change the prices, then publish it.']);

        return to_route('admin.rates.edit', $draft);
    }

    /**
     * Show a version: its zones and each route's bands. A draft also lists
     * what stops it from being published.
     */
    public function show(Request $request, RateCard $rateCard, PublishRateCard $publish): Response
    {
        Gate::authorize('view', $rateCard);

        $rateCard->load(['zones', 'routes.bands', 'createdBy', 'publishedBy']);
        $user = $request->user();

        return Inertia::render('admin/rates/Show', [
            'rateCard' => RateCardResource::make($rateCard),
            'problems' => $rateCard->isDraft() ? $publish->problems($rateCard) : [],
            'states' => MalaysianState::options(),
            'can' => [
                'update' => $user->can('update', $rateCard),
                'publish' => $user->can('publish', $rateCard),
                'withdraw' => $user->can('withdraw', $rateCard),
                'delete' => $user->can('delete', $rateCard),
            ],
        ]);
    }

    /**
     * Show the editor for a draft: details, zones and the price grid. Rates
     * published meanwhile (by another admin, say) open on their own page,
     * which says why they cannot be edited.
     */
    public function edit(RateCard $rateCard): Response|RedirectResponse
    {
        Gate::authorize('manage', RateCard::class);

        if (! $rateCard->isDraft()) {
            return to_route('admin.rates.show', $rateCard)->withErrors([
                'card' => 'Published rates cannot be changed. Copy them into a new draft instead.',
            ]);
        }

        return Inertia::render('admin/rates/Edit', [
            'rateCard' => RateCardResource::make($rateCard->load(['zones', 'routes.bands'])),
            'states' => MalaysianState::options(),
            'maxWeightG' => config()->integer('kotak.max_weight_g'),
        ]);
    }

    /**
     * Save a draft. It may still be incomplete; publishing checks it in full.
     */
    public function update(UpdateRateCardRequest $request, RateCard $rateCard, UpdateRateCardDraft $updateDraft): RedirectResponse
    {
        $updateDraft->handle($rateCard, $request->draft());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft saved.']);

        return to_route('admin.rates.show', $rateCard);
    }

    /**
     * Delete a draft.
     */
    public function destroy(RateCard $rateCard, DeleteRateCardDraft $deleteDraft): RedirectResponse
    {
        Gate::authorize('manage', RateCard::class);

        $deleteDraft->handle($rateCard);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Draft “{$rateCard->name}” deleted."]);

        return to_route('admin.rates.index');
    }

    /**
     * Publish a draft, now or from a date and time.
     */
    public function publish(PublishRateCardRequest $request, RateCard $rateCard, PublishRateCard $publish): RedirectResponse
    {
        $card = $publish->handle($rateCard, $request->user(), $request->effectiveFrom());

        $message = $card->isScheduled() && $card->effective_from !== null
            ? sprintf(
                'Rates scheduled for %s.',
                $card->effective_from->setTimezone(config()->string('kotak.timezone'))->format('j M Y, H:i'),
            )
            : 'Rates published. New orders are priced with them from now on.';

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('admin.rates.show', $card);
    }

    /**
     * Take scheduled rates back to a draft before they take effect.
     */
    public function withdraw(RateCard $rateCard, WithdrawRateCard $withdraw): RedirectResponse
    {
        Gate::authorize('manage', RateCard::class);

        $withdraw->handle($rateCard);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rates withdrawn. They are a draft again.']);

        return to_route('admin.rates.show', $rateCard);
    }
}
