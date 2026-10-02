<?php

namespace Tests\Feature\Driver;

use App\Actions\Delivery\AssignDriver;
use App\Actions\Delivery\ReturnToSender;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A parcel that keeps failing: rescheduled until the attempt limit, then returned to the sender.
 */
class FailedDeliveryLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_each_failure_is_counted_until_the_parcel_must_be_returned_to_the_sender()
    {
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->paid()->create();

        foreach ([1, 2, 3] as $attempt) {
            $this->assign($order, $driver);
            $this->actingAs($driver)->post(route('driver.jobs.pickup', $order))->assertSessionHasNoErrors();
            $this->actingAs($driver)
                ->post(route('driver.jobs.fail', $order), ['reason' => 'recipient_unavailable'])
                ->assertSessionHasNoErrors();

            $this->assertSame($attempt, $order->failedAttemptsCount());
        }

        $order->refresh();
        $this->assertSame(OrderStatus::DeliveryFailed, $order->status);
        $this->assertFalse($order->canBeAssigned());

        try {
            $this->assign($order, $driver);
            $this->fail('A fourth delivery attempt must not be scheduled.');
        } catch (InvalidStatusTransition $e) {
            $this->assertStringContainsString('maximum of 3 delivery attempts', $e->getMessage());
        }

        $order = app(ReturnToSender::class)->handle($order, $this->admin);

        $this->assertSame(OrderStatus::ReturnedToSender, $order->status);
        $this->assertTrue($order->status->isFinal());

        // Nothing is left for the driver to do.
        $this->actingAs($driver)->post(route('driver.jobs.pickup', $order))->assertSessionHasErrors('status');
    }

    public function test_a_rescheduled_job_moves_to_the_new_driver()
    {
        $first = User::factory()->driver()->create();
        $second = User::factory()->driver()->create();
        $order = Order::factory()->deliveryFailed($first)->create();

        $this->assign($order, $second);

        $this->actingAs($first)->get(route('driver.jobs.show', $order))->assertForbidden();
        $this->actingAs($first)->post(route('driver.jobs.pickup', $order))->assertForbidden();

        $this->actingAs($second)->post(route('driver.jobs.pickup', $order))->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::PickedUp, $order->fresh()?->status);
    }

    /**
     * Schedule the delivery for today, as an admin does from the dispatch board.
     */
    private function assign(Order $order, User $driver): void
    {
        app(AssignDriver::class)->handle($order, $this->admin, $driver, today(config()->string('kotak.timezone')));
    }
}
