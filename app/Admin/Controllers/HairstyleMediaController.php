<?php

namespace App\Admin\Controllers;

use App\Enums\Hairstyle\HairstyleMediaStatus;
use App\Enums\Hairstyle\HairstyleMediaType;
use App\Enums\Media\MediaStatus;
use App\Models\Hairstyle;
use App\Models\HairstyleMedia;
use App\Models\MediaFile;
use App\Services\Hairstyle\HairstyleMediaService;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Http\JsonResponse;
use Dcat\Admin\Layout\Content;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 发型媒体关联管理（发型「媒体管理」标签页的独立子页面入口）。
 *
 * 本控制器只负责界面交互（列表展示、表单字段、行为触发），
 * 所有会影响 is_primary / hairstyles.cover_media_id 一致性的写操作
 * （关联媒体、更新关联属性、设为主图、移除关联）全部委托给 HairstyleMediaService，
 * 不在本控制器或 Form 回调中重复清空其它主图或更新 cover_media_id。
 *
 * 访问方式：/admin/hairstyle-media?hairstyle_id={发型ID}（通过发型编辑页“媒体管理”标签页跳转）。
 */
class HairstyleMediaController extends AdminController
{
    private HairstyleMediaService $service;

    public function __construct(HairstyleMediaService $service)
    {
        $this->service = $service;
    }

