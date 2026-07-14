<?php

namespace App\Enums\Media;

/**
 * 对应 media_files.file_type
 */
enum MediaFileType: int
{
    case Unknown = 0;
    case Image = 1;
    case Video = 2;
    case Audio = 3;
    case Document = 4;

    public function label(): string
    {
        return match ($this) {
            self::Unknown => '未知',
            self::Image => '图片',
            self::Video => '视频',
            self::Audio => '音频',
            self::Document => '文档',
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
