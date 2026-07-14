<?php

namespace App\Enums\Hairstyle;

/**
 * 对应 hairstyles.style_type
 */
enum HairstyleStyleType: int
{
    case Other = 0;
    case Korean = 1;
    case Japanese = 2;
    case Business = 3;
    case Casual = 4;
    case Fashion = 5;
    case Retro = 6;
    case Sweet = 7;
    case Cool = 8;

    public function label(): string
    {
        return match ($this) {
            self::Other => '其他',
            self::Korean => '韩系',
            self::Japanese => '日系',
            self::Business => '商务',
            self::Casual => '休闲',
            self::Fashion => '时尚',
            self::Retro => '复古',
            self::Sweet => '甜美',
            self::Cool => '酷帅',
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
