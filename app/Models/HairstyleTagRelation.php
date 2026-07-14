<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HairstyleTagRelation extends Model
{
    /**
     * 表中只有 created_at，没有 updated_at。
     */
    const UPDATED_AT = null;

    protected $table = 'hairstyle_tag_relations';

    protected $fillable = [
        'hairstyle_id',
        'tag_id',
    ];

    protected $casts = [
        'hairstyle_id' => 'integer',
        'tag_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function hairstyle(): BelongsTo
    {
        return $this->belongsTo(Hairstyle::class, 'hairstyle_id');
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(HairstyleTag::class, 'tag_id');
    }
}
