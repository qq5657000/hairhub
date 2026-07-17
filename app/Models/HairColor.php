<?php

namespace App\Models;

use App\Casts\SuitableSkinCast;
use App\Support\ColorHex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HairColor extends Model
{
    use SoftDeletes;

    protected $table = 'hair_colors';

    protected $fillable = [
        'category_id',
        'name',
        'name_en',
        'slug',
        'color_hex',
        'suitable_skin',
        'brightness',
        'temperature',
        'saturation',
        'bleach_required',
        'maintenance_level',
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
        'brightness' => 'integer',
        'temperature' => 'integer',
        'saturation' => 'integer',
        'bleach_required' => 'integer',
        'maintenance_level' => 'integer',
        'suitable_skin' => SuitableSkinCast::class,
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
     * 所属发色分类.
     *
     * @return BelongsTo
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(HairColorCategory::class, 'category_id');
    }

    /**
     * 封面媒体.
     *
     * @return BelongsTo
     */
    public function coverMedia(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'cover_media_id');
    }

    /**
     * color_hex 保存前统一标准化：去除空格、转大写、校验 #RRGGBB / #RRGGBBAA 格式。
     * 非法格式直接抛出 ValidationException，不允许非法值静默进入数据库
     * （对应 doc/v1.0/database/02-发色模块.md 九）。
     */
    public function setColorHexAttribute(?string $value): void
    {
        $this->attributes['color_hex'] = ColorHex::normalize($value);
    }
}
