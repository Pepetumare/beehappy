<?php

namespace App\Enums;

enum SessionOfferType: string
{
    case Single = 'single';
    case Pack = 'pack';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Sesión individual',
            self::Pack => 'Pack',
        };
    }
}
