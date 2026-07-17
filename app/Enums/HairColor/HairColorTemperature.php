<?php

namespace App\Enums\HairColor;

/**
 * 对应 hair_colors.temperature（冷暖属性）
 */
enum HairColorTemperature: int
{
    case Neutral = 0;
    case Warm = 1;
    case Cool = 2;

    public function label(): string
    {
        return match ($this) {
            self::Neutral => '中性',
            self::Warm => '暖调',
            self::Cool => '冷调',
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
