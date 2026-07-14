<?php

namespace App\Enums\Media;

/**
 * 对应 media_files.status
 */
enum MediaStatus: int
{
    case Disabled = 0;
    case Active = 1;
    case Missing = 2;
    case Processing = 3;

    public function label(): string
    {
        return match ($this) {
            self::Disabled => '禁用',
            self::Active => '正常',
            self::Missing => '文件缺失',
            self::Processing => '处理中',
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