    /**
     * page index
     */
    public function index(Content $content)
    {
        return $content
            ->header('发型媒体管理')
            ->description('关联媒体 / 设为主图 / 编辑 / 移除')
            ->body($this->grid());
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new HairstyleMedia(), function (Grid $grid) {
            $hairstyleId = (int) request('hairstyle_id');

            // 本页始终按 hairstyle_id 过滤，只服务于单个发型的媒体关联管理，
            // 不存在需要跨发型展示全部 hairstyle_media 的场景，因此不做分页之外的额外优化。
            $grid->model()
                ->with('media')
                ->where('hairstyle_id', $hairstyleId)
                ->orderByDesc('is_primary')
                ->orderBy('sort')
                ->orderByDesc('id');

            $grid->column('id')->sortable();
            $grid->column('preview', '预览')->display(function () {
                return HairstyleMediaController::renderPreviewFor($this);
            });
            $grid->column('type', '类型')->using(HairstyleMediaType::options());
            $grid->column('title', '标题');
            $grid->column('alt_text', 'ALT 文本');
            $grid->column('is_primary', '主图')->display(function ($value) {
                return $value ? '<span class="label label-success">主图</span>' : '';
            });
            $grid->column('status', '状态')->using(HairstyleMediaStatus::options());
            $grid->column('sort', '排序')->sortable();
            $grid->column('created_at', '关联时间');

            $grid->disableCreateButton();
            $grid->disableBatchDelete();

            $grid->tools(function (Grid\Tools $tools) use ($hairstyleId) {
                $hairstyle = Hairstyle::withTrashed()->find($hairstyleId);
                $name = $hairstyle ? $hairstyle->name : '未知发型';
                $createUrl = admin_url('hairstyle-media/create?hairstyle_id='.$hairstyleId);
                $backUrl = $hairstyle ? admin_url('hairstyles/'.$hairstyleId.'/edit') : admin_url('hairstyles');

                $tools->append(
                    '<a href="'.e($createUrl).'" class="btn btn-primary"><i class="feather icon-plus"></i> 关联媒体</a> '
                    .'<a href="'.e($backUrl).'" class="btn btn-default"><i class="feather icon-arrow-left"></i> 返回发型「'.e($name).'」</a>'
                );
            });

            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $model = $actions->row;

                if (! $model instanceof HairstyleMedia) {
                    return;
                }

                $actions->disableView();

                if (! $model->is_primary) {
                    $actions->prepend(HairstyleMediaController::renderSetPrimaryAction($model));
                }
            });
        });
    }

    /**
     * Make a show builder.
     *
     * @param  mixed  $id
     * @return Show
     */
    protected function detail($id)
    {
        return Show::make($id, new HairstyleMedia(), function (Show $show) {
            /** @var HairstyleMedia $relation */
            $relation = $show->model()->load('hairstyle', 'media');

            $show->field('id');
            $show->field('hairstyle_name', '所属发型')->as(function () use ($relation) {
                return $relation->hairstyle->name ?? '-';
            });
            $show->field('media_display', '媒体')->as(function () use ($relation) {
                return $relation->media
                    ? sprintf('#%d %s', $relation->media->id, $relation->media->original_name ?: $relation->media->filename)
                    : '-';
            });
            $show->field('type', '类型')->using(HairstyleMediaType::options());
            $show->field('title', '标题');
            $show->field('alt_text', 'ALT 文本');
            $show->field('caption', '说明');
            $show->field('is_primary', '主图')->using([0 => '否', 1 => '是']);
            $show->field('status', '状态')->using(HairstyleMediaStatus::options());
            $show->field('sort', '排序值');
            $show->field('created_at', '关联时间');
            $show->field('updated_at', '更新时间');

            $show->panel()->tools(function ($tools) {
                $tools->disableDelete();
            });
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        $self = $this;

        return Form::make(new HairstyleMedia(), function (Form $form) use ($self) {
            $id = $form->getKey();

            /** @var HairstyleMedia|null $existing */
            $existing = $id ? HairstyleMedia::query()->find($id) : null;
            $hairstyleId = $existing ? (int) $existing->hairstyle_id : (int) request('hairstyle_id');
            $hairstyle = Hairstyle::withTrashed()->find($hairstyleId);

            $form->display('id');
            $form->html('<b>所属发型：</b>'.($hairstyle ? e($hairstyle->name).' （#'.$hairstyleId.'）' : '未知，请从发型编辑页的“媒体管理”标签页进入本页面'));
            $form->hidden('hairstyle_id')->value($hairstyleId);

            if (! $id) {
                $form->select('media_id', '选择媒体')
                    ->options(function ($value) {
                        if (! $value) {
                            return [];
                        }

                        $media = MediaFile::query()->find($value);

                        return $media
                            ? [$media->id => sprintf('#%d %s', $media->id, $media->original_name ?: $media->filename)]
                            : [];
                    })
                    ->ajax('hairstyle-media/media-options')
                    ->required()
                    ->help('仅可选择状态为“启用”的媒体资源，支持关键字搜索');
            } else {
                $media = $existing?->media;
                $form->display('media_display', '媒体')->with(function () use ($media) {
                    return $media ? sprintf('#%d %s', $media->id, $media->original_name ?: $media->filename) : '-';
                });
            }

            $form->select('type', '图片类型')->options(HairstyleMediaType::options())->default(HairstyleMediaType::Gallery->value)->required();
            $form->text('title', '标题')->rules('max:255');
            $form->text('alt_text', 'ALT 文本')->rules('max:255');
            $form->textarea('caption', '说明')->rows(2)->rules('max:500');
            $form->select('status', '状态')->options(HairstyleMediaStatus::options())->default(HairstyleMediaStatus::Enabled->value);
            $form->number('sort', '排序值')->min(0)->default(0);
            $form->switch('is_primary', '设为主图')->help('开启后会调用 HairstyleMediaService::setPrimaryMedia() 统一维护主图和发型封面');

            $form->saving(function (Form $form) use ($self) {
                return $form->isCreating() ? $self->handleCreating($form) : $self->handleUpdating($form);
            });

            $form->deleting(function (Form $form) use ($self) {
                return $self->handleDeleting($form);
            });
        });
    }

    /**
     * 快捷“设为主图”入口，等价于在编辑表单中把 is_primary 打开后保存，
     * 同样只调用 HairstyleMediaService::setPrimaryMedia()，不重复实现同步逻辑。
     *
     * @param  int  $id
     */
    public function setPrimary($id): RedirectResponse
    {
        /** @var HairstyleMedia|null $relation */
        $relation = HairstyleMedia::query()->find($id);

        if (! $relation) {
            admin_toastr('关联记录不存在', 'error');

            return redirect()->back();
        }

        try {
            $this->service->setPrimaryMedia((int) $relation->hairstyle_id, (int) $relation->media_id);
        } catch (\Throwable $e) {
            admin_toastr($e->getMessage(), 'error');

            return redirect()->back();
        }

        admin_toastr('已设为主图');

        return redirect()->back();
    }

    /**
     * 媒体选择字段的远程搜索接口（select2 ajax），仅返回状态可用的媒体资源。
     */
    public function mediaOptions(Request $request)
    {
        $keyword = trim((string) $request->get('q', ''));

        $query = MediaFile::query()->where('status', MediaStatus::Active->value);

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('original_name', 'like', "%{$keyword}%")
                    ->orWhere('file_no', 'like', "%{$keyword}%");
            });
        }

        $items = $query->orderByDesc('id')->limit(20)->get()->map(function (MediaFile $media) {
            return [
                'id' => $media->id,
                'text' => sprintf('#%d %s', $media->id, $media->original_name ?: $media->filename),
            ];
        });

        return response()->json($items);
    }

    /**
     * 新建关联：调用 HairstyleMediaService::attachMedia()，短路 Dcat 默认的 store() 流程。
     */
    private function handleCreating(Form $form): JsonResponse
    {
        $hairstyleId = (int) $form->input('hairstyle_id');
        $mediaId = (int) $form->input('media_id');

        if (! $hairstyleId || ! $mediaId) {
            return JsonResponse::make()->error('缺少发型或媒体信息');
        }

        try {
            $this->service->attachMedia($hairstyleId, $mediaId, $this->extractAttributes($form));
        } catch (ValidationException $e) {
            return JsonResponse::make()->error($this->firstValidationMessage($e));
        } catch (\Throwable $e) {
            return JsonResponse::make()->error('关联失败：'.$e->getMessage());
        }

        return JsonResponse::make()
            ->success('关联成功')
            ->redirect(admin_url('hairstyle-media?hairstyle_id='.$hairstyleId));
    }

    /**
     * 更新关联：统一调用 HairstyleMediaService::updateRelation()，
     * 该方法内部已经处理好 is_primary 变更时的主图同步/拒绝规则。
     */
    private function handleUpdating(Form $form): JsonResponse
    {
        $id = (int) $form->getKey();

        try {
            $this->service->updateRelation($id, $this->extractAttributes($form));
        } catch (ValidationException $e) {
            return JsonResponse::make()->error($this->firstValidationMessage($e));
        } catch (\Throwable $e) {
            return JsonResponse::make()->error('保存失败：'.$e->getMessage());
        }

        return JsonResponse::make()->success('保存成功')->refresh();
    }

    /**
     * 移除关联：统一调用 HairstyleMediaService::detachMedia()，兼容单个/批量删除请求。
     */
    private function handleDeleting(Form $form): ?JsonResponse
    {
        $ids = collect(explode(',', (string) $form->getKey()))
            ->filter()
            ->map(fn ($value) => (int) $value)
            ->values();

        if ($ids->isEmpty()) {
            return null;
        }

        $relations = HairstyleMedia::query()->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $relationId) {
            $relation = $relations->get($relationId);

            if (! $relation) {
                continue;
            }

            try {
                $this->service->detachMedia((int) $relation->hairstyle_id, (int) $relation->media_id);
            } catch (\Throwable $e) {
                return JsonResponse::make()->error('移除失败（ID '.$relationId.'）：'.$e->getMessage());
            }
        }

        return JsonResponse::make()->success('移除成功')->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function extractAttributes(Form $form): array
    {
        return [
            'type' => (int) $form->input('type'),
            'title' => (string) $form->input('title', ''),
            'alt_text' => (string) $form->input('alt_text', ''),
            'caption' => (string) $form->input('caption', ''),
            'status' => (int) $form->input('status'),
            'sort' => (int) $form->input('sort', 0),
            'is_primary' => (bool) $form->input('is_primary'),
        ];
    }

    private function firstValidationMessage(ValidationException $e): string
    {
        return collect($e->errors())->collapse()->first() ?? '操作失败';
    }

    /**
     * 列表“预览”列：优先展示缩略图，无媒体或非图片时展示占位文字，只读取已预加载的 media 关联。
     */
    public static function renderPreviewFor(HairstyleMedia $model): string
    {
        $media = $model->media;

        if (! $media) {
            return '-';
        }

        $src = $media->thumbnail_url ?: $media->url;

        if (! $src) {
            return '-';
        }

        return '<img src="'.e($src).'" style="width:50px;height:50px;object-fit:cover;border-radius:4px;" />';
    }

    public static function renderSetPrimaryAction(HairstyleMedia $model): string
    {
        $url = admin_url('hairstyle-media/'.$model->id.'/set-primary');
        $token = csrf_token();

        return <<<HTML
<form method="POST" action="{$url}" style="display:inline-block;margin-right:8px;" onsubmit="return confirm('确定要将该媒体设为主图吗？');">
    <input type="hidden" name="_token" value="{$token}">
    <input type="hidden" name="_method" value="PUT">
    <button type="submit" class="btn btn-link" style="padding:0;border:0;background:none;color:#f39c12;" title="设为主图"><i class="feather icon-star"></i></button>
</form>
HTML;
    }
}
