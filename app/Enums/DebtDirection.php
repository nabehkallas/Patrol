<?php

namespace App\Enums;

enum DebtDirection: string
{
    case Receivable = 'receivable';
    case Payable = 'payable';
}
