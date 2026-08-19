<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentType: string
{
    case Tithe = 'tithe';
    case Offering = 'offering';
    case Welfare = 'welfare';
    case BuildingFund = 'building_fund';
    case SpecialSeed = 'special_seed';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Tithe => 'Tithe',
            self::Offering => 'Offering',
            self::Welfare => 'Welfare',
            self::BuildingFund => 'Building Fund',
            self::SpecialSeed => 'Special Seed',
            self::Other => 'Other',
        };
    }
}
