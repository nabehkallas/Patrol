<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'phone'])]
class Debtor extends Model
{
    public const GOVERNMENT_NAME = 'حكومي';

    public function debts(): HasMany
    {
        return $this->hasMany(Debt::class);
    }

    public static function government(): self
    {
        return static::firstOrCreate(['name' => self::GOVERNMENT_NAME]);
    }
}
