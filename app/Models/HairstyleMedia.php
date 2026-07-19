<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HairstyleMedia extends BaseModel
{
    protected $table = 'hairstyle_media';

    protected $fillable = [
        'hairstyle_id',
        'media_id',
        'type',
        'title',
        'alt_text',
        'caption',
        'is_primary',
        'status',
        'sort',
    ];

    protected $casts = [
        'hairstyle_id' => 'integer',
        'media_id' => 'integer',
        'type' => 'integer',
        'is_primary' => 'boolean',
        'status' => 'integer',
        'sort' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 关联的发型.
     *
     * @return BelongsTo
     */
    public function hairstyle(): BelongsTo
    {
        return $this->belongsTo(Hairstyle::class, 'hairstyle_id');
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
