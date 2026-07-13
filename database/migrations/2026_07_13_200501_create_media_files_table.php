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
        Schema::create('media_files', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('file_no', 40)->comment('媒体业务编号');
            $table->unsignedTinyInteger('file_type')->default(0)->comment('文件类型');
            $table->string('storage', 50)->default('public')->comment('存储驱动');
            $table->string('path', 500)->comment('相对路径');
            $table->string('url', 1000)->default('')->comment('完整访问地址缓存');
            $table->string('original_name', 255)->default('')->comment('原始文件名');
            $table->string('filename', 255)->default('')->comment('实际文件名');
            $table->string('extension', 20)->default('')->comment('文件扩展名');
            $table->string('mime_type', 100)->default('')->comment('MIME 类型');
            $table->unsignedBigInteger('size')->default(0)->comment('文件大小，字节');
            $table->unsignedInteger('width')->default(0)->comment('图片或视频宽度');
            $table->unsignedInteger('height')->default(0)->comment('图片或视频高度');
            $table->unsignedInteger('duration')->default(0)->comment('视频或音频时长，秒');
            $table->char('hash', 64)->nullable()->comment('文件 SHA-256');
            $table->string('thumbnail_path', 500)->default('')->comment('缩略图相对路径');
            $table->string('thumbnail_url', 1000)->default('')->comment('缩略图访问地址缓存');
            $table->unsignedTinyInteger('source_type')->default(0)->comment('来源类型');
            $table->unsignedBigInteger('source_id')->default(0)->comment('来源业务 ID');
            $table->unsignedTinyInteger('visibility')->default(0)->comment('访问级别');
            $table->unsignedTinyInteger('status')->default(1)->comment('文件状态');
            $table->json('metadata')->nullable()->comment('扩展元数据');
            $table->timestamp('expired_at')->nullable()->comment('过期时间');
            $table->timestamps();

            $table->unique('file_no', 'uk_file_no');
            $table->unique(['storage', 'path'], 'uk_storage_path');
            $table->index('hash', 'idx_hash');
            $table->index(['source_type', 'source_id'], 'idx_source_type_id');
            $table->index(['file_type', 'status', 'created_at'], 'idx_type_status_created');
            $table->index(['visibility', 'status'], 'idx_visibility_status');
            $table->index('expired_at', 'idx_expired_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
