<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuel_pump_fuel_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pump_id')->constrained('fuel_pumps')->cascadeOnDelete();
            $table->foreignId('fuel_type_id')->constrained()->cascadeOnDelete();
            $table->unique(['pump_id', 'fuel_type_id']);
        });

        // Carry each pump's existing single fuel type over into the new pivot before the
        // column disappears. Pumps that had no fuel type (null) simply get no pivot rows.
        DB::table('fuel_pumps')->whereNotNull('fuel_type_id')->select('id', 'fuel_type_id')
            ->orderBy('id')
            ->get()
            ->each(function ($pump) {
                DB::table('fuel_pump_fuel_type')->insert([
                    'pump_id' => $pump->id,
                    'fuel_type_id' => $pump->fuel_type_id,
                ]);
            });

        Schema::table('fuel_pumps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fuel_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('fuel_pumps', function (Blueprint $table) {
            $table->foreignId('fuel_type_id')->nullable()->after('name')->constrained()->nullOnDelete();
        });

        // A pump could now have several fuel types; only the first can survive going back to
        // a single-column shape, which is an inherent, accepted loss of the rollback.
        DB::table('fuel_pump_fuel_type')
            ->select('pump_id', DB::raw('MIN(fuel_type_id) as fuel_type_id'))
            ->groupBy('pump_id')
            ->get()
            ->each(function ($row) {
                DB::table('fuel_pumps')->where('id', $row->pump_id)->update(['fuel_type_id' => $row->fuel_type_id]);
            });

        Schema::dropIfExists('fuel_pump_fuel_type');
    }
};
