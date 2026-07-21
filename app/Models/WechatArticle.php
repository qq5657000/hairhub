<?php

namespace App\Models;

use App\Enums\Wechat\WechatPublishStatus;
use App\Enums\Wechat\WechatSyncStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WechatArticle extends BaseModel
{
    protected $table = 'wechat_articles';

    protected $fillable = [
        'article_id',
        'wechat_media_id',
        'wechat_article_id',
        'wechat_url',
        'sync_status',
        'publish_status',
        'error_code',
        'error_message',
        'synced_at',
        'published_at',
    ];

    protected $casts = [
        'article_id' => 'integer',
        'sync_status' => WechatSyncStatus::class,
        'publish_status' => WechatPublishStatus::class,
        'synced_at' => 'datetime',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 关联的文章.
     *
     * @return BelongsTo
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }
}
