<?php

use App\Http\Controllers\Customer\OrderController;
use Illuminate\Support\Facades\Route;

// Customers create and follow their own orders. Every route still authorises
// the order itself through OrderPolicy.
Route::middleware(['auth', 'verified', 'role:customer'])->group(function () {
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/create', [OrderController::class, 'create'])->name('orders.create');
    Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:orders')->name('orders.store');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
});
