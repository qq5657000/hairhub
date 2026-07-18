<?php

namespace App\Admin\Controllers;

use App\Admin\Controllers\Concerns\ManagesCoverMedia;
use App\Admin\Renderable\MediaImageTable;
use App\Enums\Common\CommonStatus;
use App\Enums\HairColor\HairColorBleachRequirement;
use App\Enums\HairColor\HairColorBrightness;
use App\Enums\HairColor\HairColorMaintenanceLevel;
use App\Enums\HairColor\HairColorSaturation;
use App\Enums\HairColor\HairColorSuitableSkin;
use App\Enums\HairColor\HairColorTemperature;
use App\Models\HairColor;
use App\Models\HairColorCategory;
use App\Models\MediaFile;
use App\Services\HairColor\HairColorService;
use App\Services\Media\MediaFileService;
use App\Support\ColorHex;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Http\JsonResponse;
use Dcat\Admin\Layout\Content;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 发色 Dcat Admin 后台管理。
 *
 * 严格复用第一阶段已完成的 HairColorService，本控制器不重复实现分类有效性校验、
 * color_hex 标准化（转大写/格式校验）、suitable_skin 标准化（去重/all 收敛/枚举
 * 白名单）等业务逻辑：
 * - 新增/编辑统一通过 handleSaving() 调用 HairColorService::create()/update()，
 *   两者内部分别完成 color_hex 必填校验、分类有效性校验（新增分类必须启用；
 *   编辑时未修改分类只要求未删除）；color_hex 大小写标准化和 suitable_skin
 *   去重/all 收敛/排序统一由 HairColor Model 的 Mutator / Cast 完成；
 * - 软删除统一通过 handleDeleting() 调用 HairColorService::delete()；
 * - 恢复统一通过 restore() 调用 HairColorService::restore()（分类必须存在且
 *   未删除，不强制启用）。
 *
 * V1.0 只保留软删除和恢复，不提供永久删除（物理删除）能力：发色是长期数据资产，
 * 且当前无法确认所有未来业务引用，不因为存在回收站就默认开放物理删除入口
 * （对应本次 Review 结论）。
 *
 * saving()/deleting() 回调始终返回非空 JsonResponse，短路 Dcat 默认的
 * store()/update()/destroy() 持久化流程，确保“保存/删除”只有 Service 这一个
 * 真正的写入入口。
 */
class HairColorController extends AdminController
{
    use ManagesCoverMedia;

    /**
     * Grid 列表实际需要读取的字段白名单：显式列出，不通过 Schema::getColumnListing()
     * 动态查询表结构（避免每次列表请求都多一次数据库元数据查询）。
     *
     * 覆盖范围（对照 database/migrations/2026_07_17_120001_create_hair_colors_table.php）：
     * - 列表展示列：id/category_id/name/color_hex/suitable_skin/brightness/
     *   temperature/saturation/bleach_required/maintenance_level/is_recommended/
     *   status/sort/published_at/updated_at；
     * - 关联预加载所需外键：category_id（category 关联）、cover_media_id（coverMedia
     *   关联，belongsTo 依赖本表这个外键列才能正确 eager load，即使列表不直接展示
     *   该原始值，只展示 coverMedia 关联出来的缩略图）；
     * - 行操作/回收站状态判断所需：deleted_at（$model->trashed() 依赖该列，如果不
     *   选中会导致回收站场景下误判为“未删除”，隐藏/展示恢复按钮出错）；
     * - name_en/slug/seo_title/created_at 未在列表展示，也不在本白名单内——
     *   filter() 对这些字段的筛选是在查询构建阶段拼接 WHERE 条件，不要求字段本身
     *   出现在 SELECT 列表中，因此不影响筛选功能；
     * - 故意排除的大字段：description/ai_prompt/ai_negative_prompt/seo_description，
     *   详情页 detail() 和编辑表单 form() 都会重新单独查询完整模型，不受本白名单影响。
     */
    private const GRID_SELECT_COLUMNS = [
        'id',
        'category_id',
        'name',
        'color_hex',
        'suitable_skin',
        'brightness',
        'temperature',
        'saturation',
        'bleach_required',
        'maintenance_level',
        'cover_media_id',
        'status',
        'is_recommended',
        'sort',
        'published_at',
        'updated_at',
        'deleted_at',
    ];

