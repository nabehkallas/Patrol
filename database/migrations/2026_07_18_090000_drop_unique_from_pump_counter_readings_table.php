<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pump_counter_readings', function (Blueprint $table) {
            $table->dropUnique(['pump_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('pump_counter_readings', function (Blueprint $table) {
            $table->unique(['pump_id', 'date']);
        });
    }
};
