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
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 3);
            $table->decimal('rate_to_usd', 20, 6);
            $table->foreignId('set_by_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('effective_at');
            $table->timestamps();

            $table->index(['currency', 'effective_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
