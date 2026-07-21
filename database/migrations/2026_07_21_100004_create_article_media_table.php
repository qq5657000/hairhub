<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 用于记录文章正文图片、图集和附件（对应 doc/v1.0/database/03-内容模块.md 5.5）。
     * 文章封面主逻辑固定使用 articles.cover_media_id / wechat_cover_media_id，
     * 不在本表重复承载封面语义，因此本表不包含 is_primary 字段。
     */
    public function up(): void
    {
        Schema::create('article_media', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('article_id')->comment('文章 ID');
            $table->unsignedBigInteger('media_id')->comment('媒体 ID');
            $table->unsignedTinyInteger('media_type')->default(1)->comment('媒体用途，对应 ArticleMediaType');
            $table->string('alt_text', 255)->default('')->comment('图片 ALT 文本，用于图片 SEO');
            $table->string('caption', 500)->default('')->comment('图片说明');
            $table->unsignedInteger('sort')->default(0)->comment('排序值');
            $table->timestamps();

            $table->unique(['article_id', 'media_id', 'media_type'], 'uk_article_media_type');
            $table->index(['article_id', 'media_type', 'sort'], 'idx_article_type_sort');
            $table->index('media_id', 'idx_media_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_media');
    }
};
