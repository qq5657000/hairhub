<?php

namespace App\Admin\Controllers;

use App\Admin\Renderable\MediaImageTable;
use App\Enums\Hairstyle\FaceShape;
use App\Enums\Hairstyle\HairstyleAgeRange;
use App\Enums\Hairstyle\HairstyleGender;
use App\Enums\Hairstyle\HairstyleHairLength;
use App\Enums\Hairstyle\HairstyleHairType;
use App\Enums\Hairstyle\HairstyleHairVolume;
use App\Enums\Hairstyle\HairstyleMaintenanceLevel;
use App\Enums\Hairstyle\HairstyleMediaStatus;
use App\Enums\Hairstyle\HairstyleMediaType;
use App\Enums\Hairstyle\HairstyleStatus;
use App\Enums\Hairstyle\HairstyleStyleType;
use App\Enums\Hairstyle\SuitableScene;
use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaSourceType;
use App\Enums\Media\MediaStatus;
use App\Enums\Media\MediaVisibility;
use App\Models\Hairstyle;
use App\Models\HairstyleCategory;
use App\Models\HairstyleMedia;
use App\Models\HairstyleTag;
use App\Models\HairstyleTagRelation;
use App\Models\MediaFile;
use App\Services\Hairstyle\HairstyleMediaService;
use App\Services\Media\MediaFileService;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Http\JsonResponse;
use Dcat\Admin\Layout\Content;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * 发型管理后台 CRUD。
 *
 * 严格复用已完成的基础设施，本控制器不重复实现以下逻辑：
 * - 主图切换 / cover_media_id 同步：完全交给 HairstyleMediaService（见 HairstyleMediaController）；
 * - 标签、分类、媒体资源的独立管理：分别复用 HairstyleTagController / HairstyleCategoryController / MediaFileController。
 *
 * 表单保存采用“自定义事务保存”模式（saving() 内完成基础字段保存 + 标签 sync()，
 * 整体包一层 DB::transaction，避免出现“发型已保存但标签未同步”的半成功状态），
 * 因此不使用 Dcat 默认的 store()/update() 流程，也不需要 saved() 钩子。
 */
class HairstyleController extends AdminController
{
    /**
     * 封面上传字段（虚拟字段 cover_upload）落盘后，原始文件名的临时缓存前缀，
     * 用法与 MediaFileController::UPLOAD_META_CACHE_PREFIX 一致：File/Image 字段的
     * 异步预上传拿不到原始文件名，落盘时先缓存，主表单提交时再取出使用。
     */
    public const COVER_UPLOAD_META_CACHE_PREFIX = 'hairstyle_cover_upload_meta:';

    private HairstyleMediaService $mediaService;

    private MediaFileService $mediaFileService;

    public function __construct(HairstyleMediaService $mediaService, MediaFileService $mediaFileService)
    {
        $this->mediaService = $mediaService;
        $this->mediaFileService = $mediaFileService;
    }

