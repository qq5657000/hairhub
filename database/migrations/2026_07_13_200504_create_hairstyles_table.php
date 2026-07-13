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
        Schema::create('hairstyles', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('category_id')->default(0)->comment('主分类 ID');
            $table->string('name', 100)->comment('发型名称');
            $table->string('name_en', 150)->default('')->comment('英文名称预留');
            $table->string('slug', 150)->comment('SEO URL 标识');
            $table->unsignedTinyInteger('gender')->default(0)->comment('适合性别');
            $table->unsignedTinyInteger('age_range')->default(0)->comment('适合年龄段');
            $table->unsignedTinyInteger('style_type')->default(0)->comment('风格类型');
            $table->unsignedTinyInteger('hair_length')->default(0)->comment('头发长度');
            $table->unsignedTinyInteger('hair_type')->default(0)->comment('发质类型');
            $table->unsignedTinyInteger('hair_volume')->default(0)->comment('发量类型');
            $table->json('face_shape')->nullable()->comment('适合脸型枚举数组');
            $table->unsignedTinyInteger('maintenance_level')->default(0)->comment('打理难度');
            $table->json('suitable_scene')->nullable()->comment('适用场景枚举数组');
            $table->text('description')->nullable()->comment('发型介绍');
            $table->text('ai_prompt')->nullable()->comment('AI 正向提示词');
            $table->text('ai_negative_prompt')->nullable()->comment('AI 负向提示词');
            // cover_media_id：发型列表、首页和 SEO 查询使用的封面媒体快捷字段。
            // 与 hairstyle_media.is_primary 语义对应，后续业务层设置主图时必须在同一个数据库事务中同步维护两者。
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
            $table->index(['gender', 'status', 'sort'], 'idx_gender_status_sort');
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
        Schema::dropIfExists('hairstyles');
    }
};
