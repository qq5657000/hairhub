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

    public function category(): BelongsTo
    {
        return $this->belongsTo(HairstyleCategory::class, 'category_id');
    }

    public function coverMedia(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'cover_media_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            HairstyleTag::class,
            'hairstyle_tag_relations',
            'hairstyle_id',
            'tag_id'
        )->withPivot('created_at');
    }

    public function tagRelations(): HasMany
    {
        return $this->hasMany(HairstyleTagRelation::class, 'hairstyle_id');
    }

    public function mediaRelations(): HasMany
    {
        return $this->hasMany(HairstyleMedia::class, 'hairstyle_id');
    }

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

    public function primaryMediaRelation(): HasOne
    {
        return $this->hasOne(HairstyleMedia::class, 'hairstyle_id')->where('is_primary', 1);
    }
}
