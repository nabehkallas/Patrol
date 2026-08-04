<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tank_top_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tank_id')->constrained()->cascadeOnDelete();
            $table->decimal('liters', 10, 3);
            $table->date('date');
            $table->foreignId('recorded_by_id')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tank_top_ups');
    }
};
