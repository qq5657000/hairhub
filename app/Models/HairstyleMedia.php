<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HairstyleMedia extends Model
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

    public function hairstyle(): BelongsTo
    {
        return $this->belongsTo(Hairstyle::class, 'hairstyle_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'media_id');
    }
}
