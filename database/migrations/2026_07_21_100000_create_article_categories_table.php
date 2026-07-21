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
        Schema::create('article_categories', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('parent_id')->default(0)->comment('父分类 ID，0 表示顶级分类');
            $table->string('name', 100)->comment('分类名称');
            $table->string('name_en', 150)->default('')->comment('英文名称预留');
            $table->string('slug', 150)->comment('SEO URL 标识');
            $table->string('description', 500)->nullable()->comment('分类简介');
            $table->unsignedBigInteger('cover_media_id')->default(0)->comment('分类封面媒体 ID');
            $table->unsignedTinyInteger('status')->default(1)->comment('状态，对应 CommonStatus');
            $table->unsignedInteger('sort')->default(0)->comment('排序值');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('slug', 'uk_slug');
            $table->index(['parent_id', 'status', 'sort'], 'idx_parent_status_sort');
            $table->index('cover_media_id', 'idx_cover_media_id');
            $table->index('deleted_at', 'idx_deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_categories');
    }
};
