<?php

namespace App\Services\Content;

use App\Enums\Common\CommonStatus;
use App\Enums\Content\ContentFormat;
use App\Enums\Content\ContentStatus;
use App\Enums\Hairstyle\HairstyleStatus;
use App\Enums\Media\MediaStatus;
use App\Enums\Wechat\WechatPublishStatus;
use App\Enums\Wechat\WechatSyncStatus;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleHairColorRelation;
use App\Models\ArticleHairstyleRelation;
use App\Models\HairColor;
use App\Models\Hairstyle;
use App\Models\MediaFile;
use App\Models\WechatArticle;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 文章业务 Service（本阶段内容模块的核心写入入口）。
 *
 * 负责 articles 的创建、更新、标签/发型/发色关联同步、状态流转、审核字段维护，
 * 以及公众号同步预留记录的创建/维护。Controller 不得直接操作 Article 模型完成
 * 上述业务规则，必须统一通过本 Service。
 *
 * 状态流转规则完全委托给 ContentStatus::canTransitionTo()（对应
 * doc/v1.0/database/03-内容模块.md 三、状态设计），本 Service 不重复编写
 * 状态跃迁判断逻辑，只负责流转前后的业务副作用（审核字段、发布时间、
 * 公众号同步记录）。
 */
class ArticleService
{
    /**
     * articles 允许通过 create()/update() 直接写入的字段。
     *
     * 显式排除以下字段，均只能通过专门的方法写入，避免绕过业务规则：
     * - status / reviewed_by / reviewed_at / review_remark：只能通过 changeStatus() 维护；
     * - view_count / like_count：只能通过 updateCounts() 维护（需要非负校验）。
     */
    private const WRITABLE_ATTRIBUTES = [
        'category_id',
        'title',
        'title_en',
        'slug',
        'cover_media_id',
        'summary',
        'content',
        'content_format',
        'wechat_content',
        'wechat_cover_media_id',
        'wechat_excerpt',
        'author',
        'source',
        'source_url',
        'is_recommended',
        'is_top',
        'sort',
        'published_at',
        'seo_title',
        'seo_keywords',
        'seo_description',
    ];

    public function __construct(
        private readonly ArticleTagService $articleTagService,
    ) {
    }

    /**
     * 创建文章草稿。
     *
     * 规则：
     * - 新建文章的 status 始终为 ContentStatus::Draft，不接受外部传入的 status；
     * - category_id 非 0 时必须指向存在且未删除的分类（不要求分类已启用，
     *   分类禁用只影响前台展示，不影响文章归属关系，与 HairColorService 的
     *   分类校验原则一致）；
     * - slug 必须非空且唯一（跨软删除记录校验）；
     * - title 不能为空；
     * - cover_media_id / wechat_cover_media_id 非 0 时必须指向状态可用的媒体；
     * - content_format 必须是 ContentFormat 合法取值；
     * - 创建成功后自动确保存在一条 wechat_articles 预留记录（sync_status = Pending）。
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $tagIds
     * @param  array<int, int>  $hairstyleIds
     * @param  array<int, int>  $hairColorIds
     *
     * @throws ValidationException
     */
    public function create(array $data, array $tagIds = [], array $hairstyleIds = [], array $hairColorIds = []): Article
    {
        return DB::transaction(function () use ($data, $tagIds, $hairstyleIds, $hairColorIds) {
            $attributes = $this->filterWritableAttributes($data);

            $categoryId = (int) ($attributes['category_id'] ?? 0);
            $this->assertCategoryExistsAndNotDeleted($categoryId);
            $attributes['category_id'] = $categoryId;

            $this->assertTitleNotEmpty((string) ($attributes['title'] ?? ''));
            $this->assertSlugAvailable((string) ($attributes['slug'] ?? ''), null);
            $this->assertContentFormatValid($attributes['content_format'] ?? ContentFormat::Markdown->value);

            $this->assertOptionalMediaUsable($attributes, 'cover_media_id');
            $this->assertOptionalMediaUsable($attributes, 'wechat_cover_media_id');

            $attributes = $this->sanitizeHtmlContentFields(
                $attributes,
                (int) ($attributes['content_format'] ?? ContentFormat::Markdown->value)
            );

            $attributes['status'] = ContentStatus::Draft->value;

            /** @var Article $article */
            $article = Article::create($attributes);

            $this->articleTagService->syncTagsForArticle($article, $tagIds);
            $this->syncHairstyles($article, $hairstyleIds);
            $this->syncHairColors($article, $hairColorIds);
            $this->ensureWechatArticleRecord($article);

            return $article->refresh();
        });
    }

