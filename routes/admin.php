<?php

use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\DispatchController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\RateCardController;
use App\Http\Controllers\Admin\RateCardExportController;
use App\Http\Controllers\Admin\RateImportController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

// Dispatch, orders, users, branches, settings, rates and rate imports.
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

    // Spreadsheet imports, listed before the versions so "imports" is never
    // read as a version. Each ends as a draft, published like any other.
    // Uploads, sheets and mappings keep a file or queue a job, so they are throttled.
    Route::get('rates/imports/create', [RateImportController::class, 'create'])->name('rates.imports.create');
    Route::post('rates/imports', [RateImportController::class, 'store'])->middleware('throttle:rate-imports')->name('rates.imports.store');
    Route::get('rates/imports/{rateImport}', [RateImportController::class, 'show'])->name('rates.imports.show');
    Route::put('rates/imports/{rateImport}/sheet', [RateImportController::class, 'sheet'])->middleware('throttle:rate-imports')->name('rates.imports.sheet');
    Route::put('rates/imports/{rateImport}/mapping', [RateImportController::class, 'mapping'])->middleware('throttle:rate-imports')->name('rates.imports.mapping');
    Route::post('rates/imports/{rateImport}/draft', [RateImportController::class, 'draft'])->name('rates.imports.draft');

    // Rate card versions. Drafts are created by copying a version (or blank)
    // from the list, so there is no separate create page.
    Route::resource('rates', RateCardController::class)
        ->except(['create'])
        ->parameters(['rates' => 'rateCard']);
    Route::post('rates/{rateCard}/publish', [RateCardController::class, 'publish'])->name('rates.publish');
    Route::post('rates/{rateCard}/withdraw', [RateCardController::class, 'withdraw'])->name('rates.withdraw');
    Route::get('rates/{rateCard}/download/{format}', RateCardExportController::class)
        ->whereIn('format', ['xlsx', 'csv'])
        ->name('rates.download');
});
