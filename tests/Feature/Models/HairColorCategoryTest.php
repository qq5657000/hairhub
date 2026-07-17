<?php

namespace Tests\Feature\Models;

use App\Models\HairColor;
use App\Models\HairColorCategory;
use App\Models\MediaFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HairColorCategory Model 基础行为验证：关系是否正确、是否执行软删除。
 *
 * 业务规则（层级约束、删除/恢复前置校验）由 HairColorCategoryService 负责，
 * 见 tests/Feature/Services/HairColor/HairColorCategoryServiceTest.php。
 */
class HairColorCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeCategory(array $overrides = []): HairColorCategory
    {
        return HairColorCategory::create(array_merge([
            'name' => 'test_category',
            'slug' => 'test-category-'.uniqid(),
        ], $overrides));
    }

    private function makeMedia(array $overrides = []): MediaFile
    {
        return MediaFile::create(array_merge([
            'file_no' => 'TEST'.uniqid(),
            'path' => 'test/'.uniqid().'.jpg',
        ], $overrides));
    }

    public function test_parent_and_children_relations_are_correct(): void
    {
        $parent = $this->makeCategory(['name' => '自然色系']);
        $child = $this->makeCategory(['name' => '黑色系', 'parent_id' => $parent->id]);

        $this->assertSame($parent->id, $child->parent->id);
        $this->assertTrue($parent->children->contains('id', $child->id));
    }

    public function test_hair_colors_relation_returns_related_hair_colors(): void
    {
        $category = $this->makeCategory();

        $hairColor = HairColor::create([
            'category_id' => $category->id,
            'name' => '自然黑',
            'slug' => 'natural-black-'.uniqid(),
        ]);

        $this->assertTrue($category->hairColors->contains('id', $hairColor->id));
        $this->assertSame($category->id, $hairColor->category->id);
    }

    public function test_cover_media_relation_is_correct(): void
    {
        $media = $this->makeMedia();
        $category = $this->makeCategory(['cover_media_id' => $media->id]);

        $this->assertNotNull($category->coverMedia);
        $this->assertSame($media->id, $category->coverMedia->id);
    }

    public function test_soft_delete_sets_deleted_at_and_hides_from_default_query(): void
    {
        $category = $this->makeCategory();

        $category->delete();

        $this->assertSoftDeleted('hair_color_categories', ['id' => $category->id]);
        $this->assertNull(HairColorCategory::query()->find($category->id));
        $this->assertNotNull(HairColorCategory::withTrashed()->find($category->id));
    }
}
