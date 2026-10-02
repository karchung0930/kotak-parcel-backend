<?php

namespace Tests\Unit\Enums;

use App\Enums\MalaysianState;
use App\Enums\Role;
use PHPUnit\Framework\TestCase;

class RoleTest extends TestCase
{
    public function test_each_role_lands_on_its_own_home_page()
    {
        $this->assertSame('orders.index', Role::Customer->homeRoute());
        $this->assertSame('staff.counter', Role::Staff->homeRoute());
        $this->assertSame('admin.dispatch', Role::Admin->homeRoute());
        $this->assertSame('driver.jobs', Role::Driver->homeRoute());
    }

    public function test_roles_have_labels()
    {
        $this->assertSame(['value' => 'staff', 'label' => 'Branch Staff'], Role::Staff->toOption());
    }

    public function test_federal_territories_are_labelled_but_stored_by_name()
    {
        $this->assertSame('Kuala Lumpur', MalaysianState::KualaLumpur->value);
        $this->assertSame('W.P. Kuala Lumpur', MalaysianState::KualaLumpur->label());
        $this->assertSame('Selangor', MalaysianState::Selangor->label());
        $this->assertCount(16, MalaysianState::cases());
    }
}
