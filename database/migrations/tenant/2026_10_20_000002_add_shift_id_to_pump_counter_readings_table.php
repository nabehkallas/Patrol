<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pump readings belong to a date + shift. Every existing reading becomes shift 1; nothing else
 * about it changes (value, liters, sales, date and order stay as they are).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pump_counter_readings', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('pump_id')->constrained('shifts')->restrictOnDelete();
            $table->index(['pump_id', 'date', 'shift_id']);
        });

        $firstShift = DB::table('shifts')->orderBy('position')->value('id');
        DB::table('pump_counter_readings')->whereNull('shift_id')->update(['shift_id' => $firstShift]);
    }

    public function down(): void
    {
        Schema::table('pump_counter_readings', function (Blueprint $table) {
            $table->dropIndex(['pump_id', 'date', 'shift_id']);
            $table->dropConstrainedForeignId('shift_id');
        });
    }
};
