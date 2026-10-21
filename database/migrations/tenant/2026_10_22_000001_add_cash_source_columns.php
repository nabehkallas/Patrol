<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paying in another currency from the primary-currency box: the record keeps what actually
 * left the box (in the primary currency), plus the amount, currency and rate it was for --
 * information only, nothing else reads them. A debt payment keeps reducing the debt in the
 * debt's own currency; the cash_* columns say what left the box instead, when it differs.
 * And one opening cash amount per box, for the cash already there when the station started.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('original_amount', 15, 2)->nullable()->after('currency');
            $table->string('original_currency', 3)->nullable()->after('original_amount');
            $table->decimal('original_rate', 18, 6)->nullable()->after('original_currency');
        });

        Schema::table('debt_payments', function (Blueprint $table) {
            $table->decimal('cash_amount', 15, 2)->nullable()->after('amount');
            $table->string('cash_currency', 3)->nullable()->after('cash_amount');
            $table->decimal('cash_rate', 18, 6)->nullable()->after('cash_currency');
        });

        Schema::create('cash_box_openings', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 3)->unique();
            $table->decimal('amount', 15, 2);
            $table->date('date');
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_box_openings');

        Schema::table('debt_payments', function (Blueprint $table) {
            $table->dropColumn(['cash_amount', 'cash_currency', 'cash_rate']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['original_amount', 'original_currency', 'original_rate']);
        });
    }
};
