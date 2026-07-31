<?php

namespace App\Admin\Controllers;

use App\Admin\Controllers\Concerns\FormatsEnumBadges;
use App\Admin\Renderable\MediaImageTable;
use App\Admin\Renderable\MediaVideoTable;
use App\Enums\Content\ContentStatus;
use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaSourceType;
use App\Enums\Media\MediaStatus;
use App\Enums\Media\MediaVisibility;
use App\Enums\Video\VideoSource;
use App\Models\MediaFile;
use App\Models\Video;
use App\Services\Content\VideoService;
use App\Services\Media\MediaFileService;
use Dcat\Admin\Admin;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Http\JsonResponse;
use Dcat\Admin\Layout\Content;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * 视频内容管理 Dcat Admin 后台管理。
 *
 * 严格复用第一阶段已完成的 VideoService 作为唯一写入入口：来源一致性
 * （本地/外部至少一种）、媒体类型/状态校验、状态流转全部委托给 Service，
 * 本控制器只负责表单字段提取、封面/视频媒体解析和事务边界控制。
 *
 * 不实现视频转码、不实现远程视频下载、不实现自动抓取第三方平台信息——
 * “来源平台”“外部视频 ID”“外部视频地址”均为运营人员手工填写。
 */
class VideoController extends AdminController
{
    use FormatsEnumBadges;

    private VideoService $videoService;

    private MediaFileService $mediaFileService;

    public function __construct(VideoService $videoService, MediaFileService $mediaFileService)
    {
        $this->videoService = $videoService;
        $this->mediaFileService = $mediaFileService;
    }

    public function index(Content $content)
    {
        return $content
            ->header('视频内容')
            ->description('列表')
            ->body($this->grid());
    }

