<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Delivery\AssignDriver;
use App\Actions\Delivery\ReturnToSender;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignDriverRequest;
use App\Http\Requests\Admin\ReturnToSenderRequest;
use App\Http\Resources\OrderSummaryResource;
use App\Http\Resources\UserResource;
use App\Models\Order;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DispatchController extends Controller
{
    /**
     * The number of parcels shown per page in each queue.
     */
    private const PER_PAGE = 20;

    /**
     * Show the dispatch board: paid parcels to schedule, failed deliveries to
     * reschedule or return, open deliveries left over from earlier days, the
     * open deliveries scheduled on the chosen day (to reassign), and each
     * driver's workload on that day.
     *
     * Each queue is paginated on its own. The props are closures so a
     * partial reload (e.g. only "drivers" for another day) runs only its own query.
     */
    public function index(Request $request, Settings $settings): Response
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        $timezone = config()->string('kotak.timezone');
        $today = today($timezone);
        $date = $request->date('date', 'Y-m-d', $timezone) ?? $today;

        return Inertia::render('admin/Dispatch', [
            'date' => $date->toDateString(),
            'today' => $today->toDateString(),
            'awaiting' => fn () => OrderSummaryResource::collection(Order::query()
                ->awaitingDispatch()
                ->with('branch')
                ->oldest('paid_at')
                ->oldest('id')
                ->paginate(self::PER_PAGE, pageName: 'awaiting_page')
                ->withQueryString()),
            'failed' => fn () => OrderSummaryResource::collection(Order::query()
                ->failedAwaitingAction()
                ->with(['branch', 'driver', 'latestAttempt'])
                ->withCount('failedAttempts')
                ->oldest('updated_at')
                ->oldest('id')
                ->paginate(self::PER_PAGE, pageName: 'failed_page')
                ->withQueryString()),
            'overdue' => fn () => OrderSummaryResource::collection(Order::query()
                ->activeJobs()
                ->scheduledBefore($today)
                ->with(['branch', 'driver'])
                ->withCount('failedAttempts')
                ->oldest('scheduled_for')
                ->oldest('id')
                ->paginate(self::PER_PAGE, pageName: 'overdue_page')
                ->withQueryString()),
            // Grouped by driver, so one driver's round can be handed to others in one sitting.
            'scheduled' => fn () => OrderSummaryResource::collection(Order::query()
                ->activeJobs()
                ->scheduledOn($date)
                ->with(['branch', 'driver'])
                ->withCount('failedAttempts')
                ->orderBy(User::query()->select('name')->whereColumn('users.id', 'orders.driver_id'))
                ->oldest('id')
                ->paginate(self::PER_PAGE, pageName: 'scheduled_page')
                ->withQueryString()),
            'drivers' => fn () => UserResource::collection(User::query()
                ->withRole(Role::Driver)
                ->active()
                ->withJobsCountOn($date)
                ->orderBy('name')
                ->get()),
            'maxFailedAttempts' => $settings->maxFailedAttempts(),
        ]);
    }

    /**
     * Schedule a paid parcel, reschedule a failed delivery, or reassign a
     * delivery that has not been picked up yet.
     */
    public function assign(AssignDriverRequest $request, Order $order, AssignDriver $assignDriver): RedirectResponse
    {
        $driver = $request->driver();
        $order = $assignDriver->handle($order, $request->user(), $driver, $request->scheduledFor());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => sprintf(
                '%s is scheduled with %s on %s.',
                $order->formatted_tracking_number,
                $driver->name,
                $order->scheduled_for?->format('j M Y'),
            ),
        ]);

        return back();
    }

    /**
     * Close a failed delivery by returning the parcel to its sender.
     */
    public function returnToSender(ReturnToSenderRequest $request, Order $order, ReturnToSender $returnToSender): RedirectResponse
    {
        $order = $returnToSender->handle($order, $request->user(), $request->string('note')->value() ?: null);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => sprintf('%s will be returned to the sender.', $order->formatted_tracking_number),
        ]);

        return back();
    }
}
