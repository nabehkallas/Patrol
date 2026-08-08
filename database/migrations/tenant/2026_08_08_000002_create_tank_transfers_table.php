<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tank_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_tank_id')->constrained('tanks')->restrictOnDelete();
            $table->foreignId('to_tank_id')->constrained('tanks')->restrictOnDelete();
            $table->decimal('liters', 14, 3);
            $table->date('date');
            $table->foreignId('recorded_by_id')->constrained('users')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tank_transfers');
    }
};
