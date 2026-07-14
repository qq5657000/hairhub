<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Hairstyle extends Model
{
    use SoftDeletes;

    protected $table = 'hairstyles';

    protected $fillable = [
        'category_id',
        'name',
        'name_en',
        'slug',
        'gender',
        'age_range',
        'style_type',
        'hair_length',
        'hair_type',
        'hair_volume',
        'face_shape',
        'maintenance_level',
        'suitable_scene',
        'description',
        'ai_prompt',
        'ai_negative_prompt',
        'cover_media_id',
        'seo_title',
        'seo_description',
        'status',
        'is_recommended',
        'sort',
        'published_at',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'gender' => 'integer',
        'age_range' => 'integer',
        'style_type' => 'integer',
        'hair_length' => 'integer',
        'hair_type' => 'integer',
        'hair_volume' => 'integer',
        'face_shape' => 'array',
        'maintenance_level' => 'integer',
        'suitable_scene' => 'array',
        'cover_media_id' => 'integer',
        'status' => 'integer',
        'is_recommended' => 'boolean',
        'sort' => 'integer',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * 所属主分类.
     *
     * @return BelongsTo
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(HairstyleCategory::class, 'category_id');
    }

    /**
     * 封面媒体（与 hairstyle_media.is_primary 无数据库强制同步关系，
     * 由业务/Service 层事务负责保持一致，Model 层不做自动同步）.
     *
     * @return BelongsTo
     */
    public function coverMedia(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'cover_media_id');
    }

    /**
     * 发型关联的标签（多对多，中间表 hairstyle_tag_relations，仅有 created_at）.
     *
     * @return BelongsToMany
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            HairstyleTag::class,
            'hairstyle_tag_relations',
            'hairstyle_id',
            'tag_id'
        )->withPivot('created_at');
    }

    /**
     * 发型标签关联记录（hairstyle_tag_relations）.
     *
     * @return HasMany
     */
    public function tagRelations(): HasMany
    {
        return $this->hasMany(HairstyleTagRelation::class, 'hairstyle_id');
    }

    /**
     * 发型的媒体关联记录（hairstyle_media，包含图片类型/排序/是否主图等信息）.
     *
     * @return HasMany
     */
    public function mediaRelations(): HasMany
    {
        return $this->hasMany(HairstyleMedia::class, 'hairstyle_id');
    }

    /**
     * 发型关联的媒体文件（多对多，中间表 hairstyle_media，含 created_at/updated_at）.
     *
     * @return BelongsToMany
     */
    public function mediaFiles(): BelongsToMany
    {
        return $this->belongsToMany(
            MediaFile::class,
            'hairstyle_media',
            'hairstyle_id',
            'media_id'
        )->withPivot([
            'type',
            'title',
            'alt_text',
            'caption',
            'is_primary',
            'status',
            'sort',
        ])->withTimestamps();
    }

    /**
     * 发型的主图关联记录（is_primary = 1），仅用于读取展示，
     * 不在关系中自动同步 cover_media_id，主图切换需由 Service 层事务处理。
     *
     * @return HasOne
     */
    public function primaryMediaRelation(): HasOne
    {
        return $this->hasOne(HairstyleMedia::class, 'hairstyle_id')->where('is_primary', 1);
    }
}
