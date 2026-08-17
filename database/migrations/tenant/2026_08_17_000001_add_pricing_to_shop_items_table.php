<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_items', function (Blueprint $table) {
            $table->decimal('base_price', 12, 2)->nullable()->after('name');
            $table->decimal('sell_price', 12, 2)->nullable()->after('base_price');
            $table->string('currency')->default('SYP')->after('sell_price');
        });
    }

    public function down(): void
    {
        Schema::table('shop_items', function (Blueprint $table) {
            $table->dropColumn(['base_price', 'sell_price', 'currency']);
        });
    }
};
