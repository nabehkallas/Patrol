<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The currencies a station works with. Starts with the three the app always supported, SYP
 * as the primary (reporting) currency so existing stations see no change; admins can add any
 * other currency (EUR, JPY, a custom code ...) and pick a different primary one in Settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name');
            $table->string('symbol', 8)->nullable();
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        $now = now();
        DB::table('currencies')->insert([
            ['code' => 'SYP', 'name' => 'Syrian Pound', 'symbol' => 'ل.س', 'decimals' => 0, 'is_active' => true, 'is_primary' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'is_active' => true, 'is_primary' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'TRY', 'name' => 'Turkish Lira', 'symbol' => '₺', 'decimals' => 2, 'is_active' => true, 'is_primary' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
