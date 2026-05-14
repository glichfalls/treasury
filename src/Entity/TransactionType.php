<?php

namespace App\Entity;

enum TransactionType: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case TradeBuy = 'trade_buy';
    case TradeSell = 'trade_sell';
    case Fee = 'fee';
    case Interest = 'interest';
    case Dividend = 'dividend';
    case FxConversion = 'fx_conversion';
    case Other = 'other';
}
