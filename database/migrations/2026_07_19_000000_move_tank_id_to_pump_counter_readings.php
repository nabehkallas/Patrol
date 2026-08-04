<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pump_counter_readings', function (Blueprint $table) {
            $table->foreignId('tank_id')->nullable()->after('pump_id')->constrained()->nullOnDelete();
        });

        DB::statement('
            UPDATE pump_counter_readings
            SET tank_id = (
                SELECT tank_id FROM fuel_pumps WHERE fuel_pumps.id = pump_counter_readings.pump_id
            )
        ');

        Schema::table('fuel_pumps', function (Blueprint $table) {
            $table->dropForeign(['tank_id']);
            $table->dropColumn('tank_id');
        });
    }

    public function down(): void
    {
        Schema::table('fuel_pumps', function (Blueprint $table) {
            $table->foreignId('tank_id')->nullable()->constrained()->cascadeOnDelete();
        });

        Schema::table('pump_counter_readings', function (Blueprint $table) {
            $table->dropForeign(['tank_id']);
            $table->dropColumn('tank_id');
        });
    }
};
