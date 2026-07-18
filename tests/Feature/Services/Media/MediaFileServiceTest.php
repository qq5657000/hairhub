<?php

namespace Tests\Feature\Services\Media;

use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaSourceType;
use App\Enums\Media\MediaStatus;
use App\Enums\Media\MediaVisibility;
use App\Models\HairColor;
use App\Models\HairColorCategory;
use App\Models\Hairstyle;
use App\Models\HairstyleCategory;
use App\Models\HairstyleMedia;
use App\Models\MediaFile;
use App\Services\Media\MediaFileService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * MediaFileService 基础用例测试。
 *
 * 覆盖：正常入库并生成衍生数据、非法扩展名/MIME 拒绝、空文件拒绝、文件不存在拒绝、
 * 文件类型识别、URL 动态生成、删除前的业务引用校验（hairstyle_media / 发型封面 / 分类封面）、
 * 未被引用时正常删除数据库记录和物理文件。
 */
class MediaFileServiceTest extends TestCase
{
    use RefreshDatabase;

    private MediaFileService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->service = new MediaFileService();
    }

    /**
     * 在 fake public 磁盘上写入一张真实可解析的小 JPEG 图片，返回相对路径。
     */
    private function putFakeImage(string $path, int $width = 20, int $height = 10): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 120, 120, 120));

        ob_start();
        imagejpeg($image);
        $content = ob_get_clean();
        imagedestroy($image);

        Storage::disk('public')->put($path, $content);

        return $path;
    }

    private function makeHairstyle(): Hairstyle
    {
        return Hairstyle::create([
            'name' => 'test_hairstyle',
            'slug' => 'test-hairstyle-'.uniqid(),
        ]);
    }

    private function makeCategory(): HairstyleCategory
    {
        return HairstyleCategory::create([
            'name' => 'test_category',
            'slug' => 'test-category-'.uniqid(),
        ]);
    }

    private function makeHairColorCategory(): HairColorCategory
    {
        return HairColorCategory::create([
            'parent_id' => 0,
            'name' => 'test_hair_color_category',
            'slug' => 'test-hair-color-category-'.uniqid(),
            'status' => 1,
        ]);
    }

    public function test_store_uploaded_file_creates_media_with_expected_fields(): void
    {
        $path = $this->putFakeImage('media/2026/07/15/'.uniqid().'.jpg', 40, 20);

        $media = $this->service->storeUploadedFile('public', $path, [
            'original_name' => '我的图片.jpg',
            'source_type' => MediaSourceType::AdminUpload->value,
            'visibility' => MediaVisibility::Public->value,
            'status' => MediaStatus::Active->value,
        ]);

        $this->assertInstanceOf(MediaFile::class, $media);
        $this->assertMatchesRegularExpression('/^MED\d{8}[A-Z0-9]{6}$/', $media->file_no);
        $this->assertSame(MediaFileType::Image->value, (int) $media->file_type);
        $this->assertSame('public', $media->storage);
        $this->assertSame($path, $media->path);
        $this->assertSame('我的图片.jpg', $media->original_name);
        $this->assertSame(40, (int) $media->width);
        $this->assertSame(20, (int) $media->height);
        $this->assertSame(0, (int) $media->duration);
        $this->assertNotEmpty($media->hash);
        $this->assertNotEmpty($media->thumbnail_path);
        $this->assertTrue(Storage::disk('public')->exists($media->thumbnail_path));
        $this->assertSame(MediaSourceType::AdminUpload->value, (int) $media->source_type);
        $this->assertSame(MediaVisibility::Public->value, (int) $media->visibility);
        $this->assertSame(MediaStatus::Active->value, (int) $media->status);

        $this->assertDatabaseHas('media_files', ['id' => $media->id, 'file_no' => $media->file_no]);
    }

    public function test_store_uploaded_file_rejects_disallowed_extension_and_cleans_up_file(): void
    {
        $path = 'media/2026/07/15/'.uniqid().'.php';
        Storage::disk('public')->put($path, '<?php echo 1; ?>');

        try {
            $this->service->storeUploadedFile('public', $path, []);
            $this->fail('应当抛出 ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('upload_file', $e->errors());
        }

        $this->assertFalse(Storage::disk('public')->exists($path));
        $this->assertDatabaseCount('media_files', 0);
    }

    public function test_store_uploaded_file_rejects_empty_file(): void
    {
        $path = 'media/2026/07/15/'.uniqid().'.jpg';
        Storage::disk('public')->put($path, '');

        $this->expectException(ValidationException::class);

        $this->service->storeUploadedFile('public', $path, []);
    }

    public function test_store_uploaded_file_rejects_missing_path(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->storeUploadedFile('public', 'media/not-exists/'.uniqid().'.jpg', []);
    }

    public function test_detect_file_type_by_mime_and_extension(): void
    {
        $this->assertSame(MediaFileType::Image, $this->service->detectFileType('image/png', 'png'));
        $this->assertSame(MediaFileType::Video, $this->service->detectFileType('video/mp4', 'mp4'));
        $this->assertSame(MediaFileType::Document, $this->service->detectFileType('application/pdf', 'pdf'));
        $this->assertSame(MediaFileType::Unknown, $this->service->detectFileType('application/x-unknown', 'xyz'));
    }

    public function test_build_url_matches_storage_disk_url(): void
    {
        $path = 'media/2026/07/15/'.uniqid().'.jpg';
        Storage::disk('public')->put($path, 'fake-content');

        $this->assertSame(Storage::disk('public')->url($path), $this->service->buildUrl('public', $path));
    }

    public function test_delete_media_removes_record_and_physical_files_when_not_referenced(): void
    {
        $path = $this->putFakeImage('media/2026/07/15/'.uniqid().'.jpg');
        $media = $this->service->storeUploadedFile('public', $path, []);
        $thumbnailPath = $media->thumbnail_path;

        $this->service->deleteMedia($media->id);

        $this->assertDatabaseMissing('media_files', ['id' => $media->id]);
        $this->assertFalse(Storage::disk('public')->exists($path));
        $this->assertFalse(Storage::disk('public')->exists($thumbnailPath));
    }

    public function test_delete_media_rejects_when_referenced_by_hairstyle_media(): void
    {
        $path = $this->putFakeImage('media/2026/07/15/'.uniqid().'.jpg');
        $media = $this->service->storeUploadedFile('public', $path, []);
        $hairstyle = $this->makeHairstyle();

        HairstyleMedia::create([
            'hairstyle_id' => $hairstyle->id,
            'media_id' => $media->id,
        ]);

        $this->expectException(ValidationException::class);

        $this->service->deleteMedia($media->id);
    }

    public function test_delete_media_rejects_when_referenced_by_hairstyle_cover(): void
    {
        $path = $this->putFakeImage('media/2026/07/15/'.uniqid().'.jpg');
        $media = $this->service->storeUploadedFile('public', $path, []);
        $hairstyle = $this->makeHairstyle();
        $hairstyle->cover_media_id = $media->id;
        $hairstyle->save();

        $this->expectException(ValidationException::class);

        $this->service->deleteMedia($media->id);
    }

    public function test_delete_media_rejects_when_referenced_by_category_cover(): void
    {
        $path = $this->putFakeImage('media/2026/07/15/'.uniqid().'.jpg');
        $media = $this->service->storeUploadedFile('public', $path, []);
        $category = $this->makeCategory();
        $category->cover_media_id = $media->id;
        $category->save();

        $this->expectException(ValidationException::class);

        $this->service->deleteMedia($media->id);
    }

    /**
     * 发色模块（Phase 2 新增）：被 hair_colors.cover_media_id 引用的媒体不能删除。
     */
    public function test_delete_media_rejects_when_referenced_by_hair_color_cover(): void
    {
        $path = $this->putFakeImage('media/2026/07/15/'.uniqid().'.jpg');
        $media = $this->service->storeUploadedFile('public', $path, []);
        $category = $this->makeHairColorCategory();

        HairColor::create([
            'category_id' => $category->id,
            'name' => 'test_hair_color',
            'slug' => 'test-hair-color-'.uniqid(),
            'color_hex' => '#123456',
            'status' => 1,
            'cover_media_id' => $media->id,
        ]);

        $this->expectException(ValidationException::class);

        $this->service->deleteMedia($media->id);
    }

    /**
     * 发色模块（Phase 2 新增）：被 hair_color_categories.cover_media_id 引用的媒体不能删除。
     */
    public function test_delete_media_rejects_when_referenced_by_hair_color_category_cover(): void
    {
        $path = $this->putFakeImage('media/2026/07/15/'.uniqid().'.jpg');
        $media = $this->service->storeUploadedFile('public', $path, []);
        $category = $this->makeHairColorCategory();
        $category->cover_media_id = $media->id;
        $category->save();

        $this->expectException(ValidationException::class);

        $this->service->deleteMedia($media->id);
    }

    public function test_assert_media_deletable_throws_model_not_found_for_missing_media(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->assertMediaDeletable(999999);
    }

    public function test_delete_media_throws_model_not_found_for_missing_media(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->deleteMedia(999999);
    }
}
