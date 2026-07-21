<?php

namespace App\Services\Content;

use App\Enums\Content\ContentStatus;
use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaStatus;
use App\Enums\Video\VideoSource;
use App\Models\MediaFile;
use App\Models\Video;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 视频业务 Service（本阶段视频模块唯一的业务写入入口）。
 *
 * 负责 videos 的来源校验（本地 / 外部至少存在一种）、封面与视频媒体的类型/状态
 * 校验、发布前置条件校验，以及状态流转（复用 ContentStatus，与 articles 共用
 * 同一套四状态生命周期）。
 */
class VideoService
{
    /**
     * videos 允许通过 create()/update() 直接写入的字段。
     *
     * 显式排除 status（只能通过 changeStatus() 维护）、view_count / like_count
     * （只能通过 updateCounts() 维护，需要非负校验）。
     */
    private const WRITABLE_ATTRIBUTES = [
        'title',
        'title_en',
        'slug',
        'cover_media_id',
        'video_media_id',
        'video_url',
        'source',
        'source_video_id',
        'duration',
        'description',
        'transcript',
        'is_recommended',
        'sort',
        'published_at',
        'seo_title',
        'seo_keywords',
        'seo_description',
    ];

    /**
     * 新建视频（默认状态为 ContentStatus::Draft，不接受外部传入 status）。
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): Video
    {
        return DB::transaction(function () use ($data) {
            $attributes = $this->filterWritableAttributes($data);

            $this->assertTitleNotEmpty((string) ($attributes['title'] ?? ''));
            $this->assertSlugAvailable((string) ($attributes['slug'] ?? ''), null);

            $source = VideoSource::from((int) ($attributes['source'] ?? VideoSource::Local->value));
            $attributes['source'] = $source->value;

            $this->assertSourceConsistent($source, $attributes);
            $this->assertDurationValid($attributes['duration'] ?? 0);

            if ($source->isLocal()) {
                $this->assertOptionalMediaUsable($attributes, 'video_media_id', MediaFileType::Video);
            } else {
                $sourceVideoId = (string) ($attributes['source_video_id'] ?? '');

                if ($sourceVideoId !== '') {
                    $this->assertSourceVideoIdAvailable($source, $sourceVideoId, null);
                }
            }

            $this->assertOptionalMediaUsable($attributes, 'cover_media_id', MediaFileType::Image);

            $attributes['status'] = ContentStatus::Draft->value;

            /** @var Video $video */
            $video = Video::create($attributes);

