<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Email verification became mandatory. Accounts that already existed were created and used
 * before that, so they are marked verified rather than locked out. Users created from now on
 * must verify through the link emailed to them. Not reverted: un-verifying people would only
 * lock them out.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        //
    }
};
