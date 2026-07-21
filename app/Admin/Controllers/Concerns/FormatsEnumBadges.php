<?php

namespace App\Admin\Controllers\Concerns;

/**
 * 供 Grid 列的 ->label()/->badge() 显示器生成“取值 => 颜色”映射数组。
 *
 * 内容模块新增的多个 Enum（ContentStatus / WechatSyncStatus / WechatPublishStatus）
 * 都已经各自维护了 color() 方法（供未来其它场景复用），本 Trait 只是把
 * “遍历 cases() 生成 value=>color 映射”这段重复逻辑抽出来，不重新定义任何颜色规则，
 * 不影响发型/发色模块现有的 Grid 展示方式（那些模块的 Enum 未提供 color()，
 * 继续沿用各自控制器里手写的 using() 映射，本 Trait 不介入）。
 */
trait FormatsEnumBadges
{
    /**
     * @param  class-string  $enumClass  必须是提供 cases() 与 color() 的 PHP 枚举类
     * @return array<int, string>
     */
    public static function enumColorMap(string $enumClass): array
    {
        $map = [];

        foreach ($enumClass::cases() as $case) {
            $map[$case->value] = $case->color();
        }

        return $map;
    }
}
