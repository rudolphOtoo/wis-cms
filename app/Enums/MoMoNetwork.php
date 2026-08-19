<?php

declare(strict_types=1);

namespace App\Enums;

enum MoMoNetwork: string
{
    case MTN = 'mtn';
    case Telecel = 'telecel';
    case AT = 'at';

    public function label(): string
    {
        return match ($this) {
            self::MTN => 'MTN MoMo',
            self::Telecel => 'Telecel Cash',
            self::AT => 'AirtelTigo Money',
        };
    }

    /**
     * Paystack provider code for the Charge API mobile_money.provider field.
     */
    public function paystackProvider(): string
    {
        return match ($this) {
            self::MTN => 'mtn',
            self::Telecel => 'vod',
            self::AT => 'atl',
        };
    }
}
