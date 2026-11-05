<?php

namespace Kompo\Auth\Tests\Unit;

use Kompo\Auth\Models\NotifiableMethodsEnum;
use Kompo\Auth\Tests\TestCase;

class NotifiableMethodsEnumTest extends TestCase
{
    public function test_sms_destination_is_the_phone_string()
    {
        $notifiable = (object) ['phone' => '5145551234', 'phone_number' => '4385550000'];

        $this->assertSame('5145551234', NotifiableMethodsEnum::SMS->destination($notifiable));
    }

    public function test_sms_destination_skips_a_phone_relation_for_the_phone_number()
    {
        $notifiable = (object) ['phone' => (object) ['id' => 1], 'phone_number' => '4385550000'];

        $this->assertSame('4385550000', NotifiableMethodsEnum::SMS->destination($notifiable));
    }

    public function test_sms_destination_is_null_without_any_phone()
    {
        $this->assertNull(NotifiableMethodsEnum::SMS->destination((object) ['email' => 'a@b.test']));
    }
}
