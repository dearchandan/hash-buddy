<?php

namespace App\Enums;

/**
 * Which app the fare was quoted in, and which cab the host intends to book.
 *
 * Always optional. Null means "not decided yet", which is the honest state for
 * someone who has just landed and wants company before they pick a service.
 */
enum CabService: string
{
    case Ola = 'ola';
    case Uber = 'uber';
    case Rapido = 'rapido';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Ola => 'Ola',
            self::Uber => 'Uber',
            self::Rapido => 'Rapido',
            self::Other => 'Other',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
