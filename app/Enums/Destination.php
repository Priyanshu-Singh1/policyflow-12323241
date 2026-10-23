<?php

namespace App\Enums;

enum Destination: string
{
    case DOMESTIC = 'DOMESTIC';
    case INTERNATIONAL = 'INTERNATIONAL';

    public function multiplier(): float
    {
        return match ($this) {
            self::DOMESTIC => 1.00,
            self::INTERNATIONAL => 1.80,
        };
    }
}
