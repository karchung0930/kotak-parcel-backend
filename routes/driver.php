<?php

use App\Http\Controllers\Driver\JobController;
use Illuminate\Support\Facades\Route;

// A driver's delivery jobs. OrderPolicy::deliver limits each job to its assigned driver.
Route::middleware(['auth', 'role:driver'])->prefix('driver')->name('driver.')->group(function () {
    Route::get('jobs', [JobController::class, 'index'])->name('jobs');
    Route::get('jobs/{order}', [JobController::class, 'show'])->name('jobs.show');
    Route::post('jobs/{order}/pickup', [JobController::class, 'pickup'])->name('jobs.pickup');
    Route::post('jobs/{order}/move', [JobController::class, 'move'])->middleware('throttle:driver-moves')->name('jobs.move');
    Route::post('jobs/{order}/deliver', [JobController::class, 'deliver'])->name('jobs.deliver');
    Route::post('jobs/{order}/fail', [JobController::class, 'fail'])->name('jobs.fail');
});
