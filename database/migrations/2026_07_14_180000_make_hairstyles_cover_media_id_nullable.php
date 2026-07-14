<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * hairstyles.cover_media_id 原为 NOT NULL DEFAULT 0，用 0 表示“没有封面”。
 *
 * HairstyleMediaService::detachMedia() 移除当前主图后需要把封面清空，
 * 业务上改用 NULL 表示“没有封面”（0 与“没有”语义混淆，且与 media_files 主键从 1 开始不冲突时容易误判）。
 * 本迁移只调整该字段的可空性和默认值，不改变其它字段、索引和表结构。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE `hairstyles` MODIFY `cover_media_id` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT '封面媒体 ID，NULL 表示暂无封面'"
        );

        // 历史遗留的 0 值统一归一为 NULL，保证“无封面”语义唯一（当前表为新表，通常无历史数据）。
        DB::table('hairstyles')->where('cover_media_id', 0)->update(['cover_media_id' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('hairstyles')->whereNull('cover_media_id')->update(['cover_media_id' => 0]);

        DB::statement(
            "ALTER TABLE `hairstyles` MODIFY `cover_media_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '封面媒体 ID'"
        );
    }
};
