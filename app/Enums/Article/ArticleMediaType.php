<?php

namespace App\Enums\Article;

/**
 * 对应 article_media.media_type。
 *
 * 文章封面主逻辑固定使用 articles.cover_media_id /
 * articles.wechat_cover_media_id，不复用本枚举
 * （对应 doc/v1.0/database/03-内容模块.md 四、状态设计）。
 *
 * 取值从 1 开始（不使用 0），避免与数据库列默认值 0 混淆——
 * media_type 是业务上“必须显式选择”的分类字段，不存在合理的
 * “未设置/默认”语义。
 */
enum ArticleMediaType: int
{
    case ContentImage = 1;
    case Gallery = 2;
    case Attachment = 3;

    public function label(): string
    {
        return match ($this) {
            self::ContentImage => '正文图片',
            self::Gallery => '图集',
            self::Attachment => '附件',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ContentImage => 'primary',
            self::Gallery => 'success',
            self::Attachment => 'default',
        };
    }

    /**
     * 是否要求关联的媒体必须是图片类型（对应 MediaFileType::Image）。
     */
    public function requiresImage(): bool
    {
        return match ($this) {
            self::ContentImage, self::Gallery => true,
            self::Attachment => false,
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
