<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamp('paid_at');
            $table->foreignId('recorded_by_id')->constrained('users')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Every debt already settled before this feature existed becomes a single full-amount
        // payment, dated at its original settlement time — so Cash Box (now reading exclusively
        // from debt_payments) keeps showing exactly the same history it did before.
        DB::table('debts')->where('status', 'settled')->orderBy('id')
            ->get(['id', 'amount', 'settled_at', 'date', 'recorded_by_id'])
            ->each(function ($debt) {
                DB::table('debt_payments')->insert([
                    'debt_id' => $debt->id,
                    'amount' => $debt->amount,
                    'paid_at' => $debt->settled_at ?? $debt->date,
                    'recorded_by_id' => $debt->recorded_by_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_payments');
    }
};
