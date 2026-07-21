<?php

namespace App\Enums\Content;

/**
 * 对应 articles.content_format（网站正文格式）。
 */
enum ContentFormat: int
{
    case Markdown = 1;
    case Html = 2;

    public function label(): string
    {
        return match ($this) {
            self::Markdown => 'Markdown',
            self::Html => 'HTML',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Markdown => 'primary',
            self::Html => 'default',
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
