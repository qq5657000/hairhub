<?php

namespace Tests\Feature\Services\Content;

use App\Enums\Common\CommonStatus;
use App\Enums\Content\ContentStatus;
use App\Enums\Hairstyle\HairstyleStatus;
use App\Enums\Media\MediaStatus;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleTag;
use App\Models\HairColor;
use App\Models\Hairstyle;
use App\Models\MediaFile;
use App\Models\WechatArticle;
use App\Services\Content\ArticleService;
use App\Services\Content\ArticleTagService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ArticleService 业务规则验证：
 * - 创建草稿、状态流转（draft/pending_review/published/offline 全流程与非法流转拒绝）；
 * - 发布前置条件（标题/正文/封面/分类/发布时间/发型发色关联合法性）；
 * - published_at 为未来时间时前台不可见；
 * - 标签/发型/发色关联同步；
 * - view_count/like_count 不允许写入负数；
 * - 软删除不影响媒体、标签、关联和公众号同步记录；
 * - 公众号同步预留记录的创建与幂等。
 */
class ArticleServiceTest extends TestCase
{
    use RefreshDatabase;

    private ArticleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ArticleService(new ArticleTagService());
    }

    private function makeCategory(array $overrides = []): ArticleCategory
    {
        return ArticleCategory::create(array_merge([
            'name' => 'test_category',
            'slug' => 'test-category-'.uniqid(),
        ], $overrides));
    }

    private function makeMedia(array $overrides = []): MediaFile
    {
        return MediaFile::create(array_merge([
            'file_no' => 'TEST'.uniqid(),
            'path' => 'test/'.uniqid().'.jpg',
            'status' => MediaStatus::Active->value,
        ], $overrides));
    }

    private function makeHairstyle(array $overrides = []): Hairstyle
    {
        return Hairstyle::create(array_merge([
            'name' => 'test_hairstyle',
            'slug' => 'test-hairstyle-'.uniqid(),
            'status' => HairstyleStatus::Enabled->value,
        ], $overrides));
    }

    private function makeHairColor(array $overrides = []): HairColor
    {
        return HairColor::create(array_merge([
            'name' => 'test_hair_color',
            'slug' => 'test-hair-color-'.uniqid(),
            'status' => CommonStatus::Enabled->value,
        ], $overrides));
    }

    private function makeTag(array $overrides = []): ArticleTag
    {
        return ArticleTag::create(array_merge([
            'name' => 'test_tag',
            'slug' => 'test-tag-'.uniqid(),
        ], $overrides));
    }

    /**
     * 构造一份满足发布全部前置条件的基础数据（分类/标题/正文/封面/发布时间）。
     *
     * @return array<string, mixed>
     */
    private function publishableData(?ArticleCategory $category = null, ?MediaFile $cover = null): array
    {
        $category ??= $this->makeCategory();
        $cover ??= $this->makeMedia();

        return [
            'category_id' => $category->id,
            'title' => '可发布的文章标题',
            'slug' => 'publishable-'.uniqid(),
            'content' => '文章正文内容',
            'cover_media_id' => $cover->id,
        ];
    }

    // ----------------------------------------------------------------
    // create() / update()
    // ----------------------------------------------------------------

    public function test_create_defaults_status_to_draft_and_ignores_external_status(): void
    {
        $article = $this->service->create([
            'title' => '草稿文章',
            'slug' => 'draft-'.uniqid(),
            'status' => ContentStatus::Published->value, // 应被忽略
        ]);

        $this->assertSame(ContentStatus::Draft, $article->status);
    }

    public function test_create_ensures_wechat_article_record(): void
    {
        $article = $this->service->create([
            'title' => '草稿文章',
            'slug' => 'draft-'.uniqid(),
        ]);

        $this->assertDatabaseHas('wechat_articles', ['article_id' => $article->id]);
    }

    public function test_create_rejects_empty_title(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create(['title' => '', 'slug' => 'no-title-'.uniqid()]);
    }

    public function test_create_rejects_duplicate_slug(): void
    {
        $this->service->create(['title' => 'A', 'slug' => 'dup-slug']);

        $this->expectException(ValidationException::class);

        $this->service->create(['title' => 'B', 'slug' => 'dup-slug']);
    }

    public function test_create_rejects_missing_category(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create(['title' => 'A', 'slug' => 'x-'.uniqid(), 'category_id' => 999999]);
    }

    public function test_create_rejects_unusable_cover_media(): void
    {
        $media = $this->makeMedia(['status' => MediaStatus::Disabled->value]);

        $this->expectException(ValidationException::class);

        $this->service->create(['title' => 'A', 'slug' => 'x-'.uniqid(), 'cover_media_id' => $media->id]);
    }

    public function test_update_ignores_status_field(): void
    {
        $article = $this->service->create(['title' => 'A', 'slug' => 'x-'.uniqid()]);

        $updated = $this->service->update($article, ['status' => ContentStatus::Published->value]);

        $this->assertSame(ContentStatus::Draft, $updated->status);
    }

    public function test_update_allows_partial_fields(): void
    {
        $article = $this->service->create(['title' => 'A', 'slug' => 'x-'.uniqid(), 'sort' => 3]);

        $updated = $this->service->update($article, ['title' => 'A2']);

        $this->assertSame('A2', $updated->title);
        $this->assertSame(3, $updated->sort);
    }

    // ----------------------------------------------------------------
    // 状态流转
    // ----------------------------------------------------------------

    public function test_change_status_allows_draft_to_pending_review(): void
    {
        $article = $this->service->create(['title' => 'A', 'slug' => 'x-'.uniqid()]);

        $updated = $this->service->changeStatus($article->id, ContentStatus::PendingReview);

        $this->assertSame(ContentStatus::PendingReview, $updated->status);
    }

    public function test_change_status_rejects_illegal_transition_draft_to_published(): void
    {
        $article = $this->service->create(['title' => 'A', 'slug' => 'x-'.uniqid()]);

        $this->expectException(ValidationException::class);

        $this->service->changeStatus($article->id, ContentStatus::Published);
    }

    public function test_change_status_pending_review_to_draft_records_review_remark(): void
    {
        $article = $this->service->create(['title' => 'A', 'slug' => 'x-'.uniqid()]);
        $this->service->changeStatus($article->id, ContentStatus::PendingReview);

        $updated = $this->service->changeStatus($article->id, ContentStatus::Draft, [
            'reviewed_by' => 7,
            'review_remark' => '标题需要优化',
        ]);

        $this->assertSame(ContentStatus::Draft, $updated->status);
        $this->assertSame(7, $updated->reviewed_by);
        $this->assertSame('标题需要优化', $updated->review_remark);
    }

    public function test_change_status_pending_review_to_published_succeeds_when_publishable(): void
    {
        $article = $this->service->create($this->publishableData());
        $this->service->changeStatus($article->id, ContentStatus::PendingReview);

        $updated = $this->service->changeStatus($article->id, ContentStatus::Published, [
            'reviewed_by' => 7,
            'published_at' => Carbon::now(),
        ]);

        $this->assertSame(ContentStatus::Published, $updated->status);
        $this->assertNotNull($updated->published_at);
        $this->assertSame(7, $updated->reviewed_by);
    }

    public function test_change_status_published_to_offline_and_back_to_published(): void
    {
        $article = $this->service->create($this->publishableData());
        $this->service->changeStatus($article->id, ContentStatus::PendingReview);
        $this->service->changeStatus($article->id, ContentStatus::Published, ['published_at' => Carbon::now()]);

        $offline = $this->service->changeStatus($article->id, ContentStatus::Offline);
        $this->assertSame(ContentStatus::Offline, $offline->status);
        $this->assertNotNull($offline->published_at, '下线不应清空 published_at');

        $republished = $this->service->changeStatus($article->id, ContentStatus::Published);
        $this->assertSame(ContentStatus::Published, $republished->status);
    }

    public function test_change_status_rejects_offline_to_draft(): void
    {
        $article = $this->service->create($this->publishableData());
        $this->service->changeStatus($article->id, ContentStatus::PendingReview);
        $this->service->changeStatus($article->id, ContentStatus::Published, ['published_at' => Carbon::now()]);
        $this->service->changeStatus($article->id, ContentStatus::Offline);

        $this->expectException(ValidationException::class);

        $this->service->changeStatus($article->id, ContentStatus::Draft);
    }

    public function test_change_status_rejects_missing_article(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->changeStatus(999999, ContentStatus::PendingReview);
    }

    // ----------------------------------------------------------------
    // 发布前置条件
    // ----------------------------------------------------------------

    public function test_publish_fails_when_content_is_empty(): void
    {
        $data = $this->publishableData();
        $data['content'] = '';
        $article = $this->service->create($data);
        $this->service->changeStatus($article->id, ContentStatus::PendingReview);

        $this->expectException(ValidationException::class);

        $this->service->changeStatus($article->id, ContentStatus::Published, ['published_at' => Carbon::now()]);
    }

    public function test_publish_fails_when_cover_media_missing(): void
    {
        $data = $this->publishableData();
        $data['cover_media_id'] = 0;
        $article = $this->service->create($data);
        $this->service->changeStatus($article->id, ContentStatus::PendingReview);

        $this->expectException(ValidationException::class);

        $this->service->changeStatus($article->id, ContentStatus::Published, ['published_at' => Carbon::now()]);
    }

    public function test_publish_fails_when_published_at_missing(): void
    {
        $article = $this->service->create($this->publishableData());
        $this->service->changeStatus($article->id, ContentStatus::PendingReview);

        $this->expectException(ValidationException::class);

        $this->service->changeStatus($article->id, ContentStatus::Published);
    }

    public function test_publish_fails_when_category_missing(): void
    {
        $data = $this->publishableData();
        $data['category_id'] = 0;
        $article = $this->service->create($data);
        $this->service->changeStatus($article->id, ContentStatus::PendingReview);

        $this->expectException(ValidationException::class);

        $this->service->changeStatus($article->id, ContentStatus::Published, ['published_at' => Carbon::now()]);
    }

    public function test_publish_fails_when_category_disabled(): void
    {
        $category = $this->makeCategory(['status' => CommonStatus::Disabled->value]);
        $article = $this->service->create($this->publishableData($category));
        $this->service->changeStatus($article->id, ContentStatus::PendingReview);

        $this->expectException(ValidationException::class);

        $this->service->changeStatus($article->id, ContentStatus::Published, ['published_at' => Carbon::now()]);
    }

    public function test_publish_fails_when_related_hairstyle_was_later_disabled(): void
    {
        $article = $this->service->create($this->publishableData());
        $hairstyle = $this->makeHairstyle();
        $this->service->syncHairstyles($article, [$hairstyle->id]);

        $hairstyle->update(['status' => HairstyleStatus::Disabled->value]);

        $this->service->changeStatus($article->id, ContentStatus::PendingReview);

        $this->expectException(ValidationException::class);

        $this->service->changeStatus($article->id, ContentStatus::Published, ['published_at' => Carbon::now()]);
    }

    public function test_publish_succeeds_uses_pre_scheduled_published_at_when_context_not_provided(): void
    {
        $data = $this->publishableData();
        $data['published_at'] = Carbon::now()->addDay();
        $article = $this->service->create($data);
        $this->service->changeStatus($article->id, ContentStatus::PendingReview);

        $updated = $this->service->changeStatus($article->id, ContentStatus::Published);

        $this->assertSame(ContentStatus::Published, $updated->status);
        $this->assertNotNull($updated->published_at);
    }

    public function test_future_published_at_is_not_visible_on_frontend(): void
    {
        $data = $this->publishableData();
        $article = $this->service->create($data);
        $this->service->changeStatus($article->id, ContentStatus::PendingReview);
        $future = Carbon::now()->addDay();
        $article = $this->service->changeStatus($article->id, ContentStatus::Published, ['published_at' => $future]);

        $this->assertFalse($article->isVisibleOnFrontend());
    }

    public function test_past_published_at_is_visible_on_frontend(): void
    {
        $data = $this->publishableData();
        $article = $this->service->create($data);
        $this->service->changeStatus($article->id, ContentStatus::PendingReview);
        $article = $this->service->changeStatus($article->id, ContentStatus::Published, ['published_at' => Carbon::now()->subMinute()]);

        $this->assertTrue($article->isVisibleOnFrontend());
    }

    // ----------------------------------------------------------------
    // 标签 / 发型 / 发色 关联同步
    // ----------------------------------------------------------------

    public function test_sync_tags_delegates_to_article_tag_service(): void
    {
        $article = $this->service->create(['title' => 'A', 'slug' => 'x-'.uniqid()]);
        $tag = $this->makeTag();

        $this->service->syncTags($article, [$tag->id]);

        $this->assertDatabaseHas('article_tag_relations', ['article_id' => $article->id, 'tag_id' => $tag->id]);
    }

    public function test_sync_hairstyles_rejects_disabled_hairstyle(): void
    {
        $article = $this->service->create(['title' => 'A', 'slug' => 'x-'.uniqid()]);
        $hairstyle = $this->makeHairstyle(['status' => HairstyleStatus::Disabled->value]);

        $this->expectException(ValidationException::class);

        $this->service->syncHairstyles($article, [$hairstyle->id]);
    }

    public function test_sync_hairstyles_rejects_deleted_hairstyle(): void
    {
        $article = $this->service->create(['title' => 'A', 'slug' => 'x-'.uniqid()]);
        $hairstyle = $this->makeHairstyle();
        $hairstyle->delete();

        $this->expectException(ValidationException::class);

        $this->service->syncHairstyles($article, [$hairstyle->id]);
    }

    public function test_sync_hairstyles_updates_relations_and_sort(): void
    {
        $article = $this->service->create(['title' => 'A', 'slug' => 'x-'.uniqid()]);
        $hairstyleA = $this->makeHairstyle();
        $hairstyleB = $this->makeHairstyle();

        $this->service->syncHairstyles($article, [$hairstyleA->id, $hairstyleB->id]);
        $this->service->syncHairstyles($article, [$hairstyleB->id]);

        $this->assertDatabaseMissing('article_hairstyle_relations', ['article_id' => $article->id, 'hairstyle_id' => $hairstyleA->id]);
        $this->assertDatabaseHas('article_hairstyle_relations', ['article_id' => $article->id, 'hairstyle_id' => $hairstyleB->id, 'sort' => 0]);
    }

    public function test_sync_hairstyles_does_not_delete_hairstyle_itself(): void
    {
        $article = $this->service->create(['title' => 'A', 'slug' => 'x-'.uniqid()]);
        $hairstyle = $this->makeHairstyle();

        $this->service->syncHairstyles($article, [$hairstyle->id]);
        $this->service->syncHairstyles($article, []);

        $this->assertDatabaseHas('hairstyles', ['id' => $hairstyle->id, 'deleted_at' => null]);
    }

    public function test_sync_hair_colors_rejects_disabled_hair_color(): void
    {
        $article = $this->service->create(['title' => 'A', 'slug' => 'x-'.uniqid()]);
        $hairColor = $this->makeHairColor(['status' => CommonStatus::Disabled->value]);

        $this->expectException(ValidationException::class);

        $this->service->syncHairColors($article, [$hairColor->id]);
    }

    public function test_sync_hair_colors_updates_relations(): void
    {
        $article = $this->service->create(['title' => 'A', 'slug' => 'x-'.uniqid()]);
        $hairColor = $this->makeHairColor();

        $this->service->syncHairColors($article, [$hairColor->id]);

        $this->assertDatabaseHas('article_hair_color_relations', ['article_id' => $article->id, 'hair_color_id' => $hairColor->id]);
    }

    // ----------------------------------------------------------------
    // view_count / like_count
    // ----------------------------------------------------------------

    public function test_update_counts_rejects_negative_view_count(): void
    {
        $article = $this->service->create(['title' => 'A', 'slug' => 'x-'.uniqid()]);

        $this->expectException(ValidationException::class);

        $this->service->updateCounts($article->id, -1, null);
    }

    public function test_update_counts_rejects_negative_like_count(): void
    {
        $article = $this->service->create(['title' => 'A', 'slug' => 'x-'.uniqid()]);

        $this->expectException(ValidationException::class);

        $this->service->updateCounts($article->id, null, -1);
    }

    public function test_update_counts_updates_only_provided_fields(): void
    {
        $article = $this->service->create(['title' => 'A', 'slug' => 'x-'.uniqid()]);

        $updated = $this->service->updateCounts($article->id, 10, null);
        $this->assertSame(10, $updated->view_count);
        $this->assertSame(0, $updated->like_count);

        $updated = $this->service->updateCounts($article->id, null, 5);
        $this->assertSame(10, $updated->view_count);
        $this->assertSame(5, $updated->like_count);
    }

    // ----------------------------------------------------------------
    // 软删除
    // ----------------------------------------------------------------

    public function test_delete_soft_deletes_article_without_touching_relations(): void
    {
        $article = $this->service->create(['title' => 'A', 'slug' => 'x-'.uniqid()]);
        $tag = $this->makeTag();
        $this->service->syncTags($article, [$tag->id]);

        $this->service->delete($article->id);

        $this->assertSoftDeleted('articles', ['id' => $article->id]);
        $this->assertDatabaseHas('article_tag_relations', ['article_id' => $article->id, 'tag_id' => $tag->id]);
        $this->assertDatabaseHas('wechat_articles', ['article_id' => $article->id]);
    }

    public function test_wechat_article_record_is_idempotent(): void
    {
        $article = $this->service->create(['title' => 'A', 'slug' => 'x-'.uniqid()]);

        $first = $this->service->ensureWechatArticleRecord($article);
        $second = $this->service->ensureWechatArticleRecord($article);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, WechatArticle::query()->where('article_id', $article->id)->count());
    }
}
