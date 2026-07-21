<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 是否使用软删除与现有 hairstyle_tags 保持一致：不使用 SoftDeletes，
     * 标签禁用通过 status 字段实现，被引用的标签由 Service 层禁止物理删除
     * （对应 doc/v1.0/database/01-发型模块.md 6.6、14.3）。
     */
    public function up(): void
    {
        Schema::create('article_tags', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('name', 100)->comment('标签名称');
            $table->string('slug', 150)->comment('标签标识');
            $table->string('description', 500)->default('')->comment('标签说明');
            $table->unsignedTinyInteger('status')->default(1)->comment('状态，对应 CommonStatus');
            $table->unsignedInteger('sort')->default(0)->comment('排序值');
            $table->timestamps();

            $table->unique('slug', 'uk_slug');
            $table->index(['status', 'sort'], 'idx_status_sort');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_tags');
    }
};
