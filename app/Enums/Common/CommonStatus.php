<?php

namespace App\Enums\Common;

/**
 * 项目通用启用/禁用状态枚举。
 *
 * 供不需要额外状态语义、仅表达“禁用/启用”的 status 字段复用，
 * 避免每个模块重复定义语义相同的 0/1 状态枚举
 * （对应 doc/v1.0/database/02-发色模块.md 3.3 通用状态）。
 *
 * 发型模块历史上按表各自定义了 HairstyleStatus / HairstyleCategoryStatus /
 * HairstyleTagStatus（同样是 0=禁用、1=启用），本枚举不改动发型模块现有代码，
 * 仅供发色模块及后续新模块复用。
 */
enum CommonStatus: int
{
    case Disabled = 0;
    case Enabled = 1;

    public function label(): string
    {
        return match ($this) {
            self::Disabled => '禁用',
            self::Enabled => '启用',
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
