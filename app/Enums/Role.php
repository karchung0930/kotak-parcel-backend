<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum Role: string
{
    use HasOptions;

    case Customer = 'customer';
    case Staff = 'staff';
    case Admin = 'admin';
    case Driver = 'driver';

    /**
     * Get the human readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Staff => 'Branch Staff',
            self::Admin => 'Admin',
            self::Driver => 'Driver',
        };
    }

    /**
     * Get the name of the route a user with this role lands on after login.
     */
    public function homeRoute(): string
    {
        return match ($this) {
            self::Customer => 'orders.index',
            self::Staff => 'staff.counter',
            self::Admin => 'admin.dispatch',
            self::Driver => 'driver.jobs',
        };
    }
}
