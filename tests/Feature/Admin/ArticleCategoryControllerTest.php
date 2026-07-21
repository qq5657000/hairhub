<?php

namespace Tests\Feature\Admin;

use App\Admin\Controllers\ArticleCategoryController;
use App\Enums\Common\CommonStatus;
use App\Models\ArticleCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ArticleCategoryController 覆盖范围：
 * - articleFormOptions()：供 ArticleController“分类”下拉使用，展示全部分类
 *   （含子分类），已禁用分类标注“（已禁用）”后缀，不做启用性过滤；
 * - parentFormOptions()：文章分类表单“父级分类”下拉规则——只允许顶级分类，
 *   编辑时排除自身；
 * - restore()：委托 ArticleCategoryService::restore()，业务规则本身已由 Phase 1
 *   的 tests/Feature/Services/Content/ArticleCategoryServiceTest.php 覆盖，本文件
 *   只补充 Controller 层是否正确委托 Service。
 *
 * 不经过 Dcat 的 HTTP 路由和后台登录态，Grid/Form 的可用性通过手工在后台操作验证。
 */
class ArticleCategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private ArticleCategoryController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = app(ArticleCategoryController::class);
    }

    private function makeCategory(array $overrides = []): ArticleCategory
    {
        return ArticleCategory::create(array_merge([
            'parent_id' => 0,
            'name' => 'test_category_'.uniqid(),
            'slug' => 'test-category-'.uniqid(),
            'status' => CommonStatus::Enabled->value,
        ], $overrides));
    }

    public function test_article_form_options_includes_disabled_suffix_for_disabled_categories(): void
    {
        $enabled = $this->makeCategory(['name' => '启用分类']);
        $disabled = $this->makeCategory(['name' => '禁用分类', 'status' => CommonStatus::Disabled->value]);

        $options = ArticleCategoryController::articleFormOptions();

        $this->assertSame('启用分类', $options[$enabled->id]);
        $this->assertStringContainsString('（已禁用）', $options[$disabled->id]);
    }

    public function test_article_form_options_includes_child_categories_with_indent_prefix(): void
    {
        $parent = $this->makeCategory(['name' => '父分类']);
        $child = $this->makeCategory(['name' => '子分类', 'parent_id' => $parent->id]);

        $options = ArticleCategoryController::articleFormOptions();

        $this->assertArrayHasKey($child->id, $options);
        $this->assertStringContainsString('子分类', $options[$child->id]);
    }

    public function test_parent_form_options_only_returns_top_level_categories(): void
    {
        $top = $this->makeCategory(['name' => '顶级分类A']);
        $child = $this->makeCategory(['name' => '子分类A', 'parent_id' => $top->id]);

        $options = ArticleCategoryController::parentFormOptions(null);

        $this->assertArrayHasKey($top->id, $options);
        $this->assertArrayNotHasKey($child->id, $options, '二级分类不应出现在“父级分类”下拉中');
        $this->assertArrayHasKey(0, $options, '必须始终包含“顶级分类”选项');
    }

    public function test_parent_form_options_excludes_self_when_editing(): void
    {
        $top = $this->makeCategory(['name' => '顶级分类B']);

        $options = ArticleCategoryController::parentFormOptions($top->id);

        $this->assertArrayNotHasKey($top->id, $options, '编辑时不能选择自己作为父级');
    }

    public function test_restore_brings_back_soft_deleted_category(): void
    {
        $category = $this->makeCategory();
        $category->delete();

        $this->controller->restore($category->id);

        $this->assertDatabaseHas('article_categories', ['id' => $category->id, 'deleted_at' => null]);
    }
}
