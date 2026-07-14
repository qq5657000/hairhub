<?php

namespace App\Enums\Hairstyle;

/**
 * 对应 hairstyles.age_range
 */
enum HairstyleAgeRange: int
{
    case All = 0;
    case Child = 1;
    case Teenager = 2;
    case Young = 3;
    case MiddleAge = 4;
    case Senior = 5;

    public function label(): string
    {
        return match ($this) {
            self::All => '不限',
            self::Child => '儿童',
            self::Teenager => '青少年',
            self::Young => '青年',
            self::MiddleAge => '中年',
            self::Senior => '中老年',
        };
    }

    public static function options(): array
    {
        return array_combine(
            self::values(),
            array_map(fn (self $case) => $case->label(), self::cases())
        );
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
