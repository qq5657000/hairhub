<?php

namespace App\Enums\Hairstyle;

/**
 * 对应 hairstyles.hair_volume
 */
enum HairstyleHairVolume: int
{
    case All = 0;
    case Low = 1;
    case Medium = 2;
    case High = 3;

    public function label(): string
    {
        return match ($this) {
            self::All => '不限',
            self::Low => '发量少',
            self::Medium => '发量适中',
            self::High => '发量多',
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
