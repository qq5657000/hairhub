<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class MediaFile extends Model
{
    protected $table = 'media_files';

    protected $fillable = [
        'file_no',
        'file_type',
        'storage',
        'path',
        'url',
        'original_name',
        'filename',
        'extension',
        'mime_type',
        'size',
        'width',
        'height',
        'duration',
        'hash',
        'thumbnail_path',
        'thumbnail_url',
        'source_type',
        'source_id',
        'visibility',
        'status',
        'metadata',
        'expired_at',
    ];

    protected $casts = [
        'file_type' => 'integer',
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration' => 'integer',
        'source_type' => 'integer',
        'source_id' => 'integer',
        'visibility' => 'integer',
        'status' => 'integer',
        'metadata' => 'array',
        'expired_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 该媒体被哪些发型引用（对应 hairstyle_media 关联记录）.
     *
     * @return HasMany
     */
    public function hairstyleMediaRelations(): HasMany
    {
        return $this->hasMany(HairstyleMedia::class, 'media_id');
    }

    /**
     * 该媒体所关联的发型（多对多，中间表 hairstyle_media）.
     *
     * @return BelongsToMany
     */
    public function hairstyles(): BelongsToMany
    {
        return $this->belongsToMany(
            Hairstyle::class,
            'hairstyle_media',
            'media_id',
            'hairstyle_id'
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
     * 访问地址优先根据 storage + path 动态生成，数据库 url 字段只作为动态生成失败时的缓存兜底
     * （对应 doc/v1.0/database/05-媒体资源模块.md 6.4）。
     */
    public function getUrlAttribute($value): string
    {
        $dynamic = $this->buildStorageUrl($this->attributes['path'] ?? '');

        return $dynamic !== '' ? $dynamic : (string) $value;
    }

    /**
     * 缩略图访问地址同样优先动态生成，没有缩略图时返回空字符串。
     */
    public function getThumbnailUrlAttribute($value): string
    {
        $thumbnailPath = $this->attributes['thumbnail_path'] ?? '';

        if ($thumbnailPath === '') {
            return '';
        }

        $dynamic = $this->buildStorageUrl($thumbnailPath);

        return $dynamic !== '' ? $dynamic : (string) $value;
    }

    private function buildStorageUrl(string $path): string
    {
        if ($path === '') {
            return '';
        }

        try {
            return Storage::disk($this->attributes['storage'] ?? 'public')->url($path);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
