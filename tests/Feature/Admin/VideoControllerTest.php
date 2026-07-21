<?php

namespace Tests\Feature\Admin;

use App\Admin\Controllers\VideoController;
use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaStatus;
use App\Models\MediaFile;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * VideoController 覆盖范围：
 * - formatDuration()：秒数格式化为 mm:ss / hh:mm:ss 的纯函数，覆盖 0 秒、
 *   不足一小时、超过一小时、负数（视为 0）几种边界；
 * - resolveMediaSelection()：封面/本地视频媒体选择的校验逻辑（媒体不存在、
 *   类型不匹配、状态不可用时拒绝），与 VideoService 的媒体校验规则保持一致，
 *   但这里额外校验“文件类型是否匹配所选用途”（封面必须是图片，本地视频必须
 *   是视频），VideoService 本身也会再校验一次，双重保险；
 * - restore()：委托 VideoService::restore()，业务规则本身已由 Phase 1 的
 *   tests/Feature/Services/Content/VideoServiceTest.php 覆盖。
 *
 * 不经过 Dcat 的 HTTP 路由和后台登录态，Grid/Form 的可用性通过手工在后台操作验证。
 */
class VideoControllerTest extends TestCase
{
    use RefreshDatabase;

    private VideoController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = app(VideoController::class);
    }

    private function makeVideo(array $overrides = []): Video
    {
        return Video::create(array_merge([
            'title' => 'test_video_'.uniqid(),
            'slug' => 'test-video-'.uniqid(),
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

    /**
     * @dataProvider durationProvider
     */
    public function test_format_duration_produces_readable_string(int $seconds, string $expected): void
    {
        $this->assertSame($expected, VideoController::formatDuration($seconds));
    }

    public static function durationProvider(): array
    {
        return [
            '零秒' => [0, '00:00'],
            '不足一分钟' => [45, '00:45'],
            '数分钟' => [125, '02:05'],
            '刚好一小时' => [3600, '01:00:00'],
            '超过一小时' => [3725, '01:02:05'],
            '负数视为0' => [-10, '00:00'],
        ];
    }

    public function test_list_columns_excludes_transcript(): void
    {
        $this->assertNotContains('transcript', Video::LIST_COLUMNS);
        $this->assertContains('id', Video::LIST_COLUMNS);
        $this->assertContains('cover_media_id', Video::LIST_COLUMNS);
        $this->assertContains('video_media_id', Video::LIST_COLUMNS);
    }

    public function test_resolve_media_selection_rejects_media_type_mismatch(): void
    {
        $video = $this->makeMedia(['file_type' => MediaFileType::Video->value]);

        $this->expectException(ValidationException::class);

        $this->invokeResolveMediaSelection(0, $video->id, false, 0, MediaFileType::Image, 'cover_media_id');
    }

    public function test_resolve_media_selection_rejects_inactive_media(): void
    {
        $disabled = $this->makeMedia(['status' => MediaStatus::Disabled->value]);

        $this->expectException(ValidationException::class);

        $this->invokeResolveMediaSelection(0, $disabled->id, false, 0, MediaFileType::Image, 'cover_media_id');
    }

    public function test_resolve_media_selection_keeps_current_value_without_input(): void
    {
        $result = $this->invokeResolveMediaSelection(0, 0, false, 99, MediaFileType::Image, 'cover_media_id');

        $this->assertSame(99, $result);
    }

    public function test_resolve_media_selection_clears_when_requested(): void
    {
        $result = $this->invokeResolveMediaSelection(0, 0, true, 99, MediaFileType::Image, 'cover_media_id');

        $this->assertSame(0, $result);
    }

    private function invokeResolveMediaSelection(...$args): int
    {
        $method = new \ReflectionMethod($this->controller, 'resolveMediaSelection');
        $method->setAccessible(true);

        return $method->invoke($this->controller, ...$args);
    }

    public function test_restore_brings_back_soft_deleted_video(): void
    {
        $video = $this->makeVideo();
        $video->delete();

        $this->controller->restore($video->id);

        $this->assertDatabaseHas('videos', ['id' => $video->id, 'deleted_at' => null]);
    }
}
