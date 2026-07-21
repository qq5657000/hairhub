<?php

namespace App\Enums\Wechat;

/**
 * 对应 wechat_articles.publish_status（本阶段仅作数据结构与状态预留，不接真实微信接口）。
 */
enum WechatPublishStatus: int
{
    case Unpublished = 0;
    case Publishing = 1;
    case Published = 2;
    case PublishFailed = 3;

    public function label(): string
    {
        return match ($this) {
            self::Unpublished => '未发布',
            self::Publishing => '发布中',
            self::Published => '已发布',
            self::PublishFailed => '发布失败',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpublished => 'default',
            self::Publishing => 'warning',
            self::Published => 'success',
            self::PublishFailed => 'danger',
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

    public static function isValid(int $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
