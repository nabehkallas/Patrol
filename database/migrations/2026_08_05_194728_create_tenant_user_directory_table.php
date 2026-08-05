<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * A central-only lookup used purely to route a login attempt to the right tenant database —
     * the tenant's own `users` table stays the source of truth for the password itself. Kept in
     * sync whenever a tenant user is created/updated/deleted (station provisioning, admin/users
     * management).
     */
    public function up(): void
    {
        Schema::create('tenant_user_directory', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('tenant_id');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_user_directory');
    }
};
