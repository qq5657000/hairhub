<?php

namespace App\Enums\HairColor;

/**
 * 对应 hair_colors.saturation（饱和度等级）
 */
enum HairColorSaturation: int
{
    case Unknown = 0;
    case Low = 1;
    case Medium = 2;
    case High = 3;

    public function label(): string
    {
        return match ($this) {
            self::Unknown => '未设置',
            self::Low => '低饱和度',
            self::Medium => '中等饱和度',
            self::High => '高饱和度',
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
