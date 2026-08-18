<?php

use App\Http\Controllers\Admin\EarningsController;
use App\Http\Controllers\Admin\ExchangeRateController;
use App\Http\Controllers\Admin\FuelPriceController;
use App\Http\Controllers\Admin\FuelPumpController;
use App\Http\Controllers\Admin\FuelTypeController;
use App\Http\Controllers\Admin\TankController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\CashBoxController;
use App\Http\Controllers\DebtController;
use App\Http\Controllers\DebtorController;
use App\Http\Controllers\ForcePasswordChangeController;
use App\Http\Controllers\InventoryEntryController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PumpCounterReadingController;
use App\Http\Controllers\SadcopController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\StationController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\TankTopUpController;
use App\Http\Controllers\TankTransferController;
use App\Http\Controllers\TankVolumeCalculatorController;
use App\Http\Controllers\TransactionController;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\RequireOnboarding;
use App\Http\Middleware\RequireSuperAdmin;
use App\Http\Middleware\RequireTenant;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/cash-box')->name('home');

Route::middleware(['auth', RequireSuperAdmin::class])->prefix('platform')->name('platform.')->group(function () {
    Route::get('/', [StationController::class, 'index'])->name('home');
    Route::get('stations/create', [StationController::class, 'create'])->name('stations.create');
    Route::post('stations', [StationController::class, 'store'])->name('stations.store');
});

Route::middleware(['auth', RequireTenant::class, ForcePasswordChange::class])->group(function () {
    Route::get('password/force-change', [ForcePasswordChangeController::class, 'edit'])->name('password.force-change');
    Route::patch('password/force-change', [ForcePasswordChangeController::class, 'update'])->name('password.force-change.update');

    Route::prefix('onboarding')->name('onboarding.')->group(function () {
        Route::get('/', [OnboardingController::class, 'show'])->name('wizard');
        Route::post('sadcop-opening-balance', [OnboardingController::class, 'storeSadcopOpeningBalance'])->name('sadcop-opening-balance');
        Route::post('tank-levels', [OnboardingController::class, 'storeTankLevels'])->name('tank-levels');
        Route::post('pump-readings', [OnboardingController::class, 'storePumpReadings'])->name('pump-readings');
        Route::post('fuel-prices', [OnboardingController::class, 'storeFuelPrices'])->name('fuel-prices');
        Route::post('debts', [OnboardingController::class, 'storeDebt'])->name('debts');
        Route::post('finish', [OnboardingController::class, 'finish'])->name('finish');
    });

    Route::middleware([RequireOnboarding::class])->group(function () {
        Route::get('cash-box', [CashBoxController::class, 'index'])->name('cash-box.index');
        Route::get('cash-box/export-pdf', [CashBoxController::class, 'exportPdf'])->name('cash-box.export-pdf');

        Route::resource('transactions', TransactionController::class)->except('show');
        Route::get('transactions/export-pdf', [TransactionController::class, 'exportPdf'])->name('transactions.export-pdf');

        Route::get('inventory', [InventoryEntryController::class, 'index'])->name('inventory.index');
        Route::post('inventory', [InventoryEntryController::class, 'store'])->name('inventory.store');
        Route::get('inventory/export-entries-pdf', [InventoryEntryController::class, 'exportEntriesPdf'])->name('inventory.export-entries-pdf');
        Route::get('inventory/export-topups-pdf', [InventoryEntryController::class, 'exportTopUpsPdf'])->name('inventory.export-topups-pdf');
        Route::get('inventory/entries/{entry}/edit', [InventoryEntryController::class, 'editEntry'])->name('inventory.entries.edit')->middleware('role:admin');
        Route::patch('inventory/entries/{entry}', [InventoryEntryController::class, 'updateEntry'])->name('inventory.entries.update')->middleware('role:admin');
        Route::delete('inventory/entries/{entry}', [InventoryEntryController::class, 'destroyEntry'])->name('inventory.entries.destroy')->middleware('role:admin');
        Route::post('tank-top-ups', [TankTopUpController::class, 'store'])->name('tank-top-ups.store');
        Route::post('tank-transfers', [TankTransferController::class, 'store'])->name('tank-transfers.store');

        Route::get('tools/tank-volume', [TankVolumeCalculatorController::class, 'index'])->name('tools.tank-volume');

        Route::resource('debts', DebtController::class)->except('show');
        Route::get('debts/export-pdf', [DebtController::class, 'exportPdf'])->name('debts.export-pdf');
        Route::patch('debts/{debt}/settle', [DebtController::class, 'settle'])->name('debts.settle');
        Route::post('debts/{debt}/payments', [DebtController::class, 'storePayment'])->name('debts.payments.store');
        Route::patch('debts/{debt}/transfer', [DebtController::class, 'transfer'])->name('debts.transfer')->middleware('role:admin');

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
        Route::get('sadcop/entries/{entry}/edit', [SadcopController::class, 'editEntry'])->name('sadcop.entries.edit')->middleware('role:admin');
        Route::patch('sadcop/entries/{entry}', [SadcopController::class, 'updateEntry'])->name('sadcop.entries.update')->middleware('role:admin');
        Route::delete('sadcop/entries/{entry}', [SadcopController::class, 'destroyEntry'])->name('sadcop.entries.destroy')->middleware('role:admin');

        Route::get('statistics', [StatisticsController::class, 'index'])->name('statistics.index');
        Route::get('statistics/export-pdf', [StatisticsController::class, 'exportPdf'])->name('statistics.export-pdf');

        Route::get('pump-counters', [PumpCounterReadingController::class, 'index'])->name('pump-counters.index');
        Route::get('pump-counters/export-pdf', [PumpCounterReadingController::class, 'exportPdf'])->name('pump-counters.export-pdf');
        Route::post('pump-counters', [PumpCounterReadingController::class, 'store'])->name('pump-counters.store');
        Route::get('pump-counters/{pumpCounterReading}/edit', [PumpCounterReadingController::class, 'edit'])->name('pump-counters.edit')->middleware('role:admin');
        Route::patch('pump-counters/{pumpCounterReading}', [PumpCounterReadingController::class, 'update'])->name('pump-counters.update')->middleware('role:admin');
        Route::delete('pump-counters/{pumpCounterReading}', [PumpCounterReadingController::class, 'destroy'])->name('pump-counters.destroy')->middleware('role:admin');

        Route::get('shop', [ShopController::class, 'index'])->name('shop.index');
        Route::get('shop/export-pdf', [ShopController::class, 'exportPdf'])->name('shop.export-pdf');
        Route::post('shop/items', [ShopController::class, 'storeItem'])->name('shop.items.store');
        Route::patch('shop/items/{shopItem}', [ShopController::class, 'updateItem'])->name('shop.items.update');
        Route::delete('shop/items/{shopItem}', [ShopController::class, 'destroyItem'])->name('shop.items.destroy');
        Route::post('shop/purchases', [ShopController::class, 'storePurchase'])->name('shop.purchases.store');
        Route::post('shop/sales', [ShopController::class, 'storeSale'])->name('shop.sales.store');
    });

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
        });
    });
});

require __DIR__.'/settings.php';
