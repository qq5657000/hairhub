<?php

namespace App\Services\Content;

use App\Enums\Common\CommonStatus;
use App\Models\Article;
use App\Models\ArticleTag;
use App\Models\ArticleTagRelation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 文章标签业务 Service。
 *
 * 参照 hairstyle_tags 的成熟设计（article_tags 不使用 SoftDeletes，与
 * hairstyle_tags 保持一致），负责：
 * - slug 唯一性及 status 合法性校验；
 * - 删除前检查 article_tag_relations 引用，避免标签被误删导致文章标签数据丢失；
 * - 文章与标签的多对多关系同步（供 ArticleService 在同一事务内调用）。
 */
class ArticleTagService
{
    /**
     * 新增标签。
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException slug 已被占用 / status 非法
     */
    public function create(array $data): ArticleTag
    {
        $this->assertSlugAvailable((string) ($data['slug'] ?? ''), null);
        $this->assertStatusValid($data['status'] ?? null);

        return ArticleTag::create($data);
    }

    /**
     * 更新标签。
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException slug 已被占用 / status 非法
     */
    public function update(ArticleTag $tag, array $data): ArticleTag
    {
        if (array_key_exists('slug', $data) && $data['slug'] !== $tag->slug) {
            $this->assertSlugAvailable((string) $data['slug'], (int) $tag->id);
        }

        if (array_key_exists('status', $data)) {
            $this->assertStatusValid($data['status']);
        }

        $tag->fill($data);
        $tag->save();

        return $tag;
    }

    /**
     * 删除标签：删除前检查是否仍被文章引用，存在引用时拒绝删除
     * （article_tags 不使用 SoftDeletes，此处为物理删除，因此校验更严格）。
     *
     * @throws ModelNotFoundException 标签不存在
     * @throws ValidationException 标签仍被文章引用
     */
    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            /** @var ArticleTag|null $tag */
            $tag = ArticleTag::query()->lockForUpdate()->find($id);

            if (! $tag) {
                throw new ModelNotFoundException('文章标签不存在');
            }

            if (ArticleTagRelation::query()->where('tag_id', $id)->exists()) {
                throw ValidationException::withMessages([
                    'id' => ['该标签已关联文章，请先解除关联后再删除'],
                ]);
            }

            $tag->delete();
        });
    }

    /**
     * 同步文章的标签关联（供 ArticleService 在文章创建/更新事务内调用）。
     *
     * 规则：
     * - $tagIds 会先去重；
     * - 所有 tagId 必须指向存在的标签，否则整体拒绝（不允许部分同步成功，
     *   不会出现“部分标签关联成功、部分失败”的中间状态）；
     * - 直接操作 ArticleTagRelation 模型而不是 BelongsToMany::sync()：
     *   sync() 传入 pivot 属性时会对“保留不变”的行也执行 updateExistingPivot，
     *   如果统一附带 created_at 会把已存在关联的创建时间错误地重置为当前时间。
     *   这里仅对新增关联调用 create()（created_at 由模型自动写入，见
     *   ArticleTagRelation::UPDATED_AT = null 的说明），对被移除的关联执行
     *   delete()，未变化的关联不做任何写入。
     *
     * @param  array<int, int>  $tagIds
     *
     * @throws ValidationException 存在不合法的 tagId
     */
    public function syncTagsForArticle(Article $article, array $tagIds): void
    {
        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));

        if ($tagIds !== []) {
            $existingIds = ArticleTag::query()->whereIn('id', $tagIds)->pluck('id')->all();
            $invalidIds = array_diff($tagIds, $existingIds);

            if ($invalidIds !== []) {
                throw ValidationException::withMessages([
                    'tag_ids' => ['以下标签不存在：'.implode(',', $invalidIds)],
                ]);
            }
        }

        $currentIds = ArticleTagRelation::query()->where('article_id', $article->id)->pluck('tag_id')->all();

        $toDetach = array_diff($currentIds, $tagIds);
        $toAttach = array_diff($tagIds, $currentIds);

        if ($toDetach !== []) {
            ArticleTagRelation::query()
                ->where('article_id', $article->id)
                ->whereIn('tag_id', $toDetach)
                ->delete();
        }

        foreach ($toAttach as $tagId) {
            ArticleTagRelation::create([
                'article_id' => $article->id,
                'tag_id' => $tagId,
            ]);
        }
    }

    /**
     * 校验 slug 是否可用（article_tags 无 SoftDeletes，直接按当前表数据判断）。
     *
     * @throws ValidationException
     */
    private function assertSlugAvailable(string $slug, ?int $excludeId): void
    {
        $exists = ArticleTag::query()
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
     * 校验 status 是否为 CommonStatus 合法取值。
     *
     * @throws ValidationException
     */
    private function assertStatusValid(mixed $status): void
    {
        if ($status === null) {
            return;
        }

        if (! CommonStatus::isValid((int) $status)) {
            throw ValidationException::withMessages([
                'status' => ['状态取值不合法'],
            ]);
        }
    }
}
