<?php

namespace Tests\Feature\Admin;

use App\Admin\Controllers\HairstyleController;
use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaStatus;
use App\Models\Hairstyle;
use App\Models\HairstyleMedia;
use App\Models\HairstyleTag;
use App\Models\HairstyleTagRelation;
use App\Models\MediaFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * HairstyleController 中“软删除保留关联 / 永久删除清理关联但保留 media_files /
 * 恢复”这几条本次任务新增的业务规则的直接单元验证。
 *
 * 不经过 Dcat 的 HTTP 路由和后台登录态（项目现有的 Category/Tag/MediaFile 控制器
 * 也未建立该类端到端测试），只针对 restore()/forceDelete() 这两个纯 Eloquent
 * 逻辑方法做验证，Grid/Form 的可用性通过手工在后台操作验证（见开发总结）。
 */
class HairstyleControllerTest extends TestCase
{
    use RefreshDatabase;

    private HairstyleController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = app(HairstyleController::class);
    }

    private function makeHairstyle(): Hairstyle
    {
        return Hairstyle::create([
            'name' => 'test_hairstyle',
            'slug' => 'test-hairstyle-'.uniqid(),
        ]);
    }

    private function makeTag(): HairstyleTag
    {
        return HairstyleTag::create([
            'name' => 'test_tag',
            'slug' => 'test-tag-'.uniqid(),
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

    public function test_soft_delete_keeps_tag_relations_and_hairstyle_media(): void
    {
        $hairstyle = $this->makeHairstyle();
        $tag = $this->makeTag();
        $media = $this->makeMedia();

        HairstyleTagRelation::create(['hairstyle_id' => $hairstyle->id, 'tag_id' => $tag->id]);
        HairstyleMedia::create(['hairstyle_id' => $hairstyle->id, 'media_id' => $media->id]);

        $hairstyle->delete();

        $this->assertSoftDeleted('hairstyles', ['id' => $hairstyle->id]);
        $this->assertDatabaseHas('hairstyle_tag_relations', ['hairstyle_id' => $hairstyle->id, 'tag_id' => $tag->id]);
        $this->assertDatabaseHas('hairstyle_media', ['hairstyle_id' => $hairstyle->id, 'media_id' => $media->id]);
        $this->assertDatabaseHas('media_files', ['id' => $media->id]);
    }

    public function test_restore_brings_back_soft_deleted_hairstyle(): void
    {
        $hairstyle = $this->makeHairstyle();
        $hairstyle->delete();

        $this->controller->restore($hairstyle->id);

        $this->assertDatabaseHas('hairstyles', ['id' => $hairstyle->id, 'deleted_at' => null]);
    }

    public function test_force_delete_cleans_up_tag_relations_and_hairstyle_media_but_keeps_media_files(): void
    {
        $hairstyle = $this->makeHairstyle();
        $tag = $this->makeTag();
        $media = $this->makeMedia();

        HairstyleTagRelation::create(['hairstyle_id' => $hairstyle->id, 'tag_id' => $tag->id]);
        HairstyleMedia::create(['hairstyle_id' => $hairstyle->id, 'media_id' => $media->id]);

        $hairstyle->delete();

        $this->controller->forceDelete($hairstyle->id);

        $this->assertDatabaseMissing('hairstyles', ['id' => $hairstyle->id]);
        $this->assertDatabaseMissing('hairstyle_tag_relations', ['hairstyle_id' => $hairstyle->id]);
        $this->assertDatabaseMissing('hairstyle_media', ['hairstyle_id' => $hairstyle->id]);

        // media_files 不应被级联删除：文件可能仍被其它发型或分类引用，永久删除发型不应影响它。
        $this->assertDatabaseHas('media_files', ['id' => $media->id]);
        $this->assertDatabaseHas('hairstyle_tags', ['id' => $tag->id]);
    }

    public function test_force_delete_rejects_when_hairstyle_is_not_trashed(): void
    {
        $hairstyle = $this->makeHairstyle();

        $this->controller->forceDelete($hairstyle->id);

        // 未软删除的发型不允许直接永久删除，记录应原样保留。
        $this->assertDatabaseHas('hairstyles', ['id' => $hairstyle->id, 'deleted_at' => null]);
    }

    /**
     * 封面场景：从媒体库选择一个尚未关联的媒体，applyCoverChange() 应自动补一次关联，
     * 再统一通过 setPrimaryMedia() 同步 is_primary 和 hairstyles.cover_media_id。
     */
    public function test_apply_cover_change_selects_existing_media_and_syncs_primary(): void
    {
        $hairstyle = $this->makeHairstyle();
        $media = $this->makeMedia();

        $this->controller->applyCoverChange($hairstyle, '', $media->id);

        $this->assertDatabaseHas('hairstyle_media', [
            'hairstyle_id' => $hairstyle->id,
            'media_id' => $media->id,
            'is_primary' => 1,
        ]);
        $this->assertSame($media->id, $hairstyle->refresh()->cover_media_id);
    }

    /**
     * 更换封面后，旧封面的 is_primary 必须归零，且 cover_media_id 与新封面保持一致。
     */
    public function test_apply_cover_change_switching_cover_unsets_old_primary(): void
    {
        $hairstyle = $this->makeHairstyle();
        $mediaA = $this->makeMedia();
        $mediaB = $this->makeMedia();

        $this->controller->applyCoverChange($hairstyle, '', $mediaA->id);
        $this->controller->applyCoverChange($hairstyle, '', $mediaB->id);

        $this->assertDatabaseHas('hairstyle_media', [
            'hairstyle_id' => $hairstyle->id,
            'media_id' => $mediaA->id,
            'is_primary' => 0,
        ]);
        $this->assertDatabaseHas('hairstyle_media', [
            'hairstyle_id' => $hairstyle->id,
            'media_id' => $mediaB->id,
            'is_primary' => 1,
        ]);
        $this->assertSame($mediaB->id, $hairstyle->refresh()->cover_media_id);
    }

    /**
     * 非图片类型的媒体不能作为封面（该校验在 Controller 层完成，不侵入 HairstyleMediaService 的通用能力）。
     */
    public function test_apply_cover_change_rejects_non_image_media(): void
    {
        $hairstyle = $this->makeHairstyle();
        $video = $this->makeMedia(['file_type' => MediaFileType::Video->value]);

        $this->expectException(ValidationException::class);

        $this->controller->applyCoverChange($hairstyle, '', $video->id);
    }

    /**
     * 状态不可用（禁用）的媒体不能作为封面，由 HairstyleMediaService 内部校验拒绝。
     */
    public function test_apply_cover_change_rejects_inactive_media(): void
    {
        $hairstyle = $this->makeHairstyle();
        $disabled = $this->makeMedia(['status' => MediaStatus::Disabled->value]);

        $this->expectException(ValidationException::class);

        $this->controller->applyCoverChange($hairstyle, '', $disabled->id);
    }

    /**
     * 既没有上传新文件也没有选择库内媒体时，不应产生任何封面变更（保留原值，第一版为空）。
     */
    public function test_apply_cover_change_does_nothing_without_upload_or_selection(): void
    {
        $hairstyle = $this->makeHairstyle();

        $this->controller->applyCoverChange($hairstyle, '', 0);

        $this->assertDatabaseMissing('hairstyle_media', ['hairstyle_id' => $hairstyle->id]);
        $this->assertNull($hairstyle->refresh()->cover_media_id);
    }
}
