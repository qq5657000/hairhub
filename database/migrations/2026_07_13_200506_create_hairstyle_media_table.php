<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hairstyle_media', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('hairstyle_id')->comment('发型 ID');
            $table->unsignedBigInteger('media_id')->comment('媒体 ID');
            $table->unsignedTinyInteger('type')->default(0)->comment('图片类型');
            $table->string('title', 255)->default('')->comment('图片标题');
            $table->string('alt_text', 255)->default('')->comment('图片 ALT 文本');
            $table->string('caption', 500)->default('')->comment('图片说明');
            // is_primary：发型媒体集合中的主图标识，语义对应 hairstyles.cover_media_id。
            // 后续业务层设置主图时必须在同一个数据库事务中同步维护两者；本表不使用普通唯一索引限制每个发型只能有一张主图，该规则由 Service 和事务保证。
            $table->unsignedTinyInteger('is_primary')->default(0)->comment('是否主图');
            $table->unsignedTinyInteger('status')->default(1)->comment('状态');
            $table->unsignedInteger('sort')->default(0)->comment('排序值');
            $table->timestamps();

            $table->unique(['hairstyle_id', 'media_id'], 'uk_hairstyle_media');
            $table->index(['hairstyle_id', 'status', 'sort'], 'idx_hairstyle_status_sort');
            $table->index(['hairstyle_id', 'is_primary'], 'idx_hairstyle_primary');
            $table->index('media_id', 'idx_media_id');
            $table->index(['type', 'status'], 'idx_type_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hairstyle_media');
    }
};
