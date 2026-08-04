<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('tank_counter_readings');
    }

    public function down(): void
    {
        Schema::create('tank_counter_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tank_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('counter_value', 14, 3);
            $table->foreignId('recorded_by_id')->constrained('users')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['tank_id', 'date']);
        });
    }
};
