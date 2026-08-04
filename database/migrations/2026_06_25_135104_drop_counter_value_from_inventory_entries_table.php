<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_entries', function (Blueprint $table) {
            $table->dropColumn('counter_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_entries', function (Blueprint $table) {
            $table->decimal('counter_value', 14, 3)->nullable()->after('quantity_liters');
        });
    }
};
