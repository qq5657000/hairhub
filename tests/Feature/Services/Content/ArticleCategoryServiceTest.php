<?php

namespace Tests\Feature\Services\Content;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Services\Content\ArticleCategoryService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ArticleCategoryService 业务规则验证（对齐 HairColorCategoryServiceTest 的用例设计）：
 * - 分类最多两级，禁止选择二级分类作为父级创建第三级；
 * - 父级不能指向自身或自身的子分类；
 * - 删除前检查未删除子分类和未删除文章引用；
 * - 删除仅执行软删除；
 * - 恢复分类时检查 slug 冲突，且不自动恢复子分类。
 */
class ArticleCategoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private ArticleCategoryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ArticleCategoryService();
    }

    private function makeCategory(array $overrides = []): ArticleCategory
    {
        return ArticleCategory::create(array_merge([
            'name' => 'test_category',
            'slug' => 'test-category-'.uniqid(),
        ], $overrides));
    }

    public function test_create_allows_top_level_category(): void
    {
        $category = $this->service->create(['name' => '男生发型', 'slug' => 'men-'.uniqid()]);

        $this->assertSame(0, $category->parent_id);
    }

    public function test_create_allows_second_level_category_under_top_level(): void
    {
        $parent = $this->makeCategory();

        $child = $this->service->create([
            'name' => '男生短发',
            'slug' => 'men-short-'.uniqid(),
            'parent_id' => $parent->id,
        ]);

        $this->assertSame($parent->id, $child->parent_id);
    }

    public function test_create_rejects_third_level_category(): void
    {
        $topLevel = $this->makeCategory();
        $secondLevel = $this->makeCategory(['parent_id' => $topLevel->id]);

        $this->expectException(ValidationException::class);

        $this->service->create([
            'name' => '第三级',
            'slug' => 'third-'.uniqid(),
            'parent_id' => $secondLevel->id,
        ]);
    }

    public function test_create_rejects_missing_parent(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create([
            'name' => '子分类',
            'slug' => 'child-'.uniqid(),
            'parent_id' => 999999,
        ]);
    }

    public function test_create_rejects_duplicate_slug_even_when_original_is_trashed(): void
    {
        $category = $this->makeCategory(['slug' => 'duplicate-slug']);
        $category->delete();

        $this->expectException(ValidationException::class);

        $this->service->create(['name' => '重复Slug', 'slug' => 'duplicate-slug']);
    }

    public function test_update_rejects_parent_pointing_to_self(): void
    {
        $category = $this->makeCategory();

        $this->expectException(ValidationException::class);

        $this->service->update($category, ['parent_id' => $category->id]);
    }

    public function test_update_rejects_parent_pointing_to_own_child(): void
    {
        $parent = $this->makeCategory();
        $child = $this->makeCategory(['parent_id' => $parent->id]);

        $this->expectException(ValidationException::class);

        $this->service->update($parent, ['parent_id' => $child->id]);
    }

    public function test_delete_rejects_when_undeleted_children_exist(): void
    {
        $parent = $this->makeCategory();
        $this->makeCategory(['parent_id' => $parent->id]);

        $this->expectException(ValidationException::class);

        $this->service->delete($parent->id);
    }

    public function test_delete_rejects_when_undeleted_articles_exist(): void
    {
        $category = $this->makeCategory();

        Article::create([
            'category_id' => $category->id,
            'title' => '测试文章',
            'slug' => 'test-article-'.uniqid(),
        ]);

        $this->expectException(ValidationException::class);

        $this->service->delete($category->id);
    }

    public function test_delete_soft_deletes_category_when_no_references(): void
    {
        $category = $this->makeCategory();

        $this->service->delete($category->id);

        $this->assertSoftDeleted('article_categories', ['id' => $category->id]);
    }

    public function test_delete_rejects_missing_category(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->delete(999999);
    }

    public function test_restore_brings_back_soft_deleted_category(): void
    {
        $category = $this->makeCategory();
        $category->delete();

        $restored = $this->service->restore($category->id);

        $this->assertDatabaseHas('article_categories', ['id' => $restored->id, 'deleted_at' => null]);
    }

    public function test_restore_does_not_automatically_restore_children(): void
    {
        $parent = $this->makeCategory();
        $child = $this->makeCategory(['parent_id' => $parent->id]);

        $parent->delete();
        $child->delete();

        $this->service->restore($parent->id);

        $this->assertDatabaseHas('article_categories', ['id' => $parent->id, 'deleted_at' => null]);
        $this->assertSoftDeleted('article_categories', ['id' => $child->id]);
    }

    public function test_restore_rejects_missing_or_not_trashed_category(): void
    {
        $category = $this->makeCategory();

        $this->expectException(ModelNotFoundException::class);

        $this->service->restore($category->id);
    }
}
