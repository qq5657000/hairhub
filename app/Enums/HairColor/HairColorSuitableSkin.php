<?php

namespace App\Enums\HairColor;

/**
 * 对应 hair_colors.suitable_skin（逗号分隔存储的肤色枚举值元素）。
 *
 * 系统允许值集中在本枚举维护，禁止在 Controller / Model / Service 中
 * 各自散落维护一份允许值列表（对应 doc/v1.0/database/02-发色模块.md 6.7）。
 */
enum HairColorSuitableSkin: string
{
    case All = 'all';
    case Fair = 'fair';
    case Light = 'light';
    case Medium = 'medium';
    case Tan = 'tan';
    case Deep = 'deep';
    case WarmSkin = 'warm_skin';
    case CoolSkin = 'cool_skin';
    case NeutralSkin = 'neutral_skin';

    public function label(): string
    {
        return match ($this) {
            self::All => '全部肤色',
            self::Fair => '非常白皙',
            self::Light => '偏白',
            self::Medium => '自然肤色',
            self::Tan => '小麦色',
            self::Deep => '深肤色',
            self::WarmSkin => '暖底调',
            self::CoolSkin => '冷底调',
            self::NeutralSkin => '中性底调',
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