    /**
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new Video(), function (Grid $grid) {
            // select() 显式限定为 Video::LIST_COLUMNS（不含 transcript 等 LONGTEXT），
            // with() 预加载 coverMedia/videoMedia，避免在下面的列 display() 中逐行触发关联查询（N+1）。
            $grid->model()
                ->select(Video::LIST_COLUMNS)
                ->with(['coverMedia', 'videoMedia'])
                ->orderByDesc('sort')
                ->orderByDesc('id');

            $grid->column('id')->sortable();
            $grid->column('cover', '封面')->display(function () {
                return VideoController::renderCoverThumb($this->coverMedia);
            });
            $grid->column('title', '标题');
            $grid->column('source', '视频来源')->display(function ($value) {
                return VideoController::enumBadge($value, VideoSource::class);
            });
            $grid->column('duration', '时长')->display(function ($value) {
                return VideoController::formatDuration((int) $value);
            });
            $grid->column('status', '状态')->display(function ($value) {
                return VideoController::enumBadge($value, ContentStatus::class);
            });
            $grid->column('is_recommended', '推荐')->using([0 => '否', 1 => '是'])->label([0 => 'default', 1 => 'success']);
            $grid->column('view_count', '播放量')->sortable();
            $grid->column('like_count', '点赞量')->sortable();
            $grid->column('published_at', '发布时间')->display(fn ($value) => $value ?: '-')->sortable();
            $grid->column('updated_at', '更新时间');

            $grid->setActionClass(Grid\Displayers\Actions::class);

            $grid->filter(function (Grid\Filter $filter) {
                $filter->like('title', '标题');
                $filter->equal('source', '来源平台')->select(VideoSource::options());
                $filter->equal('status', '状态')->select(ContentStatus::options());
                $filter->equal('is_recommended', '推荐')->select([0 => '否', 1 => '是']);
                $filter->between('published_at', '发布时间')->datetime();

                // 回收站 scope：默认列表因 SoftDeletes 全局作用域已自动排除已删除记录，
                // 切到该 scope 时改为只查询 deleted_at 不为空的记录，用于恢复。
                $filter->scope('trashed', '回收站')->onlyTrashed();
            });

            // 已软删除的记录：隐藏编辑/查看/删除，改为“恢复”。V1.0 不提供永久删除入口。
            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $model = $actions->row;

                if (! $model instanceof Video) {
                    return;
                }

                if ($model->trashed()) {
                    $actions->disableView();
                    $actions->disableEdit();
                    $actions->disableDelete();
                    $actions->append(VideoController::renderRestoreAction($model));
                }
            });
        });
    }

    /**
     * @param  mixed  $id
     * @return Show
     */
    protected function detail($id)
    {
        /** @var Video $video */
        $video = Video::withTrashed()->with(['coverMedia', 'videoMedia'])->findOrFail($id);

        return Show::make($id, new Video(), function (Show $show) use ($video) {
            $show->field('id');
            $show->field('title', '标题');
            $show->field('title_en', '英文标题');
            $show->field('slug', 'Slug');
            $show->field('cover_display', '封面')->as(function () use ($video) {
                return VideoController::renderCoverPreview($video->coverMedia);
            })->unescape();
            $show->field('source', '视频来源')->as(function ($value) {
                return VideoController::enumLabel($value, VideoSource::class);
            });
            $show->field('video_media_display', '本地视频媒体')->as(function () use ($video) {
                $media = $video->videoMedia;

                return $media ? sprintf('#%d %s', $media->id, $media->original_name ?: $media->filename) : '-';
            });
            $show->field('video_url', '外部视频地址')->as(fn ($value) => $value ?: '-');
            $show->field('source_video_id', '外部视频 ID')->as(fn ($value) => $value ?: '-');
            $show->field('duration', '时长')->as(function ($value) {
                return VideoController::formatDuration((int) $value);
            });
            $show->field('description', '简介')->as(fn ($value) => $value ?: '-');
            // 复用 ArticleController::renderTextPreview()（转义 + 保留换行的只读文本预览），
            // 不重新实现同样的渲染逻辑。
            $show->field('transcript', '字幕 / 文稿')->as(function ($value) {
                return ArticleController::renderTextPreview((string) $value);
            })->unescape();
            $show->field('status', '状态')->as(function ($value) {
                return VideoController::enumLabel($value, ContentStatus::class);
            });
            $show->field('is_recommended', '是否推荐')->using([0 => '否', 1 => '是']);
            $show->field('sort', '排序');
            $show->field('published_at', '发布时间')->as(fn ($value) => $value ?: '-');
            $show->field('view_count', '播放量');
            $show->field('like_count', '点赞量');
            $show->field('seo_title', 'SEO 标题')->as(fn ($value) => $value ?: '-');
            $show->field('seo_keywords', 'SEO 关键词')->as(fn ($value) => $value ?: '-');
            $show->field('seo_description', 'SEO 描述')->as(fn ($value) => $value ?: '-');
            $show->field('created_at', '创建时间');
            $show->field('updated_at', '更新时间');

            $show->panel()->tools(function ($tools) {
                $tools->disableDelete();
            });
        });
    }

