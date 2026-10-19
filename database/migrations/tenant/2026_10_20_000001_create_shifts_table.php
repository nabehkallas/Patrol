<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A station's shifts. Every station starts with one (its name left empty, shown as "Shift 1"),
 * so single-shift stations see no change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            // Fixed once set: readings run in order of day, then shift position.
            $table->unsignedSmallInteger('position')->unique();
            $table->string('name', 60)->nullable();
            // "HH:MM", optional; only used to pick the default shift in the entry form.
            $table->string('starts_at', 5)->nullable();
            $table->string('ends_at', 5)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('shifts')->insert(['position' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
