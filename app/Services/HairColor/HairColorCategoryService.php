<?php

namespace App\Services\HairColor;

use App\Models\HairColor;
use App\Models\HairColorCategory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 发色分类业务 Service。
 *
 * 负责 hair_color_categories 的层级约束、slug 唯一性以及删除/恢复前的引用检查，
 * 是本阶段发色分类唯一的业务写入入口（对应 doc/v1.0/database/02-发色模块.md
 * 四、十、十四）：
 * - 顶级分类 parent_id = 0，最多支持两级分类；
 * - 不允许父级分类指向自身或自身的子分类；
 * - 不允许选择二级分类作为父级（会形成第三级）；
 * - 删除前必须检查未删除子分类和未删除发色引用；
 * - 恢复分类时不自动恢复子分类，仅恢复目标分类自身。
 */
class HairColorCategoryService
{
    /**
     * 新增发色分类。
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException 父级不合法 / slug 已被占用
     */
    public function create(array $data): HairColorCategory
    {
        $parentId = (int) ($data['parent_id'] ?? 0);
        $this->assertParentValid($parentId, null);
        $this->assertSlugAvailable((string) ($data['slug'] ?? ''), null);

        $data['parent_id'] = $parentId;

        return HairColorCategory::create($data);
    }

    /**
     * 更新发色分类。
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException 父级不合法 / slug 已被占用
     */
    public function update(HairColorCategory $category, array $data): HairColorCategory
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
     * 删除分类：删除前检查未删除子分类和未删除发色引用，存在引用时拒绝删除，仅执行软删除。
     *
     * @throws ModelNotFoundException 分类不存在
     * @throws ValidationException 存在未删除子分类 / 存在未删除发色引用
     */
    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            /** @var HairColorCategory|null $category */
            $category = HairColorCategory::query()->lockForUpdate()->find($id);

            if (! $category) {
                throw new ModelNotFoundException('发色分类不存在');
            }

            if (HairColorCategory::query()->where('parent_id', $id)->exists()) {
                throw ValidationException::withMessages([
                    'id' => ['存在未删除的子分类，请先删除或转移子分类后再操作'],
                ]);
            }

            if (HairColor::query()->where('category_id', $id)->exists()) {
                throw ValidationException::withMessages([
                    'id' => ['该分类下存在未删除的发色，请先处理发色数据后再操作'],
                ]);
            }

            $category->delete();
        });
    }

    /**
     * 恢复分类：仅恢复目标分类自身，不自动恢复子分类；恢复前检查 slug 是否冲突。
     *
     * @throws ModelNotFoundException 分类不存在或未处于回收站中
     * @throws ValidationException slug 已被占用
     */
    public function restore(int $id): HairColorCategory
    {
        return DB::transaction(function () use ($id) {
            /** @var HairColorCategory|null $category */
            $category = HairColorCategory::onlyTrashed()->lockForUpdate()->find($id);

            if (! $category) {
                throw new ModelNotFoundException('发色分类不存在或未处于回收站中');
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
     * - 父级自身必须是顶级分类，否则会形成第三级（V1.0 最多支持两级）。
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

        /** @var HairColorCategory|null $parent */
        $parent = HairColorCategory::query()->find($parentId);

        if (! $parent) {
            throw ValidationException::withMessages([
                'parent_id' => ['父分类不存在或已被删除'],
            ]);
        }

        if ($selfId !== null) {
            $childIds = HairColorCategory::query()->where('parent_id', $selfId)->pluck('id')->all();

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
        $exists = HairColorCategory::withTrashed()
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
