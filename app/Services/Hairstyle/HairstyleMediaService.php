<?php

namespace App\Services\Hairstyle;

use App\Enums\Media\MediaStatus;
use App\Models\Hairstyle;
use App\Models\HairstyleMedia;
use App\Models\MediaFile;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 发型媒体业务 Service。
 *
 * 负责维护 hairstyle_media（发型与媒体的关联关系）以及
 * hairstyles.cover_media_id（发型封面）之间的一致性：
 * - 任意时刻，一个发型最多只有一条 hairstyle_media.is_primary = 1 的记录；
 * - 主图切换后，hairstyles.cover_media_id 必须与该记录同步更新；
 * - 上述一致性完全由本 Service 的事务 + 行锁保证，数据库层未使用唯一索引强制约束。
 */
class HairstyleMediaService
{
    /**
     * hairstyle_media 允许通过本 Service 写入的业务字段（不包含 hairstyle_id / media_id）。
     */
    private const ALLOWED_ATTRIBUTES = [
        'type',
        'title',
        'alt_text',
        'caption',
        'is_primary',
        'status',
        'sort',
    ];

    /**
     * 将媒体关联到发型。
     *
     * 规则：
     * - 发型、媒体必须存在，媒体状态必须可用；
     * - 同一发型不允许重复关联同一媒体；
     * - 外部传入的 attributes 中即使包含 hairstyle_id / media_id 也会被忽略，
     *   目标关系始终以方法参数 $hairstyleId / $mediaId 为准；
     * - attributes.is_primary = true 时，新增后会统一走 setPrimaryMedia() 完成主图同步。
     *
     * @param  array<string, mixed>  $attributes  仅 type/title/alt_text/caption/is_primary/status/sort 会被写入
     *
     * @throws ModelNotFoundException 发型不存在 / 媒体文件不存在
     * @throws ValidationException 媒体状态不可用 / 该媒体已关联当前发型
     */
    public function attachMedia(int $hairstyleId, int $mediaId, array $attributes = []): HairstyleMedia
    {
        return DB::transaction(function () use ($hairstyleId, $mediaId, $attributes) {
            $hairstyle = $this->lockHairstyle($hairstyleId);
            $media = $this->findUsableMedia($mediaId);

            $alreadyAttached = HairstyleMedia::query()
                ->where('hairstyle_id', $hairstyle->id)
                ->where('media_id', $media->id)
                ->exists();

            if ($alreadyAttached) {
                throw ValidationException::withMessages([
                    'media_id' => ['该媒体已关联当前发型'],
                ]);
            }

            $data = $this->filterAttributes($attributes);

            // 主图设置统一走 setPrimaryMedia()，避免在这里直接写 is_primary 造成多主图或与 cover_media_id 不同步。
            $wantsPrimary = (bool) ($data['is_primary'] ?? false);
            unset($data['is_primary']);

            $relation = new HairstyleMedia(array_merge($data, [
                'hairstyle_id' => $hairstyle->id,
                'media_id' => $media->id,
            ]));
            $relation->save();

            if ($wantsPrimary) {
                return $this->setPrimaryMedia($hairstyle->id, $media->id);
            }

            // 新建实例只带有显式赋值的属性，未赋值的字段（如 is_primary/status/sort 等）
            // 在内存对象里仍是 null，实际数据库值是列默认值；这里必须 refresh() 重新从库里
            // 读取一次，让返回对象的属性与数据库真实默认值（及 casts）保持一致。
            return $relation->refresh();
        });
    }

    /**
     * 更新发型媒体关联记录的业务字段。
     *
     * 规则：
     * - title/alt_text/caption/type/status/sort 按传入值直接更新；
     * - attributes.is_primary = true：统一走 setPrimaryMedia()，禁止直接更新当前行了事，避免多主图；
     * - attributes.is_primary = false 且当前记录正是主图：直接拒绝，
     *   必须显式调用 detachMedia()（移除关联）来清空主图，
     *   不在本方法内“顺手”决定新的主图或清空 cover_media_id，
     *   避免出现『没人是主图但 cover_media_id 未清零』或『随意选新主图』的隐式行为。
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ModelNotFoundException 发型媒体关联记录不存在
     * @throws ValidationException 试图将当前主图直接改为非主图
     */
    public function updateRelation(int $relationId, array $attributes): HairstyleMedia
    {
        return DB::transaction(function () use ($relationId, $attributes) {
            /** @var HairstyleMedia|null $relation */
            $relation = HairstyleMedia::query()->lockForUpdate()->find($relationId);

            if (! $relation) {
                throw new ModelNotFoundException('发型媒体关联记录不存在');
            }

            $hasPrimaryKey = array_key_exists('is_primary', $attributes);
            $wantsPrimary = $hasPrimaryKey ? (bool) $attributes['is_primary'] : null;

            $data = $this->filterAttributes($attributes);
            unset($data['is_primary']);

            if ($data !== []) {
                $relation->fill($data);
                $relation->save();
            }

            if ($wantsPrimary === true) {
                return $this->setPrimaryMedia((int) $relation->hairstyle_id, (int) $relation->media_id);
            }

            if ($wantsPrimary === false && $relation->is_primary) {
                throw ValidationException::withMessages([
                    'is_primary' => ['不能直接将当前主图改为非主图，请调用 detachMedia() 移除该关联，或先通过 setPrimaryMedia() 指定新的主图'],
                ]);
            }

            return $relation->refresh();
        });
    }

