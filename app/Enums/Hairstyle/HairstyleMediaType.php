<?php

namespace App\Enums\Hairstyle;

/**
 * 对应 hairstyle_media.type
 */
enum HairstyleMediaType: int
{
    case Gallery = 0;
    case Cover = 1;
    case Front = 2;
    case Side = 3;
    case Back = 4;
    case Detail = 5;
    case Before = 6;
    case After = 7;
    case AiExample = 8;
    case Inspiration = 9;

    public function label(): string
    {
        return match ($this) {
            self::Gallery => '普通图库图',
            self::Cover => '封面图',
            self::Front => '正面图',
            self::Side => '侧面图',
            self::Back => '背面图',
            self::Detail => '细节图',
            self::Before => '处理前对比图',
            self::After => '处理后对比图',
            self::AiExample => 'AI 参考图',
            self::Inspiration => '灵感参考图',
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
