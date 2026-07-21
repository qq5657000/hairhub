<?php

namespace Tests\Feature\Services\Content;

use App\Enums\Content\ContentStatus;
use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaStatus;
use App\Enums\Video\VideoSource;
use App\Models\MediaFile;
use App\Models\Video;
use App\Services\Content\VideoService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * VideoService 业务规则验证：
 * - 本地视频必须提供 video_media_id，外部视频必须提供 video_url，二者至少存在一种；
 * - 封面/视频媒体的状态与类型校验；
 * - duration 不能为负数；
 * - 外部 source_video_id 避免重复录入；
 * - 状态流转与发布前置条件（与 Article 共用 ContentStatus）；
 * - view_count/like_count 不允许写入负数。
 */
class VideoServiceTest extends TestCase
{
    use RefreshDatabase;

    private VideoService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new VideoService();
    }

    private function makeMedia(?int $fileType = null, ?int $status = null): MediaFile
    {
        return MediaFile::create([
            'file_no' => 'TEST'.uniqid(),
            'path' => 'test/'.uniqid().'.jpg',
            'file_type' => $fileType ?? MediaFileType::Image->value,
            'status' => $status ?? MediaStatus::Active->value,
        ]);
    }

    private function makeCover(): MediaFile
    {
        return $this->makeMedia(MediaFileType::Image->value);
    }

    private function makeVideoMedia(): MediaFile
    {
        return $this->makeMedia(MediaFileType::Video->value);
    }

    /**
     * @return array<string, mixed>
     */
    private function localVideoData(): array
    {
        return [
            'title' => '本地视频',
            'slug' => 'local-video-'.uniqid(),
            'cover_media_id' => $this->makeCover()->id,
            'video_media_id' => $this->makeVideoMedia()->id,
            'source' => VideoSource::Local->value,
        ];
    }

    // ----------------------------------------------------------------
    // create() / update()
    // ----------------------------------------------------------------

    public function test_create_local_video_succeeds(): void
    {
        $video = $this->service->create($this->localVideoData());

        $this->assertSame(ContentStatus::Draft, $video->status);
        $this->assertSame(VideoSource::Local, $video->source);
    }

    public function test_create_rejects_when_no_source_provided(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create([
            'title' => '无来源视频',
            'slug' => 'no-source-'.uniqid(),
        ]);
    }

    public function test_create_local_source_requires_video_media_id(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create([
            'title' => '本地视频缺媒体',
            'slug' => 'local-missing-'.uniqid(),
            'source' => VideoSource::Local->value,
            'video_url' => 'https://example.com/video.mp4',
        ]);
    }

    public function test_create_external_source_requires_video_url(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create([
            'title' => '外部视频缺地址',
            'slug' => 'external-missing-'.uniqid(),
            'source' => VideoSource::Douyin->value,
        ]);
    }

    public function test_create_external_source_with_url_succeeds(): void
    {
        $video = $this->service->create([
            'title' => '外部视频',
            'slug' => 'external-'.uniqid(),
            'source' => VideoSource::Douyin->value,
            'video_url' => 'https://example.com/video.mp4',
            'source_video_id' => 'abc123',
        ]);

        $this->assertSame(VideoSource::Douyin, $video->source);
    }

    public function test_create_rejects_negative_duration(): void
    {
        $data = $this->localVideoData();
        $data['duration'] = -10;

        $this->expectException(ValidationException::class);

        $this->service->create($data);
    }

    public function test_create_rejects_duplicate_source_video_id(): void
    {
        $this->service->create([
            'title' => '外部视频1',
            'slug' => 'external-1-'.uniqid(),
            'source' => VideoSource::Douyin->value,
            'video_url' => 'https://example.com/1.mp4',
            'source_video_id' => 'dup-id',
        ]);

        $this->expectException(ValidationException::class);

        $this->service->create([
            'title' => '外部视频2',
            'slug' => 'external-2-'.uniqid(),
            'source' => VideoSource::Douyin->value,
            'video_url' => 'https://example.com/2.mp4',
            'source_video_id' => 'dup-id',
        ]);
    }

    public function test_create_rejects_video_media_with_wrong_file_type(): void
    {
        $data = $this->localVideoData();
        $data['video_media_id'] = $this->makeMedia(MediaFileType::Document->value)->id;

        $this->expectException(ValidationException::class);

        $this->service->create($data);
    }

    public function test_create_rejects_unusable_cover_media(): void
    {
        $data = $this->localVideoData();
        $data['cover_media_id'] = $this->makeMedia(MediaFileType::Image->value, MediaStatus::Disabled->value)->id;

        $this->expectException(ValidationException::class);

        $this->service->create($data);
    }

    public function test_update_ignores_status_field(): void
    {
        $video = $this->service->create($this->localVideoData());

        $updated = $this->service->update($video, ['status' => ContentStatus::Published->value]);

        $this->assertSame(ContentStatus::Draft, $updated->status);
    }

    // ----------------------------------------------------------------
    // 状态流转与发布前置条件
    // ----------------------------------------------------------------

    public function test_change_status_rejects_illegal_transition(): void
    {
        $video = $this->service->create($this->localVideoData());

        $this->expectException(ValidationException::class);

        $this->service->changeStatus($video->id, ContentStatus::Published);
    }

    public function test_publish_succeeds_with_explicit_published_at(): void
    {
        $video = $this->service->create($this->localVideoData());
        $this->service->changeStatus($video->id, ContentStatus::PendingReview);

        $updated = $this->service->changeStatus($video->id, ContentStatus::Published, [
            'published_at' => Carbon::now(),
        ]);

        $this->assertSame(ContentStatus::Published, $updated->status);
        $this->assertNotNull($updated->published_at);
    }

    public function test_publish_fails_when_published_at_missing(): void
    {
        $video = $this->service->create($this->localVideoData());
        $this->service->changeStatus($video->id, ContentStatus::PendingReview);

        $this->expectException(ValidationException::class);

        $this->service->changeStatus($video->id, ContentStatus::Published);
    }

    public function test_publish_fails_when_cover_missing(): void
    {
        $data = $this->localVideoData();
        $data['cover_media_id'] = 0;
        $video = $this->service->create($data);
        $this->service->changeStatus($video->id, ContentStatus::PendingReview);

        $this->expectException(ValidationException::class);

        $this->service->changeStatus($video->id, ContentStatus::Published, ['published_at' => Carbon::now()]);
    }

    public function test_change_status_rejects_missing_video(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->changeStatus(999999, ContentStatus::PendingReview);
    }

    // ----------------------------------------------------------------
    // view_count / like_count
    // ----------------------------------------------------------------

    public function test_update_counts_rejects_negative_values(): void
    {
        $video = $this->service->create($this->localVideoData());

        $this->expectException(ValidationException::class);

        $this->service->updateCounts($video->id, -1, null);
    }

    public function test_update_counts_updates_only_provided_fields(): void
    {
        $video = $this->service->create($this->localVideoData());

        $updated = $this->service->updateCounts($video->id, 20, null);

        $this->assertSame(20, $updated->view_count);
        $this->assertSame(0, $updated->like_count);
    }

    // ----------------------------------------------------------------
    // 软删除
    // ----------------------------------------------------------------

    public function test_delete_soft_deletes_without_removing_media(): void
    {
        $data = $this->localVideoData();
        $video = $this->service->create($data);

        $this->service->delete($video->id);

        $this->assertSoftDeleted('videos', ['id' => $video->id]);
        $this->assertDatabaseHas('media_files', ['id' => $data['cover_media_id']]);
        $this->assertDatabaseHas('media_files', ['id' => $data['video_media_id']]);
    }
}
