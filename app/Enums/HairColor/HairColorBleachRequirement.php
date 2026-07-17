<?php

namespace App\Enums\HairColor;

/**
 * 对应 hair_colors.bleach_required（是否通常需要漂发）
 */
enum HairColorBleachRequirement: int
{
    case No = 0;
    case Yes = 1;
    case Depends = 2;

    public function label(): string
    {
        return match ($this) {
            self::No => '通常不需要漂发',
            self::Yes => '通常需要漂发',
            self::Depends => '视原发色和目标效果而定',
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
