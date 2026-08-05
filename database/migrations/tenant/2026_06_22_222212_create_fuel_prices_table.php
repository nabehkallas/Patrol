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
        Schema::create('fuel_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fuel_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('price_per_liter', 14, 4);
            $table->string('currency', 3);
            $table->foreignId('set_by_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('effective_at');
            $table->timestamps();

            $table->index(['fuel_type_id', 'effective_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fuel_prices');
    }
};
