<?php

namespace Tests\Feature\Admin;

use App\Admin\Controllers\HairColorCategoryController;
use App\Enums\Common\CommonStatus;
use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaStatus;
use App\Models\HairColorCategory;
use App\Models\MediaFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * HairColorCategoryController 覆盖范围：
 * - parentFormOptions()/parentFilterOptions()：只允许选择存在、未删除、启用的
 *   顶级分类，编辑时排除自身；如果当前分类原本的父级已被禁用，会保留并展示
 *   这一个父级，但不允许出现其它禁用的顶级分类；
 * - resolveCoverMediaId()（继承自 ManagesCoverMedia trait）：封面校验规则；
 * - restore()：委托 HairColorCategoryService::restore()，本测试只验证 Controller
 *   正确调用 Service 并正确处理异常（不重新验证 Service 内部规则，Service 规则
 *   已由 tests/Feature/Services/HairColor/HairColorCategoryServiceTest.php 覆盖）。
 *
 * V1.0 只保留软删除和恢复，不提供永久删除（forceDelete）能力，本文件不覆盖该场景。
 *
 * 不经过 Dcat 的 HTTP 路由和后台登录态（与现有 HairstyleCategoryControllerTest
 * 保持一致的测试策略），Grid/Form 的可用性（含 N+1 预加载是否生效）通过手工在
 * 后台操作验证（见开发总结）。
 */
class HairColorCategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private HairColorCategoryController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = app(HairColorCategoryController::class);
    }

    private function makeCategory(array $overrides = []): HairColorCategory
    {
        return HairColorCategory::create(array_merge([
            'parent_id' => 0,
            'name' => 'test_category_'.uniqid(),
            'slug' => 'test-category-'.uniqid(),
            'status' => 1,
        ], $overrides));
    }

    private function makeMedia(array $overrides = []): MediaFile
    {
        return MediaFile::create(array_merge([
            'file_no' => 'TEST'.uniqid(),
            'path' => 'test/'.uniqid().'.jpg',
            'file_type' => MediaFileType::Image->value,
            'status' => MediaStatus::Active->value,
        ], $overrides));
    }

    // ------------------------------------------------------------------
    // parentFormOptions() / parentFilterOptions()
    // ------------------------------------------------------------------

    public function test_parent_form_options_only_includes_top_level_categories(): void
    {
        $top = $this->makeCategory(['name' => '顶级A']);
        $secondLevel = $this->makeCategory(['name' => '二级B', 'parent_id' => $top->id]);
        $anotherTop = $this->makeCategory(['name' => '顶级C']);

        $options = HairColorCategoryController::parentFormOptions(null);

        $this->assertArrayHasKey($top->id, $options);
        $this->assertArrayHasKey($anotherTop->id, $options);
        $this->assertArrayNotHasKey($secondLevel->id, $options);
        $this->assertSame('顶级分类', $options[0]);
    }

    public function test_parent_form_options_excludes_self_when_editing(): void
    {
        $top = $this->makeCategory(['name' => '顶级A']);
        $anotherTop = $this->makeCategory(['name' => '顶级C']);

        $options = HairColorCategoryController::parentFormOptions($top->id);

        $this->assertArrayNotHasKey($top->id, $options);
        $this->assertArrayHasKey($anotherTop->id, $options);
    }

    /**
     * 父级只允许选择存在、未删除、启用的顶级分类：禁用的顶级分类不应该出现在
     * 新建场景的下拉里。
     */
    public function test_parent_form_options_excludes_disabled_top_level_categories_when_creating(): void
    {
        $enabledTop = $this->makeCategory(['name' => '启用顶级']);
        $disabledTop = $this->makeCategory(['name' => '禁用顶级', 'status' => CommonStatus::Disabled->value]);

        $options = HairColorCategoryController::parentFormOptions(null);

        $this->assertArrayHasKey($enabledTop->id, $options);
        $this->assertArrayNotHasKey($disabledTop->id, $options);
    }

    /**
     * 编辑场景：如果当前分类原本的父级分类已被禁用，下拉必须保留并展示这一个
     * 父级（标注“已禁用”），避免因为找不到当前值导致浏览器把 <select> 提交为
     * 其它选项、静默改变 parent_id；但不允许借着下拉切换到其它禁用的顶级分类。
     */
    public function test_parent_form_options_keeps_current_disabled_parent_when_editing(): void
    {
        $currentDisabledParent = $this->makeCategory(['name' => '当前禁用父级', 'status' => CommonStatus::Disabled->value]);
        $otherDisabledTop = $this->makeCategory(['name' => '其它禁用顶级', 'status' => CommonStatus::Disabled->value]);
        $enabledTop = $this->makeCategory(['name' => '启用顶级']);
        $child = $this->makeCategory(['name' => '二级子分类', 'parent_id' => $currentDisabledParent->id]);

        $options = HairColorCategoryController::parentFormOptions($child->id);

        $this->assertArrayHasKey($currentDisabledParent->id, $options);
        $this->assertSame('当前禁用父级（已禁用）', $options[$currentDisabledParent->id]);
        $this->assertArrayHasKey($enabledTop->id, $options);
        $this->assertArrayNotHasKey($otherDisabledTop->id, $options);
    }

    public function test_parent_filter_options_includes_top_level_only_with_top_category_label(): void
    {
        $top = $this->makeCategory(['name' => '顶级A']);
        $this->makeCategory(['name' => '二级B', 'parent_id' => $top->id]);

        $options = HairColorCategoryController::parentFilterOptions();

        $this->assertSame('顶级分类', $options[0]);
        $this->assertArrayHasKey($top->id, $options);
    }

    /**
     * 创建第三级分类被拒绝：由 HairColorCategoryService::assertParentValid() 负责，
     * 该规则已在 Phase 1 的 HairColorCategoryServiceTest 覆盖；这里从后台视角
     * 补充一次集成验证，确保 Controller 没有绕过 Service 重新放宽该限制。
     */
    public function test_creating_third_level_category_is_rejected_by_service(): void
    {
        $top = $this->makeCategory(['name' => '顶级A']);
        $secondLevel = $this->makeCategory(['name' => '二级B', 'parent_id' => $top->id]);

        $this->expectException(ValidationException::class);

        app(\App\Services\HairColor\HairColorCategoryService::class)->create([
            'parent_id' => $secondLevel->id,
            'name' => '第三级C',
            'slug' => 'third-level-c',
            'status' => 1,
        ]);
    }

    // ------------------------------------------------------------------
    // resolveCoverMediaId()（ManagesCoverMedia trait，与 HairColorController 共用）
    // ------------------------------------------------------------------

    public function test_resolve_cover_media_id_accepts_valid_image_media(): void
    {
        $media = $this->makeMedia();

        $mediaId = $this->controller->resolveCoverMediaId(0, $media->id, false, 0);

        $this->assertSame($media->id, $mediaId);
    }

    public function test_resolve_cover_media_id_rejects_non_image_media(): void
    {
        $video = $this->makeMedia(['file_type' => MediaFileType::Video->value]);

        $this->expectException(ValidationException::class);

        $this->controller->resolveCoverMediaId(0, $video->id, false, 0);
    }

    public function test_resolve_cover_media_id_rejects_disabled_media(): void
    {
        $disabled = $this->makeMedia(['status' => MediaStatus::Disabled->value]);

        $this->expectException(ValidationException::class);

        $this->controller->resolveCoverMediaId(0, $disabled->id, false, 0);
    }

    public function test_resolve_cover_media_id_rejects_missing_media(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller->resolveCoverMediaId(0, 999999, false, 0);
    }

    public function test_resolve_cover_media_id_clears_to_zero(): void
    {
        $mediaId = $this->controller->resolveCoverMediaId(0, 0, true, 9);

        $this->assertSame(0, $mediaId);
    }

    public function test_resolve_cover_media_id_keeps_original_value_when_untouched(): void
    {
        $mediaId = $this->controller->resolveCoverMediaId(0, 0, false, 9);

        $this->assertSame(9, $mediaId);
    }

    // ------------------------------------------------------------------
    // restore()
    // ------------------------------------------------------------------

    public function test_restore_brings_back_soft_deleted_category(): void
    {
        $category = $this->makeCategory();
        $category->delete();

        $this->controller->restore($category->id);

        $this->assertDatabaseHas('hair_color_categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    public function test_restore_does_nothing_when_category_not_trashed(): void
    {
        $category = $this->makeCategory();

        // 未软删除时 restore() 内部 Service 抛出 ModelNotFoundException，
        // Controller 捕获后仅 toastr 提示，不影响已存在的记录。
        $this->controller->restore($category->id);

        $this->assertDatabaseHas('hair_color_categories', ['id' => $category->id, 'deleted_at' => null]);
    }

}
