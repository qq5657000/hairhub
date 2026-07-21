<?php

namespace App\Services\Content;

use App\Enums\Article\ArticleMediaType;
use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaStatus;
use App\Models\Article;
use App\Models\ArticleMedia;
use App\Models\MediaFile;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 文章媒体业务 Service。
 *
 * 参照 HairstyleMediaService 的事务与校验风格，负责维护 article_media
 * （文章正文图片 / 图集 / 附件关联关系）：
 * - 文章封面主逻辑固定使用 articles.cover_media_id /
 *   articles.wechat_cover_media_id，本 Service 不处理封面；
 * - 同一文章下相同 media_id + media_type 不允许重复关联（对应数据库
 *   uk_article_media_type 唯一索引，本 Service 在写入前先做业务层校验）；
 * - ContentImage / Gallery 类型要求关联的媒体必须是图片类型（MediaFileType::Image）；
 * - 关联/解除关联只操作 article_media 中间表，不删除 media_files 原始记录。
 */
class ArticleMediaService
{
    /**
     * article_media 允许通过本 Service 写入的业务字段（不包含 article_id / media_id / media_type）。
     */
    private const ALLOWED_ATTRIBUTES = [
        'alt_text',
        'caption',
        'sort',
    ];

    /**
     * 将媒体关联到文章。
     *
     * @param  array<string, mixed>  $attributes  仅 alt_text/caption/sort 会被写入
     *
     * @throws ModelNotFoundException 文章不存在 / 媒体文件不存在
     * @throws ValidationException 媒体状态不可用 / 媒体类型与用途不匹配 / 重复关联
     */
    public function attachMedia(int $articleId, int $mediaId, ArticleMediaType $type, array $attributes = []): ArticleMedia
    {
        return DB::transaction(function () use ($articleId, $mediaId, $type, $attributes) {
            $article = $this->lockArticle($articleId);
            $media = $this->findUsableMedia($mediaId, $type);

            $alreadyAttached = ArticleMedia::query()
                ->where('article_id', $article->id)
                ->where('media_id', $media->id)
                ->where('media_type', $type->value)
                ->exists();

            if ($alreadyAttached) {
                throw ValidationException::withMessages([
                    'media_id' => ['该媒体已以相同用途关联当前文章'],
                ]);
            }

            $data = $this->filterAttributes($attributes);

            $relation = new ArticleMedia(array_merge($data, [
                'article_id' => $article->id,
                'media_id' => $media->id,
                'media_type' => $type->value,
            ]));
            $relation->save();

            return $relation->refresh();
        });
    }

    /**
     * 更新文章媒体关联记录的业务字段（alt_text / caption / sort）。
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ModelNotFoundException 关联记录不存在
     */
    public function updateRelation(int $relationId, array $attributes): ArticleMedia
    {
        /** @var ArticleMedia|null $relation */
        $relation = ArticleMedia::query()->find($relationId);

        if (! $relation) {
            throw new ModelNotFoundException('文章媒体关联记录不存在');
        }

        $data = $this->filterAttributes($attributes);

        if ($data !== []) {
            $relation->fill($data);
            $relation->save();
        }

        return $relation;
    }

    /**
     * 解除文章与媒体的关联（只删除 article_media 关联记录，不删除 media_files 记录）。
     *
     * @throws ModelNotFoundException 文章不存在 / 该媒体尚未以指定用途关联当前文章
     */
    public function detachMedia(int $articleId, int $mediaId, ArticleMediaType $type): void
    {
        DB::transaction(function () use ($articleId, $mediaId, $type) {
            $article = $this->lockArticle($articleId);

            $relation = ArticleMedia::query()
                ->where('article_id', $article->id)
                ->where('media_id', $mediaId)
                ->where('media_type', $type->value)
                ->first();

            if (! $relation) {
                throw ValidationException::withMessages([
                    'media_id' => ['该媒体尚未以指定用途关联当前文章'],
                ]);
            }

            $relation->delete();
        });
    }

