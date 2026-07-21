<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleHairColorRelation extends BaseModel
{
    /**
     * article_hair_color_relations 表只有 created_at 字段，没有 updated_at。
     */
    public const UPDATED_AT = null;

    protected $table = 'article_hair_color_relations';

    protected $fillable = [
        'article_id',
        'hair_color_id',
        'sort',
    ];

    protected $casts = [
        'article_id' => 'integer',
        'hair_color_id' => 'integer',
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
     * 关联的发色.
     *
     * @return BelongsTo
     */
    public function hairColor(): BelongsTo
    {
        return $this->belongsTo(HairColor::class, 'hair_color_id');
    }
}
