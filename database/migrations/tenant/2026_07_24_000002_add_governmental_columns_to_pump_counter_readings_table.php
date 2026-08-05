<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pump_counter_readings', function (Blueprint $table) {
            $table->decimal('governmental_liters', 10, 3)->nullable()->after('liters_sold');
            $table->foreignId('governmental_transaction_id')->nullable()->after('transaction_id')->constrained('transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pump_counter_readings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('governmental_transaction_id');
            $table->dropColumn('governmental_liters');
        });
    }
};
