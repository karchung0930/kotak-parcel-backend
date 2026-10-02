<?php

namespace Tests\Unit\Rules;

use App\Rules\MalaysianPhone;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class MalaysianPhoneTest extends TestCase
{
    public function test_account_numbers_must_be_mobiles()
    {
        $this->assertTrue($this->passes(['phone' => '+60123456789'], MalaysianPhone::mobile()));

        $validator = Validator::make(['phone' => '+60378771203'], ['phone' => MalaysianPhone::mobile()]);

        $this->assertTrue($validator->fails());
        $this->assertSame('Enter a valid Malaysian mobile number, e.g. 12-345 6789.', $validator->errors()->first('phone'));
    }

    public function test_receivers_may_have_a_mobile_or_a_landline()
    {
        $this->assertTrue($this->passes(['phone' => '+60123456789'], MalaysianPhone::mobileOrLandline()));
        $this->assertTrue($this->passes(['phone' => '+60378771203'], MalaysianPhone::mobileOrLandline()));

        $validator = Validator::make(['phone' => '+601300881234'], ['phone' => MalaysianPhone::mobileOrLandline()]);

        $this->assertTrue($validator->fails());
        $this->assertSame('Enter a valid Malaysian mobile or landline number.', $validator->errors()->first('phone'));
    }

    public function test_values_that_are_not_strings_fail()
    {
        $this->assertFalse($this->passes(['phone' => 60123456789], MalaysianPhone::mobile()));
        $this->assertFalse($this->passes(['phone' => ['+60123456789']], MalaysianPhone::mobile()));
    }

    public function test_the_request_cannot_add_a_country()
    {
        $singapore = ['phone' => '+6591234567'];

        // The package's own rule takes countries from the request.
        $this->assertTrue($this->passes([...$singapore, 'phone_country' => 'SG'], 'phone:MY,mobile'));
        $this->assertTrue($this->passes([...$singapore, 'MY' => 'SG'], 'phone:MY,mobile'));

        $this->assertFalse($this->passes($singapore, MalaysianPhone::mobile()));
        $this->assertFalse($this->passes([...$singapore, 'phone_country' => 'SG'], MalaysianPhone::mobile()));
        $this->assertFalse($this->passes([...$singapore, 'MY' => 'SG'], MalaysianPhone::mobile()));
        $this->assertFalse($this->passes([...$singapore, 'phone_country' => 'SG'], MalaysianPhone::mobileOrLandline()));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function passes(array $data, MalaysianPhone|string $rule): bool
    {
        return Validator::make($data, ['phone' => $rule])->passes();
    }
}