    /**
     * @return Form
     */
    protected function form()
    {
        $self = $this;

        return Form::make(new Video(), function (Form $form) use ($self) {
            $id = $form->getKey();

            /** @var Video|null $video */
            $video = $id ? Video::withTrashed()->with(['coverMedia', 'videoMedia'])->find($id) : null;

            $form->display('id');

            $form->tab('基本信息', function (Form $form) use ($self, $video) {
                $form->text('title', '标题')->required()->rules('max:255');
                $form->text('title_en', '英文标题')->rules('max:255');
                $form->text('slug', 'Slug')
                    ->required()
                    ->rules(['required', 'max:255', 'regex:/^[a-z0-9-]+$/'])
                    ->help('仅允许小写字母、数字和短横线，用于 SEO URL；唯一性由保存时的业务校验负责（编辑时排除自身）');
                $form->textarea('description', '简介')->rows(3)->rules('max:500');

                $form->html(VideoController::renderCoverPreview($video?->coverMedia), '当前封面');

                $form->image('cover_upload', '上传新封面')
                    ->url('videos/cover-upload')
                    ->autoSave(false)
                    ->accept('jpg,jpeg,png,webp')
                    ->help('点击“上传”后立即正式创建媒体记录（可在媒体库复用，不会被重复创建）；留空表示不更换封面');

                $form->selectTable('cover_select_media_id', '从媒体库选择封面')
                    ->title('选择封面媒体')
                    ->dialogWidth('60%')
                    ->from(MediaImageTable::make())
                    ->pluck('original_name', 'id')
                    ->help('仅可选择状态为“启用”的图片类型媒体；同时上传了新文件时，以上传的文件优先');

                $form->switch('clear_cover', '清除封面')
                    ->help('开启后，若同时没有上传新文件或选择新媒体，将清除当前封面（不会删除媒体文件本身）');
            });

            $form->tab('视频来源', function (Form $form) use ($video) {
                $form->radio('source', '来源平台')
                    ->options(VideoSource::options())
                    ->default(VideoSource::Local->value)
                    ->help('本地视频来源必须提供本地视频媒体；其它来源必须填写外部视频地址');

                $form->html(VideoController::renderVideoMediaPreview($video?->videoMedia), '当前本地视频媒体');

                $form->selectTable('video_select_media_id', '从媒体库选择本地视频')
                    ->title('选择视频媒体')
                    ->dialogWidth('60%')
                    ->from(MediaVideoTable::make())
                    ->pluck('original_name', 'id')
                    ->help('仅当“来源平台”为“本地视频”时生效，仅可选择状态为“启用”的视频类型媒体');

                $form->switch('clear_video_media', '清除本地视频媒体')
                    ->help('开启后将清除当前关联的本地视频媒体（不会删除媒体文件本身）');

                $form->url('video_url', '外部视频地址')->rules('max:255')->help('来源平台非“本地视频”时必须填写');
                $form->text('source_video_id', '外部视频 ID')->rules('max:100')->help('用于避免重复录入同一条外部视频，可留空');
                $form->number('duration', '时长（秒）')->min(0)->default(0)->help('不能为负数；用于列表展示为 mm:ss 或 hh:mm:ss');
                $form->textarea('transcript', '字幕 / 文稿')->rows(6)->help('纯文本存储，不做任何自动转写或抓取');
            });

            $form->tab('发布设置', function (Form $form) use ($id, $video) {
                if ($id) {
                    $form->select('status', '状态')
                        ->options(ContentStatus::options())
                        ->default($video?->status?->value ?? ContentStatus::Draft->value)
                        ->help('状态流转规则由 VideoService 统一校验；发布前必须有封面、有效视频来源和发布时间');
                } else {
                    $form->html(
                        '<div class="alert alert-info" style="margin-bottom:0;">'.
                        '<b>状态：</b>草稿（新建视频的初始状态固定为草稿，保存后可在编辑页调整状态）'.
                        '</div>',
                        '状态'
                    );
                }

                $form->datetime('published_at', '发布时间')->help('发布为“已发布”状态前必须设置，不会自动填充为当前时间');
                $form->switch('is_recommended', '是否推荐');
                $form->number('sort', '排序值')->min(0)->default(0);

                $form->display('view_count', '播放量（只读）');
                $form->display('like_count', '点赞量（只读）');
            });

            $form->tab('SEO', function (Form $form) {
                $form->text('seo_title', 'SEO 标题')->rules('max:255')->help('留空时前台可回退使用视频标题，不会自动覆盖已填写内容');
                $form->text('seo_keywords', 'SEO 关键词')->rules('max:255')->help('建议使用英文逗号分隔');
                $form->textarea('seo_description', 'SEO 描述')->rows(3)->rules('max:500')->help('留空时前台可回退使用视频简介，不会自动覆盖已填写内容');
            });

            $form->ignore([
                'cover_upload', 'cover_select_media_id', 'clear_cover',
                'video_select_media_id', 'clear_video_media',
            ]);

            $form->saving(function (Form $form) use ($self) {
                return $self->handleSaving($form);
            });

            $form->deleting(function (Form $form) use ($self) {
                return $self->handleDeleting($form);
            });
        });
    }

