<?php

namespace Tests\Feature\Admin;

use App\Admin\Controllers\ArticleController;
use App\Enums\Common\CommonStatus;
use App\Enums\Hairstyle\HairstyleStatus;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\HairColor;
use App\Models\Hairstyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * ArticleController 覆盖范围：
 * - hairstyleOptions()/hairColorOptions()：“关联发型/发色”多选字段的远程搜索接口，
 *   只返回状态为“启用”的记录，支持关键字搜索（对应任务要求“只展示未删除且有效
 *   的数据，支持关键词搜索，数据量较大时禁止一次加载全部记录”）；
 * - restore()：委托 ArticleService::restore()，业务规则（分类有效性、slug 冲突）
 *   已由 Phase 1 的 tests/Feature/Services/Content/ArticleServiceTest.php 覆盖，
 *   本文件只补充 Controller 层是否正确委托 Service；
 * - Article::LIST_COLUMNS：锁定“列表查询不选择 content / wechat_content 等
 *   LONGTEXT 字段”这一约束（Grid::grid() 内 ->select(Article::LIST_COLUMNS)）。
 *
 * 不经过 Dcat 的 HTTP 路由和后台登录态，saving()/deleting() 回调（多 Tab 表单、
 * 封面/标签/发型/发色一次性提交）的可用性通过手工在后台操作验证（见开发总结）。
 */
class ArticleControllerTest extends TestCase
{
    use RefreshDatabase;

    private ArticleController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = app(ArticleController::class);
    }

    private function makeCategory(array $overrides = []): ArticleCategory
    {
        return ArticleCategory::create(array_merge([
            'name' => 'test_category_'.uniqid(),
            'slug' => 'test-category-'.uniqid(),
        ], $overrides));
    }

    private function makeArticle(array $overrides = []): Article
    {
        return Article::create(array_merge([
            'category_id' => $this->makeCategory()->id,
            'title' => 'test_article_'.uniqid(),
            'slug' => 'test-article-'.uniqid(),
        ], $overrides));
    }

    private function makeHairstyle(array $overrides = []): Hairstyle
    {
        return Hairstyle::create(array_merge([
            'name' => 'test_hairstyle_'.uniqid(),
            'slug' => 'test-hairstyle-'.uniqid(),
            'status' => HairstyleStatus::Enabled->value,
        ], $overrides));
    }

    private function makeHairColor(array $overrides = []): HairColor
    {
        return HairColor::create(array_merge([
            'name' => 'test_hair_color_'.uniqid(),
            'slug' => 'test-hair-color-'.uniqid(),
            'status' => CommonStatus::Enabled->value,
        ], $overrides));
    }

    public function test_list_columns_excludes_longtext_fields(): void
    {
        $this->assertNotContains('content', Article::LIST_COLUMNS);
        $this->assertNotContains('wechat_content', Article::LIST_COLUMNS);
        $this->assertContains('id', Article::LIST_COLUMNS);
        $this->assertContains('category_id', Article::LIST_COLUMNS, '列表需要预加载分类，必须包含外键');
        $this->assertContains('cover_media_id', Article::LIST_COLUMNS);
    }

    public function test_hairstyle_options_only_returns_enabled_hairstyles(): void
    {
        $enabled = $this->makeHairstyle(['name' => '启用发型']);
        $this->makeHairstyle(['name' => '禁用发型', 'status' => HairstyleStatus::Disabled->value]);

        $response = $this->controller->hairstyleOptions(Request::create('/', 'GET'));
        $items = json_decode($response->getContent(), true);

        $ids = array_column($items, 'id');
        $this->assertContains($enabled->id, $ids);
        $this->assertCount(1, $items, '禁用发型不应出现在关联发型的可选列表中');
    }

    public function test_hairstyle_options_supports_keyword_search(): void
    {
        $target = $this->makeHairstyle(['name' => '波波头短发']);
        $this->makeHairstyle(['name' => '长直发']);

        $response = $this->controller->hairstyleOptions(Request::create('/', 'GET', ['q' => '波波头']));
        $items = json_decode($response->getContent(), true);

        $this->assertCount(1, $items);
        $this->assertSame($target->id, $items[0]['id']);
    }

    public function test_hair_color_options_only_returns_enabled_hair_colors(): void
    {
        $enabled = $this->makeHairColor(['name' => '启用发色']);
        $this->makeHairColor(['name' => '禁用发色', 'status' => CommonStatus::Disabled->value]);

        $response = $this->controller->hairColorOptions(Request::create('/', 'GET'));
        $items = json_decode($response->getContent(), true);

        $ids = array_column($items, 'id');
        $this->assertContains($enabled->id, $ids);
        $this->assertCount(1, $items, '禁用发色不应出现在关联发色的可选列表中');
    }

    public function test_restore_brings_back_soft_deleted_article(): void
    {
        $article = $this->makeArticle();
        $article->delete();

        $this->controller->restore($article->id);

        $this->assertDatabaseHas('articles', ['id' => $article->id, 'deleted_at' => null]);
    }

    public function test_restore_rejects_when_category_has_been_deleted(): void
    {
        $category = $this->makeCategory();
        $article = $this->makeArticle(['category_id' => $category->id]);
        $article->delete();
        $category->delete();

        $this->controller->restore($article->id);

        // 分类已被删除时不允许恢复文章，记录应仍处于回收站中。
        $this->assertSoftDeleted('articles', ['id' => $article->id]);
    }
}
