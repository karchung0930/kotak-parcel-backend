<?php

namespace Tests\Unit\Support;

use App\Support\MailDate;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class MailDateTest extends TestCase
{
    public function test_dates_wrap_only_after_the_weekday()
    {
        $date = CarbonImmutable::parse('2026-10-06');

        $this->assertSame("Tuesday, 6\u{00A0}October\u{00A0}2026", MailDate::long($date));
        $this->assertSame("6\u{00A0}Oct", MailDate::short($date));
    }
}
