<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shop_item_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('base_price', 14, 2);
            $table->decimal('sell_price', 14, 2);
            $table->string('currency', 3);
            $table->foreignId('set_by_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('effective_at');
            $table->timestamps();

            $table->index(['shop_item_id', 'effective_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_item_prices');
    }
};
