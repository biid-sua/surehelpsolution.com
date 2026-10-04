<?php

namespace App\Enums;

/**
 * How a payment arrived. Payoneer covers card, bank debit (ACH) and Payoneer balance today.
 */
enum PaymentMethod: string
{
    case PayoneerCard = 'payoneer_card';
    case PayoneerBank = 'payoneer_bank';
    case PayoneerBalance = 'payoneer_balance';
    case BankTransfer = 'bank_transfer';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PayoneerCard => 'Card (Payoneer)',
            self::PayoneerBank => 'Bank debit / ACH (Payoneer)',
            self::PayoneerBalance => 'Payoneer balance',
            self::BankTransfer => 'Bank transfer',
            self::Other => 'Other',
        };
    }

    public function provider(): string
    {
        return str_starts_with($this->value, 'payoneer') ? 'payoneer' : 'manual';
    }
}
