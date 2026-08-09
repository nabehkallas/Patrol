<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('shop_item_id')->nullable()->after('fuel_type_id')->constrained('shop_items')->restrictOnDelete();
            $table->integer('quantity')->nullable()->after('liters');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_item_id');
            $table->dropColumn('quantity');
        });
    }
};
