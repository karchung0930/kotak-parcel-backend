<?php

namespace Tests\Unit\Support;

use App\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    /**
     * @return array<string, array{mixed, mixed}>
     */
    public static function numbers(): array
    {
        return [
            'local' => ['0123456789', '+60123456789'],
            'local with separators' => ['012-345 6789', '+60123456789'],
            'without the trunk 0' => ['12-345 6789', '+60123456789'],
            '11 digit local' => ['011-2345 6789', '+601123456789'],
            'country code' => ['60123456789', '+60123456789'],
            'e164' => ['+60 12-345 6789', '+60123456789'],
            'landline' => ['03-7877 1203', '+60378771203'],
            'foreign number is left alone' => ['+6591234567', '+6591234567'],
            'invalid number is left alone' => ['010-123 4567', '010-123 4567'],
            'too short' => ['012345', '012345'],
            'not a number' => ['not a phone', 'not a phone'],
            'empty' => ['', ''],
            'not a string' => [12345, 12345],
            'null' => [null, null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_malaysian_numbers_are_normalised_to_e164(mixed $input, mixed $expected)
    {
        $this->assertSame($expected, Phone::normalize($input));
    }

    /**
     * @return array<string, array{string, bool, bool}>
     */
    public static function types(): array
    {
        // number => [a valid mobile, a valid mobile or landline]
        return [
            'mobile' => ['+60123456789', true, true],
            '11 digit mobile' => ['+601123456789', true, true],
            'mobile as typed' => ['012-345 6789', true, true],
            'Klang Valley landline' => ['+60378771203', false, true],
            'east coast landline' => ['+6097481234', false, true],
            '1-300 number' => ['+601300881234', false, false],
            'unallocated mobile range' => ['+60101234567', false, false],
            'Singapore mobile' => ['+6591234567', false, false],
            'too short' => ['+6012345', false, false],
            'not a number' => ['not a phone', false, false],
        ];
    }

    #[DataProvider('types')]
    public function test_numbers_are_checked_with_libphonenumber(string $number, bool $mobile, bool $mobileOrLandline)
    {
        $this->assertSame($mobile, Phone::isValid($number, ['mobile']));
        $this->assertSame($mobileOrLandline, Phone::isValid($number, ['mobile', 'fixed_line']));
    }
}
