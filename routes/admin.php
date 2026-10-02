<?php

use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\DispatchController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

// Dispatch, orders, users and branches.
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('dispatch', [DispatchController::class, 'index'])->name('dispatch');
    Route::post('orders/{order}/assign', [DispatchController::class, 'assign'])->name('orders.assign');
    Route::post('orders/{order}/return', [DispatchController::class, 'returnToSender'])->name('orders.return');

    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');

    // Accounts and branches are never deleted: they are deactivated instead.
    Route::resource('users', UserController::class)->except(['show', 'destroy']);
    Route::resource('branches', BranchController::class)->except(['show', 'destroy']);
});
