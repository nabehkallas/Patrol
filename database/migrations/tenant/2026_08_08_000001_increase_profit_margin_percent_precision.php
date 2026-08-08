<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_types', function (Blueprint $table) {
            $table->decimal('profit_margin_percent', 7, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('fuel_types', function (Blueprint $table) {
            $table->decimal('profit_margin_percent', 5, 2)->nullable()->change();
        });
    }
};
