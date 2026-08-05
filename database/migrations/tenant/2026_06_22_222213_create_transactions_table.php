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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['fuel_sale', 'fuel_delivery', 'other_income', 'expense']);
            $table->foreignId('fuel_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tank_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('liters', 14, 3)->nullable();
            $table->decimal('price_per_liter', 14, 4)->nullable();
            $table->string('description')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->decimal('exchange_rate_to_usd', 20, 6)->nullable();
            $table->timestamp('occurred_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
            $table->index(['tank_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
