<?php

namespace App\Enums;

enum DebtStatus: string
{
    case Outstanding = 'outstanding';
    case Settled = 'settled';
}
