<?php

namespace App\Http\Controllers\Driver;

use App\Actions\Delivery\MarkPickedUp;
use App\Actions\Delivery\RecordDeliveryFailure;
use App\Actions\Delivery\RecordDeliverySuccess;
use App\Enums\DeliveryFailureReason;
use App\Enums\DeliveryOutcome;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\DeliverJobRequest;
use App\Http\Requests\Driver\FailJobRequest;
use App\Http\Requests\Driver\ListJobsRequest;
use App\Http\Resources\DriverJobResource;
use App\Models\DeliveryAttempt;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class JobController extends Controller
{
    /**
     * Show the driver's open deliveries for a day (today in Malaysia by default).
     *
     * Today's list also carries over jobs left open from earlier days, flagged
     * as overdue and listed first, so unfinished work never drops out of view.
     */
    public function index(ListJobsRequest $request): Response
    {
        $driver = $request->user();
        $day = $request->day();

        $jobs = Order::query()
            ->forDriver($driver)
            ->activeJobs()
            ->when(
                $day->isToday(),
                fn (Builder $jobs) => $jobs->scheduledBefore($day->addDay()),
                fn (Builder $jobs) => $jobs->scheduledOn($day),
            )
            ->with('branch')
            ->withCount('failedAttempts')
            ->orderBy('scheduled_for')
            ->orderBy('postcode')
            ->orderBy('id')
            ->get();

        return Inertia::render('driver/Jobs', [
            'date' => $day->toDateString(),
            'jobs' => DriverJobResource::collection($jobs),
            'counts' => [
                'assigned' => $jobs->where('status', OrderStatus::Assigned)->count(),
                'picked_up' => $jobs->where('status', OrderStatus::PickedUp)->count(),
                ...$this->attemptCounts($driver, $day),
            ],
        ]);
    }

    /**
     * Show one open delivery with the receiver's contact details and address.
     */
    public function show(Order $order): Response
    {
        Gate::authorize('view', $order);

        $order->load(['branch', 'deliveryAttempts' => fn ($query) => $query->oldest('attempted_at')])
            ->loadCount('failedAttempts');

        return Inertia::render('driver/JobShow', [
            'order' => DriverJobResource::make($order),
            'failureReasons' => DeliveryFailureReason::options(),
        ]);
    }

    /**
     * Record that the driver collected the parcel from the branch.
     */
    public function pickup(Request $request, Order $order, MarkPickedUp $markPickedUp): RedirectResponse
    {
        Gate::authorize('deliver', $order);

        $order = $markPickedUp->handle($order, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Picked up {$order->formatted_tracking_number}."]);

        return back();
    }

    /**
     * Record a successful delivery with the recipient's name and a photo.
     */
    public function deliver(DeliverJobRequest $request, Order $order, RecordDeliverySuccess $recordDelivery): RedirectResponse
    {
        $order = $recordDelivery->handle(
            $order,
            $request->user(),
            $request->string('recipient_name')->value(),
            $request->photo(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Delivered {$order->formatted_tracking_number}."]);

        return $this->toJobList($order);
    }

    /**
     * Record a failed delivery attempt so an admin can reschedule or return it.
     */
    public function fail(FailJobRequest $request, Order $order, RecordDeliveryFailure $recordFailure): RedirectResponse
    {
        $order = $recordFailure->handle(
            $order,
            $request->user(),
            $request->enum('reason', DeliveryFailureReason::class),
            $request->validated('note'),
        );

        Inertia::flash('toast', ['type' => 'info', 'message' => "Failed delivery recorded for {$order->formatted_tracking_number}."]);

        return $this->toJobList($order);
    }

    /**
     * Go back to the list the job was on: today's list, which includes overdue
     * jobs, or the future day it was scheduled for.
     */
    private function toJobList(Order $order): RedirectResponse
    {
        $date = $order->scheduled_for?->toDateString();
        $today = today(config()->string('kotak.timezone'))->toDateString();

        return to_route('driver.jobs', $date !== null && $date > $today ? ['date' => $date] : []);
    }

    /**
     * Count the driver's delivered and failed attempts made during the given Malaysian day.
     *
     * @return array{delivered: int, failed: int}
     */
    private function attemptCounts(User $driver, CarbonImmutable $day): array
    {
        // Attempt times are stored in UTC, so compare against the day's bounds in UTC.
        $outcomes = DeliveryAttempt::query()
            ->where('driver_id', $driver->id)
            ->whereBetween('attempted_at', [$day->utc(), $day->endOfDay()->utc()])
            ->pluck('outcome');

        return [
            'delivered' => $outcomes->filter(fn (DeliveryOutcome $outcome) => $outcome === DeliveryOutcome::Delivered)->count(),
            'failed' => $outcomes->filter(fn (DeliveryOutcome $outcome) => $outcome === DeliveryOutcome::Failed)->count(),
        ];
    }
}
