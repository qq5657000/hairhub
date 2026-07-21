<?php

namespace Tests\Feature\Admin;

use App\Admin\Controllers\ArticleTagController;
use App\Enums\Common\CommonStatus;
use App\Models\ArticleTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ArticleTagController 覆盖范围：
 * - tagOptions()：供 ArticleController“文章标签”多选字段使用，已禁用标签
 *   标注“（已禁用）”后缀，不做启用性过滤（多选历史标签仍应可见）。
 *
 * 表单规则（slug 唯一/格式、删除前引用检查）已由 Phase 1 的
 * tests/Feature/Services/Content/ArticleTagServiceTest.php 覆盖，本文件不重复验证。
 */
class ArticleTagControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeTag(array $overrides = []): ArticleTag
    {
        return ArticleTag::create(array_merge([
            'name' => 'test_tag_'.uniqid(),
            'slug' => 'test-tag-'.uniqid(),
            'status' => CommonStatus::Enabled->value,
        ], $overrides));
    }

    public function test_tag_options_includes_disabled_suffix_for_disabled_tags(): void
    {
        $enabled = $this->makeTag(['name' => '启用标签']);
        $disabled = $this->makeTag(['name' => '禁用标签', 'status' => CommonStatus::Disabled->value]);

        $options = ArticleTagController::tagOptions();

        $this->assertSame('启用标签', $options[$enabled->id]);
        $this->assertStringContainsString('（已禁用）', $options[$disabled->id]);
    }

    public function test_tag_options_are_ordered_by_sort_and_id_desc(): void
    {
        $first = $this->makeTag(['sort' => 1]);
        $second = $this->makeTag(['sort' => 5]);

        $options = ArticleTagController::tagOptions();
        $keys = array_keys($options);

        $this->assertSame($second->id, $keys[0], 'sort 值更高的标签应排在前面');
        $this->assertContains($first->id, $keys);
    }
}
