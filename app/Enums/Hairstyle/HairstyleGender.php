<?php

namespace App\Enums\Hairstyle;

/**
 * 对应 hairstyles.gender
 */
enum HairstyleGender: int
{
    case All = 0;
    case Male = 1;
    case Female = 2;
    case Child = 3;

    public function label(): string
    {
        return match ($this) {
            self::All => '不限',
            self::Male => '男',
            self::Female => '女',
            self::Child => '儿童通用',
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
