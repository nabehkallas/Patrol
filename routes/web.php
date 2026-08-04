<?php

use App\Http\Controllers\Admin\EarningsController;
use App\Http\Controllers\Admin\EarningsPasswordResetController;
use App\Http\Controllers\Admin\ExchangeRateController;
use App\Http\Controllers\Admin\FuelPriceController;
use App\Http\Controllers\Admin\FuelPumpController;
use App\Http\Controllers\Admin\FuelTypeController;
use App\Http\Controllers\Admin\TankController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\CashBoxController;
use App\Http\Controllers\DebtController;
use App\Http\Controllers\DebtorController;
use App\Http\Controllers\InventoryEntryController;
use App\Http\Controllers\PumpCounterReadingController;
use App\Http\Controllers\SadcopController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\TankTopUpController;
use App\Http\Controllers\TankVolumeCalculatorController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/cash-box')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('cash-box', [CashBoxController::class, 'index'])->name('cash-box.index');
    Route::get('cash-box/export-pdf', [CashBoxController::class, 'exportPdf'])->name('cash-box.export-pdf');

    Route::resource('transactions', TransactionController::class)->except('show');
    Route::get('transactions/export-pdf', [TransactionController::class, 'exportPdf'])->name('transactions.export-pdf');

    Route::get('inventory', [InventoryEntryController::class, 'index'])->name('inventory.index');
    Route::post('inventory', [InventoryEntryController::class, 'store'])->name('inventory.store');
    Route::get('inventory/export-entries-pdf', [InventoryEntryController::class, 'exportEntriesPdf'])->name('inventory.export-entries-pdf');
    Route::get('inventory/export-topups-pdf', [InventoryEntryController::class, 'exportTopUpsPdf'])->name('inventory.export-topups-pdf');
    Route::post('tank-top-ups', [TankTopUpController::class, 'store'])->name('tank-top-ups.store');

    Route::get('tools/tank-volume', [TankVolumeCalculatorController::class, 'index'])->name('tools.tank-volume');

    Route::resource('debts', DebtController::class)->except('show');
    Route::get('debts/export-pdf', [DebtController::class, 'exportPdf'])->name('debts.export-pdf');
    Route::patch('debts/{debt}/settle', [DebtController::class, 'settle'])->name('debts.settle');

    Route::resource('debtors', DebtorController::class)->except('show');
    Route::get('debtors/export-pdf', [DebtorController::class, 'exportPdf'])->name('debtors.export-pdf');
    Route::patch('debtors/{debtor}/settle-all', [DebtorController::class, 'settleAll'])->name('debtors.settle-all');

    Route::get('sadcop', [SadcopController::class, 'index'])->name('sadcop.index');
    Route::get('sadcop/export-pdf', [SadcopController::class, 'exportPdf'])->name('sadcop.export-pdf');
    Route::get('sadcop/deliveries/create', [SadcopController::class, 'createDelivery'])->name('sadcop.deliveries.create');
    Route::post('sadcop/deliveries', [SadcopController::class, 'storeDelivery'])->name('sadcop.deliveries.store');
    Route::get('sadcop/deposits/create', [SadcopController::class, 'createDeposit'])->name('sadcop.deposits.create')->middleware('role:admin');
    Route::post('sadcop/deposits', [SadcopController::class, 'storeDeposit'])->name('sadcop.deposits.store')->middleware('role:admin');
    Route::post('sadcop/opening-balance', [SadcopController::class, 'storeOpeningBalance'])->name('sadcop.opening-balance.store')->middleware('role:admin');

    Route::get('statistics', [StatisticsController::class, 'index'])->name('statistics.index');
    Route::get('statistics/export-pdf', [StatisticsController::class, 'exportPdf'])->name('statistics.export-pdf');

    Route::get('pump-counters', [PumpCounterReadingController::class, 'index'])->name('pump-counters.index');
    Route::get('pump-counters/export-pdf', [PumpCounterReadingController::class, 'exportPdf'])->name('pump-counters.export-pdf');
    Route::post('pump-counters', [PumpCounterReadingController::class, 'store'])->name('pump-counters.store');
    Route::get('pump-counters/{pumpCounterReading}/edit', [PumpCounterReadingController::class, 'edit'])->name('pump-counters.edit')->middleware('role:admin');
    Route::patch('pump-counters/{pumpCounterReading}', [PumpCounterReadingController::class, 'update'])->name('pump-counters.update')->middleware('role:admin');

    Route::middleware(['role:admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', UserController::class)->except('show');
        Route::resource('fuel-types', FuelTypeController::class)->except('show');
        Route::resource('tanks', TankController::class)->except('show');

        Route::get('fuel-prices', [FuelPriceController::class, 'index'])->name('fuel-prices.index');
        Route::post('fuel-prices', [FuelPriceController::class, 'store'])->name('fuel-prices.store');
        Route::delete('fuel-prices/{fuelPrice}', [FuelPriceController::class, 'destroy'])->name('fuel-prices.destroy');
        Route::patch('fuel-prices/profit-margin/{fuelType}', [FuelPriceController::class, 'updateProfitMargin'])->name('fuel-prices.profit-margin');

        Route::get('exchange-rates', [ExchangeRateController::class, 'index'])->name('exchange-rates.index');
        Route::post('exchange-rates', [ExchangeRateController::class, 'store'])->name('exchange-rates.store');

        Route::resource('fuel-pumps', FuelPumpController::class)->except('show');

        Route::prefix('earnings')->name('earnings.')->group(function () {
            Route::get('/', [EarningsController::class, 'index'])->name('index');
            Route::post('unlock', [EarningsController::class, 'unlock'])->name('unlock')->middleware('throttle:5,1');
            Route::post('setup', [EarningsController::class, 'setup'])->name('setup');
            Route::get('forgot-password', [EarningsPasswordResetController::class, 'show'])->name('forgot-password');
            Route::post('forgot-password', [EarningsPasswordResetController::class, 'send'])->name('forgot-password.send')->middleware('throttle:3,1');
            Route::get('reset-password/{token}', [EarningsPasswordResetController::class, 'edit'])->name('reset-password');
            Route::post('reset-password', [EarningsPasswordResetController::class, 'update'])->name('reset-password.update');
        });
    });
});

require __DIR__.'/settings.php';
