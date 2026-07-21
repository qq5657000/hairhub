<?php

namespace App\Admin\Controllers;

use App\Admin\Controllers\Concerns\ManagesCoverMedia;
use App\Admin\Renderable\MediaImageTable;
use App\Enums\Common\CommonStatus;
use App\Enums\Media\MediaSourceType;
use App\Enums\Media\MediaStatus;
use App\Enums\Media\MediaVisibility;
use App\Models\ArticleCategory;
use App\Models\MediaFile;
use App\Services\Content\ArticleCategoryService;
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
 * 文章分类 Dcat Admin 后台管理。
 *
 * 严格复用第一阶段已完成的 ArticleCategoryService，并复用发色分类模块已验证
 * 可用的封面处理基础设施（ManagesCoverMedia trait + MediaImageTable 选择弹窗），
 * 不重复实现分类层级约束、slug 唯一性、删除前引用检查等业务规则：
 * - 新增/编辑统一通过 handleSaving() 调用 ArticleCategoryService::create()/update()；
 * - 软删除统一通过 handleDeleting() 调用 ArticleCategoryService::delete()；
 * - 恢复统一通过 restore() 调用 ArticleCategoryService::restore()。
 *
 * V1.0 只保留软删除和恢复，不提供永久删除（物理删除）能力，与发型/发色分类模块
 * 保持一致的克制策略。
 *
 * saving()/deleting() 回调始终返回非空 JsonResponse，短路 Dcat 默认的
 * store()/update()/destroy() 持久化流程，确保“保存/删除”只有 Service 这一个
 * 真正的写入入口。
 */
class ArticleCategoryController extends AdminController
{
    use ManagesCoverMedia;

    private ArticleCategoryService $categoryService;

    private MediaFileService $mediaFileService;

    public function __construct(ArticleCategoryService $categoryService, MediaFileService $mediaFileService)
    {
        $this->categoryService = $categoryService;
        $this->mediaFileService = $mediaFileService;
    }

    public function index(Content $content)
    {
        return $content
            ->header('文章分类')
            ->description('列表')
            ->body($this->grid());
    }

    /**
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new ArticleCategory(), function (Grid $grid) {
            // with() 预加载 parent/coverMedia，withCount() 统计子分类数量和文章数量，
            // 避免在下面的列 display() 中逐行触发关联查询（N+1）。
            $grid->model()
                ->with(['parent', 'coverMedia'])
                ->withCount(['children', 'articles'])
                ->orderByDesc('sort')
                ->orderByDesc('id');

            $grid->column('id')->sortable();
            $grid->column('name', '分类名称');
            $grid->column('parent_name', '父级分类')->display(function () {
                if ((int) $this->parent_id === 0) {
                    return '顶级分类';
                }

                return $this->parent->name ?? '-';
            });
            $grid->column('children_count', '子分类数量')->sortable();
            $grid->column('articles_count', '文章数量')->sortable();
            $grid->column('cover', '封面')->display(function () {
                return ArticleCategoryController::renderCoverThumb($this->coverMedia);
            });
            $grid->column('status', '状态')->using(CommonStatus::options())->label([
                CommonStatus::Disabled->value => 'default',
                CommonStatus::Enabled->value => 'success',
            ]);
            $grid->column('sort', '排序')->sortable();
            $grid->column('created_at', '创建时间');
            $grid->column('updated_at', '更新时间');

            $grid->setActionClass(Grid\Displayers\Actions::class);

            $grid->filter(function (Grid\Filter $filter) {
                $filter->like('name', '分类名称');
                $filter->equal('parent_id', '父级分类')->select(ArticleCategoryController::parentFilterOptions());
                $filter->equal('status', '状态')->select(CommonStatus::options());
                $filter->between('created_at', '创建时间')->datetime();

                // 回收站 scope：默认列表因 SoftDeletes 全局作用域已自动排除已删除记录，
                // 切到该 scope 时改为只查询 deleted_at 不为空的记录，用于恢复。
                $filter->scope('trashed', '回收站')->onlyTrashed();
            });

            // 已软删除的记录：隐藏编辑/查看/删除，改为“恢复”。V1.0 不提供永久删除入口。
            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $model = $actions->row;

                if (! $model instanceof ArticleCategory) {
                    return;
                }

                if ($model->trashed()) {
                    $actions->disableView();
                    $actions->disableEdit();
                    $actions->disableDelete();
                    $actions->append(ArticleCategoryController::renderRestoreAction($model));
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
        /** @var ArticleCategory $category */
        $category = ArticleCategory::withTrashed()->with(['parent', 'coverMedia'])->findOrFail($id);

