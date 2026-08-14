<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debtors', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('id')->constrained('debtors')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('debtors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
