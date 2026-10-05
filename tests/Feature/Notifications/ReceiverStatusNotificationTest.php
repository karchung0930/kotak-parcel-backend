<?php

namespace Tests\Feature\Notifications;

use App\Actions\Delivery\AssignDriver;
use App\Actions\Delivery\MarkPickedUp;
use App\Actions\Delivery\RecordDeliveryFailure;
use App\Actions\Delivery\RecordDeliverySuccess;
use App\Actions\Delivery\ReturnToSender;
use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\RecordDropOff;
use App\Actions\Payments\RecordPayment;
use App\Actions\Settings\UpdateSettings;
use App\Enums\DeliveryFailureReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderStatusUpdated;
use App\Notifications\ReceiverStatusUpdated;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Markdown;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class ReceiverStatusNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Kuala_Lumpur'));
        $this->admin = User::factory()->admin()->create();
        $this->today = CarbonImmutable::parse('2026-10-05', 'Asia/Kuala_Lumpur');
    }

    public function test_the_receiver_is_emailed_from_dispatch_to_delivery()
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
            'receiver_email' => 'daniel@example.com',
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

        // Nothing before a delivery day is set.
        Notification::assertSentOnDemandTimes(ReceiverStatusUpdated::class, 0);

        $this->assign($order, $driver, $this->today->addDay());
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00', 'Asia/Kuala_Lumpur'));
        $this->actingAs($driver)->post(route('driver.jobs.pickup', $order))->assertSessionHasNoErrors();
        $this->actingAs($driver)->post(route('driver.jobs.deliver', $order), [
            'recipient_name' => 'Mei Ling (neighbour)',
            'photo' => UploadedFile::fake()->image('door.png'),
        ])->assertSessionHasNoErrors();

        $this->assertSame([
            [OrderStatus::Assigned, OrderStatus::Paid, '2026-10-06'],
            [OrderStatus::PickedUp, OrderStatus::Assigned, null],
            [OrderStatus::Delivered, OrderStatus::PickedUp, null],
        ], $this->receiverUpdates('daniel@example.com'));
        Notification::assertSentOnDemandTimes(ReceiverStatusUpdated::class, 3);
        $this->assertSame('Mei Ling (neighbour)', Notification::sent(new AnonymousNotifiable, ReceiverStatusUpdated::class)[2]->receivedBy);
    }

    public function test_a_failed_attempt_another_try_and_the_return_to_the_sender_are_emailed()
    {
        Notification::fake();
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->pickedUp($driver)->create(['receiver_email' => 'daniel@example.com']);

        $order = app(RecordDeliveryFailure::class)->handle($order, $driver, DeliveryFailureReason::RecipientUnavailable, 'Gate locked, no answer.');
        $this->assign($order, $driver, $this->today->addDays(2));

        $updates = Notification::sent(new AnonymousNotifiable, ReceiverStatusUpdated::class);
        $this->assertCount(2, $updates);
        $this->assertSame(OrderStatus::DeliveryFailed, $updates[0]->status);
        $this->assertSame(DeliveryFailureReason::RecipientUnavailable, $updates[0]->failureReason);
        // One of the 3 attempts allowed: another day will be arranged.
        $this->assertFalse($updates[0]->lastAttempt);
        $this->assertSame(OrderStatus::Assigned, $updates[1]->status);
        $this->assertSame(OrderStatus::DeliveryFailed, $updates[1]->from);
        $this->assertSame('2026-10-07', $updates[1]->deliveryDate?->toDateString());

        // The last attempt allowed fails, and the parcel goes back.
        app(UpdateSettings::class)->handle($this->admin, ['max_failed_attempts' => 2]);
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00', 'Asia/Kuala_Lumpur'));
        $order = app(MarkPickedUp::class)->handle($order, $driver);
        $order = app(RecordDeliveryFailure::class)->handle($order, $driver, DeliveryFailureReason::NoAccess);
        app(ReturnToSender::class)->handle($order, $this->admin);

        $this->assertSame([
            [OrderStatus::DeliveryFailed, OrderStatus::PickedUp, null],
            [OrderStatus::Assigned, OrderStatus::DeliveryFailed, '2026-10-07'],
            [OrderStatus::PickedUp, OrderStatus::Assigned, null],
            [OrderStatus::DeliveryFailed, OrderStatus::PickedUp, null],
            [OrderStatus::ReturnedToSender, OrderStatus::DeliveryFailed, null],
        ], $this->receiverUpdates('daniel@example.com'));

        $lastFailure = Notification::sent(new AnonymousNotifiable, ReceiverStatusUpdated::class)[3];
        $this->assertSame(DeliveryFailureReason::NoAccess, $lastFailure->failureReason);
        $this->assertTrue($lastFailure->lastAttempt);
    }

    public function test_steps_before_dispatch_and_cancellations_are_not_emailed()
    {
        Notification::fake();
        $staff = User::factory()->staff()->create();

        $paid = Order::factory()->create(['receiver_email' => 'daniel@example.com']);
        $paid = app(RecordDropOff::class)->handle($paid, $staff, 1200);
        app(RecordPayment::class)->handle($paid, $staff, PaymentMethod::Cash, (int) $paid->final_price_sen);

        $cancelled = Order::factory()->create(['receiver_email' => 'daniel@example.com']);
        app(CancelOrder::class)->handle($cancelled, $cancelled->customer, 'Sent it by hand instead.');

        Notification::assertSentOnDemandTimes(ReceiverStatusUpdated::class, 0);
        // The customer still hears about every step.
        Notification::assertSentTimes(OrderStatusUpdated::class, 3);
    }

    public function test_handing_a_delivery_to_another_driver_on_the_same_day_is_not_emailed_but_a_new_day_is()
    {
        Notification::fake();
        $order = Order::factory()->assigned()->create(['receiver_email' => 'daniel@example.com']);

        $order = $this->assign($order, User::factory()->driver()->create(), $this->today);
        Notification::assertSentOnDemandTimes(ReceiverStatusUpdated::class, 0);

        $this->assign($order, User::factory()->driver()->create(), $this->today->addDays(3));

        $this->assertSame([[OrderStatus::Assigned, OrderStatus::Assigned, '2026-10-08']], $this->receiverUpdates('daniel@example.com'));
    }

    public function test_nothing_is_sent_without_a_receiver_email()
    {
        Notification::fake();
        Storage::fake('local');
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->paid()->create(['receiver_email' => null]);

        $order = $this->assign($order, $driver, $this->today);
        $order = app(MarkPickedUp::class)->handle($order, $driver);
        app(RecordDeliverySuccess::class)->handle($order, $driver, 'Daniel Lim', UploadedFile::fake()->image('door.png'));

        Notification::assertSentOnDemandTimes(ReceiverStatusUpdated::class, 0);
        Notification::assertSentTo($order->customer, OrderStatusUpdated::class);
    }

    public function test_receiver_emails_can_be_switched_off()
    {
        Notification::fake();
        $order = Order::factory()->paid()->create(['receiver_email' => 'daniel@example.com']);
        $queued = new ReceiverStatusUpdated($order, OrderStatus::PickedUp, OrderStatus::Assigned);

        config(['kotak.receiver_emails' => false]);
        $this->assign($order, User::factory()->driver()->create(), $this->today);

        Notification::assertSentOnDemandTimes(ReceiverStatusUpdated::class, 0);
        Notification::assertSentTo($order->customer, OrderStatusUpdated::class);
        // Nor does an email queued before the switch go out.
        $this->assertFalse($queued->shouldSend($this->receiver(), 'mail'));
    }

    public function test_the_customers_own_address_is_not_emailed_twice()
    {
        Notification::fake();
        $customer = User::factory()->create(['email' => 'aisyah@example.com']);
        $order = Order::factory()->for($customer, 'customer')->paid()->create(['receiver_email' => 'Aisyah@Example.com']);

        $this->assign($order, User::factory()->driver()->create(), $this->today);

        // The customer's own email about it is enough.
        Notification::assertSentOnDemandTimes(ReceiverStatusUpdated::class, 0);
        Notification::assertSentToTimes($customer, OrderStatusUpdated::class, 1);

        // Until the customer confirms that address, only the receiver's email goes to it.
        $unverified = User::factory()->unverified()->create(['email' => 'jason@example.com']);
        $other = Order::factory()->for($unverified, 'customer')->paid()->create(['receiver_email' => 'jason@example.com']);
        $this->assign($other, User::factory()->driver()->create(), $this->today);

        $this->assertSame([[OrderStatus::Assigned, OrderStatus::Paid, '2026-10-05']], $this->receiverUpdates('jason@example.com'));
        Notification::assertNotSentTo($unverified, OrderStatusUpdated::class);
    }

    public function test_the_email_is_queued_on_its_own()
    {
        Queue::fake();
        $order = Order::factory()->paid()->create(['receiver_email' => 'daniel@example.com']);

        $this->assign($order, User::factory()->driver()->create(), $this->today);

        Queue::assertPushed(
            SendQueuedNotifications::class,
            fn (SendQueuedNotifications $job) => $job->notification instanceof ReceiverStatusUpdated
                && $job->tries === 3
                && $job->notifiables->first()?->routes === ['mail' => 'daniel@example.com'],
        );
    }

    public function test_a_queued_delivery_day_is_dropped_once_the_delivery_moved()
    {
        Notification::fake();
        $order = Order::factory()->assigned()->create(['receiver_email' => 'daniel@example.com']);
        $queued = new ReceiverStatusUpdated($order, OrderStatus::Assigned, OrderStatus::Paid, $this->today);
        $this->assertTrue($this->sendsWhenItsTurnComes($queued));

        $this->assign($order, User::factory()->driver()->create(), $this->today->addDay());

        // The move sends its own email, with the new day.
        $this->assertFalse($this->sendsWhenItsTurnComes($queued));
        $this->assertSame([[OrderStatus::Assigned, OrderStatus::Assigned, '2026-10-06']], $this->receiverUpdates('daniel@example.com'));
    }

    public function test_only_the_latest_delivery_day_goes_out_when_the_day_moves_and_moves_back()
    {
        Notification::fake();
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->paid()->create(['receiver_email' => 'daniel@example.com']);

        // Tuesday, then Wednesday, then Tuesday again, before the worker sends any.
        $order = $this->assign($order, $driver, $this->today->addDay());
        $order = $this->assign($order, $driver, $this->today->addDays(2));
        $order = $this->assign($order, $driver, $this->today->addDay());

        $queued = Notification::sent(new AnonymousNotifiable, ReceiverStatusUpdated::class);
        $this->assertSame([
            [OrderStatus::Assigned, OrderStatus::Paid, '2026-10-06'],
            [OrderStatus::Assigned, OrderStatus::Assigned, '2026-10-07'],
            [OrderStatus::Assigned, OrderStatus::Assigned, '2026-10-06'],
        ], $this->receiverUpdates('daniel@example.com'));
        $this->assertSame([false, false, true], $queued->map($this->sendsWhenItsTurnComes(...))->all());

        // A same-day driver swap sends nothing, so the latest day still goes out.
        $this->assign($order, User::factory()->driver()->create(), $this->today->addDay());
        $this->assertTrue($this->sendsWhenItsTurnComes($queued[2]));
    }

    public function test_news_of_a_step_is_dropped_once_the_parcel_has_moved_on()
    {
        Notification::fake();
        Storage::fake('local');
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->paid()->create(['receiver_email' => 'daniel@example.com']);

        $order = $this->assign($order, $driver, $this->today);
        $order = app(MarkPickedUp::class)->handle($order, $driver);
        [$deliveryDay, $outForDelivery] = Notification::sent(new AnonymousNotifiable, ReceiverStatusUpdated::class)->all();

        // Picked up before the delivery day's email went out: that day is past news.
        $this->assertFalse($this->sendsWhenItsTurnComes($deliveryDay));
        $this->assertTrue($this->sendsWhenItsTurnComes($outForDelivery));

        // Delivered before "out for delivery today" went out: only the outcome goes.
        app(RecordDeliverySuccess::class)->handle($order, $driver, 'Daniel Lim', UploadedFile::fake()->image('door.png'));
        $delivered = Notification::sent(new AnonymousNotifiable, ReceiverStatusUpdated::class)->last();

        $this->assertFalse($this->sendsWhenItsTurnComes($outForDelivery));
        $this->assertTrue($this->sendsWhenItsTurnComes($delivered));
    }

    public function test_a_failure_is_still_sent_after_the_next_day_is_set()
    {
        Notification::fake();
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->pickedUp($driver)->create(['receiver_email' => 'daniel@example.com']);

        $order = app(RecordDeliveryFailure::class)->handle($order, $driver, DeliveryFailureReason::NoAccess);
        $this->assign($order, $driver, $this->today->addDay());

        [$failure, $nextDay] = Notification::sent(new AnonymousNotifiable, ReceiverStatusUpdated::class)->all();
        $this->assertTrue($this->sendsWhenItsTurnComes($failure));
        $this->assertTrue($this->sendsWhenItsTurnComes($nextDay));
    }

    public function test_the_delivery_day_email_names_the_sender_and_the_day_and_links_to_public_tracking()
    {
        $order = $this->danielsParcel();
        $trackingUrl = route('track', ['number' => 'KT-7Q4M92XD']);

        $mail = $this->mail($order, OrderStatus::Assigned, OrderStatus::Paid, CarbonImmutable::parse('2026-10-06'));

        // The sender's name, the customer's own text, stays out of the subject.
        $this->assertSame('Parcel KT-7Q4M92XD: delivery on Tuesday, 6 October', $mail->subject);
        // Not the receiver's name either, in case the customer mistyped the address.
        $this->assertSame('Hello,', $mail->greeting);
        $this->assertSame([
            "A parcel from Aisyah Rahman is on its way to you with Kotak, tracking number **KT\u{2011}7Q4M92XD**.",
            "It is scheduled for delivery on **Tuesday, 6\u{00A0}October\u{00A0}2026**.",
            'Please make sure someone is at the address to receive it.',
        ], $mail->introLines);
        $this->assertSame('Track your parcel', $mail->actionText);
        $this->assertSame($trackingUrl, $mail->actionUrl);
        $this->assertSame([
            'You are getting this email because the sender gave your email address for updates on this delivery.',
            "If this parcel is not for you, or you no longer want these updates, you can [stop these emails]({$order->stopReceiverEmailsUrl()}).",
        ], array_map(strval(...), $mail->outroLines));

        $html = (string) $mail->render();
        $this->assertStringContainsString($trackingUrl, $html);
        $this->assertStringContainsString('>stop these emails</a>', $html);
    }

    public function test_a_moved_day_and_another_try_give_the_new_day()
    {
        $order = $this->danielsParcel();

        $moved = $this->mail($order, OrderStatus::Assigned, OrderStatus::Assigned, CarbonImmutable::parse('2026-10-07'));
        $retry = $this->mail($order, OrderStatus::Assigned, OrderStatus::DeliveryFailed, CarbonImmutable::parse('2026-10-08'));

        $this->assertSame('Parcel KT-7Q4M92XD: delivery moved to Wednesday, 7 October', $moved->subject);
        // It also reads well as the first email, when the earlier day was dropped unsent.
        $this->assertSame([
            "The delivery day of your parcel **KT\u{2011}7Q4M92XD** from Aisyah Rahman has changed. It is now scheduled for **Wednesday, 7\u{00A0}October\u{00A0}2026**.",
            'Please make sure someone is at the address to receive it.',
        ], $moved->introLines);
        $this->assertSame('Parcel KT-7Q4M92XD: next delivery attempt on Thursday, 8 October', $retry->subject);
        $this->assertSame([
            "We will try again to deliver your parcel **KT\u{2011}7Q4M92XD** from Aisyah Rahman on **Thursday, 8\u{00A0}October\u{00A0}2026**.",
            'Please make sure someone is at the address to receive it.',
        ], $retry->introLines);
    }

    public function test_out_for_delivery_delivered_and_returned_emails()
    {
        $order = $this->danielsParcel();

        $pickedUp = $this->mail($order, OrderStatus::PickedUp, OrderStatus::Assigned);
        $delivered = $this->mail($order, OrderStatus::Delivered, OrderStatus::PickedUp, receivedBy: 'Mei Ling (neighbour)');
        $deliveredToNoName = $this->mail($order, OrderStatus::Delivered, OrderStatus::PickedUp);
        $returned = $this->mail($order, OrderStatus::ReturnedToSender, OrderStatus::DeliveryFailed);

        $this->assertSame('Parcel KT-7Q4M92XD: out for delivery today', $pickedUp->subject);
        $this->assertSame([
            "Your parcel **KT\u{2011}7Q4M92XD** from Aisyah Rahman is out for delivery today.",
            'Please make sure someone is at the address to receive it.',
        ], $pickedUp->introLines);
        $this->assertSame('Parcel KT-7Q4M92XD: delivered', $delivered->subject);
        // Who took it, as the driver recorded it, in case it was a neighbour or a guard.
        $this->assertSame([
            "Your parcel **KT\u{2011}7Q4M92XD** from Aisyah Rahman has been delivered.",
            'It was received by Mei Ling (neighbour).',
        ], $delivered->introLines);
        $this->assertSame(["Your parcel **KT\u{2011}7Q4M92XD** from Aisyah Rahman has been delivered."], $deliveredToNoName->introLines);
        $this->assertSame('Parcel KT-7Q4M92XD: returned to the sender', $returned->subject);
        $this->assertSame([
            "Your parcel **KT\u{2011}7Q4M92XD** from Aisyah Rahman could not be delivered and has been returned to the sender.",
            'If you still need it, please get in touch with Aisyah Rahman.',
        ], $returned->introLines);
    }

    public function test_a_failed_attempt_gives_the_reason_and_what_happens_next()
    {
        $order = $this->danielsParcel();

        $retry = $this->mail($order, OrderStatus::DeliveryFailed, OrderStatus::PickedUp, failureReason: DeliveryFailureReason::AddressNotFound);
        $last = $this->mail($order, OrderStatus::DeliveryFailed, OrderStatus::PickedUp, failureReason: DeliveryFailureReason::RecipientRefused, lastAttempt: true);
        $other = $this->mail($order, OrderStatus::DeliveryFailed, OrderStatus::PickedUp, failureReason: DeliveryFailureReason::Other);

        $this->assertSame('Parcel KT-7Q4M92XD: we could not deliver it', $retry->subject);
        // An admin may still return the parcel before the last attempt, so no new day is promised.
        $this->assertSame([
            "We tried to deliver your parcel **KT\u{2011}7Q4M92XD** from Aisyah Rahman, but could not.",
            'Reason: **Address could not be found**.',
            'We will email you when the next delivery day is set, or if the parcel has to go back to the sender.',
        ], $retry->introLines);
        $this->assertSame([
            "We tried to deliver your parcel **KT\u{2011}7Q4M92XD** from Aisyah Rahman, but could not.",
            'Reason: **Recipient refused the parcel**.',
            'That was the last delivery attempt, so the parcel will go back to the sender.',
        ], $last->introLines);
        // "Other reason" tells the receiver nothing.
        $this->assertSame([
            "We tried to deliver your parcel **KT\u{2011}7Q4M92XD** from Aisyah Rahman, but could not.",
            'We will email you when the next delivery day is set, or if the parcel has to go back to the sender.',
        ], $other->introLines);
    }

    public function test_receiver_emails_carry_no_private_details()
    {
        $order = $this->danielsParcel();
        $day = CarbonImmutable::parse('2026-10-06');

        $emails = [
            $this->mail($order, OrderStatus::Assigned, OrderStatus::Paid, $day),
            $this->mail($order, OrderStatus::Assigned, OrderStatus::Assigned, $day),
            $this->mail($order, OrderStatus::Assigned, OrderStatus::DeliveryFailed, $day),
            $this->mail($order, OrderStatus::PickedUp, OrderStatus::Assigned),
            $this->mail($order, OrderStatus::Delivered, OrderStatus::PickedUp),
            $this->mail($order, OrderStatus::DeliveryFailed, OrderStatus::PickedUp, failureReason: DeliveryFailureReason::Other),
            $this->mail($order, OrderStatus::ReturnedToSender, OrderStatus::DeliveryFailed),
        ];

        foreach ($emails as $mail) {
            $html = (string) $mail->render();
            $this->assertStringContainsString("KT\u{2011}7Q4M92XD", $html);
            $this->assertStringContainsString('Aisyah Rahman', $html);

            foreach ([
                '+60123456789', '123456789', 'aisyah@example.com', 'Daniel Lim', 'daniel@example.com', '+60127788990',
                '127788990', 'Jalan Datuk Sulaiman', 'Taman Tun Dr Ismail', 'Ceramic dinner set', 'Ravi Kumar', 'WXA 1234',
                'RM', 'Gate locked',
            ] as $private) {
                $this->assertStringNotContainsString($private, $html."\n".$mail->subject);
            }
        }
    }

    public function test_names_cannot_add_markup_or_links_to_the_email()
    {
        $order = $this->danielsParcel();
        // Account names are free text (older ones, from before PersonName).
        $order->forceFill([
            'sender_name' => "Aisyah [Sign in](https://evil.example/in) ![](https://evil.example/p) <b>now</b> | www.evil.example\n\n# URGENT pay at evil.example",
        ]);

        // As in production, where view:cache compiles the mail views before
        // any email is sent, with plain HTML escaping only.
        $compiler = app('view')->getEngineResolver()->resolve('blade')->getCompiler();
        $view = app('view')->getFinder()->find('notifications::email');
        $compiler->compile($view);

        try {
            $mail = $this->mail($order, OrderStatus::Delivered, OrderStatus::PickedUp, receivedBy: 'Mei <i>Ling</i> https://evil.example/r');
            $html = (string) $mail->render();
            $text = $this->sentText($mail);

            $this->assertSame('Parcel KT-7Q4M92XD: delivered', $mail->subject);
            $this->assertStringContainsString('from Aisyah Sign in ( ! ( b now /b # URGENT pay at evil. example has been delivered', $html);
            $this->assertStringContainsString('It was received by Mei i Ling /i.', $html);

            foreach ([$html, $text] as $body) {
                $this->assertStringNotContainsString('evil.example', $body);
                $this->assertStringNotContainsString('https://evil', $body);
            }

            $this->assertStringNotContainsString('<img', $html);
            $this->assertStringNotContainsString('<b>now', $html);
            $this->assertStringNotContainsString('<i>Ling', $html);
            $this->assertStringNotContainsString('<h1>URGENT', $html);
        } finally {
            // The next email compiles the view again, as in the other tests.
            @unlink($compiler->getCompiledPath($view));
        }
    }

    public function test_a_long_sender_name_is_cut_short()
    {
        $order = $this->danielsParcel();
        $order->forceFill(['sender_name' => 'Kotak Customs Department Malaysia: your parcel is held, pay the RM2.50 duty today']);

        $mail = $this->mail($order, OrderStatus::PickedUp, OrderStatus::Assigned);

        $this->assertSame(
            "Your parcel **KT\u{2011}7Q4M92XD** from Kotak Customs Department Malaysia: your parcel is held,… is out for delivery today.",
            $mail->introLines[0],
        );
    }

    public function test_a_name_with_nothing_left_still_reads()
    {
        $order = $this->danielsParcel();
        $order->forceFill(['sender_name' => '[ ] https://evil.example']);

        $mail = $this->mail($order, OrderStatus::Delivered, OrderStatus::PickedUp, receivedBy: '<>');

        $this->assertSame('Parcel KT-7Q4M92XD: delivered', $mail->subject);
        $this->assertSame(["Your parcel **KT\u{2011}7Q4M92XD** from the sender has been delivered."], $mail->introLines);
    }

    public function test_the_email_is_delivered_to_the_receiver_address_with_an_unsubscribe_link()
    {
        $order = Order::factory()->paid()->create(['receiver_email' => 'daniel@example.com', 'sender_name' => 'Aisyah Rahman']);

        $this->assign($order, User::factory()->driver()->create(), $this->today->addDay());

        $sent = [];

        foreach ($this->sentMail() as $email) {
            $sent[$email->getTo()[0]->getAddress()] = $email;
        }

        $email = $sent['daniel@example.com'] ?? null;
        $this->assertNotNull($email);
        $this->assertSame("Parcel {$order->formatted_tracking_number}: delivery on Tuesday, 6 October", $email->getSubject());
        // Mail apps show their own unsubscribe button, which stops the emails in one click.
        $this->assertSame("<{$order->stopReceiverEmailsUrl()}>", $email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());
        $this->assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());
    }

    public function test_reserved_test_addresses_are_never_sent_to()
    {
        $order = Order::factory()->paid()->create(['receiver_email' => 'daniel.lim@kotak.test']);

        $this->assign($order, User::factory()->driver()->create(), $this->today->addDay());

        $recipients = array_map(fn (Email $email) => $email->getTo()[0]->getAddress(), $this->sentMail());
        $this->assertContains($order->customer->email, $recipients);
        $this->assertNotContains('daniel.lim@kotak.test', $recipients);
    }

    /**
     * Get the receiver updates sent on demand to the address, in order, as
     * [status, the status before it, the delivery day].
     *
     * @return list<array{OrderStatus, OrderStatus|null, string|null}>
     */
    private function receiverUpdates(string $address): array
    {
        return Notification::sent(
            new AnonymousNotifiable,
            ReceiverStatusUpdated::class,
            fn (ReceiverStatusUpdated $notification, array $channels, AnonymousNotifiable $notifiable) => $channels === ['mail']
                && $notifiable->routes === ['mail' => $address],
        )->map(fn (ReceiverStatusUpdated $notification) => [
            $notification->status,
            $notification->from,
            $notification->deliveryDate?->toDateString(),
        ])->values()->all();
    }

    /**
     * Determine if a queued email would still go out when the worker gets to
     * it: the queue stores it and restores it with the order as it is now.
     */
    private function sendsWhenItsTurnComes(ReceiverStatusUpdated $notification): bool
    {
        $queued = unserialize(serialize($notification));
        $this->assertInstanceOf(ReceiverStatusUpdated::class, $queued);

        return $queued->shouldSend($this->receiver(), 'mail');
    }

    /**
     * Get Daniel, the receiver, as the on-demand notifiable.
     */
    private function receiver(): AnonymousNotifiable
    {
        return (new AnonymousNotifiable)->route('mail', 'daniel@example.com');
    }

    /**
     * Build the receiver's email for the update.
     */
    private function mail(
        Order $order,
        OrderStatus $status,
        ?OrderStatus $from = null,
        ?CarbonInterface $deliveryDate = null,
        ?DeliveryFailureReason $failureReason = null,
        bool $lastAttempt = false,
        ?string $receivedBy = null,
    ): MailMessage {
        return (new ReceiverStatusUpdated($order, $status, $from, $deliveryDate, $failureReason, $lastAttempt, $receivedBy))
            ->toMail((new AnonymousNotifiable)->route('mail', (string) $order->receiver_email));
    }

    /**
     * Get the plain-text part of the email as sent.
     */
    private function sentText(MailMessage $mail): string
    {
        return (string) app(Markdown::class)->renderText($mail->markdown, $mail->data());
    }

    /**
     * Get the emails sent through the test "array" mailer.
     *
     * @return list<Email>
     */
    private function sentMail(): array
    {
        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $emails = [];

        foreach ($transport->messages() as $message) {
            $email = $message->getOriginalMessage();
            $this->assertInstanceOf(Email::class, $email);
            $emails[] = $email;
        }

        return $emails;
    }

    /**
     * Assign the order as the admin would on Dispatch.
     */
    private function assign(Order $order, User $driver, CarbonInterface $date): Order
    {
        return app(AssignDriver::class)->handle($order, $this->admin, $driver, $date);
    }

    /**
     * Create Aisyah's parcel to Daniel, picked up by Ravi, with Daniel's email.
     */
    private function danielsParcel(): Order
    {
        $customer = User::factory()->create(['name' => 'Aisyah Rahman', 'email' => 'aisyah@example.com', 'phone' => '+60123456789']);
        $driver = User::factory()->driver()->create(['name' => 'Ravi Kumar', 'vehicle_plate' => 'WXA 1234']);

        return Order::factory()->for($customer, 'customer')->pickedUp($driver)->create([
            'tracking_number' => 'KT7Q4M92XD',
            'receiver_name' => 'Daniel Lim',
            'receiver_phone' => '+60127788990',
            'receiver_email' => 'daniel@example.com',
            'address_line1' => 'No. 12, Jalan Datuk Sulaiman 1',
            'address_line2' => 'Taman Tun Dr Ismail',
            'city' => 'Kuala Lumpur',
            'state' => 'Kuala Lumpur',
            'postcode' => '60000',
            'item_name' => 'Ceramic dinner set',
        ]);
    }
}
