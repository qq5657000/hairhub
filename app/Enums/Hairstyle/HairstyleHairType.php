<?php

namespace App\Enums\Hairstyle;

/**
 * 对应 hairstyles.hair_type
 */
enum HairstyleHairType: int
{
    case All = 0;
    case Straight = 1;
    case Wavy = 2;
    case Curly = 3;
    case Coarse = 4;
    case Fine = 5;

    public function label(): string
    {
        return match ($this) {
            self::All => '不限',
            self::Straight => '直发',
            self::Wavy => '微卷',
            self::Curly => '卷发',
            self::Coarse => '粗硬发',
            self::Fine => '细软发',
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
