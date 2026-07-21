<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArticleTag extends BaseModel
{
    protected $table = 'article_tags';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'status',
        'sort',
    ];

    protected $casts = [
        'status' => 'integer',
        'sort' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 该标签的文章关联记录（article_tag_relations）.
     *
     * @return HasMany
     */
    public function tagRelations(): HasMany
    {
        return $this->hasMany(ArticleTagRelation::class, 'tag_id');
    }

    /**
     * 使用该标签的文章（多对多，中间表 article_tag_relations，仅有 created_at）.
     *
     * @return BelongsToMany
     */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(
            Article::class,
            'article_tag_relations',
            'tag_id',
            'article_id'
        )->withPivot('created_at');
    }
}
