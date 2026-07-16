<?php

namespace Tests\Feature\Admin;

use App\Enums\Hairstyle\HairstyleCategoryStatus;
use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaStatus;
use App\Models\HairstyleCategory;
use App\Models\MediaFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithAdminAuth;
use Tests\TestCase;

/**
 * HairstyleCategoryController 封面保存的真实 HTTP 链路验证：
 *
 * HTTP 路由 -> Dcat AdminController -> Dcat\Admin\Form::store()/update()
 * -> saving() 回调（applyCoverChange） -> Repository -> Eloquent -> 数据库。
 *
 * 覆盖本次修复的根因：
 * 1. cover_upload / cover_select_media_id / clear_cover 三个虚拟字段绝不会
 *    进入 INSERT/UPDATE 的真实 SQL（此前报错 "Unknown column 'cover_select_media_id'"）；
 * 2. cover_media_id 能够被 saving() 回调正确写入并持久化
 *    （此前因缺少绑定字段被 Dcat prepareInsert()/prepareUpdate() 静默剔除）；
 * 3. "上传新封面后提交出现重复媒体记录"：cover_upload 现在的提交值是 uploadCover()
 *    接口返回的 media_files.id（不再是磁盘路径），表单保存阶段只校验并关联该 media_id，
 *    绝不会再调用 MediaFileService::storeUploadedFile() 第二次，无论提交多少次都不会
 *    触发 uk_storage_path 唯一索引冲突，也不会产生"数据库有记录、物理文件已丢失"的孤儿媒体；
 * 4. uploadCover() 接口本身：一次成功上传只会产生一条 media_files 记录，原图和缩略图
 *    都真实落盘，且响应结构符合 Dcat WebUploader 前端约定（status/data.id/data.url）。
 */
class HairstyleCategoryCoverHttpTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminAuth;

    private const FAKE_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
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

    private function baseCategoryPayload(array $overrides = []): array
    {
        return array_merge([
            'parent_id' => 0,
            'name' => '测试分类'.uniqid(),
            'name_en' => 'test category',
            'slug' => 'test-category-'.uniqid(),
            'description' => '',
            'cover_upload' => '',
            'cover_select_media_id' => '',
            'clear_cover' => 0,
            'status' => HairstyleCategoryStatus::Enabled->value,
            'sort' => 0,
        ], $overrides);
    }

    /**
     * 捕获一次回调执行期间的全部 SQL 语句，用于断言虚拟字段不会出现在真实 SQL 中。
     */
    private function captureQueries(callable $callback): array
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $callback();

        return $queries;
    }

    public function test_create_category_without_cover_succeeds_and_cover_media_id_is_zero(): void
    {
        $payload = $this->baseCategoryPayload();

        $response = null;
        $queries = $this->captureQueries(function () use (&$response, $payload) {
            $response = $this->post(admin_url('hairstyle-categories'), $payload);
        });

        $response->assertStatus(200);
        $response->assertJson(['status' => true]);

        $this->assertDatabaseHas('hairstyle_categories', [
            'slug' => $payload['slug'],
            'cover_media_id' => 0,
        ]);

        $insertSql = collect($queries)->first(fn ($sql) => preg_match('/insert into [`"]?hairstyle_categories[`"]?\s/i', $sql) === 1);

        $this->assertNotNull($insertSql, '未捕获到 hairstyle_categories 的 INSERT 语句');
        $this->assertStringNotContainsString('cover_upload', $insertSql);
        $this->assertStringNotContainsString('cover_select_media_id', $insertSql);
        $this->assertStringNotContainsString('clear_cover', $insertSql);
    }

    public function test_create_category_selecting_existing_media_sets_cover_media_id(): void
    {
        $media = $this->makeMedia();

        $payload = $this->baseCategoryPayload([
            'cover_select_media_id' => $media->id,
        ]);

        $queries = [];
        $response = null;
        $queries = $this->captureQueries(function () use (&$response, $payload) {
            $response = $this->post(admin_url('hairstyle-categories'), $payload);
        });

        $response->assertStatus(200);
        $response->assertJson(['status' => true]);

        $this->assertDatabaseHas('hairstyle_categories', [
            'slug' => $payload['slug'],
            'cover_media_id' => $media->id,
        ]);

        $insertSql = collect($queries)->first(fn ($sql) => preg_match('/insert into [`"]?hairstyle_categories[`"]?\s/i', $sql) === 1);
        $this->assertNotNull($insertSql);
        $this->assertStringNotContainsString('cover_select_media_id', $insertSql);
    }

    /**
     * 模拟浏览器真实提交形态：cover_upload 现在的值是 uploadCover() 接口上传成功后
     * 返回并写入隐藏字段的 media_files.id（不再是磁盘路径字符串）。
     */
    public function test_create_category_with_uploaded_cover_media_id_links_existing_media(): void
    {
        $media = $this->makeMedia();

        $payload = $this->baseCategoryPayload([
            'cover_upload' => $media->id,
        ]);

        $queries = [];
        $response = null;
        $queries = $this->captureQueries(function () use (&$response, $payload) {
            $response = $this->post(admin_url('hairstyle-categories'), $payload);
        });

        $response->assertStatus(200);
        $response->assertJson(['status' => true]);

        $this->assertDatabaseHas('hairstyle_categories', [
            'slug' => $payload['slug'],
            'cover_media_id' => $media->id,
        ]);

        // 核心回归点：提交表单绝不会再调用 MediaFile::create()，media_files 总数保持不变。
        $this->assertSame(1, MediaFile::query()->count());

        $insertSql = collect($queries)->first(fn ($sql) => preg_match('/insert into [`"]?hairstyle_categories[`"]?\s/i', $sql) === 1);
        $this->assertNotNull($insertSql);
        $this->assertStringNotContainsString('cover_upload', $insertSql);
    }

    /**
     * 回归覆盖"上传新封面后提交出现重复媒体记录"：同一个 cover_upload media_id 被
     * 提交两次（模拟 Dcat 后台自动更新 + 用户手动提交，或用户重复点击提交），
     * media_files 总数必须始终保持为 1，且 cover_media_id 两次都能正确指向该媒体。
     */
    public function test_resubmitting_same_uploaded_cover_media_id_does_not_duplicate_media_record(): void
    {
        $media = $this->makeMedia();

        $category = HairstyleCategory::create([
            'parent_id' => 0,
            'name' => '待关联封面分类',
            'slug' => 'link-cover-category-'.uniqid(),
            'cover_media_id' => 0,
            'status' => HairstyleCategoryStatus::Enabled->value,
            'sort' => 0,
        ]);

        $payload = $this->baseCategoryPayload([
            'slug' => $category->slug,
            'cover_upload' => $media->id,
        ]);

        $firstResponse = $this->put(admin_url('hairstyle-categories/'.$category->id), $payload);
        $firstResponse->assertStatus(200);
        $firstResponse->assertJson(['status' => true]);

        $secondResponse = $this->put(admin_url('hairstyle-categories/'.$category->id), $payload);
        $secondResponse->assertStatus(200);
        $secondResponse->assertJson(['status' => true]);

        $this->assertDatabaseHas('hairstyle_categories', [
            'id' => $category->id,
            'cover_media_id' => $media->id,
        ]);

        $this->assertSame(1, MediaFile::query()->count());
    }

    /**
     * 验证新的 uploadCover() 接口本身：一次成功上传只产生一条 media_files 记录，
     * 原图和缩略图都真实落盘，响应结构符合 Dcat WebUploader 前端约定
     * （status=true，data.id 是 media_id，data.url 可用于预览）。
     */
    public function test_upload_cover_endpoint_creates_exactly_one_media_record(): void
    {
        Storage::fake('public');

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent(
            'cover.png',
            base64_decode(self::FAKE_PNG_BASE64)
        );

        $response = $this->post(admin_url('hairstyle-categories/cover-upload'), [
            '_file_' => $file,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => true]);

        $this->assertSame(1, MediaFile::query()->count());

        $media = MediaFile::query()->first();
        $this->assertNotNull($media);
        $this->assertSame(MediaFileType::Image->value, (int) $media->file_type);
        $this->assertSame(MediaStatus::Active->value, (int) $media->status);

        Storage::disk('public')->assertExists($media->path);

        if ($media->thumbnail_path !== '') {
            Storage::disk('public')->assertExists($media->thumbnail_path);
        }

        $response->assertJsonPath('data.id', (string) $media->id);
    }

    /**
     * 上传非图片文件时，uploadCover() 必须拒绝并且不产生任何 media_files 记录
     * （避免脏数据进入媒体库，也避免依赖"先创建再删除"的补偿逻辑）。
     */
    public function test_upload_cover_endpoint_rejects_non_image_file(): void
    {
        Storage::fake('public');

        $file = \Illuminate\Http\UploadedFile::fake()->create('not-image.txt', 10, 'text/plain');

        $response = $this->post(admin_url('hairstyle-categories/cover-upload'), [
            '_file_' => $file,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => false]);

        $this->assertSame(0, MediaFile::query()->count());
    }

    public function test_update_category_without_touching_cover_keeps_original_cover(): void
    {
        $media = $this->makeMedia();

        $category = HairstyleCategory::create([
            'parent_id' => 0,
            'name' => '原分类',
            'slug' => 'origin-category-'.uniqid(),
            'cover_media_id' => $media->id,
            'status' => HairstyleCategoryStatus::Enabled->value,
            'sort' => 0,
        ]);

        $payload = $this->baseCategoryPayload([
            'name' => '原分类-已改名',
            'slug' => $category->slug,
        ]);

        $response = $this->put(admin_url('hairstyle-categories/'.$category->id), $payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => true]);

        $this->assertDatabaseHas('hairstyle_categories', [
            'id' => $category->id,
            'name' => '原分类-已改名',
            'cover_media_id' => $media->id,
        ]);
    }

    public function test_update_category_clear_cover_resets_to_zero(): void
    {
        $media = $this->makeMedia();

        $category = HairstyleCategory::create([
            'parent_id' => 0,
            'name' => '待清除封面分类',
            'slug' => 'clear-cover-category-'.uniqid(),
            'cover_media_id' => $media->id,
            'status' => HairstyleCategoryStatus::Enabled->value,
            'sort' => 0,
        ]);

        $payload = $this->baseCategoryPayload([
            'slug' => $category->slug,
            'clear_cover' => 1,
        ]);

        $response = $this->put(admin_url('hairstyle-categories/'.$category->id), $payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => true]);

        $this->assertDatabaseHas('hairstyle_categories', [
            'id' => $category->id,
            'cover_media_id' => 0,
        ]);
    }
}