    /**
     * 更新文章基础字段（不包含 status / reviewed_* / view_count / like_count）。
     *
     * 校验规则与 create() 一致；若未修改 category_id，仅要求原分类仍然存在且未删除
     * （与 HairColorService::update() 的分类校验原则一致）。
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Article $article, array $data): Article
    {
        return DB::transaction(function () use ($article, $data) {
            /** @var Article $article */
            $article = Article::query()->lockForUpdate()->findOrFail($article->id);

            $attributes = $this->filterWritableAttributes($data);

            if (array_key_exists('category_id', $attributes)) {
                $categoryId = (int) $attributes['category_id'];

                if ($categoryId !== (int) $article->category_id) {
                    $this->assertCategoryExistsAndNotDeleted($categoryId);
                }

                $attributes['category_id'] = $categoryId;
            }

            if (array_key_exists('title', $attributes)) {
                $this->assertTitleNotEmpty((string) $attributes['title']);
            }

            if (array_key_exists('slug', $attributes) && $attributes['slug'] !== $article->slug) {
                $this->assertSlugAvailable((string) $attributes['slug'], (int) $article->id);
            }

            if (array_key_exists('content_format', $attributes)) {
                $this->assertContentFormatValid($attributes['content_format']);
            }

            $this->assertOptionalMediaUsable($attributes, 'cover_media_id');
            $this->assertOptionalMediaUsable($attributes, 'wechat_cover_media_id');

            $attributes = $this->sanitizeHtmlContentFields(
                $attributes,
                array_key_exists('content_format', $attributes)
                    ? (int) $attributes['content_format']
                    : (int) $article->content_format->value
            );

            $article->fill($attributes);
            $article->save();

