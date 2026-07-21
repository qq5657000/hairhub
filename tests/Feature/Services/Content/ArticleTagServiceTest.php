<?php

namespace Tests\Feature\Services\Content;

use App\Models\Article;
use App\Models\ArticleTag;
use App\Models\ArticleTagRelation;
use App\Services\Content\ArticleTagService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ArticleTagService 业务规则验证：
 * - slug 唯一；
 * - 标签仍被文章引用时不得删除（article_tags 不使用 SoftDeletes，物理删除）；
 * - syncTagsForArticle 去重、校验非法 tagId、增量同步（不重复写入、不误删未变化的关联）。
 */
class ArticleTagServiceTest extends TestCase
{
    use RefreshDatabase;

    private ArticleTagService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ArticleTagService();
    }

    private function makeTag(array $overrides = []): ArticleTag
    {
        return ArticleTag::create(array_merge([
            'name' => 'test_tag',
            'slug' => 'test-tag-'.uniqid(),
        ], $overrides));
    }

    private function makeArticle(array $overrides = []): Article
    {
        return Article::create(array_merge([
            'title' => 'test_article',
            'slug' => 'test-article-'.uniqid(),
        ], $overrides));
    }

    public function test_create_rejects_duplicate_slug(): void
    {
        $this->makeTag(['slug' => 'duplicate-tag']);

        $this->expectException(ValidationException::class);

        $this->service->create(['name' => '重复标签', 'slug' => 'duplicate-tag']);
    }

    public function test_create_rejects_invalid_status(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create(['name' => '非法状态', 'slug' => 'invalid-status-'.uniqid(), 'status' => 99]);
    }

    public function test_delete_rejects_when_tag_still_referenced_by_article(): void
    {
        $tag = $this->makeTag();
        $article = $this->makeArticle();

        ArticleTagRelation::create(['article_id' => $article->id, 'tag_id' => $tag->id]);

        $this->expectException(ValidationException::class);

        $this->service->delete($tag->id);
    }

    public function test_delete_physically_removes_tag_when_no_references(): void
    {
        $tag = $this->makeTag();

        $this->service->delete($tag->id);

        $this->assertDatabaseMissing('article_tags', ['id' => $tag->id]);
    }

    public function test_delete_rejects_missing_tag(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->delete(999999);
    }

    public function test_sync_tags_for_article_rejects_nonexistent_tag_id(): void
    {
        $article = $this->makeArticle();

        $this->expectException(ValidationException::class);

        $this->service->syncTagsForArticle($article, [999999]);
    }

    public function test_sync_tags_for_article_deduplicates_and_creates_relations(): void
    {
        $article = $this->makeArticle();
        $tagA = $this->makeTag();
        $tagB = $this->makeTag();

        $this->service->syncTagsForArticle($article, [$tagA->id, $tagB->id, $tagA->id]);

        $this->assertSame(2, ArticleTagRelation::query()->where('article_id', $article->id)->count());
        $this->assertDatabaseHas('article_tag_relations', ['article_id' => $article->id, 'tag_id' => $tagA->id]);
        $this->assertDatabaseHas('article_tag_relations', ['article_id' => $article->id, 'tag_id' => $tagB->id]);
    }

    public function test_sync_tags_for_article_removes_unselected_and_keeps_selected(): void
    {
        $article = $this->makeArticle();
        $tagA = $this->makeTag();
        $tagB = $this->makeTag();

        $this->service->syncTagsForArticle($article, [$tagA->id, $tagB->id]);

        $this->service->syncTagsForArticle($article, [$tagB->id]);

        $this->assertDatabaseMissing('article_tag_relations', ['article_id' => $article->id, 'tag_id' => $tagA->id]);
        $this->assertDatabaseHas('article_tag_relations', ['article_id' => $article->id, 'tag_id' => $tagB->id]);
    }

    public function test_sync_tags_for_article_does_not_reset_created_at_of_unchanged_relation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $article = $this->makeArticle();
        $tag = $this->makeTag();

        $this->service->syncTagsForArticle($article, [$tag->id]);

        $original = ArticleTagRelation::query()
            ->where('article_id', $article->id)
            ->where('tag_id', $tag->id)
            ->first();

        $originalCreatedAt = $original->created_at;

        // 模拟时间推进，验证重新同步不会重置未变化关联的 created_at。
        Carbon::setTestNow(Carbon::parse('2026-01-02 00:00:00'));

        $this->service->syncTagsForArticle($article, [$tag->id]);

        $afterResync = ArticleTagRelation::query()
            ->where('article_id', $article->id)
            ->where('tag_id', $tag->id)
            ->first();

        $this->assertTrue($originalCreatedAt->equalTo($afterResync->created_at));

        Carbon::setTestNow();
    }

    public function test_sync_tags_for_article_with_empty_array_detaches_all(): void
    {
        $article = $this->makeArticle();
        $tag = $this->makeTag();

        $this->service->syncTagsForArticle($article, [$tag->id]);
        $this->service->syncTagsForArticle($article, []);

        $this->assertSame(0, ArticleTagRelation::query()->where('article_id', $article->id)->count());
    }
}
