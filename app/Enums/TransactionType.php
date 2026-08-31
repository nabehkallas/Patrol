<?php

namespace App\Enums;

enum TransactionType: string
{
    case FuelSale = 'fuel_sale';
    case FuelDelivery = 'fuel_delivery';
    case OtherIncome = 'other_income';
    case Expense = 'expense';
    case Purchase = 'purchase';
    case CurrencyExchange = 'currency_exchange';
}
