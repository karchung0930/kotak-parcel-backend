<?php

namespace Tests\Feature\Notifications;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\SendDropOffReminders;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Notifications\DropOffReminder;
use App\Services\OrderStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class DropOffReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_email_gives_the_deadline_the_branch_and_a_link_to_the_order()
    {
        // Ordered at 23:30 on 3 October in Kuala Lumpur (15:30 UTC): the deadline is a Malaysian date.
        $order = $this->orderCreatedAt(CarbonImmutable::parse('2026-10-03 15:30:00', 'UTC'));

        $mail = (new DropOffReminder($order))->toMail($order->customer);

        $this->assertSame("Parcel {$order->formatted_tracking_number}: drop it off by 10 October", $mail->subject);
        $this->assertSame('Hi Aisyah Rahman,', $mail->greeting);
        $this->assertContains("Your parcel **{$order->formatted_tracking_number}** to Daniel Lim is still waiting to be dropped off.", $mail->introLines);
        $this->assertContains('Drop-off deadline: **Saturday, 10 October 2026**. If it is not dropped off by then, the order is cancelled automatically.', $mail->introLines);
        $this->assertContains('Your drop-off branch: **Petaling Jaya - SS2**, 12, Jalan SS 2/67, SS 2, 47300 Petaling Jaya.', $mail->introLines);
        $this->assertContains('Opening hours: Mon-Sat 9:00-21:00, Sun 10:00-18:00.', $mail->introLines);
        $this->assertSame('View your order', $mail->actionText);
        $this->assertSame(route('orders.show', $order), $mail->actionUrl);
        $this->assertContains('No longer sending it? You can cancel the order on the same page.', $mail->outroLines);
    }

    public function test_the_reminder_is_not_sent_once_the_parcel_is_dropped_off()
    {
        $order = $this->orderCreatedAt(now()->subDays(6));
        $reminder = new DropOffReminder($order);
        $this->assertTrue($reminder->shouldSend($order->customer, 'mail'));

        $order = app(OrderStatusService::class)->transition($order, OrderStatus::DroppedOff, null);

        $this->assertFalse((new DropOffReminder($order))->shouldSend($order->customer, 'mail'));
    }

    public function test_the_reminder_is_not_sent_once_the_order_is_cancelled()
    {
        $order = $this->orderCreatedAt(now()->subDays(6));

        $order = app(CancelOrder::class)->handle($order, $order->customer);

        $this->assertFalse((new DropOffReminder($order))->shouldSend($order->customer, 'mail'));
    }

    public function test_the_reminder_is_not_sent_to_an_account_deactivated_while_it_was_queued()
    {
        $order = $this->orderCreatedAt(now()->subDays(6));

        $order->customer->forceFill(['is_active' => false])->save();

        $this->assertFalse((new DropOffReminder($order))->shouldSend($order->customer, 'mail'));
    }

    public function test_the_email_is_delivered_to_the_customer()
    {
        $order = $this->orderCreatedAt(now()->subDays(6));

        app(SendDropOffReminders::class)->handle();

        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $messages = array_values($transport->messages()->all());
        $this->assertCount(1, $messages);

        $email = $messages[0]->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('aisyah@example.com', $email->getTo()[0]->getAddress());
        $this->assertStringContainsString(route('orders.show', $order), (string) $email->getHtmlBody());
    }

    /**
     * Create Aisyah's order to Daniel, waiting for drop-off at the SS2 branch.
     */
    private function orderCreatedAt(CarbonImmutable $createdAt): Order
    {
        $branch = Branch::factory()->create([
            'name' => 'Petaling Jaya - SS2',
            'address' => '12, Jalan SS 2/67, SS 2',
            'city' => 'Petaling Jaya',
            'postcode' => '47300',
            'opening_hours' => 'Mon-Sat 9:00-21:00, Sun 10:00-18:00',
        ]);
        $customer = User::factory()->create(['name' => 'Aisyah Rahman', 'email' => 'aisyah@example.com']);

        return Order::factory()->for($customer, 'customer')->for($branch)->create([
            'receiver_name' => 'Daniel Lim',
            'created_at' => $createdAt,
        ]);
    }
}
