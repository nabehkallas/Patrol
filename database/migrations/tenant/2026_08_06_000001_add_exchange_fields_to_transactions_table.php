<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('to_currency', 3)->nullable()->after('currency');
            $table->decimal('to_amount', 14, 2)->nullable()->after('to_currency');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['to_currency', 'to_amount']);
        });
    }
};
