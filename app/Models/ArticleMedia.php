<?php

namespace App\Models;

use App\Enums\Article\ArticleMediaType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleMedia extends BaseModel
{
    protected $table = 'article_media';

    protected $fillable = [
        'article_id',
        'media_id',
        'media_type',
        'alt_text',
        'caption',
        'sort',
    ];

    protected $casts = [
        'article_id' => 'integer',
        'media_id' => 'integer',
        'media_type' => ArticleMediaType::class,
        'sort' => 'integer',
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

    /**
     * 关联的媒体文件.
     *
     * @return BelongsTo
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'media_id');
    }
}
