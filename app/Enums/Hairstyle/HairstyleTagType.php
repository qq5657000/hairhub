<?php

namespace App\Enums\Hairstyle;

/**
 * 对应 hairstyle_tags.type
 */
enum HairstyleTagType: int
{
    case General = 0;
    case Effect = 1;
    case Style = 2;
    case FaceShape = 3;
    case Scene = 4;
    case Maintenance = 5;

    public function label(): string
    {
        return match ($this) {
            self::General => '普通标签',
            self::Effect => '功效标签',
            self::Style => '风格标签',
            self::FaceShape => '脸型标签',
            self::Scene => '场景标签',
            self::Maintenance => '打理标签',
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
