<?php

namespace App\Enums\Video;

/**
 * 对应 videos.source（视频来源平台）。
 */
enum VideoSource: int
{
    case Local = 0;
    case WechatChannels = 1;
    case Douyin = 2;
    case Kuaishou = 3;
    case Bilibili = 4;
    case Youtube = 5;
    case Other = 6;

    public function label(): string
    {
        return match ($this) {
            self::Local => '本地视频',
            self::WechatChannels => '微信视频号',
            self::Douyin => '抖音',
            self::Kuaishou => '快手',
            self::Bilibili => 'B站',
            self::Youtube => 'YouTube',
            self::Other => '其他',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Local => 'primary',
            self::WechatChannels => 'success',
            self::Douyin, self::Kuaishou, self::Bilibili, self::Youtube => 'info',
            self::Other => 'default',
        };
    }

    /**
     * 是否为本地视频来源（应使用 video_media_id，而非外部 video_url）。
     */
    public function isLocal(): bool
    {
        return $this === self::Local;
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

    public static function isValid(int $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
