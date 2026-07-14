<?php

namespace App\Enums\Hairstyle;

/**
 * 对应 hairstyles.suitable_scene（JSON 数组元素）
 */
enum SuitableScene: string
{
    case Daily = 'daily';
    case Work = 'work';
    case Business = 'business';
    case School = 'school';
    case Date = 'date';
    case Party = 'party';
    case Wedding = 'wedding';
    case Sport = 'sport';
    case Travel = 'travel';
    case Performance = 'performance';

    public function label(): string
    {
        return match ($this) {
            self::Daily => '日常',
            self::Work => '通勤',
            self::Business => '商务',
            self::School => '校园',
            self::Date => '约会',
            self::Party => '聚会',
            self::Wedding => '婚礼',
            self::Sport => '运动',
            self::Travel => '旅行',
            self::Performance => '演出',
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
