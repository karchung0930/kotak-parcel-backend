<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProofOfDeliveryController;
use App\Http\Controllers\Public\BranchController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\PricingController;
use App\Http\Controllers\Public\TrackingController;
use Illuminate\Support\Facades\Route;

// Public pages. Tracking is rate limited to stop tracking number enumeration.
Route::get('/', HomeController::class)->name('home');
Route::get('track', TrackingController::class)->middleware('throttle:tracking')->name('track');
Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
Route::get('pricing', PricingController::class)->name('pricing');

Route::middleware('auth')->group(function () {
    // Sends each role to its own home page.
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Private proof of delivery photo, authorised by OrderPolicy::viewProof.
    Route::get('orders/{order}/proof', ProofOfDeliveryController::class)->name('orders.proof');
});

require __DIR__.'/settings.php';
require __DIR__.'/customer.php';
require __DIR__.'/staff.php';
require __DIR__.'/admin.php';
require __DIR__.'/driver.php';
