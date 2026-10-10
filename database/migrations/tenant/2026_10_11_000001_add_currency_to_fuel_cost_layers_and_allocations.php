<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fuel cost (a layer's frozen cost, an allocation's cost per liter) is kept in the currency of
 * the fuel price it came from, instead of being converted into SYP at an exchange rate. Every
 * existing row was worked out in SYP, so it is labelled SYP; its value is not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_cost_layers', function (Blueprint $table) {
            $table->string('currency', 3)->default('SYP')->after('cost_per_liter_syp');
        });

        Schema::table('fuel_cost_allocations', function (Blueprint $table) {
            $table->string('currency', 3)->default('SYP')->after('cost_per_liter_syp');
        });
    }

    public function down(): void
    {
        Schema::table('fuel_cost_layers', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        Schema::table('fuel_cost_allocations', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