    /**
     * page index
     */
    public function index(Content $content)
    {
        return $content
            ->header('发型管理')
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
        return Grid::make(new Hairstyle(), function (Grid $grid) {
            // with() 预加载 category / coverMedia，withCount() 统计标签数和媒体数，
            // 避免在下面的列 display() 中逐行触发关联查询（N+1）。
            $grid->model()
                ->with(['category', 'coverMedia'])
                ->withCount(['tagRelations', 'mediaRelations'])
                ->orderByDesc('sort')
                ->orderByDesc('published_at')
                ->orderByDesc('id');

            $grid->column('id')->sortable();
            $grid->column('cover', '封面')->display(function () {
                return HairstyleController::renderCoverFor($this);
            });
            $grid->column('name', '发型名称');
            $grid->column('category.name', '主分类')->display(function ($value) {
                return $value ?: '-';
            });
            $grid->column('gender', '适合性别')->using(HairstyleGender::options());
            $grid->column('age_range', '年龄段')->using(HairstyleAgeRange::options());
            $grid->column('style_type', '风格')->using(HairstyleStyleType::options());
            $grid->column('hair_length', '头发长度')->using(HairstyleHairLength::options());
            $grid->column('is_recommended', '推荐状态')->using([0 => '否', 1 => '是']);
            $grid->column('status', '发布状态')->using(HairstyleStatus::options());
            $grid->column('tag_relations_count', '标签数')->sortable();
            $grid->column('media_relations_count', '媒体数')->sortable();
            $grid->column('sort', '排序')->sortable();
            $grid->column('published_at', '发布时间');
            $grid->column('created_at', '创建时间');

            $grid->setActionClass(Grid\Displayers\Actions::class);

            $grid->filter(function (Grid\Filter $filter) {
                $filter->like('name', '发型名称');
                $filter->like('slug', 'Slug');
                $filter->equal('category_id', '主分类')->select(HairstyleController::categoryOptions());
                $filter->equal('gender', '适合性别')->select(HairstyleGender::options());
                $filter->equal('age_range', '适合年龄段')->select(HairstyleAgeRange::options());
                $filter->equal('style_type', '风格')->select(HairstyleStyleType::options());
                $filter->equal('hair_length', '头发长度')->select(HairstyleHairLength::options());
                $filter->equal('status', '发布状态')->select(HairstyleStatus::options());
                $filter->equal('is_recommended', '是否推荐')->select([0 => '否', 1 => '是']);
                $filter->between('published_at', '发布时间')->datetime();

                // 回收站 scope：默认列表因 SoftDeletes 全局作用域已自动排除已删除记录，
                // 切到该 scope 时改为只查询 deleted_at 不为空的记录，用于恢复/永久删除。
                $filter->scope('trashed', '回收站')->onlyTrashed();
            });

            // 已软删除的记录：隐藏编辑/查看/删除，改为“恢复”“永久删除”；
            // 未删除的记录：追加“媒体管理”入口，跳转到 HairstyleMediaController。
            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $model = $actions->row;

                if (! $model instanceof Hairstyle) {
                    return;
                }

                if ($model->trashed()) {
                    $actions->disableView();
                    $actions->disableEdit();
                    $actions->disableDelete();
                    $actions->append(HairstyleController::renderRestoreAction($model));
                    $actions->append(HairstyleController::renderForceDeleteAction($model));
                } else {
                    $actions->prepend(HairstyleController::renderMediaManageAction($model));
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
        // 详情页需要展示分类、封面、标签列表、媒体列表，这里显式一次性 with() 预加载，
        // 避免在下面的标签/媒体展示中逐条查询（tags 与 mediaRelations.media 各只有一次查询）。
        /** @var Hairstyle $hairstyle */
        $hairstyle = Hairstyle::withTrashed()
            ->with(['category', 'coverMedia', 'tags', 'mediaRelations.media'])
            ->findOrFail($id);

        return Show::make($id, new Hairstyle(), function (Show $show) use ($hairstyle) {
            $show->field('id');
            $show->field('name', '发型名称');
            $show->field('name_en', '英文名称');
            $show->field('slug', 'Slug');
            $show->field('category_name', '主分类')->as(function () use ($hairstyle) {
                return $hairstyle->category->name ?? '-';
            });
            $show->field('description', '发型介绍');

            $show->field('gender', '适合性别')->using(HairstyleGender::options());
            $show->field('age_range', '适合年龄段')->using(HairstyleAgeRange::options());
            $show->field('style_type', '风格类型')->using(HairstyleStyleType::options());
            $show->field('hair_length', '头发长度')->using(HairstyleHairLength::options());
            $show->field('hair_type', '发质类型')->using(HairstyleHairType::options());
            $show->field('hair_volume', '发量类型')->using(HairstyleHairVolume::options());
            $show->field('face_shape', '适合脸型')->as(function () use ($hairstyle) {
                return HairstyleController::formatEnumArray($hairstyle->face_shape, FaceShape::class);
            });
            $show->field('maintenance_level', '打理难度')->using(HairstyleMaintenanceLevel::options());
            $show->field('suitable_scene', '适用场景')->as(function () use ($hairstyle) {
                return HairstyleController::formatEnumArray($hairstyle->suitable_scene, SuitableScene::class);
            });

            $show->field('tags_display', '标签')->as(function () use ($hairstyle) {
                $names = $hairstyle->tags->pluck('name')->all();

                return $names !== [] ? implode('、', $names) : '暂无标签';
            });

            $show->field('cover_display', '封面')->as(function () use ($hairstyle) {
                if (! $hairstyle->coverMedia) {
                    return '暂无封面';
                }

                $src = $hairstyle->coverMedia->thumbnail_url ?: $hairstyle->coverMedia->url;

                return $src ? '<img src="'.e($src).'" style="max-width:160px;max-height:160px;border-radius:4px;" />' : '暂无封面';
            })->unescape();

            $show->field('media_display', '媒体列表')->as(function () use ($hairstyle) {
                return HairstyleController::renderMediaListForShow($hairstyle);
            })->unescape();

            $show->field('ai_prompt', 'AI 正向提示词');
            $show->field('ai_negative_prompt', 'AI 负向提示词');

            $show->field('seo_title', 'SEO 标题');
            $show->field('seo_description', 'SEO 描述');

            $show->field('status', '发布状态')->using(HairstyleStatus::options());
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
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        // Dcat 触发 saving()/saved()/deleting() 监听器时会把闭包的 $this 重新绑定到当前表单模型，
        // 因此这里显式捕获控制器实例，saving()/deleting() 闭包内一律通过 $self 调用控制器方法。
        $self = $this;

        return Form::make(new Hairstyle(), function (Form $form) use ($self) {
            $id = $form->getKey();

            $form->display('id');

            $form->tab('基础信息', function (Form $form) {
                $form->select('category_id', '主分类')
                    ->options(HairstyleController::categoryOptions())
                    ->required()
                    ->rules([
                        'required',
                        Rule::exists('hairstyle_categories', 'id')->whereNull('deleted_at'),
                    ]);
                $form->text('name', '发型名称')->required()->rules('max:100');
                $form->text('name_en', '英文名称')->rules('max:150');
                $form->text('slug', 'Slug')
                    ->required()
                    ->rules('max:150|unique:hairstyles,slug,{{id}}')
                    ->help('仅建议使用小写字母、数字和短横线，用于 SEO URL');
                $form->textarea('description', '发型介绍')->rows(4);
            });

            $form->tab('适用属性', function (Form $form) {
                $form->select('gender', '适合性别')->options(HairstyleGender::options())->default(HairstyleGender::All->value);
                $form->select('age_range', '适合年龄段')->options(HairstyleAgeRange::options())->default(HairstyleAgeRange::All->value);
                $form->select('style_type', '风格类型')->options(HairstyleStyleType::options())->default(HairstyleStyleType::Other->value);
                $form->select('hair_length', '头发长度')->options(HairstyleHairLength::options())->default(HairstyleHairLength::Other->value);
                $form->select('hair_type', '发质类型')->options(HairstyleHairType::options())->default(HairstyleHairType::All->value);
                $form->select('hair_volume', '发量类型')->options(HairstyleHairVolume::options())->default(HairstyleHairVolume::All->value);
                $form->multipleSelect('face_shape', '适合脸型')
                    ->options(FaceShape::options())
                    ->help('可多选，保存为 JSON 数组；不选表示不限');
                $form->select('maintenance_level', '打理难度')->options(HairstyleMaintenanceLevel::options())->default(HairstyleMaintenanceLevel::Unknown->value);
                $form->multipleSelect('suitable_scene', '适用场景')
                    ->options(SuitableScene::options())
                    ->help('可多选，保存为 JSON 数组；不选表示不限');
            });

            $form->tab('标签', function (Form $form) use ($id) {
                $selectedTagIds = [];

                if ($id) {
                    /** @var Hairstyle|null $hairstyle */
                    $hairstyle = Hairstyle::withTrashed()->find($id);
                    $selectedTagIds = $hairstyle ? $hairstyle->tags()->pluck('hairstyle_tags.id')->all() : [];
                }

                $form->multipleSelect('tags', '标签')
                    ->options(HairstyleController::tagOptions())
                    ->default($selectedTagIds)
                    ->help('可搜索多选；保存时会调用 $hairstyle->tags()->sync() 同步关联，只接受已存在的标签');
            });

            $form->tab('AI 信息', function (Form $form) {
                $form->textarea('ai_prompt', 'AI 正向提示词')->rows(4)->help('仅存储提示词文本，本功能不会调用任何 AI 模型');
                $form->textarea('ai_negative_prompt', 'AI 负向提示词')->rows(4);
            });

            $form->tab('SEO 信息', function (Form $form) {
                $form->text('seo_title', 'SEO 标题')->rules('max:255')->help('数据库长度上限 255 个字符');
                $form->textarea('seo_description', 'SEO 描述')->rows(3)->rules('max:500')->help('数据库长度上限 500 个字符');
            });

            $form->tab('发布设置', function (Form $form) {
                $form->select('status', '状态')->options(HairstyleStatus::options())->default(HairstyleStatus::Enabled->value);
                $form->switch('is_recommended', '是否推荐');
                $form->number('sort', '排序值')->min(0)->default(0);
                $form->datetime('published_at', '发布时间')->help('留空表示暂未设置发布时间，不会自动取创建时间');
            });

            // 新建发型时同样需要显示封面上传/选择入口（不再要求"先保存、再进编辑页传封面"），
            // buildCoverAndMediaTab() 内部会根据 $hairstyleId 是否为 null 决定是否展示"已关联媒体"面板。
            $self->buildCoverAndMediaTab($form, $id ? (int) $id : null);

            $form->ignore(['tags', 'cover_upload', 'cover_select_media_id']);

            $form->saving(function (Form $form) use ($self) {
                return $self->handleSaving($form);
            });

            $form->deleting(function (Form $form) use ($self) {
                return $self->handleDeleting($form);
            });
        });
    }

    /**
     * 恢复一个已软删除的发型。
     *
     * @param  int  $id
     */
    public function restore($id): RedirectResponse
    {
        /** @var Hairstyle|null $hairstyle */
        $hairstyle = Hairstyle::onlyTrashed()->find($id);

        if (! $hairstyle) {
            admin_toastr('发型不存在或未处于回收站中', 'error');

            return redirect(admin_url('hairstyles'));
        }

        $hairstyle->restore();

        admin_toastr('恢复成功');

        return redirect(admin_url('hairstyles'));
    }

    /**
     * 永久删除一个已软删除的发型。
     *
     * 只有已软删除的发型才允许永久删除；永久删除时显式清理 hairstyle_tag_relations
     * 和 hairstyle_media（不依赖数据库级联，本表也未建立外键约束），但不删除 media_files，
     * 因为同一份媒体文件可能仍被其它发型或分类引用。
     *
     * @param  int  $id
     */
    public function forceDelete($id): RedirectResponse
    {
        /** @var Hairstyle|null $hairstyle */
        $hairstyle = Hairstyle::onlyTrashed()->find($id);

        if (! $hairstyle) {
            admin_toastr('发型不存在或未处于回收站中', 'error');

            return redirect(admin_url('hairstyles'));
        }

        DB::transaction(function () use ($hairstyle) {
            $locked = Hairstyle::onlyTrashed()->lockForUpdate()->find($hairstyle->id);

            if (! $locked) {
                return;
            }

            HairstyleTagRelation::query()->where('hairstyle_id', $locked->id)->delete();
            HairstyleMedia::query()->where('hairstyle_id', $locked->id)->delete();

            $locked->forceDelete();
        });

        admin_toastr('永久删除成功');

        return redirect(admin_url('hairstyles'));
    }

    /**
     * 保存前的统一处理：校验适用属性/标签合法性，并在同一个事务内完成
     * 基础字段保存 + 标签 sync()，避免出现“发型已保存但标签未同步”的半成功状态。
     *
     * 本方法始终返回非空 JsonResponse，短路 Dcat 默认的 store()/update() 流程
     * （做法与 MediaFileController::handleCreating()/handleUpdating() 一致）。
     */
    private function handleSaving(Form $form): JsonResponse
    {
        // 注意 1：Dcat Form::input($key, $value) 的第二个参数是"写入值"而不是"取值默认值"——
        // 只有省略第二个参数或显式传 null 时它才是纯 getter（返回 Arr::get($this->inputs, $key)），
        // 只要传了任何非 null 的第二个参数（哪怕是 0/''/[]），都会执行 Arr::set($this->inputs, $key, $value)
        // 把这个"默认值"当成提交值强行写回表单。face_shape/suitable_scene 是真实数据库字段（未被
        // ignore()），因此可以用 "input($key) ?? 默认值" 正确读取真实提交值。
        $faceShape = $this->normalizeEnumArrayInput($form->input('face_shape') ?? [], FaceShape::values());

        if ($faceShape === false) {
            return JsonResponse::make()->error('适合脸型包含无效选项');
        }

        $suitableScene = $this->normalizeEnumArrayInput($form->input('suitable_scene') ?? [], SuitableScene::values());

        if ($suitableScene === false) {
            return JsonResponse::make()->error('适用场景包含无效选项');
        }

        // 注意 2：tags / cover_upload / cover_select_media_id 三个虚拟字段都在下方 $form->ignore([...])
        // 名单里，而 Dcat Form::prepare() 会在触发 saving() 回调之前就先执行 removeIgnoredFields()，
        // 把这些字段从 $this->inputs 中删除——也就是说，无论 $form->input() 怎么调用，
        // 在 saving() 回调里都读不到这三个字段的真实提交值。必须改为直接读取底层
        // Illuminate\Http\Request（request() 助手，与 Form 内部持有的是同一个单例对象，
        // 不受 Dcat removeIgnoredFields() 影响），且 Request::input($key, $default) 才是
        // 真正安全的"取值加默认值"语义。
        $tagIds = array_values(array_unique(array_map('intval', array_filter((array) request()->input('tags', [])))));

        if ($tagIds !== [] && HairstyleTag::query()->whereIn('id', $tagIds)->count() !== count($tagIds)) {
            return JsonResponse::make()->error('标签中包含无效的标签 ID');
        }

        $id = $form->getKey();
        $attributes = $this->extractBasicAttributes($form, $faceShape, $suitableScene);

        // 封面输入不再依赖 $id：新建发型时同样允许在同一次提交里上传/选择封面，
        // 在下方事务内先 Hairstyle::create() 拿到 $hairstyle->id 后，applyCoverChange() 才会真正执行关联。
        $coverUploadPath = (string) request()->input('cover_upload', '');
        $coverSelectMediaId = (int) request()->input('cover_select_media_id', 0);

        try {
            $hairstyle = DB::transaction(function () use ($id, $attributes, $tagIds, $coverUploadPath, $coverSelectMediaId) {
                if ($id) {
                    /** @var Hairstyle $hairstyle */
                    $hairstyle = Hairstyle::withTrashed()->lockForUpdate()->findOrFail($id);
                    $hairstyle->fill($attributes);
                    $hairstyle->save();
                } else {
                    $hairstyle = Hairstyle::create($attributes);
                }

                // cover_media_id 不会在此处被直接赋值，该字段唯一的写入入口是
                // HairstyleMediaService（见下方 applyCoverChange() 和 HairstyleMediaController）。
                $hairstyle->tags()->sync($tagIds);

                // 封面上传/选择与基础字段、标签同步共用同一个事务：任一环节失败都会整体回滚，
                // 不会出现“基础信息已保存但封面处理失败”的半成功状态。
                $this->applyCoverChange($hairstyle, $coverUploadPath, $coverSelectMediaId);

                return $hairstyle;
            });
        } catch (ValidationException $e) {
            return JsonResponse::make()->error($this->firstValidationMessage($e));
        } catch (\Throwable $e) {
            return JsonResponse::make()->error('保存失败：'.$e->getMessage());
        }

        $createMessage = $hairstyle->cover_media_id
            ? '发型创建成功，封面已设置'
            : '发型创建成功，请继续在“封面与媒体”标签页中上传或选择封面';

        $response = JsonResponse::make()->success($id ? '更新成功' : $createMessage);

        return $id
            ? $response->refresh()
            : $response->redirect(admin_url('hairstyles/'.$hairstyle->id.'/edit'));
    }

    /**
     * 软删除前置校验：本项目约定软删除发型时保留 hairstyle_media 与 hairstyle_tag_relations
     * （便于恢复），因此这里不做任何清理，只放行给 Dcat 默认的软删除流程。
     * 永久删除改由 forceDelete() 独立处理，不经过本方法。
     */
    private function handleDeleting(Form $form): ?JsonResponse
    {
        return null;
    }

    /**
     * 应用封面变更：上传新文件优先，其次是从媒体库选择的媒体，两者都没有则不改变封面。
     *
     * 必须在调用方已经开启的事务内执行：得到目标 media_id 后，若尚未关联当前发型则先
     * HairstyleMediaService::attachMedia()，再统一 HairstyleMediaService::setPrimaryMedia()
     * 完成 hairstyle_media.is_primary 和 hairstyles.cover_media_id 的同步，不在本方法内直接
     * 更新这两个表。封面场景下“必须是图片类型”的校验在这里完成，不侵入 HairstyleMediaService
     * 的通用媒体关联能力（hairstyle_media 本身允许非图片类型的画廊媒体）。
     *
     * @throws ValidationException 上传/选择的媒体不是图片，或状态不可用
     */
    public function applyCoverChange(Hairstyle $hairstyle, string $uploadPath, int $selectMediaId): void
    {
        $mediaId = null;

        if ($uploadPath !== '') {
            $mediaId = $this->registerCoverUpload($uploadPath)->id;
        } elseif ($selectMediaId > 0) {
            $mediaId = $this->assertImageMedia($selectMediaId)->id;
        }

        if ($mediaId === null) {
            return;
        }

        $alreadyAttached = HairstyleMedia::query()
            ->where('hairstyle_id', $hairstyle->id)
            ->where('media_id', $mediaId)
            ->exists();

        if (! $alreadyAttached) {
            $this->mediaService->attachMedia($hairstyle->id, $mediaId);
        }

        $this->mediaService->setPrimaryMedia($hairstyle->id, $mediaId);
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
     * 校验从媒体库选择的媒体存在且为图片类型（状态可用由 HairstyleMediaService 内部校验）。
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

        return $media;
    }

    private function firstValidationMessage(ValidationException $e): string
    {
        return collect($e->errors())->collapse()->first() ?? '操作失败';
    }

    /**
     * 从表单输入中提取真实列对应的属性（不包含 tags / cover_media_id）。
     *
     * @return array<string, mixed>
     */
    private function extractBasicAttributes(Form $form, array $faceShape, array $suitableScene): array
    {
        $publishedAt = $form->input('published_at');

        // 同 handleSaving() 顶部注释：一律用 "input($key) ?? 默认值"，不使用 input($key, $default)。
        return [
            'category_id' => (int) $form->input('category_id'),
            'name' => (string) $form->input('name'),
            'name_en' => (string) ($form->input('name_en') ?? ''),
            'slug' => (string) $form->input('slug'),
            'description' => $form->input('description'),
            'gender' => (int) ($form->input('gender') ?? HairstyleGender::All->value),
            'age_range' => (int) ($form->input('age_range') ?? HairstyleAgeRange::All->value),
            'style_type' => (int) ($form->input('style_type') ?? HairstyleStyleType::Other->value),
            'hair_length' => (int) ($form->input('hair_length') ?? HairstyleHairLength::Other->value),
            'hair_type' => (int) ($form->input('hair_type') ?? HairstyleHairType::All->value),
            'hair_volume' => (int) ($form->input('hair_volume') ?? HairstyleHairVolume::All->value),
            'face_shape' => $faceShape !== [] ? $faceShape : null,
            'maintenance_level' => (int) ($form->input('maintenance_level') ?? HairstyleMaintenanceLevel::Unknown->value),
            'suitable_scene' => $suitableScene !== [] ? $suitableScene : null,
            'ai_prompt' => $form->input('ai_prompt'),
            'ai_negative_prompt' => $form->input('ai_negative_prompt'),
            'seo_title' => (string) ($form->input('seo_title') ?? ''),
            'seo_description' => (string) ($form->input('seo_description') ?? ''),
            'status' => (int) ($form->input('status') ?? HairstyleStatus::Enabled->value),
            'is_recommended' => $form->input('is_recommended') ? 1 : 0,
            'sort' => (int) ($form->input('sort') ?? 0),
            'published_at' => $publishedAt !== '' && $publishedAt !== null ? $publishedAt : null,
        ];
    }

    /**
     * 归一化多选枚举数组输入：过滤空值后校验是否全部落在允许值范围内。
     *
     * @param  mixed  $input
     * @param  array<int, string>  $allowedValues
     * @return array<int, string>|false 校验失败返回 false，成功返回归一化后的数组（可能为空数组）
     */
    private function normalizeEnumArrayInput($input, array $allowedValues)
    {
        $values = array_values(array_filter((array) $input, fn ($v) => $v !== null && $v !== ''));

        if (array_diff($values, $allowedValues) !== []) {
            return false;
        }

        return $values;
    }

    /**
     * 构建“封面与媒体”标签页：
     * - 顶部展示当前封面预览（新建时展示提示文案），并提供“上传新封面”“从媒体库选择封面”两个
     *   虚拟字段（随主表单一起提交，在 handleSaving() 的同一个事务内处理，不直接绑定 cover_media_id）；
     * - 编辑已有发型时，下方额外展示当前关联媒体的只读摘要，并给出跳转到 HairstyleMediaController
     *   的明确入口，已关联媒体的“设为封面/编辑关联信息/移除关联”都在该独立子页面完成；
     * - 新建发型时（$hairstyleId 为 null）还没有发型 ID，不展示"已关联媒体"面板，
     *   但上传/选择封面字段与编辑页复用同一段定义，保存后会在 handleSaving() 的事务内一次性生效。
     *
     * @param  int|null  $hairstyleId  null 表示当前是新建发型
     */
    private function buildCoverAndMediaTab(Form $form, ?int $hairstyleId): void
    {
        $form->tab('封面与媒体', function (Form $form) use ($hairstyleId) {
            $form->html(HairstyleController::renderCoverPreview($hairstyleId), '当前封面');

            $form->image('cover_upload', '上传新封面')
                ->disk('public')
                ->dir(function () {
                    return 'media/'.now()->format('Y/m/d');
                })
                ->name(function (UploadedFile $file) {
                    $storedName = md5(uniqid('', true)).'.'.strtolower($file->getClientOriginalExtension() ?: 'jpg');

                    Cache::put(
                        HairstyleController::COVER_UPLOAD_META_CACHE_PREFIX.$storedName,
                        ['original_name' => $file->getClientOriginalName()],
                        now()->addMinutes(30)
                    );

                    return $storedName;
                })
                ->accept('jpg,jpeg,png,webp')
                ->rules('mimes:jpg,jpeg,png,webp|mimetypes:image/jpeg,image/png,image/webp|max:'.MediaFileService::MAX_UPLOAD_SIZE_KB)
                ->help($hairstyleId ? '留空表示不更换封面；上传成功后保存表单才会正式生效' : '新建发型时同样支持直接上传封面，保存成功后自动关联并设为封面');

            $form->selectTable('cover_select_media_id', '从媒体库选择封面')
                ->title('选择封面媒体')
                ->dialogWidth('60%')
                ->from(MediaImageTable::make())
                ->pluck('original_name', 'id')
                ->help('仅可选择状态为“启用”的图片类型媒体；同时上传了新文件时，以上传的文件优先');

            $form->html(
                $hairstyleId
                    ? HairstyleController::renderMediaManagementPanel($hairstyleId)
                    : '<div class="alert alert-info" style="margin-bottom:0;">新建发型保存成功后，可在编辑页继续管理已关联的媒体列表（关联更多媒体、编辑标题/排序、移除关联）。</div>'
            );
        });
    }

    /**
     * @return array<int, string>
     */
    public static function categoryOptions(): array
    {
        return HairstyleCategory::query()
            ->orderByDesc('sort')
            ->orderByDesc('id')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function tagOptions(): array
    {
        return HairstyleTag::query()
            ->orderByDesc('sort')
            ->orderByDesc('id')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * 列表“封面”列渲染，只读取已预加载的 coverMedia 关联，不在渲染过程中触发新查询。
     */
    public static function renderCoverFor(Hairstyle $model): string
    {
        $cover = $model->coverMedia;

        if (! $cover) {
            return '-';
        }

        $src = $cover->thumbnail_url ?: $cover->url;

        if (! $src) {
            return '-';
        }

        return '<img src="'.e($src).'" style="width:50px;height:50px;object-fit:cover;border-radius:4px;" />';
    }

    public static function renderRestoreAction(Hairstyle $model): string
    {
        $url = admin_url('hairstyles/'.$model->id.'/restore');
        $token = csrf_token();

        return <<<HTML
<form method="POST" action="{$url}" style="display:inline-block;margin:0 5px;" onsubmit="return confirm('确定要恢复该发型吗？');">
    <input type="hidden" name="_token" value="{$token}">
    <input type="hidden" name="_method" value="PUT">
    <button type="submit" class="btn btn-link" style="padding:0;border:0;background:none;color:#28a745;" title="恢复"><i class="feather icon-rotate-ccw"></i></button>
</form>
HTML;
    }

    public static function renderForceDeleteAction(Hairstyle $model): string
    {
        $url = admin_url('hairstyles/'.$model->id.'/force-delete');
        $token = csrf_token();

        return <<<HTML
<form method="POST" action="{$url}" style="display:inline-block;margin:0 5px;" onsubmit="return confirm('永久删除后不可恢复，且会清理标签和媒体关联，确定继续吗？');">
    <input type="hidden" name="_token" value="{$token}">
    <input type="hidden" name="_method" value="DELETE">
    <button type="submit" class="btn btn-link" style="padding:0;border:0;background:none;color:#dc3545;" title="永久删除"><i class="feather icon-trash-2"></i></button>
</form>
HTML;
    }

    /**
     * “封面与媒体”标签页顶部的当前封面预览：缩略图 + 媒体 ID + 原始文件名；
     * 新建发型（$hairstyleId 为 null）时还没有封面，展示明确的引导文案；
     * 已有发型但尚未设置封面时展示"暂未设置封面"。
     */
    public static function renderCoverPreview(?int $hairstyleId): string
    {
        if (! $hairstyleId) {
            return '<div class="alert alert-info" style="margin-bottom:0;">新建后将使用本次上传或选择的图片作为封面；如果都不设置，保存后可在编辑页补充</div>';
        }

        /** @var Hairstyle|null $hairstyle */
        $hairstyle = Hairstyle::withTrashed()->with('coverMedia')->find($hairstyleId);
        $cover = $hairstyle?->coverMedia;

        if (! $cover) {
            return '<div class="alert alert-warning" style="margin-bottom:0;">暂未设置封面</div>';
        }

        $src = $cover->thumbnail_url ?: $cover->url;
        $img = $src ? '<img src="'.e($src).'" style="max-width:120px;max-height:120px;border-radius:4px;" />' : '-';

        return $img
            .'<div style="margin-top:6px;color:#666;">媒体 ID：#'.$cover->id.'　原始文件名：'.e($cover->original_name ?: $cover->filename).'</div>';
    }

    public static function renderMediaManageAction(Hairstyle $model): string
    {
        $url = admin_url('hairstyle-media?hairstyle_id='.$model->id);

        return '<a href="'.e($url).'" title="媒体管理" style="margin-right:8px;"><i class="feather icon-image"></i></a>';
    }

    /**
     * @param  array<int, string>|null  $values
     * @param  class-string  $enumClass
     */
    public static function formatEnumArray(?array $values, string $enumClass): string
    {
        if (! $values) {
            return '不限';
        }

        $labels = array_map(function ($value) use ($enumClass) {
            $case = $enumClass::tryFrom($value);

            return $case ? $case->label() : (string) $value;
        }, $values);

        return implode('、', $labels) ?: '不限';
    }

    public static function renderMediaListForShow(Hairstyle $hairstyle): string
    {
        if ($hairstyle->mediaRelations->isEmpty()) {
            return '暂无关联媒体';
        }

        $rows = '';

        foreach ($hairstyle->mediaRelations as $relation) {
            $media = $relation->media;
            $thumb = $media ? ($media->thumbnail_url ?: $media->url) : '';
            $img = $thumb ? '<img src="'.e($thumb).'" style="width:60px;height:60px;object-fit:cover;border-radius:4px;" />' : '-';
            $typeLabel = HairstyleMediaType::tryFrom((int) $relation->type)?->label() ?? '-';
            $statusLabel = HairstyleMediaStatus::tryFrom((int) $relation->status)?->label() ?? '-';
            $primary = $relation->is_primary ? '<span class="label label-success">主图</span>' : '';

            $rows .= '<tr>'
                .'<td>'.$img.'</td>'
                .'<td>'.e($typeLabel).'</td>'
                .'<td>'.e($relation->title ?: '-').'</td>'
                .'<td>'.e((string) $relation->sort).'</td>'
                .'<td>'.e($statusLabel).'</td>'
                .'<td>'.$primary.'</td>'
                .'</tr>';
        }

        return '<table class="table table-bordered"><thead><tr><th>预览</th><th>类型</th><th>标题</th><th>排序</th><th>状态</th><th>主图</th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    public static function renderMediaManagementPanel(int $hairstyleId): string
    {
        /** @var Hairstyle|null $hairstyle */
        $hairstyle = Hairstyle::withTrashed()->with(['mediaRelations.media'])->find($hairstyleId);

        if (! $hairstyle) {
            return '<div class="alert alert-warning">发型不存在</div>';
        }

        $manageUrl = admin_url('hairstyle-media?hairstyle_id='.$hairstyleId);
        $table = HairstyleController::renderMediaListForShow($hairstyle);

        return $table
            .'<div style="margin-top:12px;">'
            .'<a href="'.e($manageUrl).'" class="btn btn-primary" target="_blank"><i class="feather icon-image"></i> 前往媒体管理页面（关联媒体 / 设为主图 / 编辑 / 移除）</a>'
            .'</div>';
    }
}
