<?php

namespace App\Models;

use App\Enums\TransactionType;
use App\Models\Concerns\SerializesDatesInAppTimezone;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'category'])]
class ShopItem extends Model
{
    use SerializesDatesInAppTimezone;

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<ShopItemPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ShopItemPrice::class);
    }

    public function currentPrice(): ?ShopItemPrice
    {
        return $this->priceAt(now());
    }

    /**
     * The price in effect on a given date: of the prices already effective by then, the one
     * entered most recently. A price edit is never edited in place -- each save adds a row --
     * so a later entry supersedes every earlier one from its own effective date onward. That
     * is what makes a backdated edit stick: setting 350 "effective Sep 1" after a 400 that was
     * effective Sep 6 means 350 from Sep 1 on, not "350 for Sep 1-5, then back to 400".
     * Ordering by effective_at instead let the older Sep 6 row keep winning, so the edit
     * looked like it never saved.
     */
    public function priceAt(CarbonInterface $date): ?ShopItemPrice
    {
        return $this->prices()
            ->effectiveAsOf($date)
            ->latest('id')
            ->first();
    }

    /**
     * Units currently in stock: every unit ever bought in, minus every unit sold out. Mirrors
     * Tank::expectedLiters()'s "derive the current amount from movement history" approach,
     * rather than storing (and risking drift from) a separate running total.
     */
    public function currentStock(): int
    {
        $purchased = (int) $this->transactions()->where('type', TransactionType::Purchase)->sum('quantity');
        $sold = (int) $this->transactions()->where('type', TransactionType::OtherIncome)->sum('quantity');

        return $purchased - $sold;
    }
}
