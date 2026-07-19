<?php

namespace Tests\Feature\Models;

use App\Models\BaseModel;
use App\Models\HairColor;
use App\Models\HairColorCategory;
use App\Models\Hairstyle;
use App\Models\HairstyleCategory;
use App\Models\HairstyleMedia;
use App\Models\HairstyleTag;
use App\Models\HairstyleTagRelation;
use App\Models\MediaFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 验证 BaseModel::serializeDate() 统一把 created_at/updated_at/deleted_at/
 * published_at/expired_at 等 datetime 字段序列化为 `Y-m-d H:i:s`
 * （而不是 Eloquent 默认的 `2026-07-17T23:23:20.000000Z` ISO 8601 格式）。
 *
 * 只验证“读出/展示时的格式”，不涉及、也不修改数据库里实际存储的时间值：
 * 断言用正则匹配格式形状，不依赖具体时间点，避免测试因为运行时刻不同而失败。
 *
 * Dcat Grid/Show 都是通过 Eloquent Model::toArray() 读取行数据渲染的
 * （对应 vendor 里 Grid\Row::toArray() -> $this->data->toArray()），
 * 所以这里直接断言 toArray()/toJson() 的输出即可代表 Grid/Show 页面的实际展示效果。
 */
class BaseModelDateSerializationTest extends TestCase
{
    use RefreshDatabase;

    private const DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    public function test_all_target_models_extend_base_model(): void
    {
        foreach ([
            Hairstyle::class,
            HairstyleCategory::class,
            HairstyleTag::class,
            HairstyleMedia::class,
            HairstyleTagRelation::class,
            MediaFile::class,
            HairColor::class,
            HairColorCategory::class,
        ] as $modelClass) {
            $this->assertInstanceOf(
                BaseModel::class,
                new $modelClass(),
                "{$modelClass} 必须继承 App\\Models\\BaseModel"
            );
        }
    }

    public function test_hair_color_created_at_is_serialized_as_y_m_d_h_i_s(): void
    {
        $category = HairColorCategory::create([
            'name' => 'test_category',
            'slug' => 'test-category-'.uniqid(),
        ]);

        $hairColor = HairColor::create([
            'category_id' => $category->id,
            'name' => 'test_hair_color',
            'slug' => 'test-hair-color-'.uniqid(),
        ]);

        $array = $hairColor->toArray();

        $this->assertMatchesRegularExpression(self::DATETIME_PATTERN, $array['created_at']);
        $this->assertStringNotContainsString('T', $array['created_at']);
        $this->assertStringNotContainsString('Z', $array['created_at']);
    }

    public function test_hairstyle_created_at_is_serialized_as_y_m_d_h_i_s(): void
    {
        $hairstyle = Hairstyle::create([
            'name' => 'test_hairstyle',
            'slug' => 'test-hairstyle-'.uniqid(),
        ]);

        $array = $hairstyle->toArray();

        $this->assertMatchesRegularExpression(self::DATETIME_PATTERN, $array['created_at']);
        $this->assertStringNotContainsString('T', $array['created_at']);
        $this->assertStringNotContainsString('Z', $array['created_at']);
    }

    public function test_media_file_created_at_is_serialized_as_y_m_d_h_i_s(): void
    {
        $media = MediaFile::create([
            'file_no' => 'TEST'.uniqid(),
            'path' => 'test/'.uniqid().'.jpg',
        ]);

        $array = $media->toArray();

        $this->assertMatchesRegularExpression(self::DATETIME_PATTERN, $array['created_at']);
        $this->assertStringNotContainsString('T', $array['created_at']);
        $this->assertStringNotContainsString('Z', $array['created_at']);
    }

    /**
     * published_at（Hairstyle/HairColor）、deleted_at（软删除后）、expired_at
     * （MediaFile）都必须走同一个格式，不能只处理 created_at/updated_at。
     */
    public function test_published_at_deleted_at_and_expired_at_use_same_format(): void
    {
        $category = HairColorCategory::create([
            'name' => 'test_category',
            'slug' => 'test-category-'.uniqid(),
        ]);

        $hairColor = HairColor::create([
            'category_id' => $category->id,
            'name' => 'test_hair_color',
            'slug' => 'test-hair-color-'.uniqid(),
            'published_at' => now(),
        ]);
        $hairColor->delete();
        $hairColor->refresh();

        $hairColorArray = $hairColor->toArray();
        $this->assertMatchesRegularExpression(self::DATETIME_PATTERN, $hairColorArray['published_at']);
        $this->assertMatchesRegularExpression(self::DATETIME_PATTERN, $hairColorArray['deleted_at']);

        $media = MediaFile::create([
            'file_no' => 'TEST'.uniqid(),
            'path' => 'test/'.uniqid().'.jpg',
            'expired_at' => now()->addDays(30),
        ]);

        $this->assertMatchesRegularExpression(self::DATETIME_PATTERN, $media->toArray()['expired_at']);
    }

    /**
     * toJson()（Dcat 的部分异步接口会用到）必须同样生效，不能只对 toArray() 生效。
     */
    public function test_to_json_also_uses_the_same_format(): void
    {
        $hairstyle = Hairstyle::create([
            'name' => 'test_hairstyle',
            'slug' => 'test-hairstyle-'.uniqid(),
        ]);

        $decoded = json_decode($hairstyle->toJson(), true);

        $this->assertMatchesRegularExpression(self::DATETIME_PATTERN, $decoded['created_at']);
    }

    /**
     * 只改变“展示格式”，不改变实际存储值/不影响 Carbon 实例的正常使用：
     * 代码里访问 $model->created_at 仍然拿到 Carbon 实例，可以正常调用 Carbon 方法。
     */
    public function test_created_at_attribute_is_still_a_carbon_instance_in_code(): void
    {
        $hairstyle = Hairstyle::create([
            'name' => 'test_hairstyle',
            'slug' => 'test-hairstyle-'.uniqid(),
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $hairstyle->created_at);
    }
}
