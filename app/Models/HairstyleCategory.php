<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HairstyleCategory extends Model
{
    use SoftDeletes;

    protected $table = 'hairstyle_categories';

    protected $fillable = [
        'parent_id',
        'name',
        'name_en',
        'slug',
        'description',
        'cover_media_id',
        'status',
        'sort',
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'cover_media_id' => 'integer',
        'status' => 'integer',
        'sort' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function hairstyles(): HasMany
    {
        return $this->hasMany(Hairstyle::class, 'category_id');
    }

    public function coverMedia(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'cover_media_id');
    }
}