    /**
     * 恢复一个已软删除的视频：调用 VideoService::restore()（恢复前检查 slug 是否冲突）。
     *
     * @param  int  $id
     */
    public function restore($id): RedirectResponse
    {
        try {
            $this->videoService->restore((int) $id);
            admin_toastr('恢复成功');
        } catch (ModelNotFoundException $e) {
            admin_toastr('视频不存在或未处于回收站中', 'error');
        } catch (ValidationException $e) {
            admin_toastr($this->firstValidationMessage($e), 'error');
        }

        return redirect(admin_url('videos'));
    }

    /**
     * “上传新封面”字段的专用上传接口，与文章/分类模块的封面上传接口同构，
     * 仅 source_type 标注为 MediaSourceType::Video。
     */
    public function uploadCover()
    {
        if (request()->filled('key')) {
            return Admin::json()->send();
        }

        $file = request()->file('_file_');

        if (! $file) {
            return JsonResponse::make()->error('未接收到上传文件')->send();
        }

        $validator = Validator::make(
            ['cover_upload' => $file],
            ['cover_upload' => 'required|image|mimes:jpg,jpeg,png,webp|mimetypes:image/jpeg,image/png,image/webp|max:'.MediaFileService::MAX_UPLOAD_SIZE_KB]
        );

        if ($validator->fails()) {
            return JsonResponse::make()->error($validator->errors()->first())->send();
        }

        try {
            $media = $this->mediaFileService->storeFromUploadedFile($file, 'public', 'media/'.now()->format('Y/m/d'), [
                'source_type' => MediaSourceType::Video->value,
                'visibility' => MediaVisibility::Public->value,
                'status' => MediaStatus::Active->value,
            ]);
        } catch (ValidationException $e) {
            return JsonResponse::make()->error($this->firstValidationMessage($e))->send();
        } catch (\Throwable $e) {
            report($e);

            return JsonResponse::make()->error(
                config('app.debug') ? ('封面上传失败：'.$e->getMessage()) : '封面上传失败，请查看系统日志。'
            )->send();
        }

        return Admin::json([
            'id' => (string) $media->id,
            'name' => $media->original_name ?: $media->filename,
            'path' => (string) $media->id,
            'url' => $media->thumbnail_url ?: $media->url,
        ])->send();
    }

