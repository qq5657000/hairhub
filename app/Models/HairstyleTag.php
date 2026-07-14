<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HairstyleTag extends Model
{
    protected $table = 'hairstyle_tags';

    protected $fillable = [
        'name',
        'name_en',
        'slug',
        'type',
        'status',
        'sort',
    ];

    protected $casts = [
        'type' => 'integer',
        'status' => 'integer',
        'sort' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 该标签的发型关联记录（hairstyle_tag_relations）.
     *
     * @return HasMany
     */
    public function tagRelations(): HasMany
    {
        return $this->hasMany(HairstyleTagRelation::class, 'tag_id');
    }

    /**
     * 使用该标签的发型（多对多，中间表 hairstyle_tag_relations，仅有 created_at）.
     *
     * @return BelongsToMany
     */
    public function hairstyles(): BelongsToMany
    {
        return $this->belongsToMany(
            Hairstyle::class,
            'hairstyle_tag_relations',
            'tag_id',
            'hairstyle_id'
        )->withPivot('created_at');
    }
}
