<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\EarningsController;
use App\Http\Controllers\Admin\ExchangeRateController;
use App\Http\Controllers\Admin\FuelPriceController;
use App\Http\Controllers\Admin\FuelPumpController;
use App\Http\Controllers\Admin\FuelTypeController;
use App\Http\Controllers\Admin\TankController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AnnualSummaryAccessController;
use App\Http\Controllers\CashBoxController;
use App\Http\Controllers\DebtController;
use App\Http\Controllers\DebtorController;
use App\Http\Controllers\ForcePasswordChangeController;
use App\Http\Controllers\InventoryEntryController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\LandingEditorController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PlatformAccountController;
use App\Http\Controllers\PumpCounterReadingController;
use App\Http\Controllers\SadcopController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\StationController;
use App\Http\Controllers\StationRegistrationController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\TankTopUpController;
use App\Http\Controllers\TankTransferController;
use App\Http\Controllers\TankVolumeCalculatorController;
use App\Http\Controllers\TodayController;
use App\Http\Controllers\TransactionController;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\RequireActiveStation;
use App\Http\Middleware\RequireOnboarding;
use App\Http\Middleware\RequireSuperAdmin;
use App\Http\Middleware\RequireTenant;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class)->name('home');
Route::get('landing-media/{file}', [LandingEditorController::class, 'media'])
    ->where('file', '[A-Za-z0-9]{32}\.(mp4|webm|jpg|png|webp)')
    ->name('landing.media');
Route::redirect('super-admin', '/platform');

// Public sign-up for station owners (see StationRegistrationController).
Route::middleware('guest')->group(function () {
    Route::get('register', [StationRegistrationController::class, 'create'])->name('register');
    Route::post('register', [StationRegistrationController::class, 'store'])->name('register.store')->middleware('throttle:5,1');
});

Route::get('registration/pending', [StationRegistrationController::class, 'pending'])
    ->middleware(['auth', 'verified', RequireTenant::class])
    ->name('registration.pending');

Route::middleware(['auth', 'auth.session', RequireSuperAdmin::class])->prefix('platform')->name('platform.')->group(function () {
    Route::get('/', [StationController::class, 'index'])->name('home');
    Route::get('stations/create', [StationController::class, 'create'])->name('stations.create');
    Route::post('stations', [StationController::class, 'store'])->name('stations.store');
    Route::get('stations/{tenant}/users', [StationController::class, 'users'])->name('stations.users');
    // Each of these asks for the platform admin's current password (throttled against guessing).
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('stations/{tenant}/approve', [StationController::class, 'approve'])->name('stations.approve');
        Route::delete('stations/{tenant}/reject', [StationController::class, 'reject'])->name('stations.reject');
        Route::post('stations/{tenant}/suspend', [StationController::class, 'suspend'])->name('stations.suspend');
        Route::post('stations/{tenant}/subscription', [StationController::class, 'setSubscription'])->name('stations.subscription');
        Route::post('stations/{tenant}/reactivate', [StationController::class, 'reactivate'])->name('stations.reactivate');
        Route::post('stations/{tenant}/reset-admin-password', [StationController::class, 'resetAdminPassword'])->name('stations.reset-admin-password');
        Route::delete('stations/{tenant}', [StationController::class, 'destroy'])->name('stations.destroy');
    });
    Route::patch('account', [PlatformAccountController::class, 'updateProfile'])->name('account.update');
    Route::get('landing', [LandingEditorController::class, 'edit'])->name('landing.edit');
    Route::post('landing', [LandingEditorController::class, 'publish'])->name('landing.publish');
    Route::post('landing/media', [LandingEditorController::class, 'upload'])->name('landing.upload');
    Route::put('account/password', [PlatformAccountController::class, 'updatePassword'])->name('account.password')->middleware('throttle:6,1');
});

