<?php

namespace Tests\Feature\Admin;

use App\Admin\Controllers\HairstyleCategoryController;
use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaStatus;
use App\Models\MediaFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * HairstyleCategoryController 封面管理优化的核心规则验证：
 * - decideCoverAction()：纯决策函数，覆盖“上传 > 库内选择 > 清除 > 保留原值”优先级；
 * - resolveCoverMediaId()：结合实际媒体校验（图片类型 / 启用状态）得到最终 cover_media_id。
 *
 * 不经过 Dcat 的 HTTP 路由和后台登录态（与现有 HairstyleControllerTest 保持一致的测试策略），
 * Grid/Form 的可用性通过手工在后台操作验证（见开发总结）。
 */
class HairstyleCategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1x1 透明 PNG，用于模拟已经落盘的封面上传文件。
     */
    private const FAKE_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private HairstyleCategoryController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = app(HairstyleCategoryController::class);
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

    public function test_decide_cover_action_prefers_upload_over_everything(): void
    {
        $decision = HairstyleCategoryController::decideCoverAction('media/upload.png', 5, true, 9);

        $this->assertSame('upload', $decision['action']);
    }

    public function test_decide_cover_action_prefers_select_when_no_upload(): void
    {
        $decision = HairstyleCategoryController::decideCoverAction('', 5, true, 9);

        $this->assertSame(['action' => 'select', 'media_id' => 5], $decision);
    }

    public function test_decide_cover_action_clears_when_only_clear_flag_set(): void
    {
        $decision = HairstyleCategoryController::decideCoverAction('', 0, true, 9);

        $this->assertSame(['action' => 'clear', 'media_id' => 0], $decision);
    }

    public function test_decide_cover_action_keeps_original_when_nothing_changed(): void
    {
        $decision = HairstyleCategoryController::decideCoverAction('', 0, false, 9);

        $this->assertSame(['action' => 'keep', 'media_id' => 9], $decision);
    }

    public function test_resolve_cover_media_id_accepts_valid_image_media(): void
    {
        $media = $this->makeMedia();

        $mediaId = $this->controller->resolveCoverMediaId('', $media->id, false, 0);

        $this->assertSame($media->id, $mediaId);
    }

    public function test_resolve_cover_media_id_rejects_non_image_media(): void
    {
        $video = $this->makeMedia(['file_type' => MediaFileType::Video->value]);

        $this->expectException(ValidationException::class);

        $this->controller->resolveCoverMediaId('', $video->id, false, 0);
    }

    public function test_resolve_cover_media_id_rejects_disabled_media(): void
    {
        $disabled = $this->makeMedia(['status' => MediaStatus::Disabled->value]);

        $this->expectException(ValidationException::class);

        $this->controller->resolveCoverMediaId('', $disabled->id, false, 0);
    }

    public function test_resolve_cover_media_id_rejects_missing_media(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller->resolveCoverMediaId('', 999999, false, 0);
    }

    public function test_resolve_cover_media_id_clears_to_zero(): void
    {
        $mediaId = $this->controller->resolveCoverMediaId('', 0, true, 9);

        $this->assertSame(0, $mediaId);
    }

    public function test_resolve_cover_media_id_keeps_original_value_when_untouched(): void
    {
        $mediaId = $this->controller->resolveCoverMediaId('', 0, false, 9);

        $this->assertSame(9, $mediaId);
    }

    /**
     * 上传新文件时，会登记为一条新的 media_files 记录并返回其 ID，即使同时传入了
     * 库内选择的媒体 ID 或清除标记，也仍然以上传的文件优先（覆盖率对应“新上传图片优先”规则）。
     */
    public function test_resolve_cover_media_id_registers_new_media_file_on_upload(): void
    {
        Storage::fake('public');

        $path = 'media/'.now()->format('Y/m/d').'/'.uniqid().'.png';
        Storage::disk('public')->put($path, base64_decode(self::FAKE_PNG_BASE64));

        $existingMedia = $this->makeMedia();

        $mediaId = $this->controller->resolveCoverMediaId($path, $existingMedia->id, true, 9);

        $this->assertNotSame($existingMedia->id, $mediaId);
        $this->assertDatabaseHas('media_files', [
            'id' => $mediaId,
            'file_type' => MediaFileType::Image->value,
            'path' => $path,
        ]);
    }
}
