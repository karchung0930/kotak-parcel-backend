<?php

namespace Tests\Unit\Support;

use App\Support\TrackingNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TrackingNumberTest extends TestCase
{
    public function test_generated_numbers_use_the_prefix_and_crockford_alphabet()
    {
        for ($i = 0; $i < 200; $i++) {
            $this->assertMatchesRegularExpression('/^KT[0-9ABCDEFGHJKMNPQRSTVWXYZ]{8}$/', TrackingNumber::generate());
        }
    }

    public function test_generated_numbers_are_random()
    {
        $numbers = array_map(fn () => TrackingNumber::generate(), range(1, 100));

        $this->assertCount(100, array_unique($numbers));
    }

    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function inputs(): array
    {
        return [
            'stored form' => ['KT7Q4M92XD', 'KT7Q4M92XD'],
            'display form' => ['KT-7Q4M92XD', 'KT7Q4M92XD'],
            'lower case' => ['kt-7q4m92xd', 'KT7Q4M92XD'],
            'spaces' => [' KT 7Q4M 92XD ', 'KT7Q4M92XD'],
            'confusable letters' => ['KT-7Q4M92XO', 'KT7Q4M92X0'],
            'I and L read as one' => ['KT-IL000000', 'KT11000000'],
            'wrong prefix' => ['XX-7Q4M92XD', null],
            'too short' => ['KT-7Q4M92X', null],
            'too long' => ['KT-7Q4M92XDD', null],
            'excluded letter U' => ['KT-7Q4M92XU', null],
            'symbols' => ['KT-7Q4M92X%', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('inputs')]
    public function test_user_input_is_normalised_to_the_stored_form(?string $input, ?string $expected)
    {
        $this->assertSame($expected, TrackingNumber::normalize($input));
    }

    public function test_numbers_are_formatted_for_display()
    {
        $this->assertSame('KT-7Q4M92XD', TrackingNumber::format('KT7Q4M92XD'));
    }
}
