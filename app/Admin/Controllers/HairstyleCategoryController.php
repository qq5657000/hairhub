<?php

namespace App\Admin\Controllers;

use App\Admin\Renderable\MediaImageTable;
use App\Enums\Hairstyle\HairstyleCategoryStatus;
use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaSourceType;
use App\Enums\Media\MediaStatus;
use App\Enums\Media\MediaVisibility;
use App\Models\Hairstyle;
use App\Models\HairstyleCategory;
use App\Models\MediaFile;
use App\Services\Media\MediaFileService;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Http\JsonResponse;
use Dcat\Admin\Layout\Content;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class HairstyleCategoryController extends AdminController
{
    /**
     * 封面上传字段（虚拟字段 cover_upload）落盘后，原始文件名的临时缓存前缀，
     * 用法与 MediaFileController::UPLOAD_META_CACHE_PREFIX 一致。
     */
    public const COVER_UPLOAD_META_CACHE_PREFIX = 'hairstyle_category_cover_upload_meta:';

    private MediaFileService $mediaFileService;

    public function __construct(MediaFileService $mediaFileService)
    {
        $this->mediaFileService = $mediaFileService;
    }

    /**
     * page index
     */
    public function index(Content $content)
    {
        return $content
            ->header('发型分类')
            ->description('列表')
            ->body($this->grid());
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new HairstyleCategory(), function (Grid $grid) {
            $grid->model()
                ->with(['parent', 'coverMedia'])
                ->orderBy('sort', 'desc')
                ->orderBy('id', 'desc');

            $grid->column('id')->sortable();
            $grid->column('name', '分类名称');
            $grid->column('name_en', '英文名称');
            $grid->column('parent_name', '父分类')->display(function () {
                if ((int) $this->parent_id === 0) {
                    return '顶级分类';
                }

                return $this->parent->name ?? '-';
            });
            $grid->column('slug');
            $grid->column('cover_media_id', '封面')
                ->display(function () {
                    return $this->coverMedia->thumbnail_url ?? $this->coverMedia->url ?? '';
                })
                ->image('', 50, 50);
            $grid->column('status')->using(HairstyleCategoryStatus::options());
            $grid->column('sort')->sortable();
            $grid->column('created_at');
            $grid->column('updated_at');

            $grid->setActionClass(Grid\Displayers\Actions::class);

            $grid->filter(function (Grid\Filter $filter) {
                $filter->like('name', '分类名称');
                $filter->like('slug', 'Slug');
                $filter->equal('status', '状态')->select(HairstyleCategoryStatus::options());
                $filter->equal('parent_id', '父分类')->select($this->parentFilterOptions());
            });
        });
    }

    /**
     * Make a show builder.
     *
     * @param mixed $id
     *
     * @return Show
     */
    protected function detail($id)
    {
        /** @var HairstyleCategory $category */
        $category = HairstyleCategory::query()->with('coverMedia')->findOrFail($id);

        return Show::make($id, new HairstyleCategory(), function (Show $show) use ($category) {
            $show->field('id');
            $show->field('parent_id', '父分类 ID');
            $show->field('name', '分类名称');
            $show->field('name_en', '英文名称');
            $show->field('slug');
            $show->field('description', '分类简介');
            $show->field('cover_display', '封面')->as(function () use ($category) {
                return HairstyleCategoryController::renderCoverPreview($category);
            })->unescape();
            $show->field('status')->using(HairstyleCategoryStatus::options());
            $show->field('sort');
            $show->field('created_at');
            $show->field('updated_at');
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        // Dcat 触发 saving() 监听器时会把闭包的 $this 重新绑定到当前表单模型，
        // 因此这里显式捕获控制器实例，saving() 闭包内一律通过 $self 调用控制器方法（含注入的 MediaFileService）。
        $self = $this;

        return Form::make(new HairstyleCategory(), function (Form $form) use ($self) {
            $id = $form->getKey();

            $form->display('id');

            $form->select('parent_id', '父级分类')
                ->options($this->parentFormOptions($id))
                ->default(0)
                ->help('顶级分类请选择“顶级分类”');

            $form->text('name', '分类名称')->required()->rules('max:100');
            $form->text('name_en', '英文名称')->rules('max:150');
            $form->text('slug', 'Slug')
                ->required()
                ->rules('max:150|unique:hairstyle_categories,slug,{{id}}')
                ->help('仅建议使用小写字母、数字和短横线，用于 SEO URL');
            $form->textarea('description', '分类简介')->rows(4)->rules('max:500');

            /** @var HairstyleCategory|null $category */
            $category = $id ? HairstyleCategory::query()->with('coverMedia')->find($id) : null;

            $form->html(HairstyleCategoryController::renderCoverPreview($category), '当前封面');

            $form->image('cover_upload', '上传新封面')
                ->disk('public')
                ->dir(function () {
                    return 'media/'.now()->format('Y/m/d');
                })
                ->name(function (UploadedFile $file) {
                    $storedName = md5(uniqid('', true)).'.'.strtolower($file->getClientOriginalExtension() ?: 'jpg');

                    Cache::put(
                        HairstyleCategoryController::COVER_UPLOAD_META_CACHE_PREFIX.$storedName,
                        ['original_name' => $file->getClientOriginalName()],
                        now()->addMinutes(30)
                    );

                    return $storedName;
                })
                ->accept('jpg,jpeg,png,webp')
                ->rules('mimes:jpg,jpeg,png,webp|mimetypes:image/jpeg,image/png,image/webp|max:'.MediaFileService::MAX_UPLOAD_SIZE_KB)
                ->help('上传新文件优先级最高；留空表示不更换封面');

            $form->selectTable('cover_select_media_id', '从媒体库选择封面')
                ->title('选择封面媒体')
                ->dialogWidth('60%')
                ->from(MediaImageTable::make())
                ->pluck('original_name', 'id')
                ->help('仅可选择状态为“启用”的图片类型媒体；未上传新文件时才会生效');

            $form->switch('clear_cover', '清除封面')
                ->help('开启后，若同时没有上传新文件或选择新媒体，将清除当前封面');

            $form->radio('status', '状态')
                ->options(HairstyleCategoryStatus::options())
                ->default(HairstyleCategoryStatus::Enabled->value);

            $form->number('sort', '排序值')->min(0)->default(0);

            // 必须保留一个真正绑定 cover_media_id 列的 Field（哪怕是 hidden），
            // 否则 Dcat Form::prepareInsert()/prepareUpdate() 在处理 $this->updates 时，
            // 会对每个 column 调用 $this->field($column)：找不到对应 Field 的列会被
            // 直接 unset 而不会进入最终的 INSERT/UPDATE —— 也就是说，即使下面 saving()
            // 回调里执行了 $form->cover_media_id = $mediaId，只要没有这个 hidden 字段，
            // cover_media_id 永远不会被真正保存（本次问题排查中用真实 Form::store() 复现确认）。
            // 这里不设置任何默认值/不放进 ignore()，其提交值也无关紧要：真正写入的值
            // 始终由下面 saving() -> applyCoverChange() 计算后通过 $form->cover_media_id = ... 覆盖。
            $form->hidden('cover_media_id');

            $form->ignore(['cover_upload', 'cover_select_media_id', 'clear_cover']);

            $form->saving(function (Form $form) use ($self, $id) {
                if ($id) {
                    $parentId = (int) $form->parent_id;

                    if ($parentId === (int) $id) {
                        return JsonResponse::make()->error('父分类不能设置为自身');
                    }

                    $descendantIds = HairstyleCategory::query()->where('parent_id', $id)->pluck('id')->all();

                    if ($parentId && in_array($parentId, $descendantIds, true)) {
                        return JsonResponse::make()->error('父分类不能设置为自身的子分类');
                    }
                }

                return $self->applyCoverChange($form, $id);
            });

            $form->deleting(function (Form $form) {
                $ids = collect(explode(',', (string) $form->getKey()))
                    ->filter()
                    ->map(fn ($value) => (int) $value)
                    ->values();

                if ($ids->isEmpty()) {
                    return null;
                }

                if (HairstyleCategory::query()->whereIn('parent_id', $ids)->exists()) {
                    return JsonResponse::make()->error('存在子分类，请先删除或转移子分类后再操作');
                }

                if (Hairstyle::query()->whereIn('category_id', $ids)->exists()) {
                    return JsonResponse::make()->error('该分类下存在未删除的发型，请先处理发型数据后再操作');
                }

                return null;
            });
        });
    }

    /**
     * 父分类下拉选项（用于列表筛选，展示全部分类）.
     *
     * @return array<int, string>
     */
    protected function parentFilterOptions(): array
    {
        $options = HairstyleCategory::query()
            ->orderBy('sort', 'desc')
            ->orderBy('id', 'desc')
            ->pluck('name', 'id')
            ->all();

        return [0 => '顶级分类'] + $options;
    }

    /**
     * 父分类下拉选项（用于表单，编辑时排除自身与自身的子分类）.
     *
     * @param  int|null  $id
     * @return array<int, string>
     */
    protected function parentFormOptions(?int $id): array
    {
        $query = HairstyleCategory::query()
            ->orderBy('sort', 'desc')
            ->orderBy('id', 'desc');

        if ($id) {
            $childIds = HairstyleCategory::query()->where('parent_id', $id)->pluck('id')->all();

            $query->whereNotIn('id', array_merge([$id], $childIds));
        }

        return [0 => '顶级分类'] + $query->pluck('name', 'id')->all();
    }

    /**
     * 应用封面变更：按“上传新文件 > 库内选择 > 清除 > 保留原值”的优先级计算出最终
     * cover_media_id，并通过 $form->cover_media_id 注入（表单不暴露可直接提交的
     * cover_media_id 输入框，避免越权覆盖）。上传/选择的媒体不合法时整体中止保存。
     *
     * @param  int|string|null  $id
     */
    public function applyCoverChange(Form $form, $id): ?JsonResponse
    {
        $currentCoverMediaId = $id
            ? (int) (HairstyleCategory::query()->find($id)?->cover_media_id ?? 0)
            : 0;

        // 注意：cover_upload / cover_select_media_id / clear_cover 都在 $form->ignore([...]) 名单里，
        // 而 Dcat Form::prepare() 会在触发 saving() 回调之前就先执行 removeIgnoredFields()，
        // 把这些虚拟字段从 $this->inputs 中删除——也就是说，saving() 回调执行时，
        // 无论 $form->input() 怎么调用都读不到这三个字段的真实提交值（不仅是默认值参数用法的问题）。
        // 必须改为直接读取底层 Illuminate\Http\Request（request() 助手，与 Form 内部持有的是
        // 同一个单例对象，不受 Dcat removeIgnoredFields() 影响），且 Request::input($key,$default)
        // 才是真正安全的"取值加默认值"语义。
        $uploadPath = (string) request()->input('cover_upload', '');
        $selectMediaId = (int) request()->input('cover_select_media_id', 0);
        $clearCover = (bool) request()->input('clear_cover', false);

        try {
            $mediaId = $this->resolveCoverMediaId($uploadPath, $selectMediaId, $clearCover, $currentCoverMediaId);
        } catch (ValidationException $e) {
            return JsonResponse::make()->error($this->firstValidationMessage($e));
        } catch (\Throwable $e) {
            return JsonResponse::make()->error('封面处理失败：'.$e->getMessage());
        }

        $form->cover_media_id = $mediaId;

        return null;
    }

    /**
     * 结合纯决策函数 decideCoverAction() 与实际 I/O（登记上传文件 / 校验库内媒体），
     * 计算出最终应写入 cover_media_id 的值；不依赖 Dcat Form 对象，便于直接单测。
     *
     * @throws ValidationException 上传/选择的媒体不合法
     */
    public function resolveCoverMediaId(string $uploadPath, int $selectMediaId, bool $clearCover, int $currentCoverMediaId): int
    {
        $decision = self::decideCoverAction($uploadPath, $selectMediaId, $clearCover, $currentCoverMediaId);

        return match ($decision['action']) {
            'upload' => $this->registerCoverUpload($uploadPath)->id,
            'select' => $this->assertImageMedia($selectMediaId)->id,
            default => $decision['media_id'],
        };
    }

    /**
     * 纯决策函数（不做任何 I/O），根据“上传新文件 > 库内选择 > 清除 > 保留原值”的优先级
     * 判断本次保存应采取的封面动作，便于直接单测覆盖优先级规则。
     *
     * @return array{action: string, media_id: int|null} action 为 upload/select/clear/keep 之一；
     *                                                     media_id 仅在 select/clear/keep 时确定，upload 需要先落库才能得到
     */
    public static function decideCoverAction(string $uploadPath, int $selectMediaId, bool $clearCover, int $currentCoverMediaId): array
    {
        if ($uploadPath !== '') {
            return ['action' => 'upload', 'media_id' => null];
        }

        if ($selectMediaId > 0) {
            return ['action' => 'select', 'media_id' => $selectMediaId];
        }

        if ($clearCover) {
            return ['action' => 'clear', 'media_id' => 0];
        }

        return ['action' => 'keep', 'media_id' => $currentCoverMediaId];
    }

    /**
     * 将封面上传字段已经落盘的文件登记为 media_files 记录，并校验确实是图片类型。
     *
     * @throws ValidationException
     */
    private function registerCoverUpload(string $path): MediaFile
    {
        $basename = basename($path);
        $meta = Cache::pull(self::COVER_UPLOAD_META_CACHE_PREFIX.$basename, []);

        $media = $this->mediaFileService->storeUploadedFile('public', $path, [
            'original_name' => $meta['original_name'] ?? '',
            'source_type' => MediaSourceType::AdminUpload->value,
            'visibility' => MediaVisibility::Public->value,
            'status' => MediaStatus::Active->value,
        ]);

        if ((int) $media->file_type !== MediaFileType::Image->value) {
            throw ValidationException::withMessages([
                'cover_upload' => ['封面只能上传图片文件'],
            ]);
        }

        return $media;
    }

    /**
     * 校验从媒体库选择的媒体存在、为图片类型且状态可用。
     *
     * @throws ValidationException
     */
    private function assertImageMedia(int $mediaId): MediaFile
    {
        /** @var MediaFile|null $media */
        $media = MediaFile::query()->find($mediaId);

        if (! $media) {
            throw ValidationException::withMessages([
                'cover_select_media_id' => ['所选媒体不存在'],
            ]);
        }

        if ((int) $media->file_type !== MediaFileType::Image->value) {
            throw ValidationException::withMessages([
                'cover_select_media_id' => ['封面只能选择图片类型的媒体'],
            ]);
        }

        if ((int) $media->status !== MediaStatus::Active->value) {
            throw ValidationException::withMessages([
                'cover_select_media_id' => ['封面只能选择状态为启用的媒体'],
            ]);
        }

        return $media;
    }

    private function firstValidationMessage(ValidationException $e): string
    {
        return collect($e->errors())->collapse()->first() ?? '操作失败';
    }

    /**
     * 封面预览：缩略图 + 媒体 ID + 原始文件名，无封面时给出明确提示（cover_media_id = 0）。
     */
    public static function renderCoverPreview(?HairstyleCategory $category): string
    {
        $cover = $category?->coverMedia;

        if (! $cover) {
            return '<div class="alert alert-warning" style="margin-bottom:0;">暂未设置封面</div>';
        }

        $src = $cover->thumbnail_url ?: $cover->url;
        $img = $src ? '<img src="'.e($src).'" style="max-width:120px;max-height:120px;border-radius:4px;" />' : '-';

        return $img
            .'<div style="margin-top:6px;color:#666;">媒体 ID：#'.$cover->id.'　原始文件名：'.e($cover->original_name ?: $cover->filename).'</div>';
    }
}
