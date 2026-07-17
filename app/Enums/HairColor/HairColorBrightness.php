<?php

namespace App\Enums\HairColor;

/**
 * 对应 hair_colors.brightness（明暗程度）
 */
enum HairColorBrightness: int
{
    case Unknown = 0;
    case Dark = 1;
    case Medium = 2;
    case Light = 3;
    case VeryLight = 4;

    public function label(): string
    {
        return match ($this) {
            self::Unknown => '未设置',
            self::Dark => '深色',
            self::Medium => '中等',
            self::Light => '浅色',
            self::VeryLight => '极浅色',
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
