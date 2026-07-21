<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleHairstyleRelation extends BaseModel
{
    /**
     * article_hairstyle_relations 表只有 created_at 字段，没有 updated_at。
     */
    public const UPDATED_AT = null;

    protected $table = 'article_hairstyle_relations';

    protected $fillable = [
        'article_id',
        'hairstyle_id',
        'sort',
    ];

    protected $casts = [
        'article_id' => 'integer',
        'hairstyle_id' => 'integer',
        'sort' => 'integer',
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
     * 关联的发型.
     *
     * @return BelongsTo
     */
    public function hairstyle(): BelongsTo
    {
        return $this->belongsTo(Hairstyle::class, 'hairstyle_id');
    }
}
