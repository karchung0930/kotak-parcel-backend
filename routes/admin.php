<?php

use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\DispatchController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\RateCardController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

// Dispatch, orders, users, branches, settings and rates.
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('dispatch', [DispatchController::class, 'index'])->name('dispatch');
    Route::post('orders/{order}/assign', [DispatchController::class, 'assign'])->name('orders.assign');
    Route::post('orders/{order}/return', [DispatchController::class, 'returnToSender'])->name('orders.return');

    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');

    // Accounts and branches are never deleted: they are deactivated instead.
    Route::resource('users', UserController::class)->except(['show', 'destroy']);
    Route::resource('branches', BranchController::class)->except(['show', 'destroy']);

    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

    // Rate card versions. Drafts are created by copying a version (or blank)
    // from the list, so there is no separate create page.
    Route::resource('rates', RateCardController::class)
        ->except(['create'])
        ->parameters(['rates' => 'rateCard']);
    Route::post('rates/{rateCard}/publish', [RateCardController::class, 'publish'])->name('rates.publish');
    Route::post('rates/{rateCard}/withdraw', [RateCardController::class, 'withdraw'])->name('rates.withdraw');
});
