<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HairstyleTagRelation extends Model
{
    /**
     * hairstyle_tag_relations 表只有 created_at 字段，没有 updated_at，
     * 显式关闭 updated_at 自动维护；created_at 仍由 Eloquent 自动写入。
     */
    public const UPDATED_AT = null;

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
     * 关联的标签.
     *
     * @return BelongsTo
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(HairstyleTag::class, 'tag_id');
    }
}
