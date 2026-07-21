<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleTagRelation extends BaseModel
{
    /**
     * article_tag_relations 表只有 created_at 字段，没有 updated_at，
     * 显式关闭 updated_at 自动维护；created_at 仍由 Eloquent 自动写入。
     */
    public const UPDATED_AT = null;

    protected $table = 'article_tag_relations';

    protected $fillable = [
        'article_id',
        'tag_id',
    ];

    protected $casts = [
        'article_id' => 'integer',
        'tag_id' => 'integer',
        'created_at' => 'datetime',
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

    /**
     * 关联的标签.
     *
     * @return BelongsTo
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(ArticleTag::class, 'tag_id');
    }
}
