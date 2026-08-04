<?php

namespace App\Enums;

enum SadcopLedgerEntryType: string
{
    case Opening = 'opening';
    case Deposit = 'deposit';
    case Delivery = 'delivery';
}
