<?php

namespace App\Models;

use App\Enums\TransactionType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'category'])]
class ShopItem extends Model
{
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ShopItemPrice::class);
    }

    public function currentPrice(): ?ShopItemPrice
    {
        return $this->prices()
            ->where('effective_at', '<=', now())
            ->latest('effective_at')
            ->first();
    }

    /**
     * The price in effect on a given date -- mirrors FuelType::priceAt(), same reasoning: a
     * backdated correction is stored at startOfDay(), so two corrections entered for the same
     * date share an identical effective_at -- id breaks the tie in entry order.
     */
    public function priceAt(CarbonInterface $date): ?ShopItemPrice
    {
        return $this->prices()
            ->effectiveAsOf($date)
            ->latest('effective_at')
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
