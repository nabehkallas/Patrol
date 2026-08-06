<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('earnings_password_reset_tokens');
    }

    public function down(): void
    {
        Schema::create('earnings_password_reset_tokens', function (Blueprint $table) {
            $table->string('token')->primary();
            $table->timestamp('created_at')->nullable();
        });
    }
};
