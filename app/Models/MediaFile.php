<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
