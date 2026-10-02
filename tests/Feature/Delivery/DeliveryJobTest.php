<?php

namespace Tests\Feature\Delivery;

use App\Actions\Delivery\MarkPickedUp;
use App\Actions\Delivery\RecordDeliveryFailure;
use App\Actions\Delivery\RecordDeliverySuccess;
use App\Actions\Delivery\ReturnToSender;
use App\Enums\DeliveryFailureReason;
use App\Enums\DeliveryOutcome;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeliveryJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_the_assigned_driver_picks_up_the_parcel()
    {
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->assigned($driver)->create();

        $order = app(MarkPickedUp::class)->handle($order, $driver);

        $this->assertSame(OrderStatus::PickedUp, $order->status);
        $this->assertSame($driver->id, $order->latestStatusEvent()->firstOrFail()->actor_id);
    }

    public function test_other_drivers_cannot_act_on_the_job()
    {
        $order = Order::factory()->assigned()->create();
        $otherDriver = User::factory()->driver()->create();

        $this->expectException(AuthorizationException::class);

        app(MarkPickedUp::class)->handle($order, $otherDriver);
    }

    public function test_a_delivery_is_recorded_with_the_recipient_and_a_private_photo()
    {
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->pickedUp($driver)->create();

        $order = app(RecordDeliverySuccess::class)->handle(
            $order, $driver, ' Daniel Lim ', UploadedFile::fake()->image('door.png', 640, 480),
        );

        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertNotNull($order->delivered_at);

        $attempt = $order->latestAttempt()->firstOrFail();
        $this->assertSame(DeliveryOutcome::Delivered, $attempt->outcome);
        $this->assertSame('Daniel Lim', $attempt->recipient_name);
        $this->assertSame($driver->id, $attempt->driver_id);
        $this->assertMatchesRegularExpression("#^pod/{$order->id}/[0-9a-f-]{36}\.png$#", (string) $attempt->photo_path);
        Storage::disk('local')->assertExists((string) $attempt->photo_path);
    }

    public function test_the_photo_is_discarded_when_the_delivery_is_rejected()
    {
        $order = Order::factory()->pickedUp()->create();

        try {
            app(RecordDeliverySuccess::class)->handle(
                $order, User::factory()->driver()->create(), 'Daniel Lim', UploadedFile::fake()->image('door.png'),
            );
            $this->fail('Only the assigned driver may deliver.');
        } catch (AuthorizationException) {
            //
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, $order->deliveryAttempts()->count());
    }

    public function test_a_parcel_must_be_picked_up_before_it_is_delivered()
    {
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->assigned($driver)->create();

        try {
            app(RecordDeliverySuccess::class)->handle($order, $driver, 'Daniel Lim', UploadedFile::fake()->image('door.png'));
            $this->fail('An assigned parcel cannot jump to delivered.');
        } catch (InvalidStatusTransition) {
            //
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(OrderStatus::Assigned, $order->fresh()?->status);
    }

    public function test_a_failed_delivery_records_the_reason_and_keeps_the_note_private()
    {
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->pickedUp($driver)->create();

        $order = app(RecordDeliveryFailure::class)->handle(
            $order, $driver, DeliveryFailureReason::AddressNotFound, 'Unit number missing, guard turned me away.',
        );

        $this->assertSame(OrderStatus::DeliveryFailed, $order->status);
        $this->assertSame(1, $order->failedAttemptsCount());

        $attempt = $order->latestAttempt()->firstOrFail();
        $this->assertSame(DeliveryOutcome::Failed, $attempt->outcome);
        $this->assertSame(DeliveryFailureReason::AddressNotFound, $attempt->failure_reason);
        $this->assertSame('Unit number missing, guard turned me away.', $attempt->note);
        $this->assertSame('Address could not be found', $order->latestStatusEvent()->firstOrFail()->note);
    }

    public function test_other_drivers_cannot_report_a_failure()
    {
        $order = Order::factory()->pickedUp()->create();

        $this->expectException(AuthorizationException::class);

        app(RecordDeliveryFailure::class)->handle($order, User::factory()->driver()->create(), DeliveryFailureReason::Other);
    }

    public function test_admins_return_failed_deliveries_to_the_sender()
    {
        $order = Order::factory()->deliveryFailed()->create();

        $order = app(ReturnToSender::class)->handle($order, User::factory()->admin()->create());

        $this->assertSame(OrderStatus::ReturnedToSender, $order->status);
        $this->assertTrue($order->status->isFinal());
    }

    public function test_only_failed_deliveries_can_be_returned()
    {
        $order = Order::factory()->pickedUp()->create();

        $this->expectException(InvalidStatusTransition::class);

        app(ReturnToSender::class)->handle($order, User::factory()->admin()->create());
    }
}
