<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 用于文章关联已有发色（app/Models/HairColor.php，表 hair_colors）。
     * 与 hairstyle_tag_relations 保持一致：仅有 created_at，不使用 updated_at。
     */
    public function up(): void
    {
        Schema::create('article_hair_color_relations', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('article_id')->comment('文章 ID');
            $table->unsignedBigInteger('hair_color_id')->comment('发色 ID');
            $table->unsignedInteger('sort')->default(0)->comment('排序值');
            $table->timestamp('created_at')->nullable()->comment('创建时间');

            $table->unique(['article_id', 'hair_color_id'], 'uk_article_hair_color');
            $table->index(['hair_color_id', 'article_id'], 'idx_hair_color_article');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_hair_color_relations');
    }
};
