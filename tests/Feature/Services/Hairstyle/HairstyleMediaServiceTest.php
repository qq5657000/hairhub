<?php

namespace Tests\Feature\Services\Hairstyle;

use App\Enums\Media\MediaStatus;
use App\Models\Hairstyle;
use App\Models\HairstyleMedia;
use App\Models\MediaFile;
use App\Services\Hairstyle\HairstyleMediaService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * HairstyleMediaService 基础用例测试。
 *
 * 覆盖：正常关联、重复关联拒绝、首张主图设置、主图切换、
 * 同一发型仅一条 is_primary=1、cover_media_id 同步、
 * 删除主图后 cover_media_id 归零、不影响其他发型主图。
 */
class HairstyleMediaServiceTest extends TestCase
{
    use RefreshDatabase;

    private HairstyleMediaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new HairstyleMediaService();
    }

    private function makeHairstyle(string $suffix = ''): Hairstyle
    {
        return Hairstyle::create([
            'name' => 'test_hairstyle'.$suffix,
            'slug' => 'test-hairstyle'.$suffix.'-'.uniqid(),
        ]);
    }

    private function makeMedia(string $suffix = '', ?int $status = null): MediaFile
    {
        return MediaFile::create([
            'file_no' => 'TEST'.uniqid().$suffix,
            'path' => 'test/'.uniqid().$suffix.'.jpg',
            'status' => $status ?? MediaStatus::Active->value,
        ]);
    }

    public function test_attach_media_creates_relation(): void
    {
        $hairstyle = $this->makeHairstyle();
        $media = $this->makeMedia();

        $relation = $this->service->attachMedia($hairstyle->id, $media->id, [
            'title' => '测试图片',
        ]);

        $this->assertInstanceOf(HairstyleMedia::class, $relation);
        $this->assertSame($hairstyle->id, $relation->hairstyle_id);
        $this->assertSame($media->id, $relation->media_id);
        $this->assertSame('测试图片', $relation->title);
        $this->assertFalse($relation->is_primary);

        $this->assertDatabaseHas('hairstyle_media', [
            'hairstyle_id' => $hairstyle->id,
            'media_id' => $media->id,
        ]);
    }

    public function test_attach_media_rejects_duplicate_relation(): void
    {
        $hairstyle = $this->makeHairstyle();
        $media = $this->makeMedia();

        $this->service->attachMedia($hairstyle->id, $media->id);

        $this->expectException(ValidationException::class);

        $this->service->attachMedia($hairstyle->id, $media->id);
    }

    public function test_attach_media_rejects_missing_hairstyle(): void
    {
        $media = $this->makeMedia();

        $this->expectException(ModelNotFoundException::class);

        $this->service->attachMedia(999999, $media->id);
    }

    public function test_attach_media_rejects_unusable_media_status(): void
    {
        $hairstyle = $this->makeHairstyle();
        $media = $this->makeMedia('', MediaStatus::Disabled->value);

        $this->expectException(ValidationException::class);

        $this->service->attachMedia($hairstyle->id, $media->id);
    }

    public function test_set_primary_media_sets_first_primary_and_syncs_cover(): void
    {
        $hairstyle = $this->makeHairstyle();
        $media = $this->makeMedia();

        $this->service->attachMedia($hairstyle->id, $media->id);

        $relation = $this->service->setPrimaryMedia($hairstyle->id, $media->id);

        $this->assertTrue((bool) $relation->is_primary);
        $this->assertSame($media->id, (int) $hairstyle->refresh()->cover_media_id);
    }

    public function test_set_primary_media_switches_from_a_to_b_and_keeps_single_primary(): void
    {
        $hairstyle = $this->makeHairstyle();
        $mediaA = $this->makeMedia('a');
        $mediaB = $this->makeMedia('b');

        $this->service->attachMedia($hairstyle->id, $mediaA->id);
        $this->service->attachMedia($hairstyle->id, $mediaB->id);

        $this->service->setPrimaryMedia($hairstyle->id, $mediaA->id);
        $relationB = $this->service->setPrimaryMedia($hairstyle->id, $mediaB->id);

        $this->assertTrue((bool) $relationB->is_primary);

        $primaryCount = HairstyleMedia::query()
            ->where('hairstyle_id', $hairstyle->id)
            ->where('is_primary', true)
            ->count();

        $this->assertSame(1, $primaryCount);

        $relationA = HairstyleMedia::query()
            ->where('hairstyle_id', $hairstyle->id)
            ->where('media_id', $mediaA->id)
            ->first();
        $this->assertFalse((bool) $relationA->is_primary);

        $this->assertSame($mediaB->id, (int) $hairstyle->refresh()->cover_media_id);
    }

    public function test_set_primary_media_rejects_media_not_attached(): void
    {
        $hairstyle = $this->makeHairstyle();
        $media = $this->makeMedia();

        $this->expectException(ValidationException::class);

        $this->service->setPrimaryMedia($hairstyle->id, $media->id);
    }

    public function test_set_primary_media_rejects_unusable_media_status(): void
    {
        $hairstyle = $this->makeHairstyle();
        $media = $this->makeMedia();

        $this->service->attachMedia($hairstyle->id, $media->id);

        $media->update(['status' => MediaStatus::Disabled->value]);

        $this->expectException(ValidationException::class);

        $this->service->setPrimaryMedia($hairstyle->id, $media->id);
    }

    public function test_detach_media_removes_relation_and_resets_cover_when_primary(): void
    {
        $hairstyle = $this->makeHairstyle();
        $media = $this->makeMedia();

        $this->service->attachMedia($hairstyle->id, $media->id);
        $this->service->setPrimaryMedia($hairstyle->id, $media->id);

        $this->service->detachMedia($hairstyle->id, $media->id);

        $this->assertDatabaseMissing('hairstyle_media', [
            'hairstyle_id' => $hairstyle->id,
            'media_id' => $media->id,
        ]);
        $this->assertNull($hairstyle->refresh()->cover_media_id);
        $this->assertDatabaseHas('media_files', ['id' => $media->id]);
    }

    public function test_detach_media_does_not_touch_cover_when_not_primary(): void
    {
        $hairstyle = $this->makeHairstyle();
        $mediaA = $this->makeMedia('a');
        $mediaB = $this->makeMedia('b');

        $this->service->attachMedia($hairstyle->id, $mediaA->id);
        $this->service->attachMedia($hairstyle->id, $mediaB->id);
        $this->service->setPrimaryMedia($hairstyle->id, $mediaA->id);

        $this->service->detachMedia($hairstyle->id, $mediaB->id);

        $this->assertSame($mediaA->id, (int) $hairstyle->refresh()->cover_media_id);
    }

    public function test_set_primary_media_does_not_affect_other_hairstyle(): void
    {
        $hairstyleOne = $this->makeHairstyle('one');
        $hairstyleTwo = $this->makeHairstyle('two');
        $mediaOne = $this->makeMedia('one');
        $mediaTwo = $this->makeMedia('two');

        $this->service->attachMedia($hairstyleOne->id, $mediaOne->id);
        $this->service->attachMedia($hairstyleTwo->id, $mediaTwo->id);

        $this->service->setPrimaryMedia($hairstyleOne->id, $mediaOne->id);
        $this->service->setPrimaryMedia($hairstyleTwo->id, $mediaTwo->id);

        $this->assertSame($mediaOne->id, (int) $hairstyleOne->refresh()->cover_media_id);
        $this->assertSame($mediaTwo->id, (int) $hairstyleTwo->refresh()->cover_media_id);

        $relationOne = HairstyleMedia::query()
            ->where('hairstyle_id', $hairstyleOne->id)
            ->where('media_id', $mediaOne->id)
            ->first();
        $this->assertTrue((bool) $relationOne->is_primary);

        // 切换 hairstyleTwo 的主图不应影响 hairstyleOne。
        $mediaTwoB = $this->makeMedia('twoB');
        $this->service->attachMedia($hairstyleTwo->id, $mediaTwoB->id);
        $this->service->setPrimaryMedia($hairstyleTwo->id, $mediaTwoB->id);

        $this->assertTrue((bool) $relationOne->refresh()->is_primary);
        $this->assertSame($mediaOne->id, (int) $hairstyleOne->refresh()->cover_media_id);
    }

    public function test_update_relation_updates_plain_fields(): void
    {
        $hairstyle = $this->makeHairstyle();
        $media = $this->makeMedia();

        $relation = $this->service->attachMedia($hairstyle->id, $media->id);

        $updated = $this->service->updateRelation($relation->id, [
            'title' => '新标题',
            'sort' => 5,
            'hairstyle_id' => 999999, // 应被忽略
        ]);

        $this->assertSame('新标题', $updated->title);
        $this->assertSame(5, $updated->sort);
        $this->assertSame($hairstyle->id, $updated->hairstyle_id);
    }

    public function test_update_relation_with_is_primary_true_goes_through_set_primary(): void
    {
        $hairstyle = $this->makeHairstyle();
        $media = $this->makeMedia();

        $relation = $this->service->attachMedia($hairstyle->id, $media->id);

        $updated = $this->service->updateRelation($relation->id, ['is_primary' => true]);

        $this->assertTrue((bool) $updated->is_primary);
        $this->assertSame($media->id, (int) $hairstyle->refresh()->cover_media_id);
    }

    public function test_update_relation_rejects_direct_unset_of_current_primary(): void
    {
        $hairstyle = $this->makeHairstyle();
        $media = $this->makeMedia();

        $relation = $this->service->attachMedia($hairstyle->id, $media->id);
        $this->service->setPrimaryMedia($hairstyle->id, $media->id);

        $this->expectException(ValidationException::class);

        $this->service->updateRelation($relation->id, ['is_primary' => false]);
    }
}
