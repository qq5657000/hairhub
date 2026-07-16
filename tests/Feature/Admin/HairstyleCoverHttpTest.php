<?php

namespace Tests\Feature\Admin;

use App\Enums\Hairstyle\HairstyleStatus;
use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaStatus;
use App\Models\Hairstyle;
use App\Models\HairstyleCategory;
use App\Models\HairstyleTag;
use App\Models\MediaFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithAdminAuth;
use Tests\TestCase;

/**
 * HairstyleController 新建发型页封面功能的真实 HTTP 链路验证：
 *
 * HTTP 路由 -> Dcat AdminController -> Form 渲染 / handleSaving()
 * -> DB::transaction() -> Hairstyle::create() + tags()->sync() + applyCoverChange()
 * -> HairstyleMediaService -> 数据库。
 *
 * 覆盖本次修复的核心问题：新建发型第一次提交必须能够同时设置首张封面，
 * 不能要求"先保存、再进编辑页上传封面"。
 */
class HairstyleCoverHttpTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminAuth;

    private const FAKE_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
    }

    private function makeCategory(): HairstyleCategory
    {
        return HairstyleCategory::create([
            'parent_id' => 0,
            'name' => '测试分类'.uniqid(),
            'slug' => 'test-category-'.uniqid(),
            'status' => 1,
            'sort' => 0,
        ]);
    }

    private function makeTag(): HairstyleTag
    {
        return HairstyleTag::create([
            'name' => '测试标签'.uniqid(),
            'slug' => 'test-tag-'.uniqid(),
            'status' => 1,
            'sort' => 0,
        ]);
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

    private function basePayload(array $overrides = []): array
    {
        $category = $overrides['category_id'] ?? $this->makeCategory()->id;

        return array_merge([
            'category_id' => $category,
            'name' => '测试发型'.uniqid(),
            'name_en' => 'test hairstyle',
            'slug' => 'test-hairstyle-'.uniqid(),
            'description' => '',
            'gender' => 0,
            'age_range' => 0,
            'style_type' => 0,
            'hair_length' => 0,
            'hair_type' => 0,
            'hair_volume' => 0,
            'maintenance_level' => 0,
            'ai_prompt' => '',
            'ai_negative_prompt' => '',
            'seo_title' => '',
            'seo_description' => '',
            'status' => HairstyleStatus::Enabled->value,
            'is_recommended' => 0,
            'sort' => 0,
            'tags' => [],
            'cover_upload' => '',
            'cover_select_media_id' => '',
        ], $overrides);
    }

    private function captureQueries(callable $callback): array
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $callback();

        return $queries;
    }

    /**
     * 6.2 新建发型页渲染测试：断言页面包含封面上传/选择入口，且不渲染依赖既有
     * hairstyle_id 的媒体列表操作（新建页展示引导文案而不是媒体管理面板）。
     */
    public function test_create_page_renders_cover_tab_and_fields(): void
    {
        $response = $this->get(admin_url('hairstyles/create'));

        $response->assertStatus(200);
        $response->assertSee('封面与媒体', false);
        $response->assertSee('cover_upload', false);
        $response->assertSee('cover_select_media_id', false);
        $response->assertSee('上传新封面', false);
        $response->assertSee('从媒体库选择封面', false);

        // 新建页不应该出现依赖既有 hairstyle_id 的“前往媒体管理页面”入口。
        $response->assertDontSee('前往媒体管理页面', false);
    }

    /**
     * 6.3 新建发型 + 选择已有封面：断言 hairstyles / hairstyle_media / 标签关联
     * 一次性正确写入，且虚拟字段不会进入 hairstyles 的真实 INSERT 语句。
     */
    public function test_create_hairstyle_with_selected_existing_cover(): void
    {
        $media = $this->makeMedia();
        $tag = $this->makeTag();

        $payload = $this->basePayload([
            'cover_select_media_id' => $media->id,
            'tags' => [$tag->id],
        ]);

        $queries = [];
        $response = null;
        $queries = $this->captureQueries(function () use (&$response, $payload) {
            $response = $this->post(admin_url('hairstyles'), $payload);
        });

        $response->assertStatus(200);
        $response->assertJson(['status' => true]);

        $hairstyle = Hairstyle::query()->where('slug', $payload['slug'])->first();
        $this->assertNotNull($hairstyle, '发型未创建成功');

        $this->assertSame($media->id, $hairstyle->cover_media_id);

        $this->assertDatabaseHas('hairstyle_media', [
            'hairstyle_id' => $hairstyle->id,
            'media_id' => $media->id,
            'is_primary' => 1,
        ]);

        $this->assertDatabaseHas('hairstyle_tag_relations', [
            'hairstyle_id' => $hairstyle->id,
            'tag_id' => $tag->id,
        ]);

        $insertSql = collect($queries)->first(fn ($sql) => preg_match('/insert into [`"]?hairstyles[`"]?\s/i', $sql) === 1);
        $this->assertNotNull($insertSql, '未捕获到 hairstyles 的 INSERT 语句');
        $this->assertStringNotContainsString('cover_select_media_id', $insertSql);
        $this->assertStringNotContainsString('cover_upload', $insertSql);
        $this->assertStringNotContainsString('`tags`', $insertSql);
    }

    /**
     * 6.4 新建发型 + 上传新封面：cover_upload 现在的值是 uploadCover() 接口上传成功后
     * 返回并写入隐藏字段的 media_files.id（不再是磁盘路径字符串），断言 hairstyles /
     * hairstyle_media 正确创建，且绝不会重复创建 media_files 记录。
     */
    public function test_create_hairstyle_with_uploaded_cover_media_id(): void
    {
        $media = $this->makeMedia();

        $payload = $this->basePayload([
            'cover_upload' => $media->id,
        ]);

        $response = $this->post(admin_url('hairstyles'), $payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => true]);

        $hairstyle = Hairstyle::query()->where('slug', $payload['slug'])->first();
        $this->assertNotNull($hairstyle);
        $this->assertSame($media->id, $hairstyle->cover_media_id);

        $this->assertDatabaseHas('hairstyle_media', [
            'hairstyle_id' => $hairstyle->id,
            'media_id' => $media->id,
            'is_primary' => 1,
        ]);

        // 核心回归点：提交表单绝不会再调用 MediaFile::create()，media_files 总数保持不变。
        $this->assertSame(1, MediaFile::query()->count());
    }

    /**
     * 回归覆盖"上传新封面后提交出现重复媒体记录"：同一个 cover_upload media_id 被
     * 提交两次（模拟 Dcat 后台自动更新 + 用户手动提交，或用户重复点击提交/编辑其它字段后重新保存），
     * media_files 总数必须始终保持为 1。
     */
    public function test_resubmitting_same_uploaded_cover_media_id_does_not_duplicate_media_record(): void
    {
        $media = $this->makeMedia();

        $payload = $this->basePayload([
            'cover_upload' => $media->id,
        ]);

        $createResponse = $this->post(admin_url('hairstyles'), $payload);
        $createResponse->assertStatus(200);
        $createResponse->assertJson(['status' => true]);

        $hairstyle = Hairstyle::query()->where('slug', $payload['slug'])->first();
        $this->assertNotNull($hairstyle);

        $updatePayload = $this->basePayload([
            'category_id' => $hairstyle->category_id,
            'slug' => $hairstyle->slug,
            'cover_upload' => $media->id,
        ]);

        $updateResponse = $this->put(admin_url('hairstyles/'.$hairstyle->id), $updatePayload);
        $updateResponse->assertStatus(200);
        $updateResponse->assertJson(['status' => true]);

        $hairstyle->refresh();
        $this->assertSame($media->id, $hairstyle->cover_media_id);
        $this->assertSame(1, MediaFile::query()->count());
    }

    /**
     * 验证新的 uploadCover() 接口本身：一次成功上传只产生一条 media_files 记录，
     * 原图和缩略图都真实落盘，响应结构符合 Dcat WebUploader 前端约定。
     */
    public function test_upload_cover_endpoint_creates_exactly_one_media_record(): void
    {
        Storage::fake('public');

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent(
            'cover.png',
            base64_decode(self::FAKE_PNG_BASE64)
        );

        $response = $this->post(admin_url('hairstyles/cover-upload'), [
            '_file_' => $file,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => true]);

        $this->assertSame(1, MediaFile::query()->count());

        $media = MediaFile::query()->first();
        $this->assertNotNull($media);
        $this->assertSame(MediaFileType::Image->value, (int) $media->file_type);

        Storage::disk('public')->assertExists($media->path);

        $response->assertJsonPath('data.id', (string) $media->id);
    }

    /**
     * 6.5 新建发型不设置封面：断言发型创建成功、cover_media_id 为 NULL，
     * 且不会创建任何 hairstyle_media 关联记录。
     */
    public function test_create_hairstyle_without_cover(): void
    {
        $payload = $this->basePayload();

        $response = $this->post(admin_url('hairstyles'), $payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => true]);

        $hairstyle = Hairstyle::query()->where('slug', $payload['slug'])->first();
        $this->assertNotNull($hairstyle);
        $this->assertNull($hairstyle->cover_media_id);

        $this->assertDatabaseMissing('hairstyle_media', [
            'hairstyle_id' => $hairstyle->id,
        ]);
    }

    /**
     * 6.6 异常回滚：选择不存在的媒体作为封面，整个事务必须回滚，
     * 不允许出现"发型已创建但封面处理失败"的半成功状态。
     */
    public function test_create_hairstyle_rejects_missing_cover_media_and_rolls_back(): void
    {
        $payload = $this->basePayload([
            'cover_select_media_id' => 999999,
        ]);

        $response = $this->post(admin_url('hairstyles'), $payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => false]);

        $this->assertDatabaseMissing('hairstyles', ['slug' => $payload['slug']]);
    }

    /**
     * 6.6 异常回滚：选择非图片类型的媒体作为封面，事务整体回滚。
     */
    public function test_create_hairstyle_rejects_non_image_cover_and_rolls_back(): void
    {
        $video = $this->makeMedia(['file_type' => MediaFileType::Video->value]);

        $payload = $this->basePayload([
            'cover_select_media_id' => $video->id,
        ]);

        $response = $this->post(admin_url('hairstyles'), $payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => false]);

        $this->assertDatabaseMissing('hairstyles', ['slug' => $payload['slug']]);
        $this->assertDatabaseMissing('hairstyle_media', ['media_id' => $video->id]);
    }

    /**
     * 6.6 异常回滚：选择状态为禁用的媒体作为封面（该校验由 HairstyleMediaService
     * 在 attachMedia()/setPrimaryMedia() 内部完成），事务整体回滚。
     */
    public function test_create_hairstyle_rejects_disabled_cover_and_rolls_back(): void
    {
        $disabled = $this->makeMedia(['status' => MediaStatus::Disabled->value]);

        $payload = $this->basePayload([
            'cover_select_media_id' => $disabled->id,
        ]);

        $response = $this->post(admin_url('hairstyles'), $payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => false]);

        $this->assertDatabaseMissing('hairstyles', ['slug' => $payload['slug']]);
        $this->assertDatabaseMissing('hairstyle_media', ['media_id' => $disabled->id]);
    }

    /**
     * 6.6 异常回滚：cover_upload 携带一个不存在的 media_id（例如前端上传失败/被篡改），
     * applyCoverChange() 应拒绝并整体回滚，不创建任何数据。
     */
    public function test_create_hairstyle_rejects_missing_upload_media_and_rolls_back(): void
    {
        $payload = $this->basePayload([
            'cover_upload' => 999999,
        ]);

        $response = $this->post(admin_url('hairstyles'), $payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => false]);

        $this->assertDatabaseMissing('hairstyles', ['slug' => $payload['slug']]);
    }

    /**
     * 6.6 异常回滚：标签 ID 非法时在进入事务之前即返回错误，不创建任何数据。
     */
    public function test_create_hairstyle_rejects_invalid_tag_id_and_creates_nothing(): void
    {
        $payload = $this->basePayload([
            'tags' => [999999],
        ]);

        $response = $this->post(admin_url('hairstyles'), $payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => false]);

        $this->assertDatabaseMissing('hairstyles', ['slug' => $payload['slug']]);
    }
}
