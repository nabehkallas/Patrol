<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StationDataResetRequest;
use App\Http\Requests\Settings\StationDataRestoreRequest;
use App\Models\Debtor;
use App\Services\StationBackupRestorer;
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

    /**
     * Sends a backup of this station's database, after the admin re-enters their password (the
     * file holds all of the station's data). It is a fresh, consistent snapshot (VACUUM INTO)
     * rather than the live file, so entries still in SQLite's write-ahead log are included.
     */
    public function downloadBackup(Request $request): BinaryFileResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        $stationName = Str::slug(tenant('name') ?? 'station');
        $snapshot = tempnam(sys_get_temp_dir(), 'station-backup-');
        @unlink($snapshot); // VACUUM INTO needs a path that doesn't exist yet.

        DB::statement('VACUUM INTO ?', [$snapshot]);

        return response()
            ->download($snapshot, "{$stationName}-backup-".now()->format('Y-m-d-His').'.sqlite', [
                'Content-Type' => 'application/vnd.sqlite3',
            ])
            ->deleteFileAfterSend();
    }

    /**
     * Replaces the station's data with the uploaded backup (see StationBackupRestorer for what
     * is and isn't replaced). Requires the admin's current password.
     */
    public function restore(StationDataRestoreRequest $request, StationBackupRestorer $restorer): RedirectResponse
    {
        $result = $restorer->restore($request->file('backup'));

        // A restored station with fuel types set up doesn't need the first-run wizard again.
        if (tenant('onboarded_at') === null && DB::table('fuel_types')->exists()) {
            tenant()->update(['onboarded_at' => now()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Backup restored. :rows records loaded.', ['rows' => number_format($result['rows'])])]);

        return to_route('data.edit');
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