        return Show::make($id, new ArticleCategory(), function (Show $show) use ($category) {
            $show->field('id');
            $show->field('parent_name', '父级分类')->as(function () use ($category) {
                return (int) $category->parent_id === 0 ? '顶级分类' : ($category->parent->name ?? '-');
            });
            $show->field('name', '分类名称');
            $show->field('name_en', '英文名称');
            $show->field('slug', 'Slug');
            $show->field('description', '分类简介');
            $show->field('cover_display', '封面')->as(function () use ($category) {
                return ArticleCategoryController::renderCoverPreview($category->coverMedia);
            })->unescape();
            $show->field('status', '状态')->using(CommonStatus::options());
            $show->field('sort', '排序');
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
        // Dcat 触发 saving()/deleting() 监听器时会把闭包的 $this 重新绑定到当前表单模型，
        // 因此这里显式捕获控制器实例，闭包内一律通过 $self 调用控制器方法（含注入的 Service）。
        $self = $this;

        return Form::make(new ArticleCategory(), function (Form $form) use ($self) {
            $id = $form->getKey();

            $form->display('id');

            $form->select('parent_id', '父级分类')
                ->options(ArticleCategoryController::parentFormOptions($id))
                ->default(0)
                ->help('只允许选择顶级分类作为父级；顶级分类请选择“顶级分类”，V1.0 最多支持两级分类，编辑时不能选择自身');

            $form->text('name', '分类名称')->required()->rules('max:100');
            $form->text('name_en', '英文名称')->rules('max:150');
            $form->text('slug', 'Slug')
                ->required()
                ->rules(['required', 'max:150', 'regex:/^[a-z0-9-]+$/'])
                ->help('仅允许小写字母、数字和短横线，用于 SEO URL；唯一性由保存时的业务校验负责');
            $form->textarea('description', '分类简介')->rows(4)->rules('max:500');

            /** @var ArticleCategory|null $category */
            $category = $id ? ArticleCategory::withTrashed()->with('coverMedia')->find($id) : null;

            $form->html(ArticleCategoryController::renderCoverPreview($category?->coverMedia), '当前封面');

            $form->image('cover_upload', '上传新封面')
                ->url('article-categories/cover-upload')
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

            $form->radio('status', '状态')
                ->options(CommonStatus::options())
                ->default(CommonStatus::Enabled->value);

            $form->number('sort', '排序值')->min(0)->default(0);

            $form->ignore(['cover_upload', 'cover_select_media_id', 'clear_cover']);

            $form->saving(function (Form $form) use ($self) {
                return $self->handleSaving($form);
            });

            $form->deleting(function (Form $form) use ($self) {
                return $self->handleDeleting($form);
            });
        });
    }

    /**
     * 恢复一个已软删除的分类：调用 ArticleCategoryService::restore()，
     * 不自动恢复子分类（与 Service 已确定的规则一致）。
     *
     * @param  int  $id
     */
    public function restore($id): RedirectResponse
    {
        try {
            $this->categoryService->restore((int) $id);
            admin_toastr('恢复成功');
        } catch (ModelNotFoundException $e) {
            admin_toastr('分类不存在或未处于回收站中', 'error');
        } catch (ValidationException $e) {
            admin_toastr($this->firstValidationMessage($e), 'error');
        }

        return redirect(admin_url('article-categories'));
    }

    /**
     * “上传新封面”字段的专用上传接口。不复用 ManagesCoverMedia::handleCoverUpload()
     * 是因为该方法内部硬编码了 MediaSourceType::HairColor（历史上从发色模块抽取），
     * 这里改为正确标注 MediaSourceType::Article，其余校验/返回格式完全一致。
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
                'source_type' => MediaSourceType::Article->value,
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
     * 保存前的统一处理：提取表单字段，计算最终 cover_media_id，调用
     * ArticleCategoryService::create()/update() 完成真正的持久化。
     *
     * 本方法始终返回非空 JsonResponse，短路 Dcat 默认的 store()/update() 流程。
     */
    private function handleSaving(Form $form): JsonResponse
    {
        $id = $form->getKey();

        // 注意：cover_upload / cover_select_media_id / clear_cover 都在下方 $form->ignore([...])
        // 名单里，Dcat Form::prepare() 会在触发 saving() 回调之前先执行 removeIgnoredFields()，
        // 把这些虚拟字段从 $this->inputs 中删除，因此必须直接读取底层 Request 才能拿到真实提交值。
        $uploadMediaId = (int) request()->input('cover_upload', 0);
        $selectMediaId = (int) request()->input('cover_select_media_id', 0);
        $clearCover = (bool) request()->input('clear_cover', false);

        $attributes = [
            'parent_id' => (int) ($form->input('parent_id') ?? 0),
            'name' => (string) $form->input('name'),
            'name_en' => (string) ($form->input('name_en') ?? ''),
            'slug' => (string) $form->input('slug'),
            'description' => $form->input('description'),
            'status' => (int) ($form->input('status') ?? CommonStatus::Enabled->value),
            'sort' => (int) ($form->input('sort') ?? 0),
        ];

        try {
            $category = DB::transaction(function () use ($id, $attributes, $uploadMediaId, $selectMediaId, $clearCover) {
                if ($id) {
                    /** @var ArticleCategory $category */
                    $category = ArticleCategory::withTrashed()->lockForUpdate()->findOrFail($id);
                    $attributes['cover_media_id'] = $this->resolveCoverMediaId(
                        $uploadMediaId,
                        $selectMediaId,
                        $clearCover,
                        (int) $category->cover_media_id
                    );

                    return $this->categoryService->update($category, $attributes);
                }

                $attributes['cover_media_id'] = $this->resolveCoverMediaId($uploadMediaId, $selectMediaId, $clearCover, 0);

                return $this->categoryService->create($attributes);
            });
        } catch (ValidationException $e) {
            return JsonResponse::make()->error($this->firstValidationMessage($e));
        } catch (ModelNotFoundException $e) {
            return JsonResponse::make()->error('分类不存在');
        } catch (\Throwable $e) {
            report($e);

            return JsonResponse::make()->error(
                config('app.debug') ? ('保存失败：'.$e->getMessage()) : '保存失败，请查看系统日志。'
            );
        }

        $response = JsonResponse::make()->success($id ? '更新成功' : '创建成功');

        return $id
            ? $response->refresh()
            : $response->redirect(admin_url('article-categories/'.$category->id.'/edit'));
    }

    /**
     * 软删除前的统一处理：调用 ArticleCategoryService::delete()，删除前的
     * 子分类/文章引用检查完全在 Service 内完成，不在本控制器重新实现。
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
            foreach ($ids as $categoryId) {
                $this->categoryService->delete($categoryId);
            }
        } catch (ValidationException $e) {
            return JsonResponse::make()->error($this->firstValidationMessage($e));
        } catch (ModelNotFoundException $e) {
            return JsonResponse::make()->error('分类不存在');
        }

        return JsonResponse::make()->success('删除成功')->refresh();
    }

    /**
     * 父级分类下拉选项（用于列表筛选，展示全部顶级分类）.
     *
     * @return array<int, string>
     */
    public static function parentFilterOptions(): array
    {
        $options = ArticleCategory::query()
            ->where('parent_id', 0)
            ->orderByDesc('sort')
            ->orderByDesc('id')
            ->pluck('name', 'id')
            ->all();

        return [0 => '顶级分类'] + $options;
    }

    /**
     * 父级分类下拉选项（用于表单）：
     * - 只允许选择顶级分类（parent_id = 0），从根本上避免选出二级分类形成第三级；
     * - 展示全部顶级分类（含已禁用），与 ArticleCategoryService::assertParentValid()
     *   的实际校验范围一致（该 Service 不要求父级必须启用，只要求未删除），
     *   Controller 下拉不应比 Service 的真实约束更严格；
     * - 编辑时排除自身，避免把自己设置为自己的父级形成循环。
     *
     * @param  int|null  $id
     * @return array<int, string>
     */
    public static function parentFormOptions(?int $id): array
    {
        $query = ArticleCategory::query()
            ->where('parent_id', 0)
            ->orderByDesc('sort')
            ->orderByDesc('id');

        if ($id) {
            $query->where('id', '!=', $id);
        }

        $options = $query->get(['id', 'name', 'status'])
            ->mapWithKeys(function (ArticleCategory $category) {
                $suffix = (int) $category->status === CommonStatus::Enabled->value ? '' : '（已禁用）';

                return [$category->id => $category->name.$suffix];
            })
            ->all();

        return [0 => '顶级分类'] + $options;
    }

    /**
     * 供 ArticleController 表单“分类”下拉使用：展示全部分类（含子分类，已禁用分类
     * 标注“已禁用”后仍展示，不强制过滤），与 ArticleService::assertCategoryExistsAndNotDeleted()
     * 的真实校验范围（只要求分类存在且未删除，不要求已启用）保持一致——Controller 下拉
     * 不应比 Service 的真实约束更严格。真正的“发布前必须分类启用”校验在
     * ArticleService::assertPublishable() 内完成，不在本下拉重复实现。
     *
     * @return array<int, string>
     */
    public static function articleFormOptions(): array
    {
        return ArticleCategory::query()
            ->orderBy('parent_id')
            ->orderByDesc('sort')
            ->orderByDesc('id')
            ->get(['id', 'name', 'parent_id', 'status'])
            ->mapWithKeys(function (ArticleCategory $category) {
                $prefix = (int) $category->parent_id !== 0 ? '　└ ' : '';
                $suffix = (int) $category->status === CommonStatus::Enabled->value ? '' : '（已禁用）';

                return [$category->id => $prefix.$category->name.$suffix];
            })
            ->all();
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

    public static function renderRestoreAction(ArticleCategory $model): string
    {
        $url = admin_url('article-categories/'.$model->id.'/restore');
        $token = csrf_token();

        return <<<HTML
<form method="POST" action="{$url}" style="display:inline-block;margin:0 5px;" onsubmit="return confirm('确定要恢复该分类吗？');">
    <input type="hidden" name="_token" value="{$token}">
    <input type="hidden" name="_method" value="PUT">
    <button type="submit" class="btn btn-link" style="padding:0;border:0;background:none;color:#28a745;" title="恢复"><i class="feather icon-rotate-ccw"></i></button>
</form>
HTML;
    }
}
