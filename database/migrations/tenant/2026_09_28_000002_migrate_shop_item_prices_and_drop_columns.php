<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * shop_items.base_price/sell_price/currency held only the current price, with no history --
     * seeds one shop_item_prices row per existing item (dated at the item's own created_at, using
     * whatever price it currently has) before dropping those columns, so ShopItem::currentPrice()
     * resolves correctly immediately after this runs, for every item that already exists.
     */
    public function up(): void
    {
        $recordedBy = DB::table('users')->orderBy('id')->value('id');

        if ($recordedBy) {
            $now = now();

            DB::table('shop_items')->orderBy('id')->get()->each(function ($item) use ($recordedBy, $now) {
                DB::table('shop_item_prices')->insert([
                    'shop_item_id' => $item->id,
                    'base_price' => $item->base_price ?? 0,
                    'sell_price' => $item->sell_price ?? 0,
                    'currency' => $item->currency ?? 'SYP',
                    'set_by_id' => $recordedBy,
                    'effective_at' => $item->created_at ?? $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        }

        Schema::table('shop_items', function (Blueprint $table) {
            $table->dropColumn(['base_price', 'sell_price', 'currency']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_items', function (Blueprint $table) {
            $table->decimal('base_price', 12, 2)->nullable()->after('name');
            $table->decimal('sell_price', 12, 2)->nullable()->after('base_price');
            $table->string('currency')->default('SYP')->after('sell_price');
        });

        foreach (DB::table('shop_item_prices')->orderBy('shop_item_id')->orderBy('effective_at')->get() as $price) {
            DB::table('shop_items')->where('id', $price->shop_item_id)->update([
                'base_price' => $price->base_price,
                'sell_price' => $price->sell_price,
                'currency' => $price->currency,
            ]);
        }
    }
};
