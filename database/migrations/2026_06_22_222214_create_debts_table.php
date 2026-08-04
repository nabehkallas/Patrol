<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debts', function (Blueprint $table) {
            $table->id();
            $table->enum('direction', ['receivable', 'payable']);
            $table->string('name');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->decimal('exchange_rate_to_usd', 20, 6)->nullable();
            $table->date('date');
            $table->text('details')->nullable();
            $table->enum('status', ['outstanding', 'settled'])->default('outstanding');
            $table->foreignId('recorded_by_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['direction', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debts');
    }
};
