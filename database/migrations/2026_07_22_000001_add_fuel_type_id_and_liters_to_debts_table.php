<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            $table->foreignId('fuel_type_id')->nullable()->after('debtor_id')->constrained('fuel_types')->nullOnDelete();
            $table->decimal('liters', 14, 3)->nullable()->after('fuel_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fuel_type_id');
            $table->dropColumn('liters');
        });
    }
};
