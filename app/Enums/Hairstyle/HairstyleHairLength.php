<?php

namespace App\Enums\Hairstyle;

/**
 * 对应 hairstyles.hair_length
 */
enum HairstyleHairLength: int
{
    case Other = 0;
    case Buzz = 1;
    case Short = 2;
    case Medium = 3;
    case Shoulder = 4;
    case Long = 5;
    case ExtraLong = 6;

    public function label(): string
    {
        return match ($this) {
            self::Other => '其他',
            self::Buzz => '极短发',
            self::Short => '短发',
            self::Medium => '中短发',
            self::Shoulder => '中长发',
            self::Long => '长发',
            self::ExtraLong => '超长发',
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
