<?php

namespace App\Enums\HairColor;

/**
 * 对应 hair_colors.maintenance_level（维护难度）
 */
enum HairColorMaintenanceLevel: int
{
    case Unknown = 0;
    case Easy = 1;
    case Normal = 2;
    case Difficult = 3;

    public function label(): string
    {
        return match ($this) {
            self::Unknown => '未设置',
            self::Easy => '容易维护',
            self::Normal => '一般维护',
            self::Difficult => '较难维护',
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
