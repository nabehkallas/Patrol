<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sadcop_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['deposit', 'delivery']);
            $table->foreignId('transaction_id')->unique()->constrained('transactions')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->decimal('exchange_rate_to_usd', 20, 6)->nullable();
            $table->decimal('liters', 14, 3)->nullable();
            $table->decimal('price_per_liter', 14, 4)->nullable();
            $table->foreignId('recorded_by_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('occurred_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sadcop_ledger_entries');
    }
};
