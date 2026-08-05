<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_pumps', function (Blueprint $table) {
            $table->foreignId('fuel_type_id')->nullable()->after('name')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fuel_pumps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fuel_type_id');
        });
    }
};
