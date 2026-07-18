<?php

namespace Tests\Feature\Seeders;

use App\Enums\Common\CommonStatus;
use App\Enums\HairColor\HairColorBrightness;
use App\Enums\HairColor\HairColorMaintenanceLevel;
use App\Enums\HairColor\HairColorSaturation;
use App\Enums\HairColor\HairColorTemperature;
use App\Models\HairColor;
use App\Models\HairColorCategory;
use App\Models\MediaFile;
use Database\Seeders\HairColorCategorySeeder;
use Database\Seeders\HairColorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HairColorCategorySeeder / HairColorSeeder 幂等性与分类层级验证。
 *
 * 覆盖：
 * - 分类层级必须是“3 个一级分类 + 11 个二级分类”的两级结构，一级 parent_id = 0，
 *   二级 parent_id 指向真实的一级分类 id；
 * - 具体发色必须关联到二级（叶子）分类，不能关联到一级分类；
 * - Seeder 重复执行不产生重复数据；
 * - Seeder 重复执行不会覆盖后台人工维护过的结构化字段（分类的 status/sort/
 *   parent_id/description；发色的 category_id/status/sort/is_recommended/
 *   brightness/temperature/saturation/bleach_required/maintenance_level/
 *   description/ai_prompt/seo_title/cover_media_id）。
 *
 * 本测试通过 RefreshDatabase 在测试数据库中直接调用 Seeder，不属于“执行 Seeder”
 * 这一被禁止的人工操作（那是指在开发/生产数据库上手动执行 php artisan db:seed），
 * 与项目现有测试套件里 RefreshDatabase 迁移测试数据库表结构的做法一致。
 */
class HairColorSeedersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 分类树定义（与 HairColorCategorySeeder::categoryTree() 完全一致），用于校验
     * Seeder 写入的父子关系是否正确，避免测试和 Seeder 各写一份定义后来失去同步。
     */
    private const EXPECTED_TREE = [
        'natural-tones' => ['black-tones', 'brown-tones', 'linen-tones'],
        'light-tones' => ['gold-tones', 'grey-tones', 'platinum-tones'],
        'personality-tones' => ['red-tones', 'purple-tones', 'blue-tones', 'pink-tones', 'green-tones'],
    ];

    public function test_category_seeder_is_idempotent_and_creates_expected_categories(): void
    {
        (new HairColorCategorySeeder())->run();

        $countAfterFirstRun = HairColorCategory::query()->count();

        $this->assertSame(14, $countAfterFirstRun);
        $this->assertDatabaseHas('hair_color_categories', ['slug' => 'natural-tones', 'name' => '自然色系']);
        $this->assertDatabaseHas('hair_color_categories', ['slug' => 'green-tones', 'name' => '绿色系']);

        (new HairColorCategorySeeder())->run();

        $this->assertSame($countAfterFirstRun, HairColorCategory::query()->count());
    }

    /**
     * 分类层级必须是 3 个一级分类 + 11 个二级分类，一级 parent_id = 0，
     * 二级 parent_id 必须正确指向对应的一级分类真实 id（不能是硬编码数字，
     * 也不能全部挂在 parent_id = 0 下形成扁平结构）。
     */
    public function test_category_seeder_creates_correct_three_level_hierarchy(): void
    {
        (new HairColorCategorySeeder())->run();

        $topLevel = HairColorCategory::query()->where('parent_id', 0)->get();
        $this->assertCount(3, $topLevel, '一级分类必须是 3 个');

        $secondLevel = HairColorCategory::query()->where('parent_id', '!=', 0)->get();
        $this->assertCount(11, $secondLevel, '二级分类必须是 11 个');

        $this->assertSame(14, HairColorCategory::query()->count());

        foreach (self::EXPECTED_TREE as $parentSlug => $childSlugs) {
            /** @var HairColorCategory $parent */
            $parent = HairColorCategory::query()->where('slug', $parentSlug)->firstOrFail();
            $this->assertSame(0, $parent->parent_id, "一级分类 {$parentSlug} 的 parent_id 必须是 0");

            foreach ($childSlugs as $childSlug) {
                /** @var HairColorCategory $child */
                $child = HairColorCategory::query()->where('slug', $childSlug)->firstOrFail();
                $this->assertSame(
                    $parent->id,
                    $child->parent_id,
                    "二级分类 {$childSlug} 的 parent_id 必须指向一级分类 {$parentSlug} 的真实 id"
                );
            }
        }
    }

    public function test_category_seeder_does_not_overwrite_structured_fields(): void
    {
        (new HairColorCategorySeeder())->run();

        $media = MediaFile::create([
            'file_no' => 'TESTCOVER',
            'path' => 'test/cover.jpg',
            'file_type' => 1,
            'status' => 1,
        ]);

        $anotherTop = HairColorCategory::query()->where('slug', 'light-tones')->first();

        /** @var HairColorCategory $category */
        $category = HairColorCategory::query()->where('slug', 'black-tones')->first();
        $category->cover_media_id = $media->id;
        $category->description = '后台人工编辑过的描述';
        $category->status = CommonStatus::Disabled->value;
        $category->sort = 999;
        $category->parent_id = $anotherTop->id;
        $category->save();

        (new HairColorCategorySeeder())->run();

        $category->refresh();
        $this->assertSame($media->id, $category->cover_media_id);
        $this->assertSame('后台人工编辑过的描述', $category->description);
        $this->assertSame(CommonStatus::Disabled->value, (int) $category->status);
        $this->assertSame(999, $category->sort);
        $this->assertSame($anotherTop->id, $category->parent_id);
    }

    public function test_hair_color_seeder_is_idempotent_and_creates_expected_hair_colors(): void
    {
        (new HairColorCategorySeeder())->run();
        (new HairColorSeeder())->run();

        $countAfterFirstRun = HairColor::query()->count();

        $this->assertSame(14, $countAfterFirstRun);
        $this->assertDatabaseHas('hair_colors', ['slug' => 'natural-black', 'color_hex' => '#1C1C1C']);
        $this->assertDatabaseHas('hair_colors', ['slug' => 'grape-purple', 'color_hex' => '#5B2A5E']);

        (new HairColorSeeder())->run();

        $this->assertSame($countAfterFirstRun, HairColor::query()->count());
    }

    /**
     * 每条发色都必须关联到二级（叶子）分类，不能关联到“自然色系/浅色系/个性色系”
     * 这三个一级分类；顺带验证层级修正后 honey-tea / milk-tea-brown 被正确改挂到
     * 新的二级分类（不再指向已经变成一级分类的“浅色系”）。
     */
    public function test_hair_color_seeder_assigns_hair_colors_to_leaf_categories_only(): void
    {
        (new HairColorCategorySeeder())->run();
        (new HairColorSeeder())->run();

        $topLevelIds = HairColorCategory::query()->where('parent_id', 0)->pluck('id')->all();

        $hairColors = HairColor::query()->get(['id', 'slug', 'category_id']);
        $this->assertCount(14, $hairColors);

        foreach ($hairColors as $hairColor) {
            $this->assertNotContains(
                $hairColor->category_id,
                $topLevelIds,
                "发色 {$hairColor->slug} 不能直接关联到一级分类"
            );
        }

        $goldTones = HairColorCategory::query()->where('slug', 'gold-tones')->firstOrFail();
        $brownTones = HairColorCategory::query()->where('slug', 'brown-tones')->firstOrFail();

        $this->assertSame(
            $goldTones->id,
            HairColor::query()->where('slug', 'honey-tea')->firstOrFail()->category_id
        );
        $this->assertSame(
            $brownTones->id,
            HairColor::query()->where('slug', 'milk-tea-brown')->firstOrFail()->category_id
        );
    }

    public function test_hair_color_seeder_assigns_correct_category_ids(): void
    {
        (new HairColorCategorySeeder())->run();
        (new HairColorSeeder())->run();

        $blackTones = HairColorCategory::query()->where('slug', 'black-tones')->first();
        $naturalBlack = HairColor::query()->where('slug', 'natural-black')->first();

        $this->assertSame($blackTones->id, $naturalBlack->category_id);
    }

    public function test_hair_color_seeder_does_not_overwrite_structured_fields(): void
    {
        (new HairColorCategorySeeder())->run();
        (new HairColorSeeder())->run();

        $media = MediaFile::create([
            'file_no' => 'TESTCOVER2',
            'path' => 'test/cover2.jpg',
            'file_type' => 1,
            'status' => 1,
        ]);

        $anotherCategory = HairColorCategory::query()->where('slug', 'grey-tones')->first();

        /** @var HairColor $hairColor */
        $hairColor = HairColor::query()->where('slug', 'natural-black')->first();
        $hairColor->cover_media_id = $media->id;
        $hairColor->seo_title = '后台人工编辑过的 SEO 标题';
        $hairColor->description = '后台人工编辑过的发色介绍';
        $hairColor->category_id = $anotherCategory->id;
        $hairColor->status = CommonStatus::Disabled->value;
        $hairColor->sort = 888;
        $hairColor->is_recommended = 0;
        $hairColor->brightness = HairColorBrightness::VeryLight->value;
        $hairColor->temperature = HairColorTemperature::Cool->value;
        $hairColor->saturation = HairColorSaturation::High->value;
        $hairColor->maintenance_level = HairColorMaintenanceLevel::Difficult->value;
        $hairColor->save();

        (new HairColorSeeder())->run();

        $hairColor->refresh();
        $this->assertSame($media->id, $hairColor->cover_media_id);
        $this->assertSame('后台人工编辑过的 SEO 标题', $hairColor->seo_title);
        $this->assertSame('后台人工编辑过的发色介绍', $hairColor->description);
        $this->assertSame($anotherCategory->id, $hairColor->category_id);
        $this->assertSame(CommonStatus::Disabled->value, (int) $hairColor->status);
        $this->assertSame(888, $hairColor->sort);
        $this->assertSame(0, (int) $hairColor->is_recommended);
        $this->assertSame(HairColorBrightness::VeryLight->value, (int) $hairColor->brightness);
        $this->assertSame(HairColorTemperature::Cool->value, (int) $hairColor->temperature);
        $this->assertSame(HairColorSaturation::High->value, (int) $hairColor->saturation);
        $this->assertSame(HairColorMaintenanceLevel::Difficult->value, (int) $hairColor->maintenance_level);
    }

    /**
     * 分类/发色曾被软删除时，Seeder 必须能通过 withTrashed() 定位到原记录并直接
     * 返回，不会因为看不到软删除记录而重新 create() 触发 slug 唯一索引冲突，
     * 也不会“复活”被软删除的记录（保持 deleted_at 不变）。
     */
    public function test_category_seeder_finds_soft_deleted_category_without_conflict_or_restore(): void
    {
        (new HairColorCategorySeeder())->run();

        /** @var HairColorCategory $category */
        $category = HairColorCategory::query()->where('slug', 'black-tones')->first();
        $category->delete();

        (new HairColorCategorySeeder())->run();

        $this->assertSame(1, HairColorCategory::withTrashed()->where('slug', 'black-tones')->count());
        $this->assertSoftDeleted('hair_color_categories', ['id' => $category->id]);
    }

    public function test_hair_color_seeder_finds_soft_deleted_hair_color_without_conflict_or_restore(): void
    {
        (new HairColorCategorySeeder())->run();
        (new HairColorSeeder())->run();

        /** @var HairColor $hairColor */
        $hairColor = HairColor::query()->where('slug', 'natural-black')->first();
        $hairColor->delete();

        (new HairColorSeeder())->run();

        $this->assertSame(1, HairColor::withTrashed()->where('slug', 'natural-black')->count());
        $this->assertSoftDeleted('hair_colors', ['id' => $hairColor->id]);
    }
}
