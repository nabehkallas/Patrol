<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 'today' or 'yesterday' -- which calendar date new-entry forms (pump readings,
            // cash transactions, debts, inventory, ...) pre-fill for this user. Per-user, not
            // per-station: a station terminal is often shared across shifts, and it's the
            // person closing out a previous day's shift after midnight who wants "yesterday",
            // not necessarily everyone else using the same computer.
            $table->string('default_entry_date')->default('today')->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('default_entry_date');
        });
    }
};
