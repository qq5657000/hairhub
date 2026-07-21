<?php

namespace App\Enums\Wechat;

/**
 * 对应 wechat_articles.sync_status（本阶段仅作数据结构与状态预留，不接真实微信接口）。
 */
enum WechatSyncStatus: int
{
    case Pending = 0;
    case Syncing = 1;
    case Success = 2;
    case Failed = 3;

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待同步',
            self::Syncing => '同步中',
            self::Success => '同步成功',
            self::Failed => '同步失败',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'default',
            self::Syncing => 'warning',
            self::Success => 'success',
            self::Failed => 'danger',
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
