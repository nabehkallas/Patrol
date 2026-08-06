<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            $table->enum('direction', ['receivable', 'payable'])->default('receivable')->after('id');
            $table->index(['direction', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            $table->dropIndex(['direction', 'status']);
            $table->dropColumn('direction');
        });
    }
};
