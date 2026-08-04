<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tanks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fuel_type_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('capacity_liters', 14, 3);
            $table->timestamps();

            $table->unique(['fuel_type_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tanks');
    }
};
