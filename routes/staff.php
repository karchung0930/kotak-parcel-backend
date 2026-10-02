<?php

use App\Http\Controllers\Staff\CounterController;
use App\Http\Controllers\Staff\OrderController;
use App\Http\Controllers\Staff\PaymentController;
use Illuminate\Support\Facades\Route;

// Branch counter: drop-off, weighing and payment. Admins can use it too.
Route::middleware(['auth', 'role:staff,admin'])->prefix('staff')->name('staff.')->group(function () {
    Route::get('counter', CounterController::class)->name('counter');

    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('orders/{order}/drop-off', [OrderController::class, 'dropOff'])->name('orders.drop-off');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');

    Route::post('orders/{order}/payment', [PaymentController::class, 'store'])->name('orders.payment');
    Route::get('payments/{payment}/receipt', [PaymentController::class, 'show'])->name('payments.receipt');
});
