<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    /**
     * Determine whether the user can list payments.
     */
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    /**
     * Determine whether the user can view the payment and its receipt.
     */
    public function view(User $user, Payment $payment): bool
    {
        return $user->isStaff() || $payment->order->isOwnedBy($user);
    }

    /**
     * Determine whether the user can take payments.
     */
    public function create(User $user): bool
    {
        return $user->isStaff();
    }
}
