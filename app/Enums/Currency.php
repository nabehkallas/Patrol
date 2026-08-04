<?php

namespace App\Enums;

enum Currency: string
{
    case SYP = 'SYP';
    case TRY = 'TRY';
    case USD = 'USD';

    public function label(): string
    {
        return match ($this) {
            self::SYP => 'Syrian Pound',
            self::TRY => 'Turkish Lira',
            self::USD => 'US Dollar',
        };
    }
}
