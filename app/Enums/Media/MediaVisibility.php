<?php

namespace App\Enums\Media;

/**
 * 对应 media_files.visibility
 */
enum MediaVisibility: int
{
    case Private = 0;
    case Public = 1;
    case Protected = 2;

    public function label(): string
    {
        return match ($this) {
            self::Private => '私有',
            self::Public => '公开',
            self::Protected => '受限（需签名或鉴权访问）',
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