    private HairColorService $hairColorService;

    private MediaFileService $mediaFileService;

    public function __construct(HairColorService $hairColorService, MediaFileService $mediaFileService)
    {
        $this->hairColorService = $hairColorService;
        $this->mediaFileService = $mediaFileService;
    }

    public function index(Content $content)
    {
        return $content
            ->header('发色管理')
            ->description('列表')
            ->body($this->grid());
    }

    /**
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new HairColor(), function (Grid $grid) {
            // 显式 select() 白名单（见 GRID_SELECT_COLUMNS 注释），排除大字段
            // description/ai_prompt/ai_negative_prompt/seo_description，且不再通过
            // Schema::getColumnListing() 查询数据库元数据；
            // with() 预加载 category/coverMedia，避免在下面的列 display() 中逐行触发关联查询（N+1）。
            $grid->model()
                ->select(self::GRID_SELECT_COLUMNS)
                ->with(['category', 'coverMedia'])
                ->orderByDesc('sort')
                ->orderByDesc('published_at')
                ->orderByDesc('id');

            $grid->column('id')->sortable();
            $grid->column('swatch', '色卡')->display(function () {
                return HairColorController::renderColorSwatch($this->color_hex);
            });
            $grid->column('cover', '封面图')->display(function () {
                return HairColorController::renderCoverThumb($this->coverMedia);
            });
            $grid->column('name', '发色名称');
            $grid->column('category.name', '分类')->display(function ($value) {
                return $value ?: '-';
            });
            $grid->column('color_hex', '颜色值')->display(function ($value) {
                return $value !== '' ? e($value) : '-';
            });
            $grid->column('suitable_skin', '适合肤色')->display(function () {
                return HairColorController::formatSuitableSkin($this->suitable_skin);
            });
            $grid->column('temperature', '冷暖属性')->using(HairColorTemperature::options());
            $grid->column('brightness', '明暗程度')->using(HairColorBrightness::options());
            $grid->column('saturation', '饱和度')->using(HairColorSaturation::options());
            $grid->column('bleach_required', '是否需要漂发')->using(HairColorBleachRequirement::options());
            $grid->column('maintenance_level', '维护难度')->using(HairColorMaintenanceLevel::options());
            $grid->column('is_recommended', '是否推荐')->using([0 => '否', 1 => '是']);
            $grid->column('status', '状态')->using(CommonStatus::options());
            $grid->column('sort', '排序')->sortable();
            $grid->column('published_at', '发布时间');
            $grid->column('updated_at', '更新时间');

            $grid->setActionClass(Grid\Displayers\Actions::class);

            $grid->filter(function (Grid\Filter $filter) {
                $filter->like('name', '发色名称');
                $filter->like('slug', 'Slug');
                $filter->equal('category_id', '分类')->select(HairColorController::categoryOptions());
                // suitable_skin 只做轻量筛选：使用 Dcat 内置 findInSet 过滤器对逗号分隔
                // 字符串做精确的单值包含匹配（FIND_IN_SET，避免子串误匹配），不构建
                // 复杂推荐 SQL。
                $filter->findInSet('suitable_skin', '适合肤色')->select(HairColorSuitableSkin::options());
                $filter->equal('temperature', '冷暖属性')->select(HairColorTemperature::options());
                $filter->equal('brightness', '明暗程度')->select(HairColorBrightness::options());
                $filter->equal('saturation', '饱和度')->select(HairColorSaturation::options());
                $filter->equal('bleach_required', '是否需要漂发')->select(HairColorBleachRequirement::options());
                $filter->equal('maintenance_level', '维护难度')->select(HairColorMaintenanceLevel::options());
                $filter->equal('is_recommended', '是否推荐')->select([0 => '否', 1 => '是']);
                $filter->equal('status', '状态')->select(CommonStatus::options());
                $filter->between('published_at', '发布时间')->datetime();
                $filter->between('created_at', '创建时间')->datetime();

                $filter->scope('trashed', '回收站')->onlyTrashed();
            });

            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $model = $actions->row;

                if (! $model instanceof HairColor) {
                    return;
                }

                if ($model->trashed()) {
                    $actions->disableView();
                    $actions->disableEdit();
                    $actions->disableDelete();
                    $actions->append(HairColorController::renderRestoreAction($model));
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
        /** @var HairColor $hairColor */
        $hairColor = HairColor::withTrashed()->with(['category', 'coverMedia'])->findOrFail($id);

