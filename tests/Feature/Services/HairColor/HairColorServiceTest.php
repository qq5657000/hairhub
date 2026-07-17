<?php

namespace Tests\Feature\Services\HairColor;

use App\Enums\Common\CommonStatus;
use App\Enums\Media\MediaStatus;
use App\Models\HairColorCategory;
use App\Models\MediaFile;
use App\Services\HairColor\HairColorService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * HairColorService 业务规则验证：
 * - 新增时 category_id 必须指向存在、未删除且状态启用的分类；
 * - 编辑时未修改 category_id 只要求原分类存在且未删除，不要求保持启用；
 *   修改了 category_id 则新分类需满足与新增相同的完整条件；
 * - 新增时 color_hex 必须非空，编辑时显式传入空字符串会被拒绝，未传入则保留旧值；
 * - cover_media_id 非 0 时必须指向存在且状态可用的媒体；
 * - 删除仅执行软删除，不影响封面媒体；
 * - 恢复发色前只要求分类存在且未删除，不要求分类保持启用；
 * - 支持只传部分字段的局部更新；
 * - AI Prompt 不参与前台公开字段输出。
 */
class HairColorServiceTest extends TestCase
{
    use RefreshDatabase;

    private HairColorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new HairColorService();
    }

    private function makeCategory(array $overrides = []): HairColorCategory
    {
        return HairColorCategory::create(array_merge([
            'name' => 'test_category',
            'slug' => 'test-category-'.uniqid(),
        ], $overrides));
    }

    private function makeMedia(array $overrides = []): MediaFile
    {
        return MediaFile::create(array_merge([
            'file_no' => 'TEST'.uniqid(),
            'path' => 'test/'.uniqid().'.jpg',
        ], $overrides));
    }

    /**
     * 构造一份满足 Service::create() 全部必填条件（category_id / slug / color_hex）
     * 的基础数据，测试用例只需覆盖自己关心的字段，避免大量重复样板代码。
     *
     * @return array<string, mixed>
     */
    private function baseData(int $categoryId, array $overrides = []): array
    {
        return array_merge([
            'category_id' => $categoryId,
            'name' => '自然黑',
            'slug' => 'natural-black-'.uniqid(),
            'color_hex' => '#000000',
        ], $overrides);
    }

    public function test_create_succeeds_with_valid_category(): void
    {
        $category = $this->makeCategory();

        $hairColor = $this->service->create($this->baseData($category->id));

        $this->assertSame($category->id, $hairColor->category_id);
    }

    public function test_create_rejects_zero_category_id(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create(['name' => '自然黑', 'slug' => 'natural-black-'.uniqid(), 'color_hex' => '#000000']);
    }

    public function test_create_rejects_missing_category(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create($this->baseData(999999));
    }

    public function test_create_rejects_disabled_category(): void
    {
        $category = $this->makeCategory(['status' => CommonStatus::Disabled->value]);

        $this->expectException(ValidationException::class);

        $this->service->create($this->baseData($category->id));
    }

    public function test_create_rejects_deleted_category(): void
    {
        $category = $this->makeCategory();
        $category->delete();

        $this->expectException(ValidationException::class);

        $this->service->create($this->baseData($category->id));
    }

    public function test_create_rejects_duplicate_slug(): void
    {
        $category = $this->makeCategory();

        $this->service->create($this->baseData($category->id, ['slug' => 'duplicate-hair-color']));

        $this->expectException(ValidationException::class);

        $this->service->create($this->baseData($category->id, ['name' => '深棕色', 'slug' => 'duplicate-hair-color']));
    }

    public function test_create_accepts_valid_cover_media(): void
    {
        $category = $this->makeCategory();
        $media = $this->makeMedia();

        $hairColor = $this->service->create($this->baseData($category->id, ['cover_media_id' => $media->id]));

        $this->assertSame($media->id, $hairColor->cover_media_id);
    }

    public function test_create_rejects_missing_cover_media(): void
    {
        $category = $this->makeCategory();

        $this->expectException(ValidationException::class);

        $this->service->create($this->baseData($category->id, ['cover_media_id' => 999999]));
    }

    public function test_create_rejects_disabled_cover_media(): void
    {
        $category = $this->makeCategory();
        $media = $this->makeMedia(['status' => MediaStatus::Disabled->value]);

        $this->expectException(ValidationException::class);

        $this->service->create($this->baseData($category->id, ['cover_media_id' => $media->id]));
    }

    /**
     * 新增发色时 color_hex 是必填业务字段：数据库列默认空字符串只是物理约束，
     * 不代表业务允许保存空值，因此完全不传该字段也必须被拒绝。
     */
    public function test_create_rejects_missing_color_hex(): void
    {
        $category = $this->makeCategory();
        $data = $this->baseData($category->id);
        unset($data['color_hex']);

        $this->expectException(ValidationException::class);

        $this->service->create($data);
    }

    public function test_create_rejects_empty_color_hex(): void
    {
        $category = $this->makeCategory();

        $this->expectException(ValidationException::class);

        $this->service->create($this->baseData($category->id, ['color_hex' => '']));
    }

    public function test_create_rejects_blank_color_hex(): void
    {
        $category = $this->makeCategory();

        $this->expectException(ValidationException::class);

        $this->service->create($this->baseData($category->id, ['color_hex' => '   ']));
    }

    public function test_create_normalizes_color_hex_to_uppercase(): void
    {
        $category = $this->makeCategory();

        $hairColor = $this->service->create($this->baseData($category->id, ['color_hex' => '#c8a27a']));

        $this->assertSame('#C8A27A', $hairColor->color_hex);
    }

    public function test_create_rejects_invalid_color_hex_format(): void
    {
        $category = $this->makeCategory();

        $this->expectException(ValidationException::class);

        $this->service->create($this->baseData($category->id, ['color_hex' => 'not-a-color']));
    }

    public function test_delete_soft_deletes_hair_color_and_keeps_cover_media(): void
    {
        $category = $this->makeCategory();
        $media = $this->makeMedia();

        $hairColor = $this->service->create($this->baseData($category->id, ['cover_media_id' => $media->id]));

        $this->service->delete($hairColor->id);

        $this->assertSoftDeleted('hair_colors', ['id' => $hairColor->id]);
        $this->assertDatabaseHas('media_files', ['id' => $media->id]);
    }

    public function test_delete_rejects_missing_hair_color(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->delete(999999);
    }

    /**
     * 局部更新：只传 name，不传 category_id / slug / color_hex / cover_media_id，
     * 不应因为访问不存在的数组键而报错，且未修改字段应保持模型当前值不变。
     */
    public function test_update_allows_partial_fields_without_touching_others(): void
    {
        $category = $this->makeCategory();
        $media = $this->makeMedia();

        $hairColor = $this->service->create($this->baseData($category->id, [
            'color_hex' => '#C8A27A',
            'cover_media_id' => $media->id,
        ]));
        $originalSlug = $hairColor->slug;
        $originalCategoryId = $hairColor->category_id;
        $originalColorHex = $hairColor->color_hex;
        $originalCoverMediaId = $hairColor->cover_media_id;

        $updated = $this->service->update($hairColor, ['name' => '新名称']);

        $this->assertSame('新名称', $updated->name);
        $this->assertSame($originalSlug, $updated->slug);
        $this->assertSame($originalCategoryId, $updated->category_id);
        $this->assertSame($originalColorHex, $updated->color_hex);
        $this->assertSame($originalCoverMediaId, $updated->cover_media_id);
    }

    public function test_update_without_color_hex_keeps_existing_value(): void
    {
        $category = $this->makeCategory();

        $hairColor = $this->service->create($this->baseData($category->id, ['color_hex' => '#111111']));

        $updated = $this->service->update($hairColor, ['name' => '新名称']);

        $this->assertSame('#111111', $updated->color_hex);
    }

    public function test_update_rejects_empty_color_hex(): void
    {
        $category = $this->makeCategory();

        $hairColor = $this->service->create($this->baseData($category->id));

        $this->expectException(ValidationException::class);

        $this->service->update($hairColor, ['color_hex' => '']);
    }

    public function test_update_normalizes_new_color_hex_to_uppercase(): void
    {
        $category = $this->makeCategory();

        $hairColor = $this->service->create($this->baseData($category->id));

        $updated = $this->service->update($hairColor, ['color_hex' => '#abcdef']);

        $this->assertSame('#ABCDEF', $updated->color_hex);
    }

    /**
     * 分类被禁用后（未删除），只要没有修改 category_id，仍应允许修改名称等其它字段。
     */
    public function test_update_allows_editing_other_fields_when_category_is_disabled(): void
    {
        $category = $this->makeCategory();

        $hairColor = $this->service->create($this->baseData($category->id));

        $category->update(['status' => CommonStatus::Disabled->value]);

        $updated = $this->service->update($hairColor, ['name' => '禁用分类下仍可改名']);

        $this->assertSame('禁用分类下仍可改名', $updated->name);
    }

    /**
     * 原分类一旦被软删除，即使本次更新没有修改 category_id，也必须拒绝更新。
     */
    public function test_update_rejects_when_original_category_has_been_deleted(): void
    {
        $category = $this->makeCategory();

        $hairColor = $this->service->create($this->baseData($category->id));

        $category->delete();

        $this->expectException(ValidationException::class);

        $this->service->update($hairColor, ['name' => '分类已删除']);
    }

    /**
     * 更换到一个已禁用的分类时必须拒绝：修改 category_id 需满足与新增相同的完整条件。
     */
    public function test_update_rejects_switching_to_disabled_category(): void
    {
        $category = $this->makeCategory();
        $disabledCategory = $this->makeCategory(['status' => CommonStatus::Disabled->value]);

        $hairColor = $this->service->create($this->baseData($category->id));

        $this->expectException(ValidationException::class);

        $this->service->update($hairColor, ['category_id' => $disabledCategory->id]);
    }

    /**
     * 更换到一个已被软删除的分类时必须拒绝。
     */
    public function test_update_rejects_switching_to_deleted_category(): void
    {
        $category = $this->makeCategory();
        $deletedCategory = $this->makeCategory();
        $deletedCategory->delete();

        $hairColor = $this->service->create($this->baseData($category->id));

        $this->expectException(ValidationException::class);

        $this->service->update($hairColor, ['category_id' => $deletedCategory->id]);
    }

    /**
     * 更换到一个存在、未删除且启用的新分类时应正常成功。
     */
    public function test_update_allows_switching_to_valid_new_category(): void
    {
        $category = $this->makeCategory();
        $newCategory = $this->makeCategory();

        $hairColor = $this->service->create($this->baseData($category->id));

        $updated = $this->service->update($hairColor, ['category_id' => $newCategory->id]);

        $this->assertSame($newCategory->id, $updated->category_id);
    }

    public function test_restore_succeeds_when_category_still_valid(): void
    {
        $category = $this->makeCategory();

        $hairColor = $this->service->create($this->baseData($category->id));

        $hairColor->delete();

        $restored = $this->service->restore($hairColor->id);

        $this->assertDatabaseHas('hair_colors', ['id' => $restored->id, 'deleted_at' => null]);
    }

    public function test_restore_rejects_when_category_has_been_deleted(): void
    {
        $category = $this->makeCategory();

        $hairColor = $this->service->create($this->baseData($category->id));

        $hairColor->delete();
        $category->delete();

        $this->expectException(ValidationException::class);

        $this->service->restore($hairColor->id);
    }

    /**
     * 分类被禁用（但未删除）不影响恢复：分类禁用只影响前台展示，
     * 不代表发色与分类的归属关系失效。
     */
    public function test_restore_succeeds_when_category_is_disabled(): void
    {
        $category = $this->makeCategory();

        $hairColor = $this->service->create($this->baseData($category->id));

        $hairColor->delete();
        $category->update(['status' => CommonStatus::Disabled->value]);

        $restored = $this->service->restore($hairColor->id);

        $this->assertDatabaseHas('hair_colors', ['id' => $restored->id, 'deleted_at' => null]);
    }

    public function test_restore_rejects_missing_or_not_trashed_hair_color(): void
    {
        $category = $this->makeCategory();

        $hairColor = $this->service->create($this->baseData($category->id));

        $this->expectException(ModelNotFoundException::class);

        $this->service->restore($hairColor->id);
    }

    public function test_to_public_array_excludes_ai_prompt_fields(): void
    {
        $category = $this->makeCategory();

        $hairColor = $this->service->create($this->baseData($category->id, [
            'ai_prompt' => 'a natural black hair color, photorealistic',
            'ai_negative_prompt' => 'cartoon, low quality',
        ]));

        $public = $this->service->toPublicArray($hairColor);

        $this->assertArrayNotHasKey('ai_prompt', $public);
        $this->assertArrayNotHasKey('ai_negative_prompt', $public);
        $this->assertSame('自然黑', $public['name']);
    }
}
