<?php

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class ShopItem extends Model
{
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Units currently in stock: every unit ever bought in, minus every unit sold out. Mirrors
     * Tank::expectedLiters()'s "derive the current amount from movement history" approach,
     * rather than storing (and risking drift from) a separate running total.
     */
    public function currentStock(): int
    {
        $purchased = (int) $this->transactions()->where('type', TransactionType::Expense)->sum('quantity');
        $sold = (int) $this->transactions()->where('type', TransactionType::OtherIncome)->sum('quantity');

        return $purchased - $sold;
    }
}
