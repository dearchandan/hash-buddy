<?php

namespace App\Enums;

/**
 * Who a group will accept.
 *
 * WomenOnly filters on the gender a user typed into their own profile. It is a
 * matching preference and nothing more — there is no ID check behind it, so it
 * must not be presented to users as a verified guarantee.
 */
enum GenderPolicy: string
{
    case Any = 'any';
    case WomenOnly = 'women_only';

    public function admits(Gender $gender): bool
    {
        return match ($this) {
            self::Any => true,
            self::WomenOnly => $gender === Gender::Female,
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
