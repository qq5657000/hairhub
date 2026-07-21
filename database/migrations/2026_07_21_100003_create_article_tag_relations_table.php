<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 与 hairstyle_tag_relations 保持一致：关联表仅有 created_at，不使用 updated_at
     * （对应 doc/v1.0/database/01-发型模块.md 7.2）。
     */
    public function up(): void
    {
        Schema::create('article_tag_relations', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('article_id')->comment('文章 ID');
            $table->unsignedBigInteger('tag_id')->comment('标签 ID');
            $table->timestamp('created_at')->nullable()->comment('创建时间');

            $table->unique(['article_id', 'tag_id'], 'uk_article_tag');
            $table->index(['tag_id', 'article_id'], 'idx_tag_article');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_tag_relations');
    }
};
