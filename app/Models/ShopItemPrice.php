<?php

namespace App\Models;

use App\Enums\Currency;
use App\Models\Concerns\SerializesDatesInAppTimezone;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shop_item_id', 'base_price', 'sell_price', 'currency', 'set_by_id', 'effective_at'])]
class ShopItemPrice extends Model
{
    use SerializesDatesInAppTimezone;

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'sell_price' => 'decimal:2',
            'currency' => Currency::class,
            'effective_at' => 'datetime',
        ];
    }

    public function shopItem(): BelongsTo
    {
        return $this->belongsTo(ShopItem::class);
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_id');
    }

    public function scopeEffectiveAsOf($query, CarbonInterface $at)
    {
        return $query->where('effective_at', '<=', $at);
    }
}
