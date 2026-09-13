<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per "slice" of a fuel sale's liters costed against a single source: either a
        // historic fuel_cost_layer (Tier 1, FIFO) or the fuel type's current cost basis
        // (Tier 2, fuel_cost_layer_id null). A single sale can straddle a layer's depletion
        // boundary and so produce two rows -- one per tier. Deleting the sale's transaction
        // cascades here, which is also how a layer's "remaining" volume is restored on
        // delete/edit: it's derived live (initial_liters minus SUM(liters) of allocations
        // still pointing at it), never a mutable counter.
        Schema::create('fuel_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fuel_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fuel_cost_layer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('liters', 14, 3);
            $table->decimal('cost_per_liter_syp', 12, 4);
            $table->timestamps();

            $table->index(['fuel_cost_layer_id']);
            $table->index(['transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_cost_allocations');
    }
};