    /**
     * 保存前的统一处理：提取表单字段，计算最终 cover_media_id / video_media_id，
     * 调用 VideoService::create()/update() 完成真正的持久化，随后可选调用
     * changeStatus() 完成状态流转。
     *
     * 本方法始终返回非空 JsonResponse，短路 Dcat 默认的 store()/update() 流程。
     */
    private function handleSaving(Form $form): JsonResponse
    {
        $id = $form->getKey();

        $coverUploadId = (int) request()->input('cover_upload', 0);
        $coverSelectId = (int) request()->input('cover_select_media_id', 0);
        $clearCover = (bool) request()->input('clear_cover', false);

        $videoSelectId = (int) request()->input('video_select_media_id', 0);
        $clearVideoMedia = (bool) request()->input('clear_video_media', false);

        $attributes = [
            'title' => (string) $form->input('title'),
            'title_en' => (string) ($form->input('title_en') ?? ''),
            'slug' => (string) $form->input('slug'),
            'description' => (string) ($form->input('description') ?? ''),
            'source' => (int) ($form->input('source') ?? VideoSource::Local->value),
            'video_url' => (string) ($form->input('video_url') ?? ''),
            'source_video_id' => (string) ($form->input('source_video_id') ?? ''),
            'duration' => (int) ($form->input('duration') ?? 0),
            'transcript' => (string) ($form->input('transcript') ?? ''),
            'is_recommended' => (bool) ((int) ($form->input('is_recommended') ?? 0)),
            'sort' => (int) ($form->input('sort') ?? 0),
            'published_at' => $form->input('published_at') ?: null,
            'seo_title' => (string) ($form->input('seo_title') ?? ''),
            'seo_keywords' => (string) ($form->input('seo_keywords') ?? ''),
            'seo_description' => (string) ($form->input('seo_description') ?? ''),
        ];

        $targetStatusValue = $form->input('status');

        try {
            $video = DB::transaction(function () use (
                $id,
                $attributes,
                $coverUploadId,
                $coverSelectId,
                $clearCover,
                $videoSelectId,
                $clearVideoMedia,
                $targetStatusValue
            ) {
                if ($id) {
                    /** @var Video $current */
                    $current = Video::withTrashed()->lockForUpdate()->findOrFail($id);

                    $attributes['cover_media_id'] = $this->resolveMediaSelection(
                        $coverUploadId,
                        $coverSelectId,
                        $clearCover,
                        (int) $current->cover_media_id,
                        MediaFileType::Image,
                        'cover_media_id'
                    );
                    $attributes['video_media_id'] = $this->resolveMediaSelection(
                        0,
                        $videoSelectId,
                        $clearVideoMedia,
                        (int) $current->video_media_id,
                        MediaFileType::Video,
                        'video_media_id'
                    );

                    $video = $this->videoService->update($current, $attributes);

                    $targetStatus = ContentStatus::tryFrom((int) $targetStatusValue);

                    if ($targetStatus) {
                        $video = $this->videoService->changeStatus($video->id, $targetStatus);
                    }

                    return $video;
                }

                $attributes['cover_media_id'] = $this->resolveMediaSelection(
                    $coverUploadId, $coverSelectId, $clearCover, 0, MediaFileType::Image, 'cover_media_id'
                );
                $attributes['video_media_id'] = $this->resolveMediaSelection(
                    0, $videoSelectId, $clearVideoMedia, 0, MediaFileType::Video, 'video_media_id'
                );

                return $this->videoService->create($attributes);
            });
        } catch (ValidationException $e) {
            return JsonResponse::make()->error($this->firstValidationMessage($e));
        } catch (ModelNotFoundException $e) {
            return JsonResponse::make()->error('视频不存在');
        } catch (\Throwable $e) {
            report($e);

            return JsonResponse::make()->error(
                config('app.debug') ? ('保存失败：'.$e->getMessage()) : '保存失败，请查看系统日志。'
            );
        }

        $response = JsonResponse::make()->success($id ? '更新成功' : '创建成功');

        return $id
            ? $response->refresh()
            : $response->redirect(admin_url('videos/'.$video->id.'/edit'));
    }

    /**
     * 计算最终应写入的媒体 ID（上传 > 库内选择 > 清除 > 保留原值），并校验
     * 所选媒体存在、状态可用且文件类型匹配。逻辑上与 ManagesCoverMedia::
     * resolveCoverMediaId() 一致，这里改为可配置 MediaFileType，同时服务于
     * 封面（图片）和本地视频（视频）两种媒体选择场景，不重新实现媒体服务本身。
     *
     * @throws ValidationException
     */
    private function resolveMediaSelection(
        int $uploadMediaId,
        int $selectMediaId,
        bool $clear,
        int $currentMediaId,
        MediaFileType $expectedType,
        string $field
    ): int {
        $mediaId = $uploadMediaId > 0 ? $uploadMediaId : ($selectMediaId > 0 ? $selectMediaId : 0);

        if ($mediaId <= 0) {
            return $clear ? 0 : $currentMediaId;
        }

        /** @var MediaFile|null $media */
        $media = MediaFile::query()->find($mediaId);

        if (! $media) {
            throw ValidationException::withMessages([$field => ['所选媒体不存在']]);
        }

        if ((int) $media->file_type !== $expectedType->value) {
            throw ValidationException::withMessages([$field => ['媒体文件类型不匹配']]);
        }

        if ((int) $media->status !== MediaStatus::Active->value) {
            throw ValidationException::withMessages([$field => ['媒体状态不可用']]);
        }

        return $media->id;
    }

