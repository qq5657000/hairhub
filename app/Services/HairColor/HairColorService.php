<?php

namespace App\Services\HairColor;

use App\Enums\Common\CommonStatus;
use App\Enums\Media\MediaStatus;
use App\Models\HairColor;
use App\Models\HairColorCategory;
use App\Models\MediaFile;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 发色业务 Service。
 *
 * 负责 hair_colors 的分类归属校验、封面媒体可用性校验，以及删除/恢复规则，
 * 是本阶段发色唯一的业务写入入口（对应 doc/v1.0/database/02-发色模块.md 十、十四）：
 * - 新增时 category_id 必须指向存在、未删除且状态启用的分类；
 * - 编辑时若修改了 category_id，新分类同样必须存在、未删除且启用；若未修改 category_id，
 *   仅要求原分类仍然存在且未被软删除，不要求原分类保持启用（禁用分类只影响前台展示，
 *   不代表发色与分类的归属关系失效，允许继续修改名称、SEO、排序等其它字段）；
 * - 恢复发色前只要求所属分类存在且未被软删除，同样不强制分类保持启用状态；
 * - color_hex 新增时必须非空，编辑时如果传入空字符串会被拒绝，未传入该字段则保留原值；
 *   具体的格式标准化（转大写 / 正则校验）统一由 HairColor Model 的 Mutator
 *   （App\Support\ColorHex）完成，本 Service 不重复实现该正则；
 * - suitable_skin 的去重、all 收敛、枚举白名单校验统一由 App\Casts\SuitableSkinCast 完成；
 * - cover_media_id 非 0 时必须指向存在且状态可用的媒体；
 * - 删除发色仅执行软删除，不删除封面媒体；
 * - AI Prompt（ai_prompt / ai_negative_prompt）不参与前台公开字段输出，见 toPublicArray()。
 */
class HairColorService
{
    /**
     * 前台公开输出时必须剔除的字段：AI Prompt 不面向前台用户展示，deleted_at 属于内部软删除标记。
     */
    private const HIDDEN_PUBLIC_FIELDS = [
        'ai_prompt',
        'ai_negative_prompt',
        'deleted_at',
    ];

    /**
     * 新增发色。
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException 分类不合法 / slug 已被占用 / color_hex 为空 / 封面媒体不可用
     */
    public function create(array $data): HairColor
    {
        return DB::transaction(function () use ($data) {
            $categoryId = (int) ($data['category_id'] ?? 0);
            $this->assertCategoryValid($categoryId);
            $this->assertSlugAvailable((string) ($data['slug'] ?? ''), null);
            $this->assertColorHexRequired((string) ($data['color_hex'] ?? ''));

            $coverMediaId = (int) ($data['cover_media_id'] ?? 0);
            if ($coverMediaId > 0) {
                $this->assertCoverMediaUsable($coverMediaId);
            }

            $data['category_id'] = $categoryId;
            $data['cover_media_id'] = $coverMediaId;

            return HairColor::create($data);
        });
    }

    /**
     * 更新发色，支持只传部分字段（未传入的字段保持模型当前值不变）。
     *
     * 所有校验均遵循“传入新值，否则使用模型当前值”的原则，不会因为调用方只传了
     * name 等少量字段而访问不存在的数组键，也不会误用未修改字段的旧值重新触发
     * 不必要的强校验（例如未修改 category_id 时不要求原分类保持启用状态）。
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException 分类不合法 / 原分类已被删除 / slug 已被占用 /
     *                              color_hex 被显式传入空字符串 / 封面媒体不可用
     */
    public function update(HairColor $hairColor, array $data): HairColor
    {
        return DB::transaction(function () use ($hairColor, $data) {
            $categoryChanged = array_key_exists('category_id', $data)
                && (int) $data['category_id'] !== (int) $hairColor->category_id;

            if ($categoryChanged) {
                // 分类发生变更：新分类必须满足与新增发色相同的完整条件（存在、未删除、已启用）。
                $categoryId = (int) $data['category_id'];
                $this->assertCategoryValid($categoryId);
                $data['category_id'] = $categoryId;
            } else {
                // 分类未变更：只要求原分类仍然存在且未被软删除，不要求原分类保持启用状态——
                // 分类被禁用只影响前台展示，不代表该发色与分类的归属关系已经失效，
                // 仍应允许继续修改名称、SEO、排序等与分类归属无关的字段。
                $this->assertCategoryExistsAndNotDeleted((int) $hairColor->category_id);
            }

            if (array_key_exists('slug', $data) && $data['slug'] !== $hairColor->slug) {
                $this->assertSlugAvailable((string) $data['slug'], (int) $hairColor->id);
            }

            if (array_key_exists('color_hex', $data)) {
                // color_hex 一旦在本次更新中被显式传入，就必须非空；未传入该字段时完全不处理，
                // 由下面的 fill() 保留模型当前值，不会覆盖为空。
                $this->assertColorHexRequired((string) $data['color_hex']);
            }

            if (array_key_exists('cover_media_id', $data)) {
                $coverMediaId = (int) $data['cover_media_id'];

                if ($coverMediaId > 0) {
                    $this->assertCoverMediaUsable($coverMediaId);
                }

                $data['cover_media_id'] = $coverMediaId;
            }

            $hairColor->fill($data);
            $hairColor->save();

            return $hairColor;
        });
    }

