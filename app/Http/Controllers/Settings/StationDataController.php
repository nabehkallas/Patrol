<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StationDataResetRequest;
use App\Models\Debtor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StationDataController extends Controller
{
    /**
     * Tables holding operational station data. Users, roles/permissions, and framework
     * bookkeeping tables (migrations/cache/jobs) are deliberately excluded so a reset never
     * touches who can log in — only what they see once they do.
     */
    private const OPERATIONAL_TABLES = [
        'pump_counter_readings',
        'tank_transfers',
        'tank_top_ups',
        'debts',
        'transactions',
        'sadcop_ledger_entries',
        'inventory_entries',
        'shop_items',
        'debtors',
        'fuel_pumps',
        'fuel_prices',
        'exchange_rates',
        'tanks',
        'fuel_types',
        'earnings_password',
    ];

    public function edit(Request $request): Response
    {
        return Inertia::render('settings/data');
    }

    public function downloadBackup(Request $request): BinaryFileResponse
    {
        $path = config('database.connections.tenant.database');
        $stationName = Str::slug(tenant('name') ?? 'station');

        return response()->download($path, "{$stationName}-backup-".now()->format('Y-m-d-His').'.sqlite');
    }

    public function reset(StationDataResetRequest $request): RedirectResponse
    {
        Schema::disableForeignKeyConstraints();

        DB::transaction(function () {
            foreach (self::OPERATIONAL_TABLES as $table) {
                DB::table($table)->delete();
            }

            Debtor::government();
        });

        Schema::enableForeignKeyConstraints();

        tenant()->update(['onboarded_at' => null]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Station data reset.')]);

        return to_route('cash-box.index');
    }
}
