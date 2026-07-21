<?php

namespace Tests\Feature\Services\Content;

use App\Enums\Article\ArticleMediaType;
use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaStatus;
use App\Models\Article;
use App\Models\ArticleMedia;
use App\Models\MediaFile;
use App\Services\Content\ArticleMediaService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ArticleMediaService 业务规则验证：
 * - 正文图片/图集必须关联图片类型且状态可用的媒体；
 * - 同一文章下相同 media_id + media_type 不允许重复关联；
 * - 解除关联不删除 media_files 原始记录；
 * - syncMedia 整体替换并按数组顺序重新赋值 sort。
 */
class ArticleMediaServiceTest extends TestCase
{
    use RefreshDatabase;

    private ArticleMediaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ArticleMediaService();
    }

    private function makeArticle(array $overrides = []): Article
    {
        return Article::create(array_merge([
            'title' => 'test_article',
            'slug' => 'test-article-'.uniqid(),
        ], $overrides));
    }

    private function makeMedia(string $suffix = '', ?int $status = null, ?int $fileType = null): MediaFile
    {
        return MediaFile::create([
            'file_no' => 'TEST'.uniqid().$suffix,
            'path' => 'test/'.uniqid().$suffix.'.jpg',
            'file_type' => $fileType ?? MediaFileType::Image->value,
            'status' => $status ?? MediaStatus::Active->value,
        ]);
    }

    public function test_attach_media_creates_relation(): void
    {
        $article = $this->makeArticle();
        $media = $this->makeMedia();

        $relation = $this->service->attachMedia($article->id, $media->id, ArticleMediaType::ContentImage, [
            'alt_text' => '正文配图',
        ]);

        $this->assertInstanceOf(ArticleMedia::class, $relation);
        $this->assertSame($article->id, $relation->article_id);
        $this->assertSame($media->id, $relation->media_id);
        $this->assertSame('正文配图', $relation->alt_text);

        $this->assertDatabaseHas('article_media', [
            'article_id' => $article->id,
            'media_id' => $media->id,
            'media_type' => ArticleMediaType::ContentImage->value,
        ]);
    }

    public function test_attach_media_rejects_duplicate_relation_with_same_type(): void
    {
        $article = $this->makeArticle();
        $media = $this->makeMedia();

        $this->service->attachMedia($article->id, $media->id, ArticleMediaType::ContentImage);

        $this->expectException(ValidationException::class);

        $this->service->attachMedia($article->id, $media->id, ArticleMediaType::ContentImage);
    }

    public function test_attach_media_allows_same_media_with_different_type(): void
    {
        $article = $this->makeArticle();
        $media = $this->makeMedia();

        $this->service->attachMedia($article->id, $media->id, ArticleMediaType::ContentImage);
        $relation = $this->service->attachMedia($article->id, $media->id, ArticleMediaType::Gallery);

        $this->assertSame(ArticleMediaType::Gallery, $relation->media_type);
    }

    public function test_attach_media_rejects_missing_article(): void
    {
        $media = $this->makeMedia();

        $this->expectException(ModelNotFoundException::class);

        $this->service->attachMedia(999999, $media->id, ArticleMediaType::ContentImage);
    }

    public function test_attach_media_rejects_unusable_media_status(): void
    {
        $article = $this->makeArticle();
        $media = $this->makeMedia('', MediaStatus::Disabled->value);

        $this->expectException(ValidationException::class);

        $this->service->attachMedia($article->id, $media->id, ArticleMediaType::ContentImage);
    }

    public function test_attach_media_rejects_non_image_for_content_image_type(): void
    {
        $article = $this->makeArticle();
        $media = $this->makeMedia('', null, MediaFileType::Document->value);

        $this->expectException(ValidationException::class);

        $this->service->attachMedia($article->id, $media->id, ArticleMediaType::ContentImage);
    }

    public function test_attach_media_allows_non_image_for_attachment_type(): void
    {
        $article = $this->makeArticle();
        $media = $this->makeMedia('', null, MediaFileType::Document->value);

        $relation = $this->service->attachMedia($article->id, $media->id, ArticleMediaType::Attachment);

        $this->assertSame(ArticleMediaType::Attachment, $relation->media_type);
    }

    public function test_detach_media_removes_relation_but_keeps_media_file(): void
    {
        $article = $this->makeArticle();
        $media = $this->makeMedia();

        $this->service->attachMedia($article->id, $media->id, ArticleMediaType::ContentImage);
        $this->service->detachMedia($article->id, $media->id, ArticleMediaType::ContentImage);

        $this->assertDatabaseMissing('article_media', [
            'article_id' => $article->id,
            'media_id' => $media->id,
        ]);
        $this->assertDatabaseHas('media_files', ['id' => $media->id]);
    }

    public function test_detach_media_rejects_when_not_attached(): void
    {
        $article = $this->makeArticle();
        $media = $this->makeMedia();

        $this->expectException(ValidationException::class);

        $this->service->detachMedia($article->id, $media->id, ArticleMediaType::ContentImage);
    }

    public function test_sync_media_replaces_list_and_assigns_sort_by_order(): void
    {
        $article = $this->makeArticle();
        $mediaA = $this->makeMedia('a');
        $mediaB = $this->makeMedia('b');
        $mediaC = $this->makeMedia('c');

        $this->service->attachMedia($article->id, $mediaA->id, ArticleMediaType::ContentImage);

        $this->service->syncMedia($article->id, ArticleMediaType::ContentImage, [
            ['media_id' => $mediaB->id],
            ['media_id' => $mediaC->id],
        ]);

        $this->assertDatabaseMissing('article_media', ['article_id' => $article->id, 'media_id' => $mediaA->id]);

        $relationB = ArticleMedia::query()->where('article_id', $article->id)->where('media_id', $mediaB->id)->first();
        $relationC = ArticleMedia::query()->where('article_id', $article->id)->where('media_id', $mediaC->id)->first();

        $this->assertSame(0, $relationB->sort);
        $this->assertSame(1, $relationC->sort);
    }

    public function test_sync_media_rejects_duplicate_media_id_in_items(): void
    {
        $article = $this->makeArticle();
        $media = $this->makeMedia();

        $this->expectException(ValidationException::class);

        $this->service->syncMedia($article->id, ArticleMediaType::ContentImage, [
            ['media_id' => $media->id],
            ['media_id' => $media->id],
        ]);
    }

    public function test_sync_media_rejects_unusable_media_and_rolls_back_existing_relations(): void
    {
        $article = $this->makeArticle();
        $mediaA = $this->makeMedia('a');
        $mediaBad = $this->makeMedia('bad', MediaStatus::Disabled->value);

        $this->service->attachMedia($article->id, $mediaA->id, ArticleMediaType::ContentImage);

        try {
            $this->service->syncMedia($article->id, ArticleMediaType::ContentImage, [
                ['media_id' => $mediaBad->id],
            ]);
            $this->fail('应抛出 ValidationException');
        } catch (ValidationException $e) {
            // 校验失败必须整体回滚，已存在的 mediaA 关联不应被删除。
        }

        $this->assertDatabaseHas('article_media', ['article_id' => $article->id, 'media_id' => $mediaA->id]);
    }
}
