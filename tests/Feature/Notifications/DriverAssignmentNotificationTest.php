<?php

namespace Tests\Feature\Notifications;

use App\Actions\Delivery\AssignDriver;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Notifications\DriverJobAssigned;
use App\Notifications\DriverJobRemoved;
use App\Notifications\OrderStatusUpdated;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class DriverAssignmentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CarbonImmutable $today;

    /**
     * How many queued emails runQueuedEmails() has run so far.
     */
    private int $ran = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Kuala_Lumpur'));
        $this->admin = User::factory()->admin()->create();
        $this->today = CarbonImmutable::parse('2026-10-05', 'Asia/Kuala_Lumpur');
    }

    public function test_the_driver_is_emailed_a_new_job()
    {
        Notification::fake();
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->paid()->create();

        $this->assign($order, $driver, $this->today->addDay());

        Notification::assertSentTo($driver, DriverJobAssigned::class, fn (DriverJobAssigned $notification) => $notification->order->is($order)
            && $notification->scheduledFor->toDateString() === '2026-10-06'
            && $notification->movedFrom === null);
        Notification::assertSentTimes(DriverJobAssigned::class, 1);
        Notification::assertSentTimes(DriverJobRemoved::class, 0);
    }

    public function test_a_driver_whose_delivery_moves_to_another_day_is_told_the_new_day()
    {
        Notification::fake();
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->assigned($driver)->create();

        $this->assign($order, $driver, $this->today->addDays(2));

        Notification::assertSentTo($driver, DriverJobAssigned::class, fn (DriverJobAssigned $notification) => $notification->scheduledFor->toDateString() === '2026-10-07'
            && $notification->movedFrom?->toDateString() === '2026-10-05');
        Notification::assertSentTimes(DriverJobAssigned::class, 1);
        Notification::assertNotSentTo($driver, DriverJobRemoved::class);
    }

    public function test_reassigning_emails_the_new_driver_and_tells_the_previous_one_it_left_their_run()
    {
        Notification::fake();
        $ravi = User::factory()->driver()->create();
        $wong = User::factory()->driver()->create();
        $order = Order::factory()->assigned($ravi)->create();

        $this->assign($order, $wong, $this->today->addDay());

        Notification::assertSentTo($wong, DriverJobAssigned::class, fn (DriverJobAssigned $notification) => $notification->scheduledFor->toDateString() === '2026-10-06'
            && $notification->movedFrom === null);
        // The day it was on Ravi's run, captured before the change was committed.
        Notification::assertSentTo($ravi, DriverJobRemoved::class, fn (DriverJobRemoved $notification) => $notification->order->is($order)
            && $notification->date->toDateString() === '2026-10-05');
        Notification::assertNotSentTo($ravi, DriverJobAssigned::class);
        Notification::assertNotSentTo($wong, DriverJobRemoved::class);
    }

    public function test_a_same_day_reassignment_emails_both_drivers()
    {
        Notification::fake();
        $ravi = User::factory()->driver()->create();
        $wong = User::factory()->driver()->create();
        $order = Order::factory()->assigned($ravi)->create();

        $this->assign($order, $wong, $this->today);

        Notification::assertSentToTimes($wong, DriverJobAssigned::class, 1);
        Notification::assertSentTo($ravi, DriverJobRemoved::class, fn (DriverJobRemoved $notification) => $notification->date->toDateString() === '2026-10-05');
        Notification::assertSentTimes(DriverJobRemoved::class, 1);
        // The customer's delivery day did not change.
        Notification::assertNotSentTo($order->customer, OrderStatusUpdated::class);
    }

    public function test_a_reschedule_after_a_failed_delivery_is_a_new_job_and_the_driver_who_tried_hears_nothing()
    {
        Notification::fake();
        $ravi = User::factory()->driver()->create();
        $wong = User::factory()->driver()->create();
        $order = Order::factory()->deliveryFailed($ravi)->create();

        $this->assign($order, $wong, $this->today->addDay());

        Notification::assertSentTo($wong, DriverJobAssigned::class, fn (DriverJobAssigned $notification) => $notification->movedFrom === null);
        Notification::assertNothingSentTo($ravi);
    }

    public function test_a_failed_delivery_given_back_to_its_driver_is_a_new_job_even_on_the_same_day()
    {
        Notification::fake();
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->deliveryFailed($driver)->create();

        $this->assign($order, $driver, $this->today);

        Notification::assertSentTo($driver, DriverJobAssigned::class, fn (DriverJobAssigned $notification) => $notification->movedFrom === null);
        Notification::assertNotSentTo($driver, DriverJobRemoved::class);
    }

    public function test_nothing_is_sent_when_the_assignment_is_refused()
    {
        Notification::fake();
        $driver = User::factory()->driver()->create();
        $order = Order::factory()->assigned($driver)->create();

        foreach ([$driver, User::factory()->driver()->inactive()->create()] as $candidate) {
            try {
                $this->assign($order, $candidate, $this->today);
                $this->fail('The assignment must be refused.');
            } catch (ValidationException) {
                // Same driver on the same day, then an inactive driver.
            }
        }

        Notification::assertNothingSent();
    }

    public function test_nothing_is_sent_when_the_change_is_rolled_back()
    {
        Notification::fake();
        $order = Order::factory()->assigned()->create();

        try {
            DB::transaction(function () use ($order) {
                $this->assign($order, User::factory()->driver()->create(), $this->today->addDay());

                throw new RuntimeException('Something later in the same transaction failed.');
            });
        } catch (RuntimeException) {
            // Rolled back.
        }

        Notification::assertNothingSent();
        $this->assertSame('2026-10-05', $order->fresh()?->scheduled_for?->toDateString());
    }

    public function test_drivers_without_a_verified_email_or_with_a_deactivated_account_are_not_emailed()
    {
        Notification::fake();
        $unverified = User::factory()->driver()->unverified()->create();
        $this->assign(Order::factory()->paid()->create(), $unverified, $this->today);

        // Jobs are handed over from a driver who has left.
        $leaver = User::factory()->driver()->create();
        $order = Order::factory()->assigned($leaver)->create();
        $leaver->forceFill(['is_active' => false])->save();
        $wong = User::factory()->driver()->create();
        $this->assign($order, $wong, $this->today);

        Notification::assertNothingSentTo($unverified);
        Notification::assertNothingSentTo($leaver);
        Notification::assertSentToTimes($wong, DriverJobAssigned::class, 1);
    }

    public function test_each_driver_email_is_queued_on_its_own()
    {
        Queue::fake();
        $order = Order::factory()->assigned()->create();

        $this->assign($order, User::factory()->driver()->create(), $this->today);

        foreach ([DriverJobAssigned::class, DriverJobRemoved::class] as $class) {
            Queue::assertPushed(
                SendQueuedNotifications::class,
                fn (SendQueuedNotifications $job) => $job->notification instanceof $class && $job->tries === 3,
            );
        }
    }

    public function test_the_new_job_email_gives_the_day_the_branch_and_the_area_and_links_to_the_job()
    {
        [$order, $driver] = $this->ravisJob();

        $mail = (new DriverJobAssigned($order, CarbonImmutable::parse('2026-10-06')))->toMail($driver);

        $this->assertSame("New delivery for Tuesday, 6 October: {$order->formatted_tracking_number}", $mail->subject);
        $this->assertSame('Hi Ravi Kumar,', $mail->greeting);
        $this->assertSame([
            "A delivery has been added to your round for **Tuesday, 6\u{00A0}October\u{00A0}2026**.",
            "Tracking number: **KT\u{2011}7Q4M92XD**",
            "Collect it from: **Petaling Jaya - SS2**, 12, Jalan SS 2/67, SS 2, 47300\u{00A0}Petaling\u{00A0}Jaya.",
            "Delivery area: **Kuala Lumpur\u{00A0}60000**",
        ], $mail->introLines);
        $this->assertSame('Open the job', $mail->actionText);
        $this->assertSame(route('driver.jobs.show', $order), $mail->actionUrl);
        $this->assertSame(["The receiver's name, address and phone number are on the job page."], $mail->outroLines);
    }

    public function test_the_moved_email_gives_both_days()
    {
        [$order, $driver] = $this->ravisJob();

        $mail = (new DriverJobAssigned($order, CarbonImmutable::parse('2026-10-07'), CarbonImmutable::parse('2026-10-05')))->toMail($driver);

        $this->assertSame("Delivery {$order->formatted_tracking_number} moved to Wednesday, 7 October", $mail->subject);
        $this->assertContains("A delivery on your round has moved from Monday, 5\u{00A0}October\u{00A0}2026 to **Wednesday, 7\u{00A0}October\u{00A0}2026**.", $mail->introLines);
        $this->assertSame(route('driver.jobs.show', $order), $mail->actionUrl);
    }

    public function test_the_removed_email_names_the_day_and_links_to_that_day_on_my_jobs()
    {
        [$order, $driver] = $this->ravisJob();

        $later = (new DriverJobRemoved($order, CarbonImmutable::parse('2026-10-08')))->toMail($driver);
        $today = (new DriverJobRemoved($order, CarbonImmutable::parse('2026-10-05')))->toMail($driver);

        $this->assertSame("Delivery {$order->formatted_tracking_number} removed from your round for Thursday, 8 October", $later->subject);
        $this->assertSame([
            "Delivery **KT\u{2011}7Q4M92XD** has been removed from your round for **Thursday, 8\u{00A0}October\u{00A0}2026**. You no longer need to collect\u{00A0}it.",
            "It was to be collected from Petaling Jaya - SS2 for delivery to Kuala Lumpur\u{00A0}60000.",
        ], $later->introLines);
        $this->assertSame('Open My jobs', $later->actionText);
        $this->assertSame(route('driver.jobs', ['date' => '2026-10-08']), $later->actionUrl);
        // Today's list (with any overdue jobs) needs no date.
        $this->assertSame("Delivery {$order->formatted_tracking_number} removed from your round for Monday, 5 October", $today->subject);
        $this->assertSame(route('driver.jobs'), $today->actionUrl);
    }

    public function test_an_overdue_job_taken_away_is_removed_from_the_list_the_driver_sees_today()
    {
        [$order, $driver] = $this->ravisJob();

        $mail = (new DriverJobRemoved($order, CarbonImmutable::parse('2026-10-02')))->toMail($driver);

        $this->assertSame("Delivery {$order->formatted_tracking_number} removed from your list", $mail->subject);
        $this->assertSame([
            "Delivery **KT\u{2011}7Q4M92XD**, carried over from Friday, 2\u{00A0}October\u{00A0}2026, has been removed from your list. You no longer need to collect\u{00A0}it.",
            "It was to be collected from Petaling Jaya - SS2 for delivery to Kuala Lumpur\u{00A0}60000.",
        ], $mail->introLines);
        // It was on today's list as carried over, so the link opens today's list.
        $this->assertSame(route('driver.jobs'), $mail->actionUrl);
    }

    public function test_driver_emails_carry_no_private_details()
    {
        [$order, $driver] = $this->ravisJob();
        $day = CarbonImmutable::parse('2026-10-06');

        $emails = [
            (new DriverJobAssigned($order, $day))->toMail($driver)->render(),
            (new DriverJobAssigned($order, $day, $day->subDay()))->toMail($driver)->render(),
            (new DriverJobRemoved($order, $day))->toMail($driver)->render(),
        ];

        foreach ($emails as $html) {
            $this->assertStringContainsString("KT\u{2011}7Q4M92XD", (string) $html);

            foreach (['Daniel Lim', '+60127788990', '127788990', 'Jalan Datuk Sulaiman', 'Taman Tun Dr Ismail', 'Aisyah', 'aisyah@example.com', '123456789', 'Ceramic dinner set'] as $private) {
                $this->assertStringNotContainsString($private, (string) $html);
            }
        }
    }

    public function test_a_queued_email_is_dropped_once_the_delivery_moved_on()
    {
        $ravi = User::factory()->driver()->create();
        $wong = User::factory()->driver()->create();
        $order = Order::factory()->assigned($ravi)->create();
        $this->assertTrue((new DriverJobAssigned($order, $this->today))->shouldSend($ravi, 'mail'));

        Notification::fake();
        $order = $this->assign($order, $wong, $this->today);

        // Queued for Ravi before the handover: no longer true, and Wong has his own email.
        $this->assertFalse((new DriverJobAssigned($order, $this->today))->shouldSend($ravi, 'mail'));
        $this->assertTrue((new DriverJobAssigned($order, $this->today))->shouldSend($wong, 'mail'));
        // Moved to another day before it went out: that move sends its own email.
        $this->assertFalse((new DriverJobAssigned($order, $this->today->addDay()))->shouldSend($wong, 'mail'));
    }

    public function test_a_queued_removal_is_dropped_once_the_delivery_is_back_on_the_round()
    {
        $ravi = User::factory()->driver()->create();
        $wong = User::factory()->driver()->create();
        $order = Order::factory()->assigned($ravi)->create();

        Notification::fake();
        $order = $this->assign($order, $wong, $this->today);
        $this->assertTrue((new DriverJobRemoved($order, $this->today))->shouldSend($ravi, 'mail'));

        // Handed back to Ravi before his "removed" email went out.
        $order = $this->assign($order, $ravi, $this->today);
        $this->assertFalse((new DriverJobRemoved($order, $this->today))->shouldSend($ravi, 'mail'));
        // Still sent for another day than the one it is back on.
        $this->assertTrue((new DriverJobRemoved($order, $this->today->addDay()))->shouldSend($ravi, 'mail'));
    }

    public function test_queued_emails_are_dropped_for_accounts_that_can_no_longer_be_emailed()
    {
        $ravi = User::factory()->driver()->create();
        $wong = User::factory()->driver()->create();
        $order = Order::factory()->assigned($wong)->create();
        $assigned = new DriverJobAssigned($order, $this->today);
        $removed = new DriverJobRemoved($order, $this->today);
        $this->assertTrue($assigned->shouldSend($wong, 'mail'));
        $this->assertTrue($removed->shouldSend($ravi, 'mail'));

        // Wong changes his address on Profile, which then waits for him to confirm it.
        $wong->forceFill(['email_verified_at' => null])->save();
        $this->assertFalse($assigned->shouldSend($wong, 'mail'));

        // Ravi's account is deactivated.
        $ravi->forceFill(['is_active' => false])->save();
        $this->assertFalse($removed->shouldSend($ravi, 'mail'));
    }

    public function test_a_driver_who_never_heard_of_a_job_is_not_told_it_was_removed()
    {
        Queue::fake();
        $ravi = User::factory()->driver()->create(['email' => 'ravi@example.com']);
        $wong = User::factory()->driver()->create(['email' => 'wong@example.com']);
        $order = Order::factory()->assigned($ravi)->create();

        // Handed to Wong and straight back to Ravi, before the queue worker ran.
        $this->assign($order, $wong, $this->today);
        $this->assign($order, $ravi, $this->today);

        // Wong never heard of it, so he is not told it was taken away either,
        // and Ravi's "removed" is dropped as it is back on his round.
        $this->assertSame([
            'ravi@example.com' => "New delivery for Monday, 5 October: {$order->formatted_tracking_number}",
        ], $this->runQueuedEmails());
    }

    public function test_a_driver_who_heard_of_a_job_is_told_it_was_removed()
    {
        Queue::fake();
        $ravi = User::factory()->driver()->create(['email' => 'ravi@example.com']);
        $wong = User::factory()->driver()->create(['email' => 'wong@example.com']);
        $order = Order::factory()->paid()->create();

        $this->assign($order, $ravi, $this->today);
        $this->assertSame(['ravi@example.com' => "New delivery for Monday, 5 October: {$order->formatted_tracking_number}"], $this->runQueuedEmails());

        $this->assign($order, $wong, $this->today);
        $this->assertSame([
            'wong@example.com' => "New delivery for Monday, 5 October: {$order->formatted_tracking_number}",
            'ravi@example.com' => "Delivery {$order->formatted_tracking_number} removed from your round for Monday, 5 October",
        ], $this->runQueuedEmails());
    }

    public function test_the_previous_driver_is_told_even_when_the_new_one_cannot_be_emailed()
    {
        Notification::fake();
        $ravi = User::factory()->driver()->create();
        $unverified = User::factory()->driver()->unverified()->create();
        $order = Order::factory()->assigned($ravi)->create();

        $this->assign($order, $unverified, $this->today->addDay());

        Notification::assertSentTo($ravi, DriverJobRemoved::class, fn (DriverJobRemoved $notification) => $notification->date->toDateString() === '2026-10-05');
        Notification::assertNothingSentTo($unverified);
    }

    public function test_a_city_cannot_add_markup_to_the_emails()
    {
        [$order, $driver] = $this->ravisJob();
        // Older orders, from before the city was checked for these characters.
        $order->forceFill(['city' => "Shah Alam [Sign in](https://evil.example/in) ![](https://evil.example/p) <b>now</b> |\n\n# URGENT"])->save();
        $day = CarbonImmutable::parse('2026-10-06');

        // As in production, where view:cache compiles the mail views before
        // any email is sent, with plain HTML escaping only.
        $compiler = app('view')->getEngineResolver()->resolve('blade')->getCompiler();
        $view = app('view')->getFinder()->find('notifications::email');
        $compiler->compile($view);

        try {
            foreach ([new DriverJobAssigned($order, $day), new DriverJobRemoved($order, $day)] as $notification) {
                $html = (string) $notification->toMail($driver)->render();

                $this->assertStringContainsString('Shah Alam Sign in (https://evil.example/in) ! (https://evil.example/p) b now /b # URGENT', $html);
                $this->assertStringNotContainsString('href="https://evil.example', $html);
                $this->assertStringNotContainsString('<img', $html);
                $this->assertStringNotContainsString('<b>now', $html);
                $this->assertStringNotContainsString('<h1>URGENT', $html);
            }
        } finally {
            // The next email compiles the view again, as in the other tests.
            @unlink($compiler->getCompiledPath($view));
        }
    }

    public function test_the_drivers_name_and_the_branch_cannot_add_markup_to_the_emails()
    {
        [$order, $driver] = $this->ravisJob();
        $markup = "[Sign in](https://evil.example/in) ![](https://evil.example/p) <b>now</b> |\n\n# URGENT";
        // Typed by an admin: the driver's account and the branch.
        $driver->forceFill(['name' => "Ravi {$markup}"])->save();
        $order->branch->forceFill(['name' => "SS2 {$markup}", 'address' => "12, Jalan SS 2/67 {$markup}"])->save();
        $day = CarbonImmutable::parse('2026-10-06');
        $plain = 'Sign in (https://evil.example/in) ! (https://evil.example/p) b now /b # URGENT';

        // As in production, where view:cache compiles the mail views before
        // any email is sent, with plain HTML escaping only.
        $compiler = app('view')->getEngineResolver()->resolve('blade')->getCompiler();
        $view = app('view')->getFinder()->find('notifications::email');
        $compiler->compile($view);

        try {
            $assigned = (string) (new DriverJobAssigned($order, $day))->toMail($driver)->render();
            $removed = (string) (new DriverJobRemoved($order, $day))->toMail($driver)->render();

            $this->assertStringContainsString("SS2 {$plain}</strong>, 12, Jalan SS 2/67 {$plain}, 47300\u{00A0}Petaling\u{00A0}Jaya.", $assigned);
            $this->assertStringContainsString("It was to be collected from SS2 {$plain} for delivery", $removed);

            foreach ([$assigned, $removed] as $html) {
                $this->assertStringContainsString("Hi Ravi {$plain},", $html);
                $this->assertStringNotContainsString('href="https://evil.example', $html);
                $this->assertStringNotContainsString('<img', $html);
                $this->assertStringNotContainsString('<b>now', $html);
                $this->assertStringNotContainsString('<h1>URGENT', $html);
            }
        } finally {
            // The next email compiles the view again, as in the other tests.
            @unlink($compiler->getCompiledPath($view));
        }
    }

    public function test_the_emails_are_delivered_to_both_drivers()
    {
        $ravi = User::factory()->driver()->create(['email' => 'ravi@example.com']);
        $wong = User::factory()->driver()->create(['email' => 'wong@example.com']);
        $order = Order::factory()->assigned($ravi)->create();

        $this->assign($order, $wong, $this->today);

        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $subjects = [];

        foreach ($transport->messages() as $message) {
            $email = $message->getOriginalMessage();
            $this->assertInstanceOf(Email::class, $email);
            $subjects[$email->getTo()[0]->getAddress()] = $email->getSubject();
        }

        $this->assertSame([
            'wong@example.com' => "New delivery for Monday, 5 October: {$order->formatted_tracking_number}",
            'ravi@example.com' => "Delivery {$order->formatted_tracking_number} removed from your round for Monday, 5 October",
        ], $subjects);
    }

    /**
     * Run the driver emails queued since the last call in order, as the queue
     * worker would, and return the subject of each email sent, by address.
     *
     * @return array<string, string>
     */
    private function runQueuedEmails(): array
    {
        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $transport->flush();

        $jobs = Queue::pushed(SendQueuedNotifications::class)->slice($this->ran);
        $this->ran += $jobs->count();

        foreach ($jobs as $job) {
            unserialize(serialize($job))->handle(app(ChannelManager::class));
        }

        $subjects = [];

        foreach ($transport->messages() as $message) {
            $email = $message->getOriginalMessage();
            $this->assertInstanceOf(Email::class, $email);
            $subjects[$email->getTo()[0]->getAddress()] = $email->getSubject();
        }

        return $subjects;
    }

    /**
     * Assign the order as the admin would on Dispatch.
     */
    private function assign(Order $order, User $driver, CarbonInterface $date): Order
    {
        return app(AssignDriver::class)->handle($order, $this->admin, $driver, $date);
    }

    /**
     * Create Ravi's job for Aisyah's parcel to Daniel, collected from the SS2 branch.
     *
     * @return array{Order, User}
     */
    private function ravisJob(): array
    {
        $branch = Branch::factory()->create([
            'name' => 'Petaling Jaya - SS2',
            'address' => '12, Jalan SS 2/67, SS 2',
            'city' => 'Petaling Jaya',
            'postcode' => '47300',
        ]);
        $customer = User::factory()->create(['name' => 'Aisyah Rahman', 'email' => 'aisyah@example.com', 'phone' => '+60123456789']);
        $driver = User::factory()->driver()->create(['name' => 'Ravi Kumar']);

        $order = Order::factory()->for($customer, 'customer')->for($branch)->assigned($driver)->create([
            'tracking_number' => 'KT7Q4M92XD',
            'receiver_name' => 'Daniel Lim',
            'receiver_phone' => '+60127788990',
            'address_line1' => 'No. 12, Jalan Datuk Sulaiman 1',
            'address_line2' => 'Taman Tun Dr Ismail',
            'city' => 'Kuala Lumpur',
            'state' => 'Kuala Lumpur',
            'postcode' => '60000',
            'item_name' => 'Ceramic dinner set',
        ]);

        return [$order, $driver];
    }
}
