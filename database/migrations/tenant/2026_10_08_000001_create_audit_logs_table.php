<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only record of critical changes in this station (who changed what, from what, to what,
 * when and from where). Lives in the station's own database, so it is written on the same
 * machine and in the same transaction as the change itself. The two triggers make the table
 * immutable at the database level: any UPDATE or DELETE is rejected, whatever code runs it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            // Kept as written, so the entry still says who it was if the account is later removed.
            $table->string('user_name')->nullable();
            $table->string('action', 40);
            $table->string('entity_type', 60)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at');

            $table->index(['entity_type', 'entity_id']);
            $table->index('created_at');
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs
            BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only'); END;
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs
            BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only'); END;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
        Schema::dropIfExists('audit_logs');
    }
};
