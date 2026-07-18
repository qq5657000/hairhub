<?php

namespace Tests\Feature\Admin;

use App\Admin\Controllers\HairColorController;
use App\Enums\Common\CommonStatus;
use App\Enums\HairColor\HairColorSuitableSkin;
use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaStatus;
use App\Models\HairColor;
use App\Models\HairColorCategory;
use App\Models\MediaFile;
use App\Services\HairColor\HairColorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * HairColorController 覆盖范围：
 * - categoryOptions()：列表筛选下拉选项，禁用分类需要带“（已禁用）”后缀；
 * - categoryFormOptions()：新增/编辑 Form 的“发色分类”下拉规则——新建时排除
 *   禁用分类，编辑时保留当前分类（即使已禁用）但不允许出现其它禁用分类；
 * - renderColorSwatch()/formatSuitableSkin()：Grid/Show 展示用的纯格式化方法，
 *   重点验证输出内容被正确转义（对应“禁止把数据库值直接拼接成不安全 HTML”）；
 * - resolveCoverMediaId()（继承自 ManagesCoverMedia trait）：封面校验规则；
 * - restore()：委托 HairColorService::restore()，业务规则（分类有效性、
 *   color_hex 必填、suitable_skin 标准化等）已由 Phase 1 的
 *   tests/Feature/Services/HairColor/HairColorServiceTest.php 覆盖，本文件只
 *   补充 Controller 层是否正确委托 Service 并处理异常的集成验证。
 *
 * V1.0 只保留软删除和恢复，不提供永久删除（forceDelete）能力，本文件不覆盖该场景。
 *
 * 不经过 Dcat 的 HTTP 路由和后台登录态，Grid/Form 的可用性（含 N+1 预加载、
 * select() 排除大字段是否影响编辑）通过手工在后台操作验证（见开发总结）。
 */
class HairColorControllerTest extends TestCase
{
    use RefreshDatabase;

