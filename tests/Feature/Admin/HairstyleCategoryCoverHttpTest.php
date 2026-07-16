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
 * 覆盖本次修复的两个根因：
 * 1. cover_upload / cover_select_media_id / clear_cover 三个虚拟字段绝不会
 *    进入 INSERT/UPDATE 的真实 SQL（此前报错 "Unknown column 'cover_select_media_id'"）；
 * 2. cover_media_id 能够被 saving() 回调正确写入并持久化
 *    （此前因缺少绑定字段被 Dcat prepareInsert()/prepareUpdate() 静默剔除）。
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
     * 模拟浏览器真实提交形态：cover_upload 是 Dcat WebUploader 异步上传后已经落盘的
     * disk 相对路径字符串（例如 media/2026/07/16/xxx.png），不是 UploadedFile 对象。
     */
    public function test_create_category_with_uploaded_cover_path_registers_media_file(): void
    {
        Storage::fake('public');

        $path = 'media/'.now()->format('Y/m/d').'/'.uniqid().'.png';
        Storage::disk('public')->put($path, base64_decode(self::FAKE_PNG_BASE64));

        $payload = $this->baseCategoryPayload([
            'cover_upload' => $path,
        ]);

        $queries = [];
        $response = null;
        $queries = $this->captureQueries(function () use (&$response, $payload) {
            $response = $this->post(admin_url('hairstyle-categories'), $payload);
        });

        $response->assertStatus(200);
        $response->assertJson(['status' => true]);

        $this->assertDatabaseHas('media_files', [
            'path' => $path,
            'file_type' => MediaFileType::Image->value,
        ]);

        $media = MediaFile::query()->where('path', $path)->first();
        $this->assertNotNull($media);

        $this->assertDatabaseHas('hairstyle_categories', [
            'slug' => $payload['slug'],
            'cover_media_id' => $media->id,
        ]);

        $insertSql = collect($queries)->first(fn ($sql) => preg_match('/insert into [`"]?hairstyle_categories[`"]?\s/i', $sql) === 1);
        $this->assertNotNull($insertSql);
        $this->assertStringNotContainsString('cover_upload', $insertSql);
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
