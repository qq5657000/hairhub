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
        Schema::create('videos', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('title', 255)->comment('视频标题');
            $table->string('title_en', 255)->default('')->comment('英文标题预留');
            $table->string('slug', 180)->comment('SEO URL 标识');
            $table->unsignedBigInteger('cover_media_id')->default(0)->comment('视频封面媒体 ID');
            $table->unsignedBigInteger('video_media_id')->default(0)->comment('本地视频媒体 ID');
            $table->string('video_url', 500)->default('')->comment('外部视频地址');
            $table->unsignedTinyInteger('source')->default(0)->comment('视频来源平台，对应 VideoSource');
            $table->string('source_video_id', 150)->default('')->comment('外部平台视频 ID');
            $table->unsignedInteger('duration')->default(0)->comment('视频时长，单位秒');
            $table->text('description')->nullable()->comment('视频简介');
            $table->longText('transcript')->nullable()->comment('字幕或文稿');
            $table->unsignedTinyInteger('status')->default(0)->comment('内容状态，对应 ContentStatus');
            $table->unsignedTinyInteger('is_recommended')->default(0)->comment('是否推荐');
            $table->unsignedInteger('sort')->default(0)->comment('排序值');
            $table->timestamp('published_at')->nullable()->comment('发布时间');
            $table->string('seo_title', 255)->default('')->comment('SEO 标题');
            $table->string('seo_keywords', 500)->default('')->comment('SEO 关键词');
            $table->string('seo_description', 500)->default('')->comment('SEO 描述');
            $table->unsignedBigInteger('view_count')->default(0)->comment('浏览量');
            $table->unsignedBigInteger('like_count')->default(0)->comment('点赞量');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('slug', 'uk_slug');
            $table->index(['source', 'source_video_id'], 'idx_source_source_video');
            $table->index(['status', 'published_at'], 'idx_status_published');
            $table->index(['status', 'is_recommended', 'sort'], 'idx_status_recommended_sort');
            $table->index('cover_media_id', 'idx_cover_media_id');
            $table->index('video_media_id', 'idx_video_media_id');
            $table->index('deleted_at', 'idx_deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
