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
        Schema::create('articles', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('category_id')->default(0)->comment('主分类 ID');
            $table->string('title', 255)->comment('文章标题');
            $table->string('title_en', 255)->default('')->comment('英文标题预留');
            $table->string('slug', 180)->comment('SEO URL 标识');
            $table->unsignedBigInteger('cover_media_id')->default(0)->comment('网站封面媒体 ID');
            $table->string('summary', 500)->default('')->comment('网站摘要');
            $table->longText('content')->nullable()->comment('网站正文');
            $table->unsignedTinyInteger('content_format')->default(1)->comment('网站正文格式，对应 ContentFormat');
            $table->longText('wechat_content')->nullable()->comment('公众号正文（与网站正文独立保存，互不覆盖）');
            $table->unsignedBigInteger('wechat_cover_media_id')->default(0)->comment('公众号封面媒体 ID');
            $table->string('wechat_excerpt', 120)->default('')->comment('公众号摘要');
            $table->string('author', 100)->default('')->comment('作者名称');
            $table->string('source', 100)->default('')->comment('内容来源');
            $table->string('source_url', 500)->default('')->comment('原文地址');
            $table->unsignedTinyInteger('status')->default(0)->comment('内容状态，对应 ContentStatus');
            $table->unsignedBigInteger('reviewed_by')->default(0)->comment('审核人（后台管理员）ID，0 表示未审核');
            $table->timestamp('reviewed_at')->nullable()->comment('审核时间');
            $table->string('review_remark', 500)->default('')->comment('审核备注');
            $table->unsignedTinyInteger('is_recommended')->default(0)->comment('是否推荐');
            $table->unsignedTinyInteger('is_top')->default(0)->comment('是否置顶');
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
            $table->index(['category_id', 'status', 'published_at'], 'idx_category_status_published');
            $table->index(['status', 'is_top', 'published_at'], 'idx_status_top_published');
            $table->index(['status', 'is_recommended', 'sort'], 'idx_status_recommended_sort');
            $table->index('cover_media_id', 'idx_cover_media_id');
            $table->index('wechat_cover_media_id', 'idx_wechat_cover_media_id');
            $table->index('published_at', 'idx_published_at');
            $table->index('deleted_at', 'idx_deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