        return Show::make($id, new HairColor(), function (Show $show) use ($hairColor) {
            $show->field('id');
            $show->field('name', '发色名称');
            $show->field('name_en', '英文名称');
            $show->field('slug', 'Slug');
            $show->field('category_name', '分类')->as(function () use ($hairColor) {
                return $hairColor->category->name ?? '-';
            });
            $show->field('color_display', '颜色值')->as(function () use ($hairColor) {
                return HairColorController::renderColorSwatch($hairColor->color_hex);
            })->unescape();
            $show->field('suitable_skin_display', '适合肤色')->as(function () use ($hairColor) {
                return HairColorController::formatSuitableSkin($hairColor->suitable_skin);
            });
            $show->field('brightness', '明暗程度')->using(HairColorBrightness::options());
            $show->field('temperature', '冷暖属性')->using(HairColorTemperature::options());
            $show->field('saturation', '饱和度')->using(HairColorSaturation::options());
            $show->field('bleach_required', '是否需要漂发')->using(HairColorBleachRequirement::options());
            $show->field('maintenance_level', '维护难度')->using(HairColorMaintenanceLevel::options());
            $show->field('description', '发色介绍');

            $show->field('cover_display', '封面')->as(function () use ($hairColor) {
                return HairColorController::renderCoverPreview($hairColor->coverMedia);
            })->unescape();

            $show->field('ai_prompt', 'AI 正向提示词');
            $show->field('ai_negative_prompt', 'AI 负向提示词');

            $show->field('seo_title', 'SEO 标题');
            $show->field('seo_description', 'SEO 描述');

            $show->field('status', '状态')->using(CommonStatus::options());
            $show->field('is_recommended', '是否推荐')->using([0 => '否', 1 => '是']);
            $show->field('sort', '排序值');
            $show->field('published_at', '发布时间');
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

        return Form::make(new HairColor(), function (Form $form) use ($self) {
            $id = $form->getKey();

            /** @var HairColor|null $hairColor */
            $hairColor = $id ? HairColor::withTrashed()->with('coverMedia')->find($id) : null;

            $form->display('id');

            $form->tab('基础信息', function (Form $form) use ($hairColor) {
                $form->select('category_id', '发色分类')
                    ->options(HairColorController::categoryFormOptions($hairColor?->category_id))
                    ->required()
                    ->help('新建时只能选择状态为“启用”的分类；编辑时如果原分类已禁用，下拉会保留并显示当前分类（标注“已禁用”），但不能切换到其它已禁用的分类。若未修改所属分类，即使原分类已禁用也可以正常保存其它字段的修改');
                $form->text('name', '发色名称')->required()->rules('max:100');
                $form->text('name_en', '英文名称')->rules('max:150');
                $form->text('slug', 'Slug')
                    ->required()
                    ->rules(['required', 'max:150', 'regex:/^[a-z0-9-]+$/'])
                    ->help('仅允许小写字母、数字和短横线，用于 SEO URL；唯一性由保存时的业务校验负责');
                $form->color('color_hex', '颜色值')
                    ->hex()
                    ->required()
                    ->rules(['required', 'regex:/^#([0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/'])
                    ->default('#000000')
                    ->help('支持颜色选择器或手工输入十六进制颜色（如 #8B4513），保存时会统一转换为大写；新增时必填');
                $form->textarea('description', '发色介绍')->rows(4);
            });

            $form->tab('适合属性', function (Form $form) {
                $form->multipleSelect('suitable_skin', '适合肤色')
                    ->options(HairColorSuitableSkin::options())
                    ->help('可多选。选择“全部肤色”后，其他肤色选项将被忽略。');
                $form->select('brightness', '明暗程度')->options(HairColorBrightness::options())->default(HairColorBrightness::Unknown->value);
                $form->select('temperature', '冷暖属性')->options(HairColorTemperature::options())->default(HairColorTemperature::Neutral->value);
                $form->select('saturation', '饱和度')->options(HairColorSaturation::options())->default(HairColorSaturation::Unknown->value);
                $form->select('bleach_required', '是否需要漂发')->options(HairColorBleachRequirement::options())->default(HairColorBleachRequirement::No->value);
                $form->select('maintenance_level', '维护难度')->options(HairColorMaintenanceLevel::options())->default(HairColorMaintenanceLevel::Unknown->value);
            });

            $form->tab('AI 配置', function (Form $form) {
                $form->textarea('ai_prompt', 'AI 正向提示词')->rows(4)->help('仅存储提示词文本，本功能不会调用任何 AI 模型');
                $form->textarea('ai_negative_prompt', 'AI 负向提示词')->rows(4);
            });

            $form->tab('封面与媒体', function (Form $form) use ($hairColor) {
                $form->html(
                    HairColorController::renderCoverPreview(
                        $hairColor?->coverMedia,
                        '新建后将使用本次上传或选择的图片作为封面；如果都不设置，保存后可在编辑页补充'
                    ),
                    '当前封面'
                );

                $form->image('cover_upload', '上传新封面')
                    ->url('hair-colors/cover-upload')
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

            $form->tab('SEO 设置', function (Form $form) {
                $form->text('seo_title', 'SEO 标题')->rules('max:255')->help('留空允许保存，数据库长度上限 255 个字符');
                $form->textarea('seo_description', 'SEO 描述')->rows(3)->rules('max:500')->help('留空允许保存，数据库长度上限 500 个字符');
            });

            $form->tab('发布设置', function (Form $form) {
                $form->select('status', '状态')->options(CommonStatus::options())->default(CommonStatus::Enabled->value);
                $form->switch('is_recommended', '是否推荐');
                $form->number('sort', '排序值')->min(0)->default(0);
                $form->datetime('published_at', '发布时间')->help('留空表示暂未设置发布时间，不会自动取创建时间');
            });

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
     * 恢复一个已软删除的发色：调用 HairColorService::restore()（分类必须存在且
     * 未删除，不强制启用）。
     *
     * @param  int  $id
     */
    public function restore($id): RedirectResponse
    {
        try {
            $this->hairColorService->restore((int) $id);
            admin_toastr('恢复成功');
        } catch (ModelNotFoundException $e) {
            admin_toastr('发色不存在或未处于回收站中', 'error');
        } catch (ValidationException $e) {
            admin_toastr($this->firstValidationMessage($e), 'error');
        }

        return redirect(admin_url('hair-colors'));
    }

    /**
     * “上传新封面”字段的专用上传接口，逻辑完全复用 ManagesCoverMedia::handleCoverUpload()。
     */
    public function uploadCover()
    {
        return $this->handleCoverUpload($this->mediaFileService);
    }

    /**
     * 保存前的统一处理：提取表单字段，计算最终 cover_media_id，整体在一个事务内
     * 调用 HairColorService::create()/update() 完成真正的持久化，避免出现
     * “发色已保存但封面处理失败”的半成功状态。
     *
     * color_hex 必填、分类有效性、suitable_skin 标准化等业务规则完全由 Service /
     * Model 负责，本方法只做“表单输入 -> 属性数组”的搬运，不重新实现任何校验逻辑。
     *
     * 本方法始终返回非空 JsonResponse，短路 Dcat 默认的 store()/update() 流程。
     */
    private function handleSaving(Form $form): JsonResponse
    {
        $id = $form->getKey();

        $uploadMediaId = (int) request()->input('cover_upload', 0);
        $selectMediaId = (int) request()->input('cover_select_media_id', 0);
        $clearCover = (bool) request()->input('clear_cover', false);

        $suitableSkin = array_values(array_filter((array) ($form->input('suitable_skin') ?? [])));

        // 颜色选择器部分实现可能提交不带 # 前缀的十六进制值，这里只做“补齐前缀”这一项
        // 输入格式兜底，真正的大小写标准化与合法性校验统一交给 HairColor Model 的
        // Mutator（App\Support\ColorHex），不在这里重复实现正则。
        $colorHex = trim((string) $form->input('color_hex'));

        if ($colorHex !== '' && ! str_starts_with($colorHex, '#') && ColorHex::isValid('#'.strtoupper($colorHex))) {
            $colorHex = '#'.$colorHex;
        }

        $attributes = [
            'category_id' => (int) $form->input('category_id'),
            'name' => (string) $form->input('name'),
            'name_en' => (string) ($form->input('name_en') ?? ''),
            'slug' => (string) $form->input('slug'),
            'color_hex' => $colorHex,
            'suitable_skin' => $suitableSkin,
            'brightness' => (int) ($form->input('brightness') ?? HairColorBrightness::Unknown->value),
            'temperature' => (int) ($form->input('temperature') ?? HairColorTemperature::Neutral->value),
            'saturation' => (int) ($form->input('saturation') ?? HairColorSaturation::Unknown->value),
            'bleach_required' => (int) ($form->input('bleach_required') ?? HairColorBleachRequirement::No->value),
            'maintenance_level' => (int) ($form->input('maintenance_level') ?? HairColorMaintenanceLevel::Unknown->value),
            'description' => $form->input('description'),
            'ai_prompt' => $form->input('ai_prompt'),
            'ai_negative_prompt' => $form->input('ai_negative_prompt'),
            'seo_title' => (string) ($form->input('seo_title') ?? ''),
            'seo_description' => (string) ($form->input('seo_description') ?? ''),
            'status' => (int) ($form->input('status') ?? CommonStatus::Enabled->value),
            'is_recommended' => $form->input('is_recommended') ? 1 : 0,
            'sort' => (int) ($form->input('sort') ?? 0),
            'published_at' => $this->normalizeNullableInput($form->input('published_at')),
        ];

        try {
            $hairColor = DB::transaction(function () use ($id, $attributes, $uploadMediaId, $selectMediaId, $clearCover) {
                if ($id) {
                    /** @var HairColor $hairColor */
                    $hairColor = HairColor::withTrashed()->lockForUpdate()->findOrFail($id);
                    $attributes['cover_media_id'] = $this->resolveCoverMediaId(
                        $uploadMediaId,
                        $selectMediaId,
                        $clearCover,
                        (int) $hairColor->cover_media_id
                    );

                    return $this->hairColorService->update($hairColor, $attributes);
                }

                $attributes['cover_media_id'] = $this->resolveCoverMediaId($uploadMediaId, $selectMediaId, $clearCover, 0);

                return $this->hairColorService->create($attributes);
            });
        } catch (ValidationException $e) {
            return JsonResponse::make()->error($this->firstValidationMessage($e));
        } catch (ModelNotFoundException $e) {
            return JsonResponse::make()->error('发色不存在');
        } catch (\Throwable $e) {
            report($e);

            return JsonResponse::make()->error(
                config('app.debug') ? ('保存失败：'.$e->getMessage()) : '保存失败，请查看系统日志。'
            );
        }

        $response = JsonResponse::make()->success($id ? '更新成功' : '创建成功');

        return $id
            ? $response->refresh()
            : $response->redirect(admin_url('hair-colors/'.$hairColor->id.'/edit'));
    }

    /**
     * 软删除前的统一处理：调用 HairColorService::delete()。
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
            foreach ($ids as $hairColorId) {
                $this->hairColorService->delete($hairColorId);
            }
        } catch (ModelNotFoundException $e) {
            return JsonResponse::make()->error('发色不存在');
        }

        return JsonResponse::make()->success('删除成功')->refresh();
    }

    /**
     * 空字符串统一视为 null（发布时间允许为空，不自动取创建时间）。
     */
    private function normalizeNullableInput($value): ?string
    {
        return $value !== null && $value !== '' ? (string) $value : null;
    }

    /**
     * @return array<int, string>
     */
    public static function categoryOptions(): array
    {
        return HairColorCategory::query()
            ->orderByDesc('sort')
            ->orderByDesc('id')
            ->get(['id', 'name', 'status'])
            ->mapWithKeys(function (HairColorCategory $category) {
                $suffix = (int) $category->status === CommonStatus::Enabled->value ? '' : '（已禁用）';

                return [$category->id => $category->name.$suffix];
            })
            ->all();
    }

    /**
     * 发色 Form（新增/编辑）“发色分类”下拉可选项：
     * - 只包含状态为“启用”的分类（未删除，因为 HairColorCategory 默认查询已排除软删除）；
     * - 编辑时如果当前发色所属分类已被禁用，额外把这一个分类加回来（保留并展示，
     *   标注“已禁用”），保证下拉里始终能看到当前值、不会因为被过滤掉而在前端把
     *   category_id 显示为空；但不会加入除“当前分类”外的其它任何禁用分类，
     *   所以用户不能借着下拉切换到别的禁用分类；
     * - 新增场景（$currentCategoryId 为 null/0）不会额外加回任何禁用分类，
     *   即“新建时只能选择存在、未删除、启用的分类”。
     *
     * 是否真的允许保存仍由 HairColorService::create()/update() 的
     * assertCategoryValid()/assertCategoryExistsAndNotDeleted() 做最终业务校验，
     * 本方法只负责控制下拉“展示哪些可选项”，不是唯一的校验防线。
     */
    public static function categoryFormOptions(?int $currentCategoryId): array
    {
        return HairColorCategory::query()
            ->where(function ($query) use ($currentCategoryId) {
                $query->where('status', CommonStatus::Enabled->value);

                if ($currentCategoryId) {
                    $query->orWhere('id', $currentCategoryId);
                }
            })
            ->orderByDesc('sort')
            ->orderByDesc('id')
            ->get(['id', 'name', 'status'])
            ->mapWithKeys(function (HairColorCategory $category) {
                $suffix = (int) $category->status === CommonStatus::Enabled->value ? '' : '（已禁用）';

                return [$category->id => $category->name.$suffix];
            })
            ->all();
    }

    /**
     * 列表“封面图”列渲染，只读取已预加载的 coverMedia 关联，不在渲染过程中触发新查询。
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

    /**
     * 色卡渲染：小型色块 + 十六进制文本。渲染前用 App\Support\ColorHex::isValid()
     * 复核格式（不重新实现正则，只是复用已有校验结果），避免把任何非受控字符串
     * 拼接进 style 属性；色块背后固定使用 e() 转义十六进制文本本身。
     */
    public static function renderColorSwatch(string $colorHex): string
    {
        if ($colorHex === '') {
            return '<span style="color:#999;">未设置</span>';
        }

        if (! ColorHex::isValid($colorHex)) {
            // 理论上不会出现（Model Mutator 已保证落库值合法），这里仅做防御性兜底，
            // 不把该值当作可信的 CSS 值使用。
            return '<span style="color:#dc3545;">'.e($colorHex).'</span>';
        }

        $safeHex = e($colorHex);

        return '<span style="display:inline-block;width:16px;height:16px;border-radius:3px;'
            .'border:1px solid #ddd;vertical-align:middle;background-color:'.$safeHex.';"></span> '
            .'<code>'.$safeHex.'</code>';
    }

    /**
     * @param  array<int, string>  $values
     */
    public static function formatSuitableSkin(array $values): string
    {
        if ($values === []) {
            return '不限';
        }

        $labels = array_map(
            fn (string $value) => HairColorSuitableSkin::tryFrom($value)?->label() ?? $value,
            $values
        );

        return implode('、', $labels);
    }

    public static function renderRestoreAction(HairColor $model): string
    {
        $url = admin_url('hair-colors/'.$model->id.'/restore');
        $token = csrf_token();

        return <<<HTML
<form method="POST" action="{$url}" style="display:inline-block;margin:0 5px;" onsubmit="return confirm('确定要恢复该发色吗？');">
    <input type="hidden" name="_token" value="{$token}">
    <input type="hidden" name="_method" value="PUT">
    <button type="submit" class="btn btn-link" style="padding:0;border:0;background:none;color:#28a745;" title="恢复"><i class="feather icon-rotate-ccw"></i></button>
</form>
HTML;
    }
}
