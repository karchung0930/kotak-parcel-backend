<?php

namespace Tests\Feature\Public;

use App\Actions\Delivery\MarkPickedUp;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderStatusUpdated;
use App\Notifications\ReceiverStatusUpdated;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReceiverEmailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_receiver_email_links_to_a_page_that_stops_them()
    {
        $order = $this->danielsParcel();
        $url = $order->stopReceiverEmailsUrl();

        $html = (string) (new ReceiverStatusUpdated($order, OrderStatus::Assigned, OrderStatus::Paid, $order->scheduled_for))
            ->toMail($this->daniel())
            ->render();
        $this->assertStringContainsString('href="'.e($url).'"', $html);

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('deliveries/Emails')
                ->where('trackingNumber', 'KT-7Q4M92XD')
                ->where('stopped', false)
                ->where('stopUrl', $url));
    }

    public function test_the_page_shows_nothing_about_the_people()
    {
        $order = $this->danielsParcel();

        $props = $this->get($order->stopReceiverEmailsUrl())->viewData('page')['props'];
        $json = (string) json_encode($props, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach (['daniel@example.com', 'Daniel Lim', '+60127788990', 'Jalan Datuk Sulaiman', 'Aisyah Rahman', 'aisyah@example.com', 'Ceramic dinner set'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public function test_the_receiver_stops_the_emails_and_nothing_more_is_sent()
    {
        Notification::fake();
        $order = $this->danielsParcel();
        $url = $order->stopReceiverEmailsUrl();
        $queued = new ReceiverStatusUpdated($order, OrderStatus::PickedUp, OrderStatus::Assigned);

        $this->post($url)->assertRedirect($url);

        $this->assertNull($order->refresh()->receiver_email);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('stopped', true));

        // Neither an email queued before nor the next step reaches the receiver.
        $this->assertFalse($queued->shouldSend($this->daniel(), 'mail'));
        app(MarkPickedUp::class)->handle($order, $order->driver()->firstOrFail());
        Notification::assertSentOnDemandTimes(ReceiverStatusUpdated::class, 0);
        // The customer still hears about it.
        Notification::assertSentTo($order->customer, OrderStatusUpdated::class);

        // Asking again changes nothing.
        $this->post($url)->assertRedirect($url);
        $this->assertNull($order->refresh()->receiver_email);
    }

    public function test_a_mail_apps_unsubscribe_button_stops_them_in_one_click()
    {
        $order = $this->danielsParcel();

        // RFC 8058: the mail app posts this, without a session or a page.
        $this->post($order->stopReceiverEmailsUrl(), ['List-Unsubscribe' => 'One-Click'])->assertNoContent();

        $this->assertNull($order->refresh()->receiver_email);

        // So the link takes posts without a CSRF token (tests skip that check, so ask the middleware).
        $csrf = new class(app(), app('encrypter')) extends ValidateCsrfToken
        {
            public function excludes(Request $request): bool
            {
                return $this->inExceptArray($request);
            }
        };
        $this->assertTrue($csrf->excludes(Request::create($order->stopReceiverEmailsUrl(), 'POST')));
        $this->assertFalse($csrf->excludes(Request::create(route('orders.store'), 'POST')));
    }

    public function test_a_link_without_a_valid_signature_is_refused()
    {
        $order = $this->danielsParcel();
        $other = Order::factory()->assigned()->create(['receiver_email' => 'mei@example.com']);
        $signature = (string) parse_url($other->stopReceiverEmailsUrl(), PHP_URL_QUERY);

        $this->get(route('receiver-emails.show', $order))->assertForbidden();
        $this->get($order->stopReceiverEmailsUrl().'0')->assertForbidden();
        // Another parcel's signature does not open this one.
        $this->get(route('receiver-emails.show', $order).'?'.$signature)->assertForbidden();
        $this->post(route('receiver-emails.destroy', $order), ['List-Unsubscribe' => 'One-Click'])->assertForbidden();
        $this->post(route('receiver-emails.destroy', $order).'?'.$signature)->assertForbidden();

        $this->assertSame('daniel@example.com', $order->refresh()->receiver_email);
        $this->assertSame('mei@example.com', $other->refresh()->receiver_email);
    }

    /**
     * Get Daniel, the receiver, as the on-demand notifiable.
     */
    private function daniel(): AnonymousNotifiable
    {
        return (new AnonymousNotifiable)->route('mail', 'daniel@example.com');
    }

    /**
     * Create Aisyah's parcel to Daniel, assigned for today, with Daniel's email.
     */
    private function danielsParcel(): Order
    {
        $customer = User::factory()->create(['name' => 'Aisyah Rahman', 'email' => 'aisyah@example.com']);

        return Order::factory()->for($customer, 'customer')->assigned()->create([
            'tracking_number' => 'KT7Q4M92XD',
            'receiver_name' => 'Daniel Lim',
            'receiver_phone' => '+60127788990',
            'receiver_email' => 'daniel@example.com',
            'address_line1' => 'No. 12, Jalan Datuk Sulaiman 1',
            'item_name' => 'Ceramic dinner set',
        ]);
    }
}
