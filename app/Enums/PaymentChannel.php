<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentChannel: string
{
    case MobileMoney = 'momo';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';

    public function label(): string
    {
        return match ($this) {
            self::MobileMoney => 'Mobile Money',
            self::Card => 'Card',
            self::BankTransfer => 'Bank Transfer',
        };
    }
}
