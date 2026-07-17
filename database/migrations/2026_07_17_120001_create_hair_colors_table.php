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
        Schema::create('hair_colors', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('category_id')->default(0)->comment('发色分类 ID');
            $table->string('name', 100)->comment('发色名称');
            $table->string('name_en', 150)->default('')->comment('英文名称预留');
            $table->string('slug', 150)->comment('SEO URL 标识');
            $table->string('color_hex', 9)->default('')->comment('颜色值，支持 #RRGGBB 和 #RRGGBBAA');
            $table->string('suitable_skin', 100)->default('')->comment('适合肤色，逗号分隔枚举值');
            $table->unsignedTinyInteger('brightness')->default(0)->comment('明暗程度');
            $table->unsignedTinyInteger('temperature')->default(0)->comment('冷暖属性');
            $table->unsignedTinyInteger('saturation')->default(0)->comment('饱和度等级');
            $table->unsignedTinyInteger('bleach_required')->default(0)->comment('是否通常需要漂发');
            $table->unsignedTinyInteger('maintenance_level')->default(0)->comment('维护难度');
            $table->text('description')->nullable()->comment('发色介绍');
            $table->text('ai_prompt')->nullable()->comment('AI 正向提示词');
            $table->text('ai_negative_prompt')->nullable()->comment('AI 负向提示词');
            $table->unsignedBigInteger('cover_media_id')->default(0)->comment('封面媒体 ID');
            $table->string('seo_title', 255)->default('')->comment('SEO 标题');
            $table->string('seo_description', 500)->default('')->comment('SEO 描述');
            $table->unsignedTinyInteger('status')->default(1)->comment('状态');
            $table->unsignedTinyInteger('is_recommended')->default(0)->comment('是否推荐');
            $table->unsignedInteger('sort')->default(0)->comment('排序值');
            $table->timestamp('published_at')->nullable()->comment('发布时间');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('slug', 'uk_slug');
            $table->index(['category_id', 'status', 'sort'], 'idx_category_status_sort');
            $table->index(['temperature', 'status', 'sort'], 'idx_temperature_status_sort');
            $table->index(['brightness', 'status', 'sort'], 'idx_brightness_status_sort');
            $table->index(['is_recommended', 'status', 'sort'], 'idx_recommended_status_sort');
            $table->index('cover_media_id', 'idx_cover_media_id');
            $table->index('published_at', 'idx_published_at');
            $table->index('deleted_at', 'idx_deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hair_colors');
    }
};
