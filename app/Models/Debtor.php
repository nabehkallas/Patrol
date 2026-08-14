<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'phone', 'parent_id'])]
class Debtor extends Model
{
    public const GOVERNMENT_NAME = 'حكومي';

    public function debts(): HasMany
    {
        return $this->hasMany(Debt::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Debtor::class, 'parent_id');
    }

    /**
     * Sub-debtors — e.g. individual employees under a company debtor. Kept to a single level
     * (a sub-debtor can't itself have children) so the hierarchy stays simple to reason about.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Debtor::class, 'parent_id');
    }

    public static function government(): self
    {
        return static::firstOrCreate(['name' => self::GOVERNMENT_NAME]);
    }
}
