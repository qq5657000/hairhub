<?php

namespace App\Enums\Hairstyle;

/**
 * 对应 hairstyles.maintenance_level
 */
enum HairstyleMaintenanceLevel: int
{
    case Unknown = 0;
    case Easy = 1;
    case Normal = 2;
    case Difficult = 3;

    public function label(): string
    {
        return match ($this) {
            self::Unknown => '未设置',
            self::Easy => '容易打理',
            self::Normal => '一般打理',
            self::Difficult => '较难打理',
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
