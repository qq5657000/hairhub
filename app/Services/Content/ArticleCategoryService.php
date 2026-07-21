<?php

namespace App\Services\Content;

use App\Models\Article;
use App\Models\ArticleCategory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 文章分类业务 Service。
 *
 * 负责 article_categories 的层级约束、slug 唯一性以及删除/恢复前的引用检查，
 * 是本阶段文章分类唯一的业务写入入口（参照 HairColorCategoryService /
 * doc/v1.0/database/02-发色模块.md 的成熟设计）：
 * - 顶级分类 parent_id = 0，最多支持两级分类；
 * - 不允许父级分类指向自身或自身的子分类；
 * - 不允许选择二级分类作为父级（会形成第三级）；
 * - 删除前必须检查未删除子分类和未删除文章引用；
 * - 恢复分类时不自动恢复子分类，仅恢复目标分类自身。
 */
class ArticleCategoryService
{
    /**
     * 新增文章分类。
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException 父级不合法 / slug 已被占用
     */
    public function create(array $data): ArticleCategory
    {
        $parentId = (int) ($data['parent_id'] ?? 0);
        $this->assertParentValid($parentId, null);
        $this->assertSlugAvailable((string) ($data['slug'] ?? ''), null);

        $data['parent_id'] = $parentId;

        return ArticleCategory::create($data);
    }

    /**
     * 更新文章分类。
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException 父级不合法 / slug 已被占用
     */
    public function update(ArticleCategory $category, array $data): ArticleCategory
    {
        $parentId = (int) ($data['parent_id'] ?? $category->parent_id);
        $this->assertParentValid($parentId, (int) $category->id);

        if (array_key_exists('slug', $data) && $data['slug'] !== $category->slug) {
            $this->assertSlugAvailable((string) $data['slug'], (int) $category->id);
        }

        $data['parent_id'] = $parentId;

        $category->fill($data);
        $category->save();

        return $category;
    }

    /**
     * 删除分类：删除前检查未删除子分类和未删除文章引用，存在引用时拒绝删除，仅执行软删除。
     *
     * @throws ModelNotFoundException 分类不存在
     * @throws ValidationException 存在未删除子分类 / 存在未删除文章引用
     */
    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            /** @var ArticleCategory|null $category */
            $category = ArticleCategory::query()->lockForUpdate()->find($id);

            if (! $category) {
                throw new ModelNotFoundException('文章分类不存在');
            }

            if (ArticleCategory::query()->where('parent_id', $id)->exists()) {
                throw ValidationException::withMessages([
                    'id' => ['存在未删除的子分类，请先删除或转移子分类后再操作'],
                ]);
            }

            if (Article::query()->where('category_id', $id)->exists()) {
                throw ValidationException::withMessages([
                    'id' => ['该分类下存在未删除的文章，请先处理文章数据后再操作'],
                ]);
            }

            $category->delete();
        });
    }

    /**
     * 恢复分类：仅恢复目标分类自身，不自动恢复子分类；恢复前检查 slug 是否冲突，
     * 并要求父分类（若非顶级）仍然存在且未被软删除。
     *
     * @throws ModelNotFoundException 分类不存在或未处于回收站中
     * @throws ValidationException 父分类已被删除 / slug 已被占用
     */
    public function restore(int $id): ArticleCategory
    {
        return DB::transaction(function () use ($id) {
            /** @var ArticleCategory|null $category */
            $category = ArticleCategory::onlyTrashed()->lockForUpdate()->find($id);

            if (! $category) {
                throw new ModelNotFoundException('文章分类不存在或未处于回收站中');
            }

            if ((int) $category->parent_id !== 0) {
                $parentExists = ArticleCategory::query()->where('id', $category->parent_id)->exists();

                if (! $parentExists) {
                    throw ValidationException::withMessages([
                        'parent_id' => ['父分类已被删除，无法恢复'],
                    ]);
                }
            }

            $this->assertSlugAvailable((string) $category->slug, (int) $category->id);

            $category->restore();

            return $category;
        });
    }

    /**
     * 校验父级分类是否合法：
     * - parent_id = 0 表示顶级分类，始终合法；
     * - 父级必须存在且未被软删除；
     * - 父级不能是自身，也不能是自身的子分类（避免出现环）；
     * - 父级自身必须是顶级分类，否则会形成第三级（本阶段最多支持两级）。
     *
     * @throws ValidationException
     */
    private function assertParentValid(int $parentId, ?int $selfId): void
    {
        if ($parentId === 0) {
            return;
        }

        if ($selfId !== null && $parentId === $selfId) {
            throw ValidationException::withMessages([
                'parent_id' => ['父分类不能设置为自身'],
            ]);
        }

        /** @var ArticleCategory|null $parent */
        $parent = ArticleCategory::query()->find($parentId);

        if (! $parent) {
            throw ValidationException::withMessages([
                'parent_id' => ['父分类不存在或已被删除'],
            ]);
        }

        if ($selfId !== null) {
            $childIds = ArticleCategory::query()->where('parent_id', $selfId)->pluck('id')->all();

            if (in_array($parentId, $childIds, true)) {
                throw ValidationException::withMessages([
                    'parent_id' => ['父分类不能设置为自身的子分类'],
                ]);
            }
        }

        if ((int) $parent->parent_id !== 0) {
            throw ValidationException::withMessages([
                'parent_id' => ['最多支持两级分类，不能选择二级分类作为父级'],
            ]);
        }
    }

    /**
     * 校验 slug 是否可用：跨软删除状态检查（含已删除记录），避免触发数据库唯一索引冲突。
     *
     * @throws ValidationException
     */
    private function assertSlugAvailable(string $slug, ?int $excludeId): void
    {
        $exists = ArticleCategory::withTrashed()
            ->where('slug', $slug)
            ->when($excludeId !== null, fn ($query) => $query->where('id', '!=', $excludeId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'slug' => ['Slug 已被占用'],
            ]);
        }
    }
}
