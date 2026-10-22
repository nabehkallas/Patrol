<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A station sign-up waiting for its email to be confirmed. Nothing else exists for it yet -- no
 * station, no database -- until the link in the email is opened (PendingRegistration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('station_name');
            $table->string('owner_name');
            $table->string('email')->unique();
            $table->string('phone', 30);
            $table->string('password'); // already hashed
            $table->string('token_hash', 64);
            $table->string('ip', 45)->index();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_registrations');
    }
};