    private HairColorController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = app(HairColorController::class);
    }

    private function makeCategory(array $overrides = []): HairColorCategory
    {
        return HairColorCategory::create(array_merge([
            'parent_id' => 0,
            'name' => 'test_category_'.uniqid(),
            'slug' => 'test-category-'.uniqid(),
            'status' => CommonStatus::Enabled->value,
        ], $overrides));
    }

    private function makeHairColor(array $overrides = []): HairColor
    {
        $category = $overrides['category_id'] ?? null;
        unset($overrides['category_id']);

        return HairColor::create(array_merge([
            'category_id' => $category ?: $this->makeCategory()->id,
            'name' => 'test_hair_color_'.uniqid(),
            'slug' => 'test-hair-color-'.uniqid(),
            'color_hex' => '#123456',
            'status' => CommonStatus::Enabled->value,
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
    // categoryOptions()
    // ------------------------------------------------------------------

    public function test_category_options_marks_disabled_category(): void
    {
        $enabled = $this->makeCategory(['name' => '启用分类']);
        $disabled = $this->makeCategory(['name' => '禁用分类', 'status' => CommonStatus::Disabled->value]);

        $options = HairColorController::categoryOptions();

        $this->assertSame('启用分类', $options[$enabled->id]);
        $this->assertSame('禁用分类（已禁用）', $options[$disabled->id]);
    }

    // ------------------------------------------------------------------
    // categoryFormOptions()：发色 Form 的“发色分类”下拉规则
    // ------------------------------------------------------------------

    /**
     * 新建场景（$currentCategoryId 为 null）：下拉只能出现启用分类，禁用分类
     * 完全不应该出现在可选项里。
     */
    public function test_category_form_options_excludes_disabled_categories_when_creating(): void
    {
        $enabled = $this->makeCategory(['name' => '启用分类']);
        $disabled = $this->makeCategory(['name' => '禁用分类', 'status' => CommonStatus::Disabled->value]);

        $options = HairColorController::categoryFormOptions(null);

        $this->assertArrayHasKey($enabled->id, $options);
        $this->assertArrayNotHasKey($disabled->id, $options);
    }

    /**
     * 编辑场景：如果当前发色所属分类已被禁用，下拉必须保留并展示这一个分类
     * （标注“已禁用”），避免因为被过滤掉而在前端把 category_id 显示为空。
     */
    public function test_category_form_options_keeps_current_disabled_category_when_editing(): void
    {
        $currentDisabled = $this->makeCategory(['name' => '当前禁用分类', 'status' => CommonStatus::Disabled->value]);
        $otherDisabled = $this->makeCategory(['name' => '其它禁用分类', 'status' => CommonStatus::Disabled->value]);
        $enabled = $this->makeCategory(['name' => '启用分类']);

        $options = HairColorController::categoryFormOptions($currentDisabled->id);

        $this->assertArrayHasKey($currentDisabled->id, $options);
        $this->assertSame('当前禁用分类（已禁用）', $options[$currentDisabled->id]);
        $this->assertArrayHasKey($enabled->id, $options);
        // 不允许切换到其它禁用分类：除了当前分类自己，不应该出现任何其它禁用分类。
        $this->assertArrayNotHasKey($otherDisabled->id, $options);
    }

    // ------------------------------------------------------------------
    // renderColorSwatch() / formatSuitableSkin()
    // ------------------------------------------------------------------

    public function test_render_color_swatch_shows_placeholder_for_empty_value(): void
    {
        $html = HairColorController::renderColorSwatch('');

        $this->assertStringContainsString('未设置', $html);
    }

    public function test_render_color_swatch_renders_valid_hex_escaped(): void
    {
        $html = HairColorController::renderColorSwatch('#ABCDEF');

        $this->assertStringContainsString('#ABCDEF', $html);
        $this->assertStringContainsString('background-color:#ABCDEF', $html);
    }

    /**
     * 防御性兜底：即使出现非受控字符串（理论上 Model Mutator 已保证落库值合法，
     * 不会发生），也必须整体转义输出，不能把该值当作可信的 CSS 值拼接。
     */
    public function test_render_color_swatch_escapes_unsafe_value_defensively(): void
    {
        $html = HairColorController::renderColorSwatch('"><script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_format_suitable_skin_returns_unlimited_for_empty_array(): void
    {
        $this->assertSame('不限', HairColorController::formatSuitableSkin([]));
    }

    public function test_format_suitable_skin_joins_labels_in_given_order(): void
    {
        $result = HairColorController::formatSuitableSkin([
            HairColorSuitableSkin::Fair->value,
            HairColorSuitableSkin::WarmSkin->value,
        ]);

        $this->assertSame('非常白皙、暖底调', $result);
    }

    // ------------------------------------------------------------------
    // resolveCoverMediaId()（ManagesCoverMedia trait，与 HairColorCategoryController 共用）
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

    // ------------------------------------------------------------------
    // restore()
    // ------------------------------------------------------------------

    public function test_restore_brings_back_soft_deleted_hair_color(): void
    {
        $hairColor = $this->makeHairColor();
        $hairColor->delete();

        $this->controller->restore($hairColor->id);

        $this->assertDatabaseHas('hair_colors', ['id' => $hairColor->id, 'deleted_at' => null]);
    }

    /**
     * 恢复发色时无效分类被拒绝：分类已被软删除，恢复发色应被拒绝，发色保持软删除状态。
     * 对应 Phase 1 已确定的规则：恢复发色时分类必须存在且未删除（不强制启用）。
     */
    public function test_restore_rejects_when_category_is_soft_deleted(): void
    {
        $category = $this->makeCategory();
        $hairColor = $this->makeHairColor(['category_id' => $category->id]);
        $hairColor->delete();
        $category->delete();

        $this->controller->restore($hairColor->id);

        $this->assertSoftDeleted('hair_colors', ['id' => $hairColor->id]);
    }

    /**
     * 封面上传一致性：媒体文件的落盘与 media_files 入库发生在“上传”这次独立的
     * 异步请求内（ManagesCoverMedia::handleCoverUpload() -> MediaFileService::
     * storeFromUploadedFile()），与主表单提交（本测试模拟的 HairColorService::create()）
     * 是两个独立操作，不共享同一个数据库事务。这里验证：当业务写入（create()）因为
     * slug 冲突失败时，已经独立提交成功的媒体记录不会被回滚或清理，会以“未被引用”
     * 的状态保留在媒体库中，媒体记录本身与实际文件（此处用已存在的 MediaFile 模拟）
     * 始终保持一致，不会产生“数据库记录指向不存在文件”的悬空记录。
     */
    public function test_failed_service_create_does_not_touch_already_uploaded_cover_media(): void
    {
        $media = $this->makeMedia();
        $existing = $this->makeHairColor(['slug' => 'duplicate-slug-hair-color']);

        try {
            app(HairColorService::class)->create([
                'category_id' => $existing->category_id,
                'name' => '重复 slug 发色',
                'slug' => 'duplicate-slug-hair-color',
                'color_hex' => '#111111',
                'cover_media_id' => $media->id,
            ]);
            $this->fail('应当抛出 ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slug', $e->errors());
        }

        $this->assertDatabaseHas('media_files', ['id' => $media->id]);
        $this->assertDatabaseMissing('hair_colors', ['cover_media_id' => $media->id]);
    }

    /**
     * 发色分类无效时不能保存：新增发色时分类被禁用应被拒绝。规则本身已由 Phase 1
     * 的 HairColorServiceTest 覆盖，这里从后台调用路径做一次集成验证，确认
     * Controller 依赖的 HairColorService::create() 没有被绕过或放宽。
     */
    public function test_creating_hair_color_with_disabled_category_is_rejected_by_service(): void
    {
        $disabledCategory = $this->makeCategory(['status' => CommonStatus::Disabled->value]);

        $this->expectException(ValidationException::class);

        app(HairColorService::class)->create([
            'category_id' => $disabledCategory->id,
            'name' => '测试发色',
            'slug' => 'test-hair-color-disabled-category',
            'color_hex' => '#654321',
        ]);
    }
}
