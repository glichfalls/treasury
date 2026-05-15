<?php

namespace App\Entity;

/**
 * Spending / income categories. Fixed enum (rather than free text) so the
 * dashboard cashflow-by-category breakdown stays comprehensible — free text
 * invites "Groceries", "groceries ", "Migros" all looking different.
 *
 * Add a new case here + migration if a category is genuinely missing; don't
 * fork on every nuance.
 */
enum TransactionCategory: string
{
    // Income
    case Salary = 'salary';
    case Interest = 'interest';
    case Dividend = 'dividend';
    case Gift = 'gift';

    // Recurring household
    case Rent = 'rent';
    case Utilities = 'utilities';
    case Insurance = 'insurance';
    case Subscriptions = 'subscriptions';

    // Day-to-day spending
    case Groceries = 'groceries';
    case Dining = 'dining';
    case Transport = 'transport';
    case Healthcare = 'healthcare';
    case Entertainment = 'entertainment';
    case Travel = 'travel';
    case Shopping = 'shopping';
    case Education = 'education';

    // Money movement
    case Transfer = 'transfer';
    case Savings = 'savings';
    case Tax = 'tax';
    case Fees = 'fees';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Salary => 'Salary',
            self::Interest => 'Interest',
            self::Dividend => 'Dividend',
            self::Gift => 'Gift',
            self::Rent => 'Rent',
            self::Utilities => 'Utilities',
            self::Insurance => 'Insurance',
            self::Subscriptions => 'Subscriptions',
            self::Groceries => 'Groceries',
            self::Dining => 'Dining',
            self::Transport => 'Transport',
            self::Healthcare => 'Healthcare',
            self::Entertainment => 'Entertainment',
            self::Travel => 'Travel',
            self::Shopping => 'Shopping',
            self::Education => 'Education',
            self::Transfer => 'Transfer',
            self::Savings => 'Savings',
            self::Tax => 'Tax',
            self::Fees => 'Fees',
            self::Other => 'Other',
        };
    }
}
