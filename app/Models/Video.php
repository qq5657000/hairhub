<?php

namespace App\Models;

use App\Enums\Content\ContentStatus;
use App\Enums\Video\VideoSource;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Video extends BaseModel
{
    use SoftDeletes;

    protected $table = 'videos';

    protected $fillable = [
        'title',
        'title_en',
        'slug',
        'cover_media_id',
        'video_media_id',
        'video_url',
        'source',
        'source_video_id',
        'duration',
        'description',
        'transcript',
        'status',
        'is_recommended',
        'sort',
        'published_at',
        'seo_title',
        'seo_keywords',
        'seo_description',
        'view_count',
        'like_count',
    ];

    protected $casts = [
        'cover_media_id' => 'integer',
        'video_media_id' => 'integer',
        'source' => VideoSource::class,
        'duration' => 'integer',
        'status' => ContentStatus::class,
        'is_recommended' => 'boolean',
        'sort' => 'integer',
        'published_at' => 'datetime',
        'view_count' => 'integer',
        'like_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * 列表场景使用的字段（不包含 transcript 等大字段），
     * 供 Service 层查询列表时显式指定，避免默认加载 LONGTEXT。
     */
    public const LIST_COLUMNS = [
        'id',
        'title',
        'title_en',
        'slug',
        'cover_media_id',
        'video_media_id',
        'video_url',
        'source',
        'source_video_id',
        'duration',
        'description',
        'status',
        'is_recommended',
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
     * 视频封面媒体.
     *
     * @return BelongsTo
     */
    public function coverMedia(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'cover_media_id');
    }

    /**
     * 本地视频媒体（source 为本地时使用）.
     *
     * @return BelongsTo
     */
    public function videoMedia(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'video_media_id');
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
