<?php

namespace Tests\Unit\Support;

use App\Support\MailText;
use Tests\TestCase;

class MailTextTest extends TestCase
{
    public function test_names_stay_as_they_are()
    {
        $this->assertSame('Aisyah Rahman', MailText::plain('Aisyah Rahman'));
        $this->assertSame("Siti Nur'aini binti Abdullah", MailText::plain("Siti Nur'aini binti Abdullah"));
        $this->assertSame('', MailText::plain(null));
    }

    public function test_markup_becomes_one_line_of_plain_text()
    {
        $this->assertSame(
            'Aisyah Sign in (https://example.com) ! (https://example.com/p.png) b now /b # Urgent',
            MailText::plain("  Aisyah [Sign in](https://example.com) ![](https://example.com/p.png)\n<b>now</b> |\r\n\t# Urgent "),
        );
        $this->assertSame('', MailText::plain('[ ] < > |'));
    }
}