    /**
     * 软删除前的统一处理：调用 VideoService::delete()（只执行软删除，不删除封面/视频媒体文件）。
     *
     * 本方法始终返回非空 JsonResponse，短路 Dcat 默认的 destroy() 流程。
     */
    private function handleDeleting(Form $form): JsonResponse
    {
        $ids = collect(explode(',', (string) $form->getKey()))
            ->filter()
            ->map(fn ($value) => (int) $value)
            ->values();

        if ($ids->isEmpty()) {
            return JsonResponse::make()->error('缺少待删除的记录');
        }

        try {
            foreach ($ids as $videoId) {
                $this->videoService->delete($videoId);
            }
        } catch (ModelNotFoundException $e) {
            return JsonResponse::make()->error('视频不存在');
        }

        return JsonResponse::make()->success('删除成功')->refresh();
    }

    private function firstValidationMessage(ValidationException $e): string
    {
        return collect($e->errors())->collapse()->first() ?? '操作失败';
    }

    /**
     * 列表“封面”列渲染，只读取已预加载的 coverMedia 关联，不在渲染过程中触发新查询。
     */
    public static function renderCoverThumb(?MediaFile $cover): string
    {
        if (! $cover) {
            return '-';
        }

        $src = $cover->thumbnail_url ?: $cover->url;

        if (! $src) {
            return '-';
        }

        return '<img src="'.e($src).'" style="width:50px;height:50px;object-fit:cover;border-radius:4px;" />';
    }

    public static function renderCoverPreview(?MediaFile $cover): string
    {
        if (! $cover) {
            return '<div class="alert alert-warning" style="margin-bottom:0;">暂未设置封面</div>';
        }

        $src = $cover->thumbnail_url ?: $cover->url;
        $img = $src ? '<img src="'.e($src).'" style="max-width:120px;max-height:120px;border-radius:4px;" />' : '-';

        return $img.'<div style="margin-top:6px;color:#666;">媒体 ID：#'.(int) $cover->id.'　原始文件名：'.e($cover->original_name ?: $cover->filename).'</div>';
    }

    public static function renderVideoMediaPreview(?MediaFile $media): string
    {
        if (! $media) {
            return '<div class="alert alert-warning" style="margin-bottom:0;">暂未关联本地视频媒体</div>';
        }

        return '<div>媒体 ID：#'.(int) $media->id.'　原始文件名：'.e($media->original_name ?: $media->filename).'</div>';
    }

    /**
     * 将秒数格式化为易读的 mm:ss（超过 1 小时时使用 hh:mm:ss）。
     */
    public static function formatDuration(int $seconds): string
    {
        if ($seconds < 0) {
            $seconds = 0;
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%02d:%02d', $minutes, $secs);
    }

    public static function renderRestoreAction(Video $model): string
    {
        $url = admin_url('videos/'.$model->id.'/restore');
        $token = csrf_token();

        return <<<HTML
<form method="POST" action="{$url}" style="display:inline-block;margin:0 5px;" onsubmit="return confirm('确定要恢复该视频吗？');">
    <input type="hidden" name="_token" value="{$token}">
    <input type="hidden" name="_method" value="PUT">
    <button type="submit" class="btn btn-link" style="padding:0;border:0;background:none;color:#28a745;" title="恢复"><i class="feather icon-rotate-ccw"></i></button>
</form>
HTML;
    }
}
