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

    public function test_a_name_for_someone_elses_email_keeps_real_names()
    {
        $this->assertSame('Aisyah Rahman', MailText::name('Aisyah Rahman'));
        $this->assertSame("Siti Nur'aini bt. Abdullah-Lim a/p Rajan", MailText::name("Siti Nur'aini bt. Abdullah-Lim a/p Rajan"));
        $this->assertSame('林美玲', MailText::name('林美玲'));
        $this->assertSame('', MailText::name(null));
    }

    public function test_a_name_for_someone_elses_email_cannot_become_a_link()
    {
        $this->assertSame(
            'Kotak Customs: pay RM2.50 duty at',
            MailText::name('Kotak Customs: pay RM2.50 duty at https://kotak-duty.example/pay?ref=1'),
        );
        $this->assertSame('Pay at now', MailText::name('Pay at www.kotak-duty.example now'));
        $this->assertSame('Pay at now', MailText::name('Pay at HTTP://kotak-duty.example now'));
        // A bare domain or address is broken up; initials read as usual.
        $this->assertSame('Pay at kotak-duty. example', MailText::name('Pay at kotak-duty.example'));
        $this->assertSame('Write to aisyah@mail. example. com', MailText::name('Write to aisyah@mail.example.com'));
        $this->assertSame("Pay at kotak-duty\u{3002} example", MailText::name("Pay at kotak-duty\u{3002}example"));
        $this->assertSame('J. K. Lim', MailText::name('J.K. Lim'));
        // Markup goes too, as in plain().
        $this->assertSame('Aisyah Sign in ( b now /b', MailText::name('Aisyah [Sign in](https://evil.example/in) <b>now</b>'));
    }

    public function test_a_name_for_someone_elses_email_loses_hidden_characters_and_is_kept_short()
    {
        // Right-to-left override and isolates, zero-width space, word joiner, byte order mark.
        $this->assertSame('Aisyah Rahman', MailText::name("Ai\u{202E}syah\u{2066} Rah\u{200B}m\u{2060}an\u{FEFF}"));

        $long = 'Nur Aisyah binti Ahmad Zulkifli Rahman Abdullah Hassan Ibrahim Yusof';
        $this->assertSame('Nur Aisyah binti Ahmad Zulkifli Rahman Abdullah Hassan…', MailText::name($long));
        $this->assertSame('Nur Aisyah…', MailText::name($long, 12));
    }
}
