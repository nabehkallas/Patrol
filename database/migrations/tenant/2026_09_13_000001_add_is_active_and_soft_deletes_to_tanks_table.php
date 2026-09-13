<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tanks', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('capacity_liters');
            // Soft delete rather than a real row delete -- tank_transfers.from_tank_id/
            // to_tank_id are restrictOnDelete(), and every other table referencing tanks
            // (transactions, pump_counter_readings, inventory_entries, tank_top_ups) would
            // otherwise lose real historical data to a cascade/null-out. A soft-deleted row
            // still physically exists, so none of those constraints or cascades ever fire,
            // and every relation that needs to keep resolving it for historical display uses
            // ->withTrashed() (see Transaction::tank(), PumpCounterReading::tank(), etc.).
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('tanks', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'deleted_at']);
        });
    }
};
