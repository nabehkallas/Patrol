<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sadcop_ledger_entries', function (Blueprint $table) {
            $table->dropUnique(['transaction_id']);
            $table->foreignId('transaction_id')->nullable()->change();
            $table->dropColumn(['currency', 'exchange_rate_to_usd']);
            $table->unique('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('sadcop_ledger_entries', function (Blueprint $table) {
            $table->string('currency', 3)->default('SYP')->after('amount');
            $table->decimal('exchange_rate_to_usd', 20, 6)->nullable()->after('currency');
        });
    }
};
