<?php

namespace App\Admin\Controllers\Concerns;

use BackedEnum;

/**
 * 供 Grid/Show 展示 Enum 取值的通用方法。
 *
 * 背景：ArticleController / VideoController / WechatArticleController / ArticleMediaController
 * 涉及的 status / content_format / source / sync_status / publish_status / media_type
 * 字段在 Model 里使用的是 PHP 原生 Enum Cast（例如 'status' => ContentStatus::class），
 * 取出来的属性值是 Enum 实例，而不是 int/string 标量。
 *
 * Dcat 的 Grid\Column::using() / Show\Field::using() 内部通过
 * Illuminate\Support\Arr::get($map, $value) 做映射，最终会执行
 * array_key_exists($value, $map)。当 $value 是 Enum 实例（对象）时，
 * PHP 8 会直接抛出 TypeError: array_key_exists(): ... must be a valid array offset type，
 * 导致 /admin/articles 等列表页 500（这是本次修复的直接原因）。
 *
 * 发型/发色/媒体资源等第一批模块的同名字段都是 'status' => 'integer' 这种普通标量 Cast，
 * 不会触发该问题，因此那些控制器继续使用 Dcat 原生 using()/label()，本 Trait 不介入、
 * 不影响其现有展示方式。
 */
trait FormatsEnumBadges
{
    /**
     * 将取值解析为对应的 Enum 实例：
     * - 原生 Enum Cast 场景下 $value 本身已经是该 Enum 实例；
     * - 兼容极少数场景下拿到的是原始标量（如未经过 Cast 的原始查询结果）。
     *
     * @param  mixed  $value
     * @param  class-string<BackedEnum>  $enumClass
     */
    public static function resolveEnum($value, string $enumClass): ?BackedEnum
    {
        if ($value instanceof $enumClass) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return $enumClass::tryFrom((int) $value);
        }

        return $enumClass::tryFrom($value);
    }

    /**
     * Grid/Show 中展示“取值 => 中文文案”的通用方法，替代 Dcat 的 using()，
     * 避免原生 Enum Cast 返回枚举实例导致的 TypeError。
     *
     * @param  mixed  $value
     * @param  class-string<BackedEnum>  $enumClass
     */
    public static function enumLabel($value, string $enumClass): string
    {
        $enum = static::resolveEnum($value, $enumClass);

        return $enum?->label() ?? (string) $value;
    }

    /**
     * Grid 中展示带颜色徽章的通用方法，直接使用枚举自身的 color()（Bootstrap 关键字，
     * 如 success/warning/danger/default），不依赖 Dcat Label 显示器基于
     * getOriginal() 做的“取值 => 颜色”映射（同样会因为原生 Enum Cast 拿不到标量取值，
     * 只能退化显示数组第一个颜色，视觉上不正确）。
     *
     * @param  mixed  $value
     * @param  class-string<BackedEnum>  $enumClass
     */
    public static function enumBadge($value, string $enumClass): string
    {
        $enum = static::resolveEnum($value, $enumClass);

        if ($enum === null) {
            return (string) $value;
        }

        $color = method_exists($enum, 'color') ? $enum->color() : 'default';

        return '<span class="label label-'.e($color).'">'.e($enum->label()).'</span>';
    }
}
