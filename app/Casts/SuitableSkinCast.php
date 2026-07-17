<?php

namespace App\Casts;

use App\Enums\HairColor\HairColorSuitableSkin;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Validation\ValidationException;

/**
 * hair_colors.suitable_skin 自定义 Cast。
 *
 * 数据库存储为英文枚举值逗号分隔字符串，Model 读取时统一返回数组
 * （对应 doc/v1.0/database/02-发色模块.md 5.3、八）。
 *
 * set() 规则：
 * - 接受 array、字符串（逗号分隔）或 null；
 * - 去除每一项前后空格；
 * - 删除空值；
 * - 去重；
 * - 仅允许 HairColorSuitableSkin 定义的系统允许值，非法值直接抛出异常，不静默丢弃或保存
 *   （校验发生在“all 收敛”之前，即使最终会被收敛为 all，非法值也必须先被拒绝）；
 * - 如果结果中包含 all，最终只保存 all（不保留 all,fair,light 这类冗余组合）；
 * - 最终结果统一按照 HairColorSuitableSkin 枚举定义顺序排序保存，
 *   保证同一批取值无论提交顺序如何，保存结果都完全一致（不依赖提交顺序，比“仅保留首次出现顺序”更稳定）；
 * - 空数组 / null 保存为空字符串。
 *
 * @implements CastsAttributes<array<int, string>, array<int, string>|string|null>
 */
class SuitableSkinCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): array
    {
        $value = (string) ($value ?? '');

        if ($value === '') {
            return [];
        }

        return explode(',', $value);
    }

    /**
     * @throws ValidationException 存在系统未定义的肤色枚举值
     */
    public function set($model, string $key, $value, array $attributes): array
    {
        $items = is_array($value) ? $value : explode(',', (string) ($value ?? ''));

        $items = array_values(array_unique(array_filter(array_map(
            static fn ($item) => trim((string) $item),
            $items
        ), static fn (string $item) => $item !== '')));

        $allowed = HairColorSuitableSkin::values();
        $invalid = array_values(array_diff($items, $allowed));

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'suitable_skin' => ['无效的肤色枚举值：'.implode('、', $invalid)],
            ]);
        }

        if (in_array(HairColorSuitableSkin::All->value, $items, true)) {
            $items = [HairColorSuitableSkin::All->value];
        }

        $order = array_flip($allowed);
        usort($items, static fn (string $a, string $b) => $order[$a] <=> $order[$b]);

        return [$key => implode(',', $items)];
    }
}
