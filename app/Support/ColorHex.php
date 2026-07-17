<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * hair_colors.color_hex 标准化工具类。
 *
 * 统一处理颜色值的格式校验和大小写标准化，避免同一套正则和大写转换逻辑
 * 散落在多个 Controller / Service 中（对应 doc/v1.0/database/02-发色模块.md 5.3、九）。
 *
 * 规则：
 * - 必须以 # 开头；
 * - 仅支持 #RRGGBB（7 位）或 #RRGGBBAA（9 位）；
 * - 标准化后统一转换为大写；
 * - 空值（null / 空字符串 / 去除空格后为空）标准化为空字符串，代表"未设置"。
 */
class ColorHex
{
    public const PATTERN = '/^#(?:[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/';

    /**
     * 标准化 color_hex：去除首尾空格、转大写；空值统一为空字符串；非法格式抛出异常。
     *
     * @throws ValidationException 格式不合法（非空且不符合 #RRGGBB / #RRGGBBAA）
     */
    public static function normalize(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $normalized = strtoupper($value);

        if (! self::isValid($normalized)) {
            throw ValidationException::withMessages([
                'color_hex' => ['颜色值格式不合法，必须为 #RRGGBB 或 #RRGGBBAA 格式'],
            ]);
        }

        return $normalized;
    }

    /**
     * 校验是否符合 #RRGGBB 或 #RRGGBBAA 格式（大小写均可）。
     */
    public static function isValid(string $value): bool
    {
        return (bool) preg_match(self::PATTERN, $value);
    }
}
