<?php

use App\Models\Debtor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('debtors')->insertOrIgnore([
            'name' => Debtor::GOVERNMENT_NAME,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('debtors')->where('name', Debtor::GOVERNMENT_NAME)->delete();
    }
};
