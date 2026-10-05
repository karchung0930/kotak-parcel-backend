<?php

namespace Tests\Feature\Notifications;

use App\Actions\Delivery\AssignDriver;
use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\RecordDropOff;
use App\Actions\Payments\RecordPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Events\OrderStatusChanged;
use App\Listeners\SendOrderStatusNotification;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderStatusUpdated;
use App\Services\OrderStatusService;
use App\Support\MailDate;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class OrderStatusNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_customer_is_emailed_at_every_step_of_the_journey()
    {
        Notification::fake();
        Storage::fake('local');

        $branch = Branch::factory()->create();
        $customer = User::factory()->create();
        $staff = User::factory()->staff($branch)->create();
        $driver = User::factory()->driver()->create();

        $order = app(CreateOrder::class)->handle($customer, [
            'branch_id' => $branch->id,
            'receiver_name' => 'Daniel Lim',
            'receiver_phone' => '+60127788990',
            'address_line1' => 'No. 12, Jalan Datuk Sulaiman 1',
            'city' => 'Kuala Lumpur',
            'state' => 'Kuala Lumpur',
            'postcode' => '60000',
            'item_name' => 'Ceramic dinner set',
            'declared_weight_g' => 4200,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 25,
        ]);
        $order = app(RecordDropOff::class)->handle($order, $staff, 4300);
        app(RecordPayment::class)->handle($order, $staff, PaymentMethod::Cash, (int) $order->final_price_sen);
        app(AssignDriver::class)->handle($order, User::factory()->admin()->create(), $driver, today(config()->string('kotak.timezone')));

        $this->actingAs($driver)->post(route('driver.jobs.pickup', $order))->assertSessionHasNoErrors();
        $this->actingAs($driver)->post(route('driver.jobs.deliver', $order), [
            'recipient_name' => 'Daniel Lim',
            'photo' => UploadedFile::fake()->image('door.png'),
        ])->assertSessionHasNoErrors();

        $statuses = Notification::sent($customer, OrderStatusUpdated::class)
            ->map(fn (OrderStatusUpdated $notification) => $notification->status)
            ->all();

        $this->assertSame([
            OrderStatus::Created,
            OrderStatus::DroppedOff,
            OrderStatus::Paid,
            OrderStatus::Assigned,
            OrderStatus::PickedUp,
            OrderStatus::Delivered,
        ], $statuses);

        Notification::assertNotSentTo([$staff, $driver], OrderStatusUpdated::class);
    }

    public function test_the_listener_runs_on_the_queue()
    {
        Queue::fake();
        $order = Order::factory()->create();

        app(OrderStatusService::class)->transition($order, OrderStatus::DroppedOff, null);

        Queue::assertPushed(CallQueuedListener::class, 1);
        Queue::assertPushed(
            CallQueuedListener::class,
            fn (CallQueuedListener $job) => $job->class === SendOrderStatusNotification::class && $job->tries === 3,
        );
    }

    public function test_customers_without_a_verified_email_are_not_emailed()
    {
        Queue::fake();
        $order = Order::factory()->for(User::factory()->unverified(), 'customer')->create();

        app(OrderStatusService::class)->transition($order, OrderStatus::DroppedOff, null);

        Queue::assertNothingPushed();
    }

    public function test_nothing_is_sent_when_a_change_is_rejected()
    {
        Notification::fake();
        Storage::fake('local');
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->assigned($driver)->create();

        $this->actingAs($driver)->post(route('driver.jobs.deliver', $order), [
            'recipient_name' => 'Daniel Lim',
            'photo' => UploadedFile::fake()->image('door.png'),
        ])->assertSessionHasErrors('status');

        Notification::assertNothingSent();
    }

    public function test_the_customer_is_told_the_new_date_when_a_delivery_is_rescheduled()
    {
        Notification::fake();
        $order = Order::factory()->assigned()->create(['scheduled_for' => today(config()->string('kotak.timezone'))->toDateString()]);
        $newDate = today(config()->string('kotak.timezone'))->addDays(2);

        app(AssignDriver::class)->handle($order, User::factory()->admin()->create(), User::factory()->driver()->create(), $newDate);

        Notification::assertSentTo($order->customer, OrderStatusUpdated::class, function (OrderStatusUpdated $notification) use ($newDate) {
            $mail = $notification->toMail($notification->order->customer);

            return $notification->status === OrderStatus::Assigned
                && $notification->rescheduled
                && $mail->subject === "Parcel {$notification->order->formatted_tracking_number}: Delivery Rescheduled"
                && in_array('Your delivery has been moved to a new date.', $mail->introLines, true)
                && in_array('New delivery date: '.MailDate::long($newDate).'.', $mail->introLines, true);
        });
        Notification::assertSentTimes(OrderStatusUpdated::class, 1);
    }

    public function test_handing_a_delivery_to_another_driver_on_the_same_day_is_not_emailed()
    {
        Notification::fake();
        $order = Order::factory()->assigned()->create(['scheduled_for' => today(config()->string('kotak.timezone'))->toDateString()]);

        app(AssignDriver::class)->handle(
            $order, User::factory()->admin()->create(), User::factory()->driver()->create(), today(config()->string('kotak.timezone')),
        );

        // Recorded in the history, but the customer's delivery day did not change (the drivers are told).
        $this->assertStringStartsWith('Reassigned to another driver', (string) $order->latestStatusEvent()->firstOrFail()->note);
        Notification::assertNotSentTo($order->customer, OrderStatusUpdated::class);
        Notification::assertSentTimes(OrderStatusUpdated::class, 0);
    }

    public function test_a_queued_copy_of_the_event_still_knows_a_new_day_from_a_driver_swap()
    {
        Event::fake([OrderStatusChanged::class]);
        $today = today(config()->string('kotak.timezone'));
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->assigned()->create(['scheduled_for' => $today->toDateString()]);

        $order = app(AssignDriver::class)->handle($order, $admin, User::factory()->driver()->create(), $today->addDay());
        app(AssignDriver::class)->handle($order, $admin, User::factory()->driver()->create(), $today->addDay());

        // A queued listener gets the event back with the order fresh from the
        // database, which no longer knows what its last save changed.
        $events = Event::dispatched(OrderStatusChanged::class)->map(fn (array $dispatched) => unserialize(serialize($dispatched[0])));

        $this->assertSame([false, true], $events->map(fn (OrderStatusChanged $event) => $event->isDriverSwap())->all());
        $this->assertFalse($events[0]->order->wasChanged('scheduled_for'));
    }

    public function test_the_email_names_the_status_explains_it_and_links_to_public_tracking()
    {
        $customer = User::factory()->create(['name' => 'Aisyah Rahman']);
        $order = Order::factory()->for($customer, 'customer')->pickedUp()->create(['tracking_number' => 'KT7Q4M92XD']);
        $trackingUrl = route('track', ['number' => 'KT-7Q4M92XD']);

        $mail = (new OrderStatusUpdated($order, OrderStatus::PickedUp))->toMail($customer);

        $this->assertSame("Parcel {$order->formatted_tracking_number}: Picked Up", $mail->subject);
        $this->assertSame('Hi Aisyah Rahman,', $mail->greeting);
        $this->assertContains("Status update for parcel **KT\u{2011}7Q4M92XD**: **Picked Up**", $mail->introLines);
        $this->assertContains('Your parcel is out for delivery.', $mail->introLines);
        $this->assertSame('Track your parcel', $mail->actionText);
        $this->assertSame($trackingUrl, $mail->actionUrl);
        $this->assertStringContainsString($trackingUrl, (string) $mail->render());
    }

    public function test_the_customers_name_cannot_add_markup_to_the_email()
    {
        // An account name from before names were checked.
        $customer = User::factory()->create(['name' => "Aisyah [Sign in](https://evil.example/in) <b>now</b> |\n\n# URGENT"]);
        $order = Order::factory()->for($customer, 'customer')->pickedUp()->create();

        // As in production, where view:cache compiles the mail views before
        // any email is sent, with plain HTML escaping only.
        $compiler = app('view')->getEngineResolver()->resolve('blade')->getCompiler();
        $view = app('view')->getFinder()->find('notifications::email');
        $compiler->compile($view);

        try {
            $html = (string) (new OrderStatusUpdated($order, OrderStatus::PickedUp))->toMail($customer)->render();

            $this->assertStringContainsString('Hi Aisyah Sign in (https://evil.example/in) b now /b # URGENT,', $html);
            $this->assertStringNotContainsString('href="https://evil.example', $html);
            $this->assertStringNotContainsString('<b>now', $html);
            $this->assertStringNotContainsString('<h1>URGENT', $html);
        } finally {
            // The next email compiles the view again, as in the other tests.
            @unlink($compiler->getCompiledPath($view));
        }
    }

    public function test_the_scheduled_date_is_included_once_a_driver_is_assigned()
    {
        $order = Order::factory()->assigned()->create(['scheduled_for' => '2026-10-01']);

        $mail = (new OrderStatusUpdated($order, OrderStatus::Assigned))->toMail($order->customer);

        $this->assertContains("Scheduled delivery date: Thursday, 1\u{00A0}October\u{00A0}2026.", $mail->introLines);
    }

    public function test_the_email_is_delivered_to_the_customer_address()
    {
        $order = Order::factory()->for(User::factory()->state(['email' => 'aisyah@example.com']), 'customer')->create();

        app(OrderStatusService::class)->transition($order, OrderStatus::DroppedOff, null);

        $messages = $this->sentMail();
        $this->assertCount(1, $messages);

        $email = $messages[0]->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('aisyah@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame("Parcel {$order->formatted_tracking_number}: Dropped Off", $email->getSubject());
        $this->assertStringContainsString(
            route('track', ['number' => $order->formatted_tracking_number]),
            (string) $email->getHtmlBody(),
        );
    }

    /**
     * Get the messages sent through the test "array" mailer.
     *
     * @return list<SentMessage>
     */
    private function sentMail(): array
    {
        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return array_values($transport->messages()->all());
    }
}