    /**
     * 将指定媒体设置为发型的主图（唯一的主图设置入口）。
     *
     * 事务内完成：
     * 1. lockForUpdate 锁定目标发型记录；
     * 2. 校验目标媒体存在且状态可用（禁用/缺失/处理中的媒体不能被设为主图）；
     * 3. 确认目标媒体已经关联该发型；
     * 4. 一次 update 将该发型下其它 is_primary = 1 的记录清零（限定 hairstyle_id，不使用循环逐条更新）；
     * 5. 将目标关联记录 is_primary 置 1；
     * 6. 同步 hairstyles.cover_media_id 为目标 media_id；
     * 7. 返回刷新后的 HairstyleMedia。
     *
     * @throws ModelNotFoundException 发型不存在 / 媒体文件不存在
     * @throws ValidationException 媒体状态不可用 / 该媒体尚未关联当前发型
     */
    public function setPrimaryMedia(int $hairstyleId, int $mediaId): HairstyleMedia
    {
        return DB::transaction(function () use ($hairstyleId, $mediaId) {
            $hairstyle = $this->lockHairstyle($hairstyleId);
            $media = $this->findUsableMedia($mediaId);

            /** @var HairstyleMedia|null $relation */
            $relation = HairstyleMedia::query()
                ->where('hairstyle_id', $hairstyle->id)
                ->where('media_id', $media->id)
                ->first();

            if (! $relation) {
                throw ValidationException::withMessages([
                    'media_id' => ['该媒体尚未关联当前发型'],
                ]);
            }

            HairstyleMedia::query()
                ->where('hairstyle_id', $hairstyle->id)
                ->where('is_primary', true)
                ->where('id', '!=', $relation->id)
                ->update(['is_primary' => false]);

            if (! $relation->is_primary) {
                $relation->is_primary = true;
                $relation->save();
            }

            if ((int) $hairstyle->cover_media_id !== $media->id) {
                $hairstyle->cover_media_id = $media->id;
                $hairstyle->save();
            }

            return $relation->refresh();
        });
    }

    /**
     * 解除发型与媒体的关联（只删除 hairstyle_media 关联记录，不删除 media_files 记录，不处理物理文件）。
     *
     * 若被移除的是当前主图，会将 hairstyles.cover_media_id 重置为 null（表示当前没有封面）；
     * 第一版不会自动挑选其他媒体作为新主图，新主图需业务层另行调用 setPrimaryMedia()。
     *
     * @throws ModelNotFoundException 发型不存在
     * @throws ValidationException 该媒体尚未关联当前发型
     */
    public function detachMedia(int $hairstyleId, int $mediaId): void
    {
        DB::transaction(function () use ($hairstyleId, $mediaId) {
            $hairstyle = $this->lockHairstyle($hairstyleId);

            /** @var HairstyleMedia|null $relation */
            $relation = HairstyleMedia::query()
                ->where('hairstyle_id', $hairstyle->id)
                ->where('media_id', $mediaId)
                ->first();

            if (! $relation) {
                throw ValidationException::withMessages([
                    'media_id' => ['该媒体尚未关联当前发型'],
                ]);
            }

            $wasPrimary = (bool) $relation->is_primary;

            $relation->delete();

            if ($wasPrimary) {
                $hairstyle->cover_media_id = null;
                $hairstyle->save();
            }
        });
    }

    /**
     * 加锁读取目标发型，不存在则抛出异常。
     *
     * @throws ModelNotFoundException
     */
    private function lockHairstyle(int $hairstyleId): Hairstyle
    {
        /** @var Hairstyle|null $hairstyle */
        $hairstyle = Hairstyle::query()->lockForUpdate()->find($hairstyleId);

        if (! $hairstyle) {
            throw new ModelNotFoundException('发型不存在');
        }

        return $hairstyle;
    }

    /**
     * 读取媒体文件并校验状态是否可用（仅 MediaStatus::Active 视为可用）。
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    private function findUsableMedia(int $mediaId): MediaFile
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

        return $media;
    }

    /**
     * 过滤出 hairstyle_media 允许写入的业务字段，屏蔽 hairstyle_id / media_id 等关键字段被外部覆盖。
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function filterAttributes(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip(self::ALLOWED_ATTRIBUTES));
    }
}
