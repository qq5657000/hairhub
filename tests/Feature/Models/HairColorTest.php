<?php

namespace Tests\Feature\Models;

use App\Models\HairColor;
use App\Models\HairColorCategory;
use App\Models\MediaFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * HairColor Model 基础行为验证：
 * - color_hex 标准化（转大写、非法格式拒绝、空值处理）；
 * - suitable_skin 数组读写、去重、清空值、非法枚举拒绝；
 * - category / coverMedia 关系；
 * - 软删除。
 *
 * 业务级校验（分类有效性、slug 冲突、封面媒体可用性等）由 HairColorService 负责，
 * 见 tests/Feature/Services/HairColor/HairColorServiceTest.php。
 */
class HairColorTest extends TestCase
{
    use RefreshDatabase;

    private function makeCategory(array $overrides = []): HairColorCategory
    {
        return HairColorCategory::create(array_merge([
            'name' => 'test_category',
            'slug' => 'test-category-'.uniqid(),
        ], $overrides));
    }

    private function makeHairColor(array $overrides = []): HairColor
    {
        $category = $overrides['category_id'] ?? null;

        return HairColor::create(array_merge([
            'category_id' => $category ?? $this->makeCategory()->id,
            'name' => 'test_hair_color',
            'slug' => 'test-hair-color-'.uniqid(),
        ], $overrides));
    }

    public function test_color_hex_is_normalized_to_uppercase(): void
    {
        $hairColor = $this->makeHairColor(['color_hex' => '#c8a27a']);

        $this->assertSame('#C8A27A', $hairColor->color_hex);
        $this->assertDatabaseHas('hair_colors', ['id' => $hairColor->id, 'color_hex' => '#C8A27A']);
    }

    public function test_color_hex_supports_eight_digit_alpha_format(): void
    {
        $hairColor = $this->makeHairColor(['color_hex' => '#c8a27aff']);

        $this->assertSame('#C8A27AFF', $hairColor->color_hex);
    }

    public function test_color_hex_empty_value_is_stored_as_empty_string(): void
    {
        $hairColor = $this->makeHairColor(['color_hex' => null]);

        $this->assertSame('', $hairColor->color_hex);
    }

    public function test_color_hex_rejects_invalid_format(): void
    {
        $this->expectException(ValidationException::class);

        $this->makeHairColor(['color_hex' => '#12345']);
    }

    public function test_color_hex_rejects_value_without_hash_prefix(): void
    {
        $this->expectException(ValidationException::class);

        $this->makeHairColor(['color_hex' => 'C8A27A']);
    }

    public function test_suitable_skin_returns_array_and_removes_duplicates_and_blanks(): void
    {
        $hairColor = $this->makeHairColor([
            'suitable_skin' => ['light', ' light ', 'medium', '', '  '],
        ]);

        $this->assertSame(['light', 'medium'], $hairColor->suitable_skin);
        $this->assertDatabaseHas('hair_colors', ['id' => $hairColor->id, 'suitable_skin' => 'light,medium']);
    }

    public function test_suitable_skin_accepts_comma_separated_string(): void
    {
        $hairColor = $this->makeHairColor(['suitable_skin' => 'tan,cool_skin']);

        $this->assertSame(['tan', 'cool_skin'], $hairColor->suitable_skin);
    }

    public function test_suitable_skin_empty_value_returns_empty_array(): void
    {
        $hairColor = $this->makeHairColor(['suitable_skin' => null]);

        $this->assertSame([], $hairColor->suitable_skin);
        $this->assertDatabaseHas('hair_colors', ['id' => $hairColor->id, 'suitable_skin' => '']);
    }

    /**
     * 保存顺序统一按照 HairColorSuitableSkin 枚举定义顺序排列（all、fair、light、medium、
     * tan、deep、warm_skin、cool_skin、neutral_skin），而不是按提交时的原始顺序，
     * 保证同一批取值无论提交顺序如何，保存结果都完全一致。
     */
    public function test_suitable_skin_is_sorted_by_enum_defined_order_regardless_of_input_order(): void
    {
        $hairColor = $this->makeHairColor(['suitable_skin' => ['tan', 'cool_skin', 'medium']]);

        $this->assertSame(['medium', 'tan', 'cool_skin'], $hairColor->suitable_skin);

        $reloaded = HairColor::query()->find($hairColor->id);
        $this->assertSame(['medium', 'tan', 'cool_skin'], $reloaded->suitable_skin);
    }

    public function test_suitable_skin_produces_same_result_for_different_submission_orders(): void
    {
        $hairColorA = $this->makeHairColor(['suitable_skin' => ['tan', 'medium']]);
        $hairColorB = $this->makeHairColor(['suitable_skin' => ['medium', 'tan']]);

        $this->assertSame(['medium', 'tan'], $hairColorA->suitable_skin);
        $this->assertSame($hairColorA->suitable_skin, $hairColorB->suitable_skin);
    }

    public function test_suitable_skin_rejects_unknown_enum_value(): void
    {
        $this->expectException(ValidationException::class);

        $this->makeHairColor(['suitable_skin' => ['light', 'not_a_real_skin']]);
    }

    /**
     * all 表示“全部肤色”，与其它具体肤色值语义上互斥：一旦提交内容包含 all，
     * 最终只保存 all，不允许出现 all,fair,light 这类冗余组合。
     */
    public function test_suitable_skin_collapses_to_all_when_submitted_together_with_other_values(): void
    {
        $hairColor = $this->makeHairColor(['suitable_skin' => ['fair', 'all', 'light']]);

        $this->assertSame(['all'], $hairColor->suitable_skin);
        $this->assertDatabaseHas('hair_colors', ['id' => $hairColor->id, 'suitable_skin' => 'all']);
    }

    /**
     * 即使最终会被 all 收敛掉，非法枚举值也必须先被拒绝，不能因为“反正会被收敛”而放行。
     */
    public function test_suitable_skin_still_rejects_unknown_value_even_when_all_is_present(): void
    {
        $this->expectException(ValidationException::class);

        $this->makeHairColor(['suitable_skin' => ['all', 'not_a_real_skin']]);
    }

    public function test_category_relation_is_correct(): void
    {
        $category = $this->makeCategory();
        $hairColor = $this->makeHairColor(['category_id' => $category->id]);

        $this->assertSame($category->id, $hairColor->category->id);
    }

    public function test_cover_media_relation_is_correct(): void
    {
        $media = MediaFile::create([
            'file_no' => 'TEST'.uniqid(),
            'path' => 'test/'.uniqid().'.jpg',
        ]);

        $hairColor = $this->makeHairColor(['cover_media_id' => $media->id]);

        $this->assertNotNull($hairColor->coverMedia);
        $this->assertSame($media->id, $hairColor->coverMedia->id);
    }

    public function test_soft_delete_sets_deleted_at_and_hides_from_default_query(): void
    {
        $hairColor = $this->makeHairColor();

        $hairColor->delete();

        $this->assertSoftDeleted('hair_colors', ['id' => $hairColor->id]);
        $this->assertNull(HairColor::query()->find($hairColor->id));
        $this->assertNotNull(HairColor::withTrashed()->find($hairColor->id));
    }
}
