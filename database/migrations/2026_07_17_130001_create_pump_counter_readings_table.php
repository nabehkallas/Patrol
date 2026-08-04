<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pump_counter_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pump_id')->constrained('fuel_pumps')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('reading_value', 14, 3);
            $table->decimal('liters_sold', 10, 3)->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('recorded_by_id')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['pump_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pump_counter_readings');
    }
};
