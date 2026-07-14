<?php

namespace App\Enums\Media;

/**
 * 对应 media_files.source_type
 */
enum MediaSourceType: int
{
    case Unknown = 0;
    case UserUpload = 1;
    case AiInput = 2;
    case AiResult = 3;
    case Hairstyle = 4;
    case HairColor = 5;
    case Article = 6;
    case Video = 7;
    case Topic = 8;
    case AdminUpload = 9;

    public function label(): string
    {
        return match ($this) {
            self::Unknown => '未知',
            self::UserUpload => '用户上传',
            self::AiInput => 'AI 输入图',
            self::AiResult => 'AI 生成结果',
            self::Hairstyle => '发型',
            self::HairColor => '发色',
            self::Article => '文章',
            self::Video => '视频',
            self::Topic => '专题',
            self::AdminUpload => '后台上传',
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
