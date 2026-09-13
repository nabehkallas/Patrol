<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A "batch" of pre-existing fuel inventory, tagged with its cost basis at the moment a
        // new price is recorded. FIFO sales draw down remaining_liters (derived from
        // fuel_cost_allocations, never stored/mutated directly -- see FuelCostAllocationService)
        // before falling back to the fuel type's current cost basis.
        Schema::create('fuel_cost_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fuel_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fuel_price_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('cost_per_liter_syp', 12, 4);
            $table->decimal('initial_liters', 14, 3);
            $table->timestamp('effective_from');
            $table->timestamps();

            $table->index(['fuel_type_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_cost_layers');
    }
};