            return $article->refresh();
        });
    }

    /**
     * 同步文章标签（委托 ArticleTagService，保持文章创建/更新以外的独立调用入口）。
     *
     * @param  array<int, int>  $tagIds
     *
     * @throws ValidationException
     */
    public function syncTags(Article $article, array $tagIds): Article
    {
        return DB::transaction(function () use ($article, $tagIds) {
            /** @var Article $article */
            $article = Article::query()->lockForUpdate()->findOrFail($article->id);

            $this->articleTagService->syncTagsForArticle($article, $tagIds);

            return $article->refresh();
        });
    }

    /**
     * 同步文章关联的发型：只允许关联未删除且状态为启用的发型；
     * 通过直接操作 ArticleHairstyleRelation 模型完成增量同步（新增/移除/排序更新），
     * 不使用 BelongsToMany::sync()，避免统一写入 created_at 导致已存在关联的
     * 创建时间被错误重置（同 ArticleTagService::syncTagsForArticle 的原因）。
     *
     * @param  array<int, int>  $hairstyleIds  按数组顺序即最终排序顺序
     *
     * @throws ValidationException 存在不存在、已删除或未启用的发型
     */
    public function syncHairstyles(Article $article, array $hairstyleIds): Article
    {
        return DB::transaction(function () use ($article, $hairstyleIds) {
            /** @var Article $article */
            $article = Article::query()->lockForUpdate()->findOrFail($article->id);

            $hairstyleIds = array_values(array_unique(array_map('intval', $hairstyleIds)));

            if ($hairstyleIds !== []) {
                $validCount = Hairstyle::query()
                    ->whereIn('id', $hairstyleIds)
                    ->where('status', HairstyleStatus::Enabled->value)
                    ->count();

                if ($validCount !== count($hairstyleIds)) {
                    throw ValidationException::withMessages([
                        'hairstyle_ids' => ['存在不存在、已删除或未启用的发型'],
                    ]);
                }
            }

            $currentIds = ArticleHairstyleRelation::query()
                ->where('article_id', $article->id)
                ->pluck('hairstyle_id')
                ->all();

            $toDetach = array_diff($currentIds, $hairstyleIds);

            if ($toDetach !== []) {
                ArticleHairstyleRelation::query()
                    ->where('article_id', $article->id)
                    ->whereIn('hairstyle_id', $toDetach)
                    ->delete();
            }

            foreach (array_values($hairstyleIds) as $index => $hairstyleId) {
                /** @var ArticleHairstyleRelation|null $relation */
                $relation = ArticleHairstyleRelation::query()
                    ->where('article_id', $article->id)
                    ->where('hairstyle_id', $hairstyleId)
                    ->first();

                if ($relation) {
                    if ((int) $relation->sort !== $index) {
                        $relation->sort = $index;
                        $relation->save();
                    }

                    continue;
                }

                ArticleHairstyleRelation::create([
                    'article_id' => $article->id,
                    'hairstyle_id' => $hairstyleId,
                    'sort' => $index,
                ]);
            }

            return $article->refresh();
        });
    }

    /**
     * 同步文章关联的发色：只允许关联未删除且状态为启用的发色；实现方式与
     * syncHairstyles() 一致。
     *
     * @param  array<int, int>  $hairColorIds  按数组顺序即最终排序顺序
     *
     * @throws ValidationException 存在不存在、已删除或未启用的发色
     */
    public function syncHairColors(Article $article, array $hairColorIds): Article
    {
        return DB::transaction(function () use ($article, $hairColorIds) {
            /** @var Article $article */
            $article = Article::query()->lockForUpdate()->findOrFail($article->id);

            $hairColorIds = array_values(array_unique(array_map('intval', $hairColorIds)));

            if ($hairColorIds !== []) {
                $validCount = HairColor::query()
                    ->whereIn('id', $hairColorIds)
                    ->where('status', CommonStatus::Enabled->value)
                    ->count();

                if ($validCount !== count($hairColorIds)) {
                    throw ValidationException::withMessages([
                        'hair_color_ids' => ['存在不存在、已删除或未启用的发色'],
                    ]);
                }
            }

            $currentIds = ArticleHairColorRelation::query()
                ->where('article_id', $article->id)
                ->pluck('hair_color_id')
                ->all();

            $toDetach = array_diff($currentIds, $hairColorIds);

            if ($toDetach !== []) {
                ArticleHairColorRelation::query()
                    ->where('article_id', $article->id)
                    ->whereIn('hair_color_id', $toDetach)
                    ->delete();
            }

            foreach (array_values($hairColorIds) as $index => $hairColorId) {
                /** @var ArticleHairColorRelation|null $relation */
                $relation = ArticleHairColorRelation::query()
                    ->where('article_id', $article->id)
                    ->where('hair_color_id', $hairColorId)
                    ->first();

                if ($relation) {
                    if ((int) $relation->sort !== $index) {
                        $relation->sort = $index;
                        $relation->save();
                    }

                    continue;
                }

                ArticleHairColorRelation::create([
                    'article_id' => $article->id,
                    'hair_color_id' => $hairColorId,
                    'sort' => $index,
                ]);
            }

            return $article->refresh();
        });
    }

    /**
     * 更新浏览量/点赞量（不允许写入负数，未传入的字段保持不变）。
     *
     * @throws ModelNotFoundException 文章不存在
     * @throws ValidationException view_count / like_count 为负数
     */
    public function updateCounts(int $articleId, ?int $viewCount = null, ?int $likeCount = null): Article
    {
        return DB::transaction(function () use ($articleId, $viewCount, $likeCount) {
            /** @var Article|null $article */
            $article = Article::query()->lockForUpdate()->find($articleId);

            if (! $article) {
                throw new ModelNotFoundException('文章不存在');
            }

            if ($viewCount !== null) {
                if ($viewCount < 0) {
                    throw ValidationException::withMessages([
                        'view_count' => ['浏览量不能为负数'],
                    ]);
                }

                $article->view_count = $viewCount;
            }

            if ($likeCount !== null) {
                if ($likeCount < 0) {
                    throw ValidationException::withMessages([
                        'like_count' => ['点赞量不能为负数'],
                    ]);
                }

                $article->like_count = $likeCount;
            }

            $article->save();

            return $article;
        });
    }

    /**
     * 文章状态流转（唯一的状态写入入口）。
     *
     * 合法流转由 ContentStatus::canTransitionTo() 统一维护：
     * draft -> pending_review -> published <-> offline，pending_review -> draft（打回草稿）。
     *
     * 副作用：
     * - 流转到 pending_review：清空上一轮审核信息（reviewed_by/reviewed_at/review_remark），
     *   进入新的审核周期；
     * - 流转到 draft（审核打回）：记录 reviewed_by/reviewed_at/review_remark（审核意见）；
     * - 流转到 published：必须先通过 assertPublishable() 全部校验，同时记录审核信息，
     *   并写入 published_at（取 $context['published_at']，未提供时使用文章已有的
     *   published_at，两者都为空则拒绝）；
     * - 流转到 offline：不清空 published_at / 审核信息，保留历史发布记录；
     * - 任意流转成功后都会调用 ensureWechatArticleRecord() 确保公众号同步预留记录存在。
     *
     * 目标状态与当前状态相同时视为“空流转”：直接返回，不重写 reviewed_by/reviewed_at/
     * review_remark/published_at，也不重新执行 assertPublishable() 校验（例如
     * published 编辑后仍保存为 published），避免未来后台表单无脑提交当前状态时
     * 被错误拒绝或产生无意义的审核时间刷新。
     *
     * @param  array{reviewed_by?: int, review_remark?: string, published_at?: string|\DateTimeInterface|null}  $context
     *
     * @throws ModelNotFoundException 文章不存在
     * @throws ValidationException 非法状态流转 / 发布条件不满足
     */
    public function changeStatus(int $articleId, ContentStatus $target, array $context = []): Article
    {
        return DB::transaction(function () use ($articleId, $target, $context) {
            /** @var Article|null $article */
            $article = Article::query()->lockForUpdate()->find($articleId);

            if (! $article) {
                throw new ModelNotFoundException('文章不存在');
            }

            /** @var ContentStatus $current */
            $current = $article->status;

            if ($current === $target) {
                // 同状态“空流转”：不重写 reviewed_by/reviewed_at/review_remark/published_at，
                // 只确保公众号预留记录存在，直接返回，不触发下方任何状态迁移副作用。
                $this->ensureWechatArticleRecord($article);

                return $article->refresh();
            }

            if (! $current->canTransitionTo($target)) {
                throw ValidationException::withMessages([
                    'status' => ["不允许从「{$current->label()}」变更为「{$target->label()}」"],
                ]);
            }

            if ($target === ContentStatus::PendingReview) {
                $article->reviewed_by = 0;
                $article->reviewed_at = null;
                $article->review_remark = '';
            }

            if ($target === ContentStatus::Draft) {
                $article->reviewed_by = (int) ($context['reviewed_by'] ?? 0);
                $article->reviewed_at = Carbon::now();
                $article->review_remark = (string) ($context['review_remark'] ?? '');
            }

            if ($target === ContentStatus::Published) {
                $publishedAt = $this->resolvePublishedAt($article, $context);
                $this->assertPublishable($article);

                $article->reviewed_by = (int) ($context['reviewed_by'] ?? $article->reviewed_by);
                $article->reviewed_at = Carbon::now();
                $article->review_remark = (string) ($context['review_remark'] ?? $article->review_remark);
                $article->published_at = $publishedAt;
            }

            $article->status = $target;
            $article->save();

            $this->ensureWechatArticleRecord($article);

            return $article->refresh();
        });
    }

    /**
     * 软删除文章：仅执行软删除，不删除 article_media / article_tag_relations /
     * article_hairstyle_relations / article_hair_color_relations / wechat_articles
     * 等关联数据（软删除只是隐藏文章，关联数据在文章恢复后应保持完整）。
     *
     * @throws ModelNotFoundException 文章不存在
     */
    public function delete(int $id): void
    {
        /** @var Article|null $article */
        $article = Article::query()->find($id);

        if (! $article) {
            throw new ModelNotFoundException('文章不存在');
        }

        $article->delete();
    }

    /**
     * 恢复文章：恢复前重新校验所属分类仍然存在且未删除，并检查 slug 是否冲突。
     *
     * @throws ModelNotFoundException 文章不存在或未处于回收站中
     * @throws ValidationException 分类已被删除 / slug 已被占用
     */
    public function restore(int $id): Article
    {
        return DB::transaction(function () use ($id) {
            /** @var Article|null $article */
            $article = Article::onlyTrashed()->lockForUpdate()->find($id);

            if (! $article) {
                throw new ModelNotFoundException('文章不存在或未处于回收站中');
            }

            $this->assertCategoryExistsAndNotDeleted((int) $article->category_id);
            $this->assertSlugAvailable((string) $article->slug, (int) $article->id);

            $article->restore();

            return $article;
        });
    }

    /**
     * 确保文章存在一条公众号同步预留记录（一篇文章最多一条，article_id 唯一，
     * 对应 wechat_articles.uk_article_id 唯一索引）。
     *
     * 本阶段仅维护数据结构和默认状态（Pending / Unpublished），不调用任何真实
     * 微信接口，也不创建“同步成功”的伪实现；记录一旦存在就不会重置已有的
     * sync_status / publish_status，避免覆盖后续真实同步 Job 写入的状态。
     *
     * 使用 firstOrCreate() 而非“先查询再创建”，避免并发场景下两个请求都判断
     * 记录不存在从而重复插入：firstOrCreate() 在未命中时会尝试 create()，若命中
     * uk_article_id 唯一约束冲突（并发下另一请求已插入），会在当前事务内建立
     * savepoint 后捕获该冲突并重新查询返回已存在的记录，不会向上抛出异常，
     * 也不会影响外层事务（create()/changeStatus() 的事务边界）。
     */
    public function ensureWechatArticleRecord(Article $article): WechatArticle
    {
        return WechatArticle::firstOrCreate(
            ['article_id' => $article->id],
            [
                'sync_status' => WechatSyncStatus::Pending->value,
                'publish_status' => WechatPublishStatus::Unpublished->value,
            ]
        );
    }

    /**
     * 校验发布前置条件：分类存在且启用、标题非空、slug 非空、正文非空、
     * 封面媒体可用、标签/发型/发色关联当前均合法（不存在因后续被软删除/禁用而
     * 失效的历史关联）。published_at 的非空校验在 resolvePublishedAt() 中完成。
     *
     * @throws ValidationException
     */
    private function assertPublishable(Article $article): void
    {
        $categoryId = (int) $article->category_id;

        if ($categoryId <= 0) {
            throw ValidationException::withMessages([
                'category_id' => ['发布前必须设置分类'],
            ]);
        }

        /** @var ArticleCategory|null $category */
        $category = ArticleCategory::query()->find($categoryId);

        if (! $category) {
            throw ValidationException::withMessages([
                'category_id' => ['所选分类不存在或已被删除'],
            ]);
        }

        if ((int) $category->status !== CommonStatus::Enabled->value) {
            throw ValidationException::withMessages([
                'category_id' => ['所选分类当前处于禁用状态'],
            ]);
        }

        if (trim((string) $article->title) === '') {
            throw ValidationException::withMessages([
                'title' => ['标题不能为空'],
            ]);
        }

        if (trim((string) $article->slug) === '') {
            throw ValidationException::withMessages([
                'slug' => ['Slug 不能为空'],
            ]);
        }

        if (trim((string) $article->content) === '') {
            throw ValidationException::withMessages([
                'content' => ['正文不能为空'],
            ]);
        }

        $coverMediaId = (int) $article->cover_media_id;

        if ($coverMediaId <= 0) {
            throw ValidationException::withMessages([
                'cover_media_id' => ['发布前必须设置封面'],
            ]);
        }

        $this->assertMediaUsable($coverMediaId, 'cover_media_id');

        $this->assertRelationsValidForPublish($article);
    }

    /**
     * 校验发型、发色关联当前是否仍然全部合法：中间表记录数必须与目标表中
     * 「存在且未删除且状态启用」的记录数一致，否则说明存在关联建立之后才被
     * 软删除/禁用的历史脏数据，不允许在此状态下发布。
     *
     * 标签（article_tags）不参与本校验：article_tags 不使用 SoftDeletes，且
     * ArticleTagService::delete() 已在删除前强制检查引用，被文章引用的标签
     * 不可能被删除，因此标签关联不存在“建立后失效”的问题，只在
     * syncTagsForArticle() 时校验一次即可。
     *
     * @throws ValidationException
     */
    private function assertRelationsValidForPublish(Article $article): void
    {
        $hairstyleIds = ArticleHairstyleRelation::query()->where('article_id', $article->id)->pluck('hairstyle_id')->all();

        if ($hairstyleIds !== []) {
            $validCount = Hairstyle::query()
                ->whereIn('id', $hairstyleIds)
                ->where('status', HairstyleStatus::Enabled->value)
                ->count();

            if ($validCount !== count($hairstyleIds)) {
                throw ValidationException::withMessages([
                    'hairstyle_ids' => ['存在已失效的发型关联，请重新同步发型关联后再发布'],
                ]);
            }
        }

        $hairColorIds = ArticleHairColorRelation::query()->where('article_id', $article->id)->pluck('hair_color_id')->all();

        if ($hairColorIds !== []) {
            $validCount = HairColor::query()
                ->whereIn('id', $hairColorIds)
                ->where('status', CommonStatus::Enabled->value)
                ->count();

            if ($validCount !== count($hairColorIds)) {
                throw ValidationException::withMessages([
                    'hair_color_ids' => ['存在已失效的发色关联，请重新同步发色关联后再发布'],
                ]);
            }
        }
    }

    /**
     * 解析并校验发布时间：优先使用 $context['published_at']（显式指定发布时间），
     * 未提供时使用文章当前已有的 published_at（例如草稿阶段已预先排期）；
     * 两者都为空时拒绝发布——发布时间必须显式存在，不由本 Service 静默填充为
     * “当前时间”，避免测试和业务上出现无法感知的隐式默认值。
     *
     * @param  array{published_at?: string|\DateTimeInterface|null}  $context
     *
     * @throws ValidationException
     */
    private function resolvePublishedAt(Article $article, array $context): Carbon
    {
        $value = array_key_exists('published_at', $context) ? $context['published_at'] : $article->published_at;

        if ($value === null || $value === '') {
            throw ValidationException::withMessages([
                'published_at' => ['发布时间不能为空'],
            ]);
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        return Carbon::parse($value);
    }

    /**
     * 校验分类是否存在且未被软删除（不要求启用状态），用于新增/编辑时分类未被
     * 更换的场景，以及恢复文章前的分类校验，原则与 HairColorService 一致。
     *
     * @throws ValidationException
     */
    private function assertCategoryExistsAndNotDeleted(int $categoryId): void
    {
        if ($categoryId === 0) {
            return;
        }

        $exists = ArticleCategory::query()->where('id', $categoryId)->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'category_id' => ['所选分类不存在或已被删除'],
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertTitleNotEmpty(string $title): void
    {
        if (trim($title) === '') {
            throw ValidationException::withMessages([
                'title' => ['标题不能为空'],
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertSlugAvailable(string $slug, ?int $excludeId): void
    {
        if (trim($slug) === '') {
            throw ValidationException::withMessages([
                'slug' => ['Slug 不能为空'],
            ]);
        }

        $exists = Article::withTrashed()
            ->where('slug', $slug)
            ->when($excludeId !== null, fn ($query) => $query->where('id', '!=', $excludeId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'slug' => ['Slug 已被占用'],
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertContentFormatValid(mixed $value): void
    {
        if (! ContentFormat::isValid((int) $value)) {
            throw ValidationException::withMessages([
                'content_format' => ['正文格式取值不合法'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    private function assertOptionalMediaUsable(array $attributes, string $field): void
    {
        if (! array_key_exists($field, $attributes)) {
            return;
        }

        $mediaId = (int) $attributes[$field];

        if ($mediaId <= 0) {
            return;
        }

        $this->assertMediaUsable($mediaId, $field);
    }

    /**
     * @throws ValidationException
     */
    private function assertMediaUsable(int $mediaId, string $field): MediaFile
    {
        /** @var MediaFile|null $media */
        $media = MediaFile::query()->find($mediaId);

        if (! $media) {
            throw ValidationException::withMessages([
                $field => ['媒体文件不存在'],
            ]);
        }

        if ((int) $media->status !== MediaStatus::Active->value) {
            throw ValidationException::withMessages([
                $field => ['媒体状态不可用'],
            ]);
        }

        return $media;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function filterWritableAttributes(array $data): array
    {
        return array_intersect_key($data, array_flip(self::WRITABLE_ATTRIBUTES));
    }

    /**
     * 正文安全处理（对应本次“正文编辑体验”任务十、安全要求）。
     *
     * 后台正文已统一改为 TinyMCE 富文本（ContentFormat::Html），存在 XSS 风险，
     * 因此仅当有效格式为 Html 时才对 content / wechat_content 做清洗；
     * 格式仍为 Markdown 时不做任何处理，避免破坏 Markdown 语法（历史数据兼容）。
     *
     * 项目当前未引入任何 HTML Sanitizer 依赖（HTMLPurifier / league-html-sanitizer 等），
     * 本方法只是基于 PHP 内置 DOMDocument 的最小防护，只做三件事：删除 <script>/<iframe>、
     * 删除所有 on* 事件属性、清除 href/src 中的 javascript: 伪协议；不做任何标签/属性白名单
     * 意义上的完整清洗，不能替代正式的 HTML Sanitizer——后续必须评估引入正式方案。
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function sanitizeHtmlContentFields(array $attributes, int $effectiveFormat): array
    {
        if ($effectiveFormat !== ContentFormat::Html->value) {
            return $attributes;
        }

        foreach (['content', 'wechat_content'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = $this->sanitizeHtml((string) $attributes[$field]);
            }
        }

        return $attributes;
    }

    private function sanitizeHtml(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return $html;
        }

        libxml_use_internal_errors(true);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        // 用 <?xml encoding="UTF-8"> 前缀避免 loadHTML() 把中文按 ISO-8859-1 误解析；
        // 包一层带唯一 id 的 div，避免 DOMDocument 对多个顶层兄弟节点的处理异常。
        $wrapped = '<?xml encoding="UTF-8"><div id="__article_content_root__">'.$html.'</div>';
        $loaded = @$dom->loadHTML(
            $wrapped,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED
        );
        libxml_clear_errors();

        if (! $loaded) {
            // 解析失败时保留原文：宁可暂不清洗，也不能把无法解析的正文误清空。
            return $html;
        }

        $xpath = new \DOMXPath($dom);
        $root = $xpath->query('//div[@id="__article_content_root__"]')->item(0);

        if (! $root) {
            return $html;
        }

        $this->stripDangerousHtmlNodes($dom);

        $inner = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $inner .= (string) $dom->saveHTML($child);
        }

        return $inner;
    }

    private function stripDangerousHtmlNodes(\DOMDocument $dom): void
    {
        // 禁止的标签：script、iframe（项目暂无可信 iframe 白名单，本次一律禁止）。
        foreach (['script', 'iframe'] as $tagName) {
            foreach (iterator_to_array($dom->getElementsByTagName($tagName)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $xpath = new \DOMXPath($dom);

        foreach ($xpath->query('//*[@*]') as $element) {
            if (! $element instanceof \DOMElement) {
                continue;
            }

            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->name);

                if (str_starts_with($name, 'on')) {
                    $element->removeAttribute($attribute->name);

                    continue;
                }

                if (in_array($name, ['href', 'src'], true) && preg_match('/^\s*javascript:/i', $attribute->value)) {
                    $element->removeAttribute($attribute->name);
                }
            }
        }
    }
}
