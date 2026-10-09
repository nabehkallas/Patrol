<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each fuel type keeps its own colour instead of one derived from its position. Existing fuel
 * types get the colours the reports and sales chart show today (by name: the first amber, the
 * second blue, ...), which is also petrol orange and diesel blue on every current station.
 */
return new class extends Migration
{
    private const PALETTE = ['amber', 'blue', 'emerald', 'violet', 'rose', 'cyan', 'lime', 'fuchsia'];

    public function up(): void
    {
        Schema::table('fuel_types', function (Blueprint $table) {
            $table->string('color', 20)->nullable()->after('slug');
        });

        DB::table('fuel_types')->orderBy('name')->orderBy('id')->pluck('id')->each(function (int $id, int $i) {
            DB::table('fuel_types')->where('id', $id)->update([
                'color' => self::PALETTE[$i % count(self::PALETTE)],
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('fuel_types', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
