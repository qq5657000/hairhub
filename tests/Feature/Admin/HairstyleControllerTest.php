<?php

namespace Tests\Feature\Admin;

use App\Admin\Controllers\HairstyleController;
use App\Models\Hairstyle;
use App\Models\HairstyleMedia;
use App\Models\HairstyleTag;
use App\Models\HairstyleTagRelation;
use App\Models\MediaFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->controller = new HairstyleController();
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

    private function makeMedia(): MediaFile
    {
        return MediaFile::create([
            'file_no' => 'TEST'.uniqid(),
            'path' => 'test/'.uniqid().'.jpg',
        ]);
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
}