    /**
     * 整体同步指定用途下的媒体关联列表（先校验全部合法，再一次性替换，不允许部分成功）。
     *
     * @param  array<int, array{media_id: int, alt_text?: string, caption?: string}>  $items  按数组顺序即最终排序顺序
     * @return array<int, ArticleMedia>
     *
     * @throws ModelNotFoundException 文章不存在
     * @throws ValidationException 存在不可用的媒体 / 媒体类型不匹配 / 重复的 media_id
     */
    public function syncMedia(int $articleId, ArticleMediaType $type, array $items): array
    {
        return DB::transaction(function () use ($articleId, $type, $items) {
            $article = $this->lockArticle($articleId);

            $mediaIds = array_map(fn (array $item) => (int) $item['media_id'], $items);

            if (count($mediaIds) !== count(array_unique($mediaIds))) {
                throw ValidationException::withMessages([
                    'items' => ['同一用途下不允许重复关联相同媒体'],
                ]);
            }

            foreach ($mediaIds as $mediaId) {
                $this->findUsableMedia($mediaId, $type);
            }

            ArticleMedia::query()
                ->where('article_id', $article->id)
                ->where('media_type', $type->value)
                ->delete();

            $relations = [];

            foreach (array_values($items) as $index => $item) {
                $relation = new ArticleMedia([
                    'article_id' => $article->id,
                    'media_id' => (int) $item['media_id'],
                    'media_type' => $type->value,
                    'alt_text' => (string) ($item['alt_text'] ?? ''),
                    'caption' => (string) ($item['caption'] ?? ''),
                    'sort' => $index,
                ]);
                $relation->save();
                $relations[] = $relation;
            }

            return $relations;
        });
    }

    /**
     * 按传入顺序重新排序指定用途下的媒体关联（sort 从 0 开始按数组顺序赋值）。
     *
     * @param  array<int, int>  $orderedMediaIds
     *
     * @throws ModelNotFoundException 文章不存在
     * @throws ValidationException 存在不属于该文章 / 该用途的 media_id
     */
    public function reorderMedia(int $articleId, ArticleMediaType $type, array $orderedMediaIds): void
    {
        DB::transaction(function () use ($articleId, $type, $orderedMediaIds) {
            $article = $this->lockArticle($articleId);

            $relations = ArticleMedia::query()
                ->where('article_id', $article->id)
                ->where('media_type', $type->value)
                ->get()
                ->keyBy('media_id');

            $orderedMediaIds = array_map('intval', $orderedMediaIds);

            if (count($orderedMediaIds) !== $relations->count() || array_diff($orderedMediaIds, $relations->keys()->all()) !== []) {
                throw ValidationException::withMessages([
                    'media_ids' => ['排序列表与当前文章该用途下的媒体关联不一致'],
                ]);
            }

            foreach ($orderedMediaIds as $index => $mediaId) {
                /** @var ArticleMedia $relation */
                $relation = $relations->get($mediaId);

                if ((int) $relation->sort !== $index) {
                    $relation->sort = $index;
                    $relation->save();
                }
            }
        });
    }

    /**
     * 加锁读取目标文章，不存在则抛出异常。
     *
     * @throws ModelNotFoundException
     */
    private function lockArticle(int $articleId): Article
    {
        /** @var Article|null $article */
        $article = Article::query()->lockForUpdate()->find($articleId);

        if (! $article) {
            throw new ModelNotFoundException('文章不存在');
        }

        return $article;
    }

    /**
     * 读取媒体文件并校验状态是否可用（仅 MediaStatus::Active 视为可用），
     * 且当用途要求图片时（ContentImage / Gallery）媒体文件类型必须为 MediaFileType::Image。
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    private function findUsableMedia(int $mediaId, ArticleMediaType $type): MediaFile
    {
        /** @var MediaFile|null $media */
        $media = MediaFile::query()->find($mediaId);

        if (! $media) {
            throw new ModelNotFoundException('媒体文件不存在');
        }

        if ((int) $media->status !== MediaStatus::Active->value) {
            throw ValidationException::withMessages([
                'media_id' => ['媒体状态不可用'],
            ]);
        }

        if ($type->requiresImage() && (int) $media->file_type !== MediaFileType::Image->value) {
            throw ValidationException::withMessages([
                'media_id' => ['该用途仅允许关联图片类型的媒体文件'],
            ]);
        }

        return $media;
    }

    /**
     * 过滤出 article_media 允许写入的业务字段，屏蔽 article_id / media_id / media_type 等关键字段被外部覆盖。
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function filterAttributes(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip(self::ALLOWED_ATTRIBUTES));
    }
}
