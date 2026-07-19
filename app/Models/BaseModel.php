<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * 项目统一业务 Model 基类。
 *
 * 目前唯一职责：统一 created_at/updated_at/deleted_at/published_at/expired_at
 * 等所有 datetime 类型字段在 toArray()/toJson()（Dcat Grid/Show 底层依赖这两个
 * 方法读取展示数据）时的序列化格式，从
 *
 *   2026-07-17T23:23:20.000000Z（Eloquent 默认 ISO 8601 格式）
 *
 * 统一为项目约定的
 *
 *   2026-07-17 23:23:20
 *
 * 只影响“日期如何被格式化成字符串展示”，不影响：
 * - 数据库里实际存储的时间值（本方法只在读出后格式化，不改写入逻辑）；
 * - $casts 里声明为 'datetime' 的字段在代码里仍然是 Carbon 实例，可以正常调用
 *   Carbon 的方法（->diffForHumans() 等）；
 * - 应用时区（config('app.timezone') = Asia/Shanghai），本类不涉及时区转换，
 *   Carbon 实例本身已经是按 Asia/Shanghai 解析/展示的。
 *
 * 后续新增的业务 Model 应统一继承本类，不要直接继承 Illuminate\Database\Eloquent\Model。
 */
class BaseModel extends Model
{
    /**
     * 统一 datetime 字段的展示格式，供 toArray()/toJson()（Dcat Grid/Show 等
     * 依赖 toArray() 的场景）使用，避免每个 Admin Controller 重复写
     * Carbon::parse()->format('Y-m-d H:i:s')。
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
