<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pump_counter_readings', function (Blueprint $table) {
            $table->dropColumn('is_return');
            $table->decimal('return_liters', 10, 3)->nullable()->after('governmental_liters');
        });
    }

    public function down(): void
    {
        Schema::table('pump_counter_readings', function (Blueprint $table) {
            $table->dropColumn('return_liters');
            $table->boolean('is_return')->default(false)->after('governmental_liters');
        });
    }
};
