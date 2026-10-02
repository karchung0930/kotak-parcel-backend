<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderStatusEvent;
use App\Models\User;
use App\Notifications\OrderStatusUpdated;
use App\Services\OrderStatusService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class OrderStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrderStatusService $statuses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statuses = app(OrderStatusService::class);
    }

    public function test_a_transition_updates_the_status_timestamp_and_history()
    {
        $this->freezeSecond();
        $branch = Branch::factory()->create();
        $staff = User::factory()->staff($branch)->create();
        $order = Order::factory()->create();

        $updated = $this->statuses->transition($order, OrderStatus::DroppedOff, $staff, 'Weighed at the counter.', [
            'measured_weight_g' => 1234,
        ]);

        $this->assertSame(OrderStatus::DroppedOff, $updated->status);
        $this->assertSame(1234, $updated->measured_weight_g);
        $this->assertTrue($updated->dropped_off_at->equalTo(now()));

        $event = $order->latestStatusEvent()->firstOrFail();
        $this->assertSame(OrderStatus::Created, $event->from_status);
        $this->assertSame(OrderStatus::DroppedOff, $event->to_status);
        $this->assertSame('Weighed at the counter.', $event->note);
        $this->assertSame($staff->id, $event->actor_id);
        $this->assertSame($branch->id, $event->branch_id);
    }

    public function test_an_illegal_transition_is_rejected_and_nothing_is_written()
    {
        $order = Order::factory()->create();
        $events = $order->statusEvents()->count();

        try {
            $this->statuses->transition($order, OrderStatus::Delivered, null);
            $this->fail('The transition should have been rejected.');
        } catch (InvalidStatusTransition $e) {
            $this->assertSame('This parcel is currently "Created" and cannot be changed to "Delivered".', $e->getMessage());
        }

        $this->assertSame(OrderStatus::Created, $order->fresh()?->status);
        $this->assertSame($events, $order->statusEvents()->count());
    }

    public function test_the_status_is_re_read_under_lock_so_stale_models_cannot_repeat_a_change()
    {
        $order = Order::factory()->create();
        $stale = Order::findOrFail($order->id);

        $this->statuses->transition($order, OrderStatus::DroppedOff, null);

        // The stale copy still says "created", but the service checks the database.
        $this->assertSame(OrderStatus::Created, $stale->status);
        $this->expectException(InvalidStatusTransition::class);

        $this->statuses->transition($stale, OrderStatus::DroppedOff, null);
    }

    public function test_the_change_is_announced_only_after_the_transaction_commits()
    {
        $order = Order::factory()->create();
        $announced = [];
        Event::listen(OrderStatusChanged::class, function (OrderStatusChanged $event) use (&$announced) {
            $announced[] = [$event->from, $event->to];
        });

        DB::transaction(function () use ($order, &$announced) {
            $this->statuses->transition($order, OrderStatus::DroppedOff, null);

            $this->assertSame([], $announced);
        });

        $this->assertSame([[OrderStatus::Created, OrderStatus::DroppedOff]], $announced);
    }

    public function test_nothing_is_announced_when_the_transaction_rolls_back()
    {
        $order = Order::factory()->create();
        $announced = false;
        Event::listen(OrderStatusChanged::class, function () use (&$announced) {
            $announced = true;
        });

        try {
            DB::transaction(function () use ($order) {
                $this->statuses->transition($order, OrderStatus::DroppedOff, null);

                throw new RuntimeException('Something later failed.');
            });
        } catch (RuntimeException) {
            //
        }

        $this->assertFalse($announced);
        $this->assertSame(OrderStatus::Created, $order->fresh()?->status);
    }

    public function test_the_customer_is_notified_of_each_change()
    {
        Notification::fake();
        $order = Order::factory()->create();

        $this->statuses->transition($order, OrderStatus::DroppedOff, null);

        Notification::assertSentTo(
            $order->customer,
            OrderStatusUpdated::class,
            fn (OrderStatusUpdated $notification) => $notification->status === OrderStatus::DroppedOff,
        );
    }

    public function test_the_notification_is_queued_and_links_to_tracking()
    {
        $order = Order::factory()->create();
        $notification = new OrderStatusUpdated($order, OrderStatus::Created);

        $mail = $notification->toMail($order->customer);

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame("Parcel {$order->formatted_tracking_number}: Created", $mail->subject);
        $this->assertStringContainsString('/track?number='.$order->formatted_tracking_number, (string) $mail->actionUrl);
    }

    public function test_history_rows_are_append_only()
    {
        $order = Order::factory()->create();
        $event = $order->statusEvents()->firstOrFail();

        try {
            $event->update(['note' => 'Rewritten']);
            $this->fail('History rows must not be updated.');
        } catch (LogicException) {
            //
        }

        $this->expectException(LogicException::class);
        $event->delete();
    }

    public function test_the_exception_renders_a_conflict_for_json_clients()
    {
        $request = Request::create('/staff/orders/1/payment', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);

        $response = InvalidStatusTransition::between(OrderStatus::Paid, OrderStatus::Paid)->render($request);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString('cannot be changed to', (string) $response->getContent());
    }

    public function test_the_exception_sends_inertia_pages_back_with_an_error()
    {
        $this->startSession();
        $request = Request::create('/staff/orders/1/payment', 'POST', server: ['HTTP_X_INERTIA' => 'true']);
        $request->setLaravelSession($this->app['session.store']);

        $response = (new InvalidStatusTransition('Already paid.'))->render($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Already paid.', session('errors')?->first('status'));
    }

    public function test_the_first_history_entry_is_recorded_on_creation()
    {
        $customer = User::factory()->create();
        $order = Order::factory()->for($customer, 'customer')->make();
        $order->save();

        $this->statuses->recordCreation($order, $customer);

        $event = OrderStatusEvent::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertNull($event->from_status);
        $this->assertSame(OrderStatus::Created, $event->to_status);
        $this->assertSame($customer->id, $event->actor_id);
    }
}