    /**
     * 删除发色：仅执行软删除，不删除封面媒体、不删除 AI 任务历史。
     *
     * @throws ModelNotFoundException 发色不存在
     */
    public function delete(int $id): void
    {
        /** @var HairColor|null $hairColor */
        $hairColor = HairColor::query()->find($id);

        if (! $hairColor) {
            throw new ModelNotFoundException('发色不存在');
        }

        $hairColor->delete();
    }

    /**
     * 恢复发色：恢复前重新校验所属分类仍然存在且未删除，并检查 slug 是否冲突。
     *
     * 恢复时不强制分类保持启用状态——分类禁用只影响前台展示，不代表发色与分类的
     * 归属关系无效；只有分类已被软删除（归属关系确实不再有效）时才拒绝恢复。
     *
     * @throws ModelNotFoundException 发色不存在或未处于回收站中
     * @throws ValidationException 分类已被删除 / slug 已被占用
     */
    public function restore(int $id): HairColor
    {
        return DB::transaction(function () use ($id) {
            /** @var HairColor|null $hairColor */
            $hairColor = HairColor::onlyTrashed()->lockForUpdate()->find($id);

            if (! $hairColor) {
                throw new ModelNotFoundException('发色不存在或未处于回收站中');
            }

            $this->assertCategoryExistsAndNotDeleted((int) $hairColor->category_id);
            $this->assertSlugAvailable((string) $hairColor->slug, (int) $hairColor->id);

            $hairColor->restore();

            return $hairColor;
        });
    }

    /**
     * 将发色转换为可供前台公开输出的数组：剔除 AI Prompt 等内部字段。
     *
     * 注意：这只是本阶段（数据库与业务基础）的临时辅助输出方法，用于在没有前台
     * Controller / API 之前，先明确“AI Prompt 不能对外输出”这条业务规则并可被测试
     * 覆盖。后续真正对外的前台 API 必须使用 Laravel API Resource（如
     * HairColorResource）显式声明字段白名单，不应该依赖本方法或 Model::toArray()
     * 的隐式字段集合；本阶段不创建该 Resource，留待前台 API 开发阶段实现。
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(HairColor $hairColor): array
    {
        return collect($hairColor->toArray())
            ->except(self::HIDDEN_PUBLIC_FIELDS)
            ->all();
    }

    /**
     * 校验分类是否存在、未删除且状态启用，用于新增发色，以及编辑时分类被更换的场景。
     *
     * @throws ValidationException
     */
    private function assertCategoryValid(int $categoryId): HairColorCategory
    {
        $category = $this->assertCategoryExistsAndNotDeleted($categoryId);

        if ((int) $category->status !== CommonStatus::Enabled->value) {
            throw ValidationException::withMessages([
                'category_id' => ['所选分类当前处于禁用状态'],
            ]);
        }

        return $category;
    }

    /**
     * 校验分类是否存在且未被软删除（不要求启用状态），用于编辑时分类未被更换的场景，
     * 以及恢复发色前的分类校验——分类被禁用只影响前台展示，不代表归属关系失效。
     *
     * @throws ValidationException
     */
    private function assertCategoryExistsAndNotDeleted(int $categoryId): HairColorCategory
    {
        if ($categoryId <= 0) {
            throw ValidationException::withMessages([
                'category_id' => ['发色必须归属一个有效的分类'],
            ]);
        }

        /** @var HairColorCategory|null $category */
        $category = HairColorCategory::query()->find($categoryId);

        if (! $category) {
            throw ValidationException::withMessages([
                'category_id' => ['所选分类不存在或已被删除'],
            ]);
        }

        return $category;
    }

    /**
     * 校验 color_hex 是否非空：新增时必须提供；编辑时一旦显式传入该字段就不允许为空字符串。
     * 数据库默认空字符串只代表“字段允许为空”的物理约束，不代表业务上允许保存空值，
     * 因此该校验必须在 Service 层完成，不能只依赖数据库默认值。
     *
     * 具体格式（是否符合 #RRGGBB / #RRGGBBAA）由 HairColor Model 的 Mutator
     * （App\Support\ColorHex）统一校验，本方法只负责“不能为空”这条业务规则，
     * 避免正则逻辑重复维护两份。
     *
     * @throws ValidationException
     */
    private function assertColorHexRequired(string $value): void
    {
        if (trim($value) === '') {
            throw ValidationException::withMessages([
                'color_hex' => ['颜色值不能为空'],
            ]);
        }
    }

    /**
     * 校验封面媒体是否存在且状态可用（仅 MediaStatus::Active 视为可用）。
     *
     * @throws ValidationException
     */
    private function assertCoverMediaUsable(int $mediaId): MediaFile
    {
        /** @var MediaFile|null $media */
        $media = MediaFile::query()->find($mediaId);

        if (! $media) {
            throw ValidationException::withMessages([
                'cover_media_id' => ['封面媒体不存在'],
            ]);
        }

        if ((int) $media->status !== MediaStatus::Active->value) {
            throw ValidationException::withMessages([
                'cover_media_id' => ['封面媒体状态不可用'],
            ]);
        }

        return $media;
    }

    /**
     * 校验 slug 是否可用：跨软删除状态检查（含已删除记录），避免触发数据库唯一索引冲突。
     *
     * @throws ValidationException
     */
    private function assertSlugAvailable(string $slug, ?int $excludeId): void
    {
        $exists = HairColor::withTrashed()
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