            return $video->refresh();
        });
    }

    /**
     * 更新视频基础字段（不包含 status / view_count / like_count）。
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Video $video, array $data): Video
    {
        return DB::transaction(function () use ($video, $data) {
            /** @var Video $video */
            $video = Video::query()->lockForUpdate()->findOrFail($video->id);

            $attributes = $this->filterWritableAttributes($data);

            if (array_key_exists('title', $attributes)) {
                $this->assertTitleNotEmpty((string) $attributes['title']);
            }

            if (array_key_exists('slug', $attributes) && $attributes['slug'] !== $video->slug) {
                $this->assertSlugAvailable((string) $attributes['slug'], (int) $video->id);
            }

            $source = array_key_exists('source', $attributes)
                ? VideoSource::from((int) $attributes['source'])
                : $video->source;
            $attributes['source'] = $source->value;

            $merged = array_merge($video->only([
                'video_media_id', 'video_url', 'source_video_id',
            ]), $attributes);

            $this->assertSourceConsistent($source, $merged);

            if (array_key_exists('duration', $attributes)) {
                $this->assertDurationValid($attributes['duration']);
            }

            if ($source->isLocal()) {
                $this->assertOptionalMediaUsable($attributes, 'video_media_id', MediaFileType::Video);
            } elseif (array_key_exists('source_video_id', $attributes)) {
                $sourceVideoId = (string) $attributes['source_video_id'];

                if ($sourceVideoId !== '') {
                    $this->assertSourceVideoIdAvailable($source, $sourceVideoId, (int) $video->id);
                }
            }

            $this->assertOptionalMediaUsable($attributes, 'cover_media_id', MediaFileType::Image);

            $video->fill($attributes);
            $video->save();

            return $video->refresh();
        });
    }

    /**
     * 更新浏览量/点赞量（不允许写入负数，未传入的字段保持不变）。
     *
     * @throws ModelNotFoundException 视频不存在
     * @throws ValidationException view_count / like_count 为负数
     */
    public function updateCounts(int $videoId, ?int $viewCount = null, ?int $likeCount = null): Video
    {
        return DB::transaction(function () use ($videoId, $viewCount, $likeCount) {
            /** @var Video|null $video */
            $video = Video::query()->lockForUpdate()->find($videoId);

            if (! $video) {
                throw new ModelNotFoundException('视频不存在');
            }

            if ($viewCount !== null) {
                if ($viewCount < 0) {
                    throw ValidationException::withMessages([
                        'view_count' => ['浏览量不能为负数'],
                    ]);
                }

                $video->view_count = $viewCount;
            }

            if ($likeCount !== null) {
                if ($likeCount < 0) {
                    throw ValidationException::withMessages([
                        'like_count' => ['点赞量不能为负数'],
                    ]);
                }

                $video->like_count = $likeCount;
            }

            $video->save();

            return $video;
        });
    }

    /**
     * 视频状态流转（与 Article 共用 ContentStatus::canTransitionTo() 规则）。
     *
     * @param  array{published_at?: string|\DateTimeInterface|null}  $context
     *
     * @throws ModelNotFoundException 视频不存在
     * @throws ValidationException 非法状态流转 / 发布条件不满足
     */
    public function changeStatus(int $videoId, ContentStatus $target, array $context = []): Video
    {
        return DB::transaction(function () use ($videoId, $target, $context) {
            /** @var Video|null $video */
            $video = Video::query()->lockForUpdate()->find($videoId);

            if (! $video) {
                throw new ModelNotFoundException('视频不存在');
            }

            /** @var ContentStatus $current */
            $current = $video->status;

            if ($current === $target) {
                // 同状态“空流转”：不重写 published_at，直接返回，不触发下方状态迁移副作用。
                return $video->refresh();
            }

            if (! $current->canTransitionTo($target)) {
                throw ValidationException::withMessages([
                    'status' => ["不允许从「{$current->label()}」变更为「{$target->label()}」"],
                ]);
            }

            if ($target === ContentStatus::Published) {
                $publishedAt = $this->resolvePublishedAt($video, $context);
                $this->assertPublishable($video);
                $video->published_at = $publishedAt;
            }

            $video->status = $target;
            $video->save();

            return $video->refresh();
        });
    }

    /**
     * 软删除视频：仅执行软删除，不删除封面 / 视频媒体文件。
     *
     * @throws ModelNotFoundException 视频不存在
     */
    public function delete(int $id): void
    {
        /** @var Video|null $video */
        $video = Video::query()->find($id);

        if (! $video) {
            throw new ModelNotFoundException('视频不存在');
        }

        $video->delete();
    }

    /**
     * 恢复视频：恢复前检查 slug 是否冲突。
     *
     * @throws ModelNotFoundException 视频不存在或未处于回收站中
     * @throws ValidationException slug 已被占用
     */
    public function restore(int $id): Video
    {
        return DB::transaction(function () use ($id) {
            /** @var Video|null $video */
            $video = Video::onlyTrashed()->lockForUpdate()->find($id);

            if (! $video) {
                throw new ModelNotFoundException('视频不存在或未处于回收站中');
            }

            $this->assertSlugAvailable((string) $video->slug, (int) $video->id);

            $video->restore();

            return $video;
        });
    }

    /**
     * 校验发布前置条件：封面媒体可用、有效视频来源（本地或外部至少一种）、published_at
     * 非空（在 resolvePublishedAt() 中完成）。
     *
     * @throws ValidationException
     */
    private function assertPublishable(Video $video): void
    {
        if (trim((string) $video->title) === '') {
            throw ValidationException::withMessages([
                'title' => ['标题不能为空'],
            ]);
        }

        $coverMediaId = (int) $video->cover_media_id;

        if ($coverMediaId <= 0) {
            throw ValidationException::withMessages([
                'cover_media_id' => ['发布前必须设置封面'],
            ]);
        }

        $this->assertMediaUsable($coverMediaId, 'cover_media_id', MediaFileType::Image);

        /** @var VideoSource $source */
        $source = $video->source;
        $this->assertSourceConsistent($source, [
            'video_media_id' => $video->video_media_id,
            'video_url' => $video->video_url,
            'source_video_id' => $video->source_video_id,
        ]);

        if ($source->isLocal()) {
            $this->assertMediaUsable((int) $video->video_media_id, 'video_media_id', MediaFileType::Video);
        }
    }

    /**
     * 校验视频来源与本地/外部字段是否一致：
     * - 本地来源（VideoSource::Local）必须提供 video_media_id；
     * - 非本地来源必须提供 video_url（外部地址）；
     * - 本地视频与外部地址至少存在一种，不允许两者同时为空。
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    private function assertSourceConsistent(VideoSource $source, array $attributes): void
    {
        $videoMediaId = (int) ($attributes['video_media_id'] ?? 0);
        $videoUrl = trim((string) ($attributes['video_url'] ?? ''));

        if ($videoMediaId <= 0 && $videoUrl === '') {
            throw ValidationException::withMessages([
                'video_media_id' => ['本地视频和外部视频地址至少需要提供一种'],
            ]);
        }

        if ($source->isLocal() && $videoMediaId <= 0) {
            throw ValidationException::withMessages([
                'video_media_id' => ['来源为本地视频时必须选择视频媒体'],
            ]);
        }

        if (! $source->isLocal() && $videoUrl === '') {
            throw ValidationException::withMessages([
                'video_url' => ['来源为外部平台时必须填写外部视频地址'],
            ]);
        }
    }

    /**
     * 校验 duration 非负（秒），且不超过数据库 unsignedInteger 上限。
     *
     * @throws ValidationException
     */
    private function assertDurationValid(mixed $duration): void
    {
        if ((int) $duration < 0) {
            throw ValidationException::withMessages([
                'duration' => ['视频时长不能为负数'],
            ]);
        }
    }

    /**
     * 校验外部平台 + source_video_id 组合是否已被其它视频录入过，避免重复录入
     * 同一条外部视频。
     *
     * @throws ValidationException
     */
    private function assertSourceVideoIdAvailable(VideoSource $source, string $sourceVideoId, ?int $excludeId): void
    {
        $exists = Video::query()
            ->where('source', $source->value)
            ->where('source_video_id', $sourceVideoId)
            ->when($excludeId !== null, fn ($query) => $query->where('id', '!=', $excludeId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'source_video_id' => ['该外部视频已被录入，请勿重复添加'],
            ]);
        }
    }

    /**
     * 解析并校验发布时间：优先使用 $context['published_at']，未提供时使用视频
     * 当前已有的 published_at，两者都为空时拒绝发布。
     *
     * @param  array{published_at?: string|\DateTimeInterface|null}  $context
     *
     * @throws ValidationException
     */
    private function resolvePublishedAt(Video $video, array $context): Carbon
    {
        $value = array_key_exists('published_at', $context) ? $context['published_at'] : $video->published_at;

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

        $exists = Video::withTrashed()
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
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    private function assertOptionalMediaUsable(array $attributes, string $field, MediaFileType $expectedType): void
    {
        if (! array_key_exists($field, $attributes)) {
            return;
        }

        $mediaId = (int) $attributes[$field];

        if ($mediaId <= 0) {
            return;
        }

        $this->assertMediaUsable($mediaId, $field, $expectedType);
    }

    /**
     * @throws ValidationException
     */
    private function assertMediaUsable(int $mediaId, string $field, MediaFileType $expectedType): MediaFile
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

        if ((int) $media->file_type !== $expectedType->value) {
            throw ValidationException::withMessages([
                $field => ['媒体文件类型不匹配'],
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
}
