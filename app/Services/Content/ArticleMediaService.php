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
use Illuminate\Support\Facades\Storage;
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
 *
 * syncContentImagesFromHtml() 是“正文编辑体验”任务新增的能力：文章保存后根据
 * 正文 HTML 中实际引用的 <img> 标签反查媒体库，自动增量维护 ContentImage 类型
 * 关联，运营人员不再需要手工在本 Service 对应的后台页面逐张关联正文图片。
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
     * 根据文章正文（网站正文 + 公众号正文）实际引用的 <img> 标签，增量同步该文章
     * ContentImage 类型的 article_media 关联（对应“正文编辑体验”任务六）。
     *
     * 背景：Dcat-Plus 自带 TinyMCE 的 Editor::imageUrl() 只支持“上传成功后由前端
     * 自动把返回的 location 插入为 <img src>”这一种简单模式，没有暴露自定义
     * images_upload_handler 回调，无法在插入的 <img> 标签上可靠地附加
     * data-media-id 属性（若要实现需要覆盖 TinyMCE 初始化选项写一段自定义上传逻辑，
     * 属于不必要的前端 hack）。因此本方法改为“URL 精确匹配 media_files.storage+path”
     * 的方式反查 media_id，而不依赖 data-media-id。
     *
     * 规则：
     * - 只新增/移除关联关系，不触碰 media_files 表，也不会删除任何原始文件；
     * - 已存在且仍被正文引用的关联保持不变，不重置 alt_text/caption/sort
     *   （避免覆盖运营人员在“媒体与关联”页面手工维护的信息）；
     * - 不再被任一正文引用的历史 ContentImage 关联会被移除；
     * - 正文中引用了不存在/已被禁用/非图片类型的媒体地址时静默跳过，不阻断文章保存
     *   （常见于历史脏数据或并发场景，不应因为一张失效图片导致整篇文章保存失败）。
     *
     * @param  array<int, string>  $htmlContents  需要扫描的正文 HTML 原文（网站正文、公众号正文）
     */
    public function syncContentImagesFromHtml(int $articleId, array $htmlContents): void
    {
        $mediaIds = [];

        foreach ($htmlContents as $html) {
            foreach ($this->extractImageSrcList((string) $html) as $src) {
                $mediaId = $this->resolveMediaIdFromUrl($src);

                if ($mediaId !== null && ! in_array($mediaId, $mediaIds, true)) {
                    $mediaIds[] = $mediaId;
                }
            }
        }

        $this->syncContentImageReferences($articleId, $mediaIds);
    }

    /**
     * @return array<int, string>
     */
    private function extractImageSrcList(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        libxml_use_internal_errors(true);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $loaded = @$dom->loadHTML(
            '<?xml encoding="UTF-8">'.$html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED
        );
        libxml_clear_errors();

        if (! $loaded) {
            return [];
        }

        $srcList = [];

        foreach ($dom->getElementsByTagName('img') as $img) {
            $src = trim($img->getAttribute('src'));

            if ($src !== '') {
                $srcList[] = $src;
            }
        }

        return $srcList;
    }

    /**
     * 通过 media_files.storage + path 精确反查 media_id：项目媒体统一使用 'public'
     * 磁盘（对应 MediaFileService::storeFromUploadedFile() 的固定入参），
     * Storage::disk('public')->url('') 与 MediaFile::getUrlAttribute() 使用同一套
     * 拼接规则（rtrim($configUrl,'/').'/'.ltrim($path,'/')），因此可以反向剥离前缀
     * 还原出 path 再做精确匹配，不依赖任何正则猜测 URL 结构。
     */
    private function resolveMediaIdFromUrl(string $src): ?int
    {
        $src = trim($src);

        if ($src === '') {
            return null;
        }

        $prefix = Storage::disk('public')->url('');

        if (! str_starts_with($src, $prefix)) {
            return null;
        }

        $path = ltrim(substr($src, strlen($prefix)), '/');

        if ($path === '') {
            return null;
        }

        /** @var MediaFile|null $media */
        $media = MediaFile::query()
            ->where('storage', 'public')
            ->where('path', $path)
            ->where('file_type', MediaFileType::Image->value)
            ->where('status', MediaStatus::Active->value)
            ->first();

        return $media?->id;
    }

    /**
     * 增量同步 ContentImage 类型关联：新增缺失的、移除多余的，保留仍然有效的关联记录不变。
     *
     * @param  array<int, int>  $mediaIds  按正文中出现顺序去重后的图片媒体 ID
     *
     * @throws ModelNotFoundException 文章不存在
     */
    private function syncContentImageReferences(int $articleId, array $mediaIds): void
    {
        DB::transaction(function () use ($articleId, $mediaIds) {
            $article = $this->lockArticle($articleId);

            $mediaIds = array_values(array_unique(array_map('intval', $mediaIds)));

            $currentIds = ArticleMedia::query()
                ->where('article_id', $article->id)
                ->where('media_type', ArticleMediaType::ContentImage->value)
                ->pluck('media_id')
                ->all();

            $toDetach = array_diff($currentIds, $mediaIds);

            if ($toDetach !== []) {
                ArticleMedia::query()
                    ->where('article_id', $article->id)
                    ->where('media_type', ArticleMediaType::ContentImage->value)
                    ->whereIn('media_id', $toDetach)
                    ->delete();
            }

            foreach (array_values($mediaIds) as $index => $mediaId) {
                /** @var ArticleMedia|null $relation */
                $relation = ArticleMedia::query()
                    ->where('article_id', $article->id)
                    ->where('media_id', $mediaId)
                    ->where('media_type', ArticleMediaType::ContentImage->value)
                    ->first();

                if ($relation) {
                    if ((int) $relation->sort !== $index) {
                        $relation->sort = $index;
                        $relation->save();
                    }

                    continue;
                }

                try {
                    $this->findUsableMedia($mediaId, ArticleMediaType::ContentImage);
                } catch (ModelNotFoundException|ValidationException) {
                    // 正文引用的图片地址已不可用（被删除/禁用/并发变更），静默跳过，不阻断文章保存。
                    continue;
                }

                ArticleMedia::create([
                    'article_id' => $article->id,
                    'media_id' => $mediaId,
                    'media_type' => ArticleMediaType::ContentImage->value,
                    'sort' => $index,
                ]);
            }
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
