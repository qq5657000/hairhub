<?php

namespace App\Models;

use App\Enums\Article\ArticleMediaType;
use App\Enums\Content\ContentFormat;
use App\Enums\Content\ContentStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends BaseModel
{
    use SoftDeletes;

    protected $table = 'articles';

    protected $fillable = [
        'category_id',
        'title',
        'title_en',
        'slug',
        'cover_media_id',
        'summary',
        'content',
        'content_format',
        'wechat_content',
        'wechat_cover_media_id',
        'wechat_excerpt',
        'author',
        'source',
        'source_url',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_remark',
        'is_recommended',
        'is_top',
        'sort',
        'published_at',
        'seo_title',
        'seo_keywords',
        'seo_description',
        'view_count',
        'like_count',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'cover_media_id' => 'integer',
        'content_format' => ContentFormat::class,
        'wechat_cover_media_id' => 'integer',
        'status' => ContentStatus::class,
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
        'is_recommended' => 'boolean',
        'is_top' => 'boolean',
        'sort' => 'integer',
        'published_at' => 'datetime',
        'view_count' => 'integer',
        'like_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * 列表场景使用的字段（不包含 content / wechat_content 等大字段），
     * 供 Service 层查询列表时显式指定，避免默认加载 LONGTEXT。
     */
    public const LIST_COLUMNS = [
        'id',
        'category_id',
        'title',
        'title_en',
        'slug',
        'cover_media_id',
        'summary',
        'content_format',
        'wechat_cover_media_id',
        'wechat_excerpt',
        'author',
        'source',
        'source_url',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_remark',
        'is_recommended',
        'is_top',
        'sort',
        'published_at',
        'seo_title',
        'seo_keywords',
        'seo_description',
        'view_count',
        'like_count',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * 所属分类.
     *
     * @return BelongsTo
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ArticleCategory::class, 'category_id');
    }

    /**
     * 关联的标签（多对多，中间表 article_tag_relations，仅有 created_at）.
     *
     * @return BelongsToMany
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            ArticleTag::class,
            'article_tag_relations',
            'article_id',
            'tag_id'
        )->withPivot('created_at');
    }

    /**
     * 文章关联的全部媒体记录（正文图片、图集、附件）.
     *
     * @return HasMany
     */
    public function media(): HasMany
    {
        return $this->hasMany(ArticleMedia::class, 'article_id');
    }

    /**
     * 仅正文图片类型的媒体关联.
     *
     * @return HasMany
     */
    public function contentImages(): HasMany
    {
        return $this->media()->where('media_type', ArticleMediaType::ContentImage->value);
    }

    /**
     * 网站封面媒体.
     *
     * @return BelongsTo
     */
    public function coverMedia(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'cover_media_id');
    }

    /**
     * 公众号封面媒体.
     *
     * @return BelongsTo
     */
    public function wechatCoverMedia(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'wechat_cover_media_id');
    }

    /**
     * 关联的发型（多对多，中间表 article_hairstyle_relations，仅有 created_at）.
     *
     * @return BelongsToMany
     */
    public function hairstyles(): BelongsToMany
    {
        return $this->belongsToMany(
            Hairstyle::class,
            'article_hairstyle_relations',
            'article_id',
            'hairstyle_id'
        )->withPivot('sort', 'created_at');
    }

    /**
     * 关联的发色（多对多，中间表 article_hair_color_relations，仅有 created_at）.
     *
     * @return BelongsToMany
     */
    public function hairColors(): BelongsToMany
    {
        return $this->belongsToMany(
            HairColor::class,
            'article_hair_color_relations',
            'article_id',
            'hair_color_id'
        )->withPivot('sort', 'created_at');
    }

    /**
     * 公众号同步记录（一对一，article_id 唯一）.
     *
     * @return HasOne
     */
    public function wechatArticle(): HasOne
    {
        return $this->hasOne(WechatArticle::class, 'article_id');
    }

    /**
     * 审核人（Dcat Admin 后台管理员，对应 admin_users 表）。
     * reviewed_by 默认 0 表示未审核，此关系仅在 reviewed_by > 0 时有意义。
     *
     * @return BelongsTo
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by');
    }

    /**
     * 前台可见性判断：已发布、发布时间已到、未被软删除。
     */
    public function isVisibleOnFrontend(): bool
    {
        return $this->status === ContentStatus::Published
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo(now())
            && $this->deleted_at === null;
    }
}