// 'verified': a station account can't use the app until its email address is confirmed through
// the link emailed to it (Fortify's verification.* routes and screen handle that step).
Route::middleware(['auth', 'auth.session', 'verified', RequireTenant::class, RequireActiveStation::class, ForcePasswordChange::class])->group(function () {
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
        Route::get('today', TodayController::class)->name('today');

        Route::get('cash-box', [CashBoxController::class, 'index'])->name('cash-box.index');
        Route::get('cash-box/export-pdf', [CashBoxController::class, 'exportPdf'])->name('cash-box.export-pdf');
        Route::get('cash-box/export-xlsx', [CashBoxController::class, 'exportXlsx'])->name('cash-box.export-xlsx');

        Route::resource('transactions', TransactionController::class)->except('show');
        Route::get('transactions/export-pdf', [TransactionController::class, 'exportPdf'])->name('transactions.export-pdf');
        Route::get('transactions/export-xlsx', [TransactionController::class, 'exportXlsx'])->name('transactions.export-xlsx');

        Route::get('inventory', [InventoryEntryController::class, 'index'])->name('inventory.index');
        Route::post('inventory', [InventoryEntryController::class, 'store'])->name('inventory.store');
        Route::get('inventory/export-entries-pdf', [InventoryEntryController::class, 'exportEntriesPdf'])->name('inventory.export-entries-pdf');
        Route::get('inventory/export-topups-pdf', [InventoryEntryController::class, 'exportTopUpsPdf'])->name('inventory.export-topups-pdf');
        Route::get('inventory/export-tanks-xlsx', [InventoryEntryController::class, 'exportTanksLedgerXlsx'])->name('inventory.export-tanks-xlsx');
        Route::get('inventory/entries/{entry}/edit', [InventoryEntryController::class, 'editEntry'])->name('inventory.entries.edit')->middleware('role:admin');
        Route::patch('inventory/entries/{entry}', [InventoryEntryController::class, 'updateEntry'])->name('inventory.entries.update')->middleware('role:admin');
        Route::delete('inventory/entries/{entry}', [InventoryEntryController::class, 'destroyEntry'])->name('inventory.entries.destroy')->middleware('role:admin');
        Route::post('tank-top-ups', [TankTopUpController::class, 'store'])->name('tank-top-ups.store');
        Route::get('tank-top-ups/{topUp}/edit', [TankTopUpController::class, 'edit'])->name('tank-top-ups.edit')->middleware('role:admin');
        Route::patch('tank-top-ups/{topUp}', [TankTopUpController::class, 'update'])->name('tank-top-ups.update')->middleware('role:admin');
        Route::delete('tank-top-ups/{topUp}', [TankTopUpController::class, 'destroy'])->name('tank-top-ups.destroy')->middleware('role:admin');
        Route::post('tank-transfers', [TankTransferController::class, 'store'])->name('tank-transfers.store');
        Route::get('tank-transfers/{transfer}/edit', [TankTransferController::class, 'edit'])->name('tank-transfers.edit')->middleware('role:admin');
        Route::patch('tank-transfers/{transfer}', [TankTransferController::class, 'update'])->name('tank-transfers.update')->middleware('role:admin');
        Route::delete('tank-transfers/{transfer}', [TankTransferController::class, 'destroy'])->name('tank-transfers.destroy')->middleware('role:admin');

        Route::get('tools/tank-volume', [TankVolumeCalculatorController::class, 'index'])->name('tools.tank-volume')->middleware('role:admin');

        Route::get('debts/settle-filtered/preview', [DebtController::class, 'settleFilteredPreview'])->name('debts.settle-filtered.preview')->middleware('role:admin');
        Route::patch('debts/settle-filtered', [DebtController::class, 'settleFiltered'])->name('debts.settle-filtered')->middleware('role:admin');
        // Everyone records debts and collects payments on them (settle in full, or part-pay into the
        // cash box); changing or deleting a recorded debt is for admins only.
        Route::resource('debts', DebtController::class)->only(['index', 'create', 'store']);
        Route::resource('debts', DebtController::class)->only(['edit', 'update', 'destroy'])->middleware('role:admin');
        Route::get('debts/export-pdf', [DebtController::class, 'exportPdf'])->name('debts.export-pdf');
        Route::get('debts/export-xlsx', [DebtController::class, 'exportXlsx'])->name('debts.export-xlsx');
        Route::patch('debts/{debt}/settle', [DebtController::class, 'settle'])->name('debts.settle');
        Route::post('debts/{debt}/payments', [DebtController::class, 'storePayment'])->name('debts.payments.store');
        Route::patch('debts/{debt}/transfer', [DebtController::class, 'transfer'])->name('debts.transfer')->middleware('role:admin');

        // Everyone sees balances and can add a new customer; editing or deleting one is for admins.
        Route::resource('debtors', DebtorController::class)->only(['index', 'create', 'store']);
        Route::resource('debtors', DebtorController::class)->only(['edit', 'update', 'destroy'])->middleware('role:admin');
        Route::get('debtors/export-pdf', [DebtorController::class, 'exportPdf'])->name('debtors.export-pdf');
        Route::get('debtors/export-xlsx', [DebtorController::class, 'exportXlsx'])->name('debtors.export-xlsx');
        Route::patch('debtors/{debtor}/settle-all', [DebtorController::class, 'settleAll'])->name('debtors.settle-all');

        Route::get('sadcop', [SadcopController::class, 'index'])->name('sadcop.index');
        Route::get('sadcop/export-pdf', [SadcopController::class, 'exportPdf'])->name('sadcop.export-pdf');
        Route::get('sadcop/export-xlsx', [SadcopController::class, 'exportXlsx'])->name('sadcop.export-xlsx');
        Route::get('sadcop/deliveries/create', [SadcopController::class, 'createDelivery'])->name('sadcop.deliveries.create');
        Route::post('sadcop/deliveries', [SadcopController::class, 'storeDelivery'])->name('sadcop.deliveries.store');
        Route::get('sadcop/deposits/create', [SadcopController::class, 'createDeposit'])->name('sadcop.deposits.create')->middleware('role:admin');
        Route::post('sadcop/deposits', [SadcopController::class, 'storeDeposit'])->name('sadcop.deposits.store')->middleware('role:admin');
        Route::post('sadcop/opening-balance', [SadcopController::class, 'storeOpeningBalance'])->name('sadcop.opening-balance.store')->middleware('role:admin');
        Route::get('sadcop/entries/{entry}/edit', [SadcopController::class, 'editEntry'])->name('sadcop.entries.edit')->middleware('role:admin');
        Route::patch('sadcop/entries/{entry}', [SadcopController::class, 'updateEntry'])->name('sadcop.entries.update')->middleware('role:admin');
        Route::delete('sadcop/entries/{entry}', [SadcopController::class, 'destroyEntry'])->name('sadcop.entries.destroy')->middleware('role:admin');

        Route::middleware('role:admin')->group(function () {
            Route::get('statistics', [StatisticsController::class, 'index'])->name('statistics.index');
            Route::get('statistics/export-pdf', [StatisticsController::class, 'exportPdf'])->name('statistics.export-pdf');
            Route::get('statistics/export-xlsx', [StatisticsController::class, 'exportXlsx'])->name('statistics.export-xlsx');
            Route::post('statistics/annual/unlock', [AnnualSummaryAccessController::class, 'unlock'])->name('statistics.annual.unlock')->middleware('throttle:5,1');
            Route::post('statistics/annual/lock', [AnnualSummaryAccessController::class, 'lock'])->name('statistics.annual.lock');
        });

        Route::get('pump-counters', [PumpCounterReadingController::class, 'index'])->name('pump-counters.index');
        Route::get('pump-counters/export-pdf', [PumpCounterReadingController::class, 'exportPdf'])->name('pump-counters.export-pdf');
        Route::get('pump-counters/export-xlsx', [PumpCounterReadingController::class, 'exportXlsx'])->name('pump-counters.export-xlsx');
        Route::post('pump-counters', [PumpCounterReadingController::class, 'store'])->name('pump-counters.store');
        Route::post('pump-counters/bulk', [PumpCounterReadingController::class, 'storeBulk'])->name('pump-counters.store-bulk');
        Route::get('pump-counters/{pumpCounterReading}/edit', [PumpCounterReadingController::class, 'edit'])->name('pump-counters.edit')->middleware('role:admin');
        Route::patch('pump-counters/{pumpCounterReading}', [PumpCounterReadingController::class, 'update'])->name('pump-counters.update')->middleware('role:admin');
        Route::delete('pump-counters/{pumpCounterReading}', [PumpCounterReadingController::class, 'destroy'])->name('pump-counters.destroy')->middleware('role:admin');

        Route::get('shop', [ShopController::class, 'index'])->name('shop.index');
        Route::get('shop/export-pdf', [ShopController::class, 'exportPdf'])->name('shop.export-pdf');
        Route::get('shop/export-xlsx', [ShopController::class, 'exportXlsx'])->name('shop.export-xlsx');
        Route::post('shop/items', [ShopController::class, 'storeItem'])->name('shop.items.store');
        Route::patch('shop/items/{shopItem}', [ShopController::class, 'updateItem'])->name('shop.items.update');
        Route::delete('shop/items/{shopItem}', [ShopController::class, 'destroyItem'])->name('shop.items.destroy');
        Route::post('shop/purchases', [ShopController::class, 'storePurchase'])->name('shop.purchases.store');
        Route::post('shop/sales', [ShopController::class, 'storeSale'])->name('shop.sales.store');
        Route::patch('shop/transactions/{transaction}', [ShopController::class, 'updateTransaction'])->name('shop.transactions.update');
    });

    Route::middleware(['role:admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', UserController::class)->except('show');
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password')->middleware('throttle:10,1');
        Route::patch('users/{user}/toggle-disabled', [UserController::class, 'toggleDisabled'])->name('users.toggle-disabled');
        Route::resource('fuel-types', FuelTypeController::class)->except('show');
        Route::resource('tanks', TankController::class)->except('show');
        Route::patch('tanks/{tank}/toggle-active', [TankController::class, 'toggleActive'])->name('tanks.toggle-active');

        Route::get('fuel-prices', [FuelPriceController::class, 'index'])->name('fuel-prices.index');
        Route::post('fuel-prices', [FuelPriceController::class, 'store'])->name('fuel-prices.store');
        Route::patch('fuel-prices/{fuelPrice}', [FuelPriceController::class, 'update'])->name('fuel-prices.update');
        Route::delete('fuel-prices/{fuelPrice}', [FuelPriceController::class, 'destroy'])->name('fuel-prices.destroy');
        Route::patch('fuel-prices/profit-margin/{fuelType}', [FuelPriceController::class, 'updateProfitMargin'])->name('fuel-prices.profit-margin');

        Route::get('exchange-rates', [ExchangeRateController::class, 'index'])->name('exchange-rates.index');
        Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
        Route::post('exchange-rates', [ExchangeRateController::class, 'store'])->name('exchange-rates.store');

        Route::resource('fuel-pumps', FuelPumpController::class)->except('show');

        Route::prefix('earnings')->name('earnings.')->group(function () {
            Route::get('/', [EarningsController::class, 'index'])->name('index');
            Route::get('export-xlsx', [EarningsController::class, 'exportXlsx'])->name('export-xlsx');
            Route::post('unlock', [EarningsController::class, 'unlock'])->name('unlock')->middleware('throttle:5,1');
            Route::post('setup', [EarningsController::class, 'setup'])->name('setup');
        });
    });
});

require __DIR__.'/settings.php';
