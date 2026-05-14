<?php

namespace App\Entity;

enum AccountType: string
{
    case BankChecking = 'bank_checking';
    case BankSavings = 'bank_savings';
    case Cash = 'cash';
    case CreditCard = 'credit_card';
    case Brokerage = 'brokerage';
    case CryptoExchange = 'crypto_exchange';
    case CryptoWallet = 'crypto_wallet';
    case RealEstate = 'real_estate';
    case Vehicle = 'vehicle';
    case Other = 'other';
}
