<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_types', function (Blueprint $table) {
            $table->dropColumn('profit_margin_syp');
            $table->decimal('profit_margin_percent', 5, 2)->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('fuel_types', function (Blueprint $table) {
            $table->dropColumn('profit_margin_percent');
            $table->decimal('profit_margin_syp', 12, 2)->nullable()->after('slug');
        });
    }
};
