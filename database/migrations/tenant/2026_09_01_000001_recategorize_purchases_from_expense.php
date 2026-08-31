<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Shop purchases previously recorded as a generic Expense now get their own category.
        DB::table('transactions')
            ->where('type', 'expense')
            ->whereNotNull('shop_item_id')
            ->update(['type' => 'purchase']);

        // Sadcop deposits (money transferred to Sadcop) move to the same category — identified
        // via their linked sadcop_ledger_entries row (type = 'deposit'), the same way
        // application code already distinguishes them from other expenses.
        DB::table('transactions')
            ->where('type', 'expense')
            ->whereIn('id', function ($query) {
                $query->select('transaction_id')
                    ->from('sadcop_ledger_entries')
                    ->where('type', 'deposit')
                    ->whereNotNull('transaction_id');
            })
            ->update(['type' => 'purchase']);
    }

    public function down(): void
    {
        DB::table('transactions')->where('type', 'purchase')->update(['type' => 'expense']);
    }
};
