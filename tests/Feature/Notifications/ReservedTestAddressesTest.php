<?php

namespace Tests\Feature\Notifications;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReservedTestAddressesTest extends TestCase
{
    public function test_mail_only_for_test_addresses_is_not_sent()
    {
        config(['mail.default' => 'array']);

        Mail::raw('Demo', fn ($message) => $message->to('aisyah@kotak.test')->subject('Demo'));

        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_mail_for_a_real_address_is_still_sent()
    {
        config(['mail.default' => 'array']);

        Mail::raw('Hello', fn ($message) => $message->to('someone@example.com')->cc('aisyah@kotak.test')->subject('Hello'));

        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }
}
