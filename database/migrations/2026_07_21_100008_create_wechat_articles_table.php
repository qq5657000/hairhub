<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 保留公众号同步当前状态，不做真实微信接口，不保存任何微信密钥
     * （对应 doc/v1.0/database/03-内容模块.md 五.9）。
     */
    public function up(): void
    {
        Schema::create('wechat_articles', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('article_id')->comment('文章 ID');
            $table->string('wechat_media_id', 255)->default('')->comment('微信素材 ID');
            $table->string('wechat_article_id', 255)->default('')->comment('微信草稿或文章 ID');
            $table->string('wechat_url', 500)->default('')->comment('微信文章地址');
            $table->unsignedTinyInteger('sync_status')->default(0)->comment('同步状态，对应 WechatSyncStatus');
            $table->unsignedTinyInteger('publish_status')->default(0)->comment('微信发布状态，对应 WechatPublishStatus');
            $table->string('error_code', 100)->default('')->comment('微信错误码');
            $table->string('error_message', 1000)->default('')->comment('错误信息');
            $table->timestamp('synced_at')->nullable()->comment('最近同步时间');
            $table->timestamp('published_at')->nullable()->comment('微信发布时间');
            $table->timestamps();

            $table->unique('article_id', 'uk_article_id');
            $table->index(['sync_status', 'updated_at'], 'idx_sync_status_updated');
            $table->index(['publish_status', 'updated_at'], 'idx_publish_status_updated');
            $table->index('wechat_article_id', 'idx_wechat_article_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wechat_articles');
    }
};
