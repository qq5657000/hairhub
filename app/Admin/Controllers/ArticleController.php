<?php

namespace App\Admin\Controllers;

use App\Admin\Controllers\Concerns\FormatsEnumBadges;
use App\Admin\Controllers\Concerns\ManagesCoverMedia;
use App\Admin\Renderable\MediaImageTable;
use App\Enums\Common\CommonStatus;
use App\Enums\Content\ContentFormat;
use App\Enums\Content\ContentStatus;
use App\Enums\Hairstyle\HairstyleStatus;
use App\Enums\Media\MediaSourceType;
use App\Enums\Media\MediaStatus;
use App\Enums\Media\MediaVisibility;
use App\Enums\Wechat\WechatSyncStatus;
use App\Models\Article;
use App\Models\HairColor;
use App\Models\Hairstyle;
use App\Models\MediaFile;
use App\Services\Content\ArticleService;
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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * 文章管理 Dcat Admin 后台管理。
 *
 * 严格复用第一阶段已完成的 ArticleService 作为唯一写入入口：状态流转、标签/发型/
 * 发色关联同步、公众号预留记录维护全部委托给 Service，本控制器只负责表单字段提取、
 * 封面媒体解析（复用 ManagesCoverMedia trait）和事务边界控制。
 *
 * 正文图片/图集/附件的关联管理委托给独立的 ArticleMediaController 子页面（详见该
 * 控制器头部注释关于编辑器插图能力限制的说明），本表单“媒体与关联”标签页只提供
 * 入口链接，不重复实现媒体关联的增删改逻辑。
 *
 * saving()/deleting() 回调始终返回非空 JsonResponse，短路 Dcat 默认的
 * store()/update()/destroy() 持久化流程，确保“保存/删除”只有 Service 这一个
 * 真正的写入入口，不允许通过直接赋值 status 绕过状态流转校验。
 */
class ArticleController extends AdminController
{
    use FormatsEnumBadges;
    use ManagesCoverMedia;

    private ArticleService $articleService;

    private MediaFileService $mediaFileService;

    public function __construct(ArticleService $articleService, MediaFileService $mediaFileService)
    {
        $this->articleService = $articleService;
        $this->mediaFileService = $mediaFileService;
    }

    public function index(Content $content)
    {
        return $content
            ->header('文章管理')
            ->description('列表')
            ->body($this->grid());
    }

    /**
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new Article(), function (Grid $grid) {
            // select() 显式限定为 Article::LIST_COLUMNS（不含 content / wechat_content 等 LONGTEXT），
            // with() 预加载 category/coverMedia/wechatArticle/tags，避免在下面的列 display() 中
            // 逐行触发关联查询（N+1）。
            $grid->model()
                ->select(Article::LIST_COLUMNS)
                ->with(['category', 'coverMedia', 'wechatArticle', 'tags'])
                ->orderByDesc('sort')
                ->orderByDesc('id');

            $grid->column('id')->sortable();
            $grid->column('cover', '封面')->display(function () {
                return ArticleController::renderCoverThumb($this->coverMedia);
            });
            $grid->column('title', '标题');
            $grid->column('category.name', '分类')->display(function ($value) {
                return $value ?: '-';
            });
            $grid->column('tags', '标签')->display(function () {
                return $this->tags->pluck('name')->implode('、') ?: '-';
            });
            $grid->column('status', '状态')
                ->using(ContentStatus::options())
                ->label(ArticleController::enumColorMap(ContentStatus::class));
            $grid->column('is_recommended', '推荐')->using([0 => '否', 1 => '是'])->label([0 => 'default', 1 => 'success']);
            $grid->column('is_top', '置顶')->using([0 => '否', 1 => '是'])->label([0 => 'default', 1 => 'danger']);
            $grid->column('author', '作者')->display(fn ($value) => $value ?: '-');
            $grid->column('view_count', '浏览量')->sortable();
            $grid->column('like_count', '点赞量')->sortable();
            $grid->column('published_at', '发布时间')->display(fn ($value) => $value ?: '-')->sortable();
            $grid->column('updated_at', '更新时间');
            $grid->column('wechat_sync', '微信同步状态')->display(function () {
                return ArticleController::renderWechatSyncBadge($this->wechatArticle);
            });

            $grid->setActionClass(Grid\Displayers\Actions::class);

            $grid->filter(function (Grid\Filter $filter) {
                $filter->like('title', '标题');
                $filter->like('slug', 'Slug');
                $filter->equal('category_id', '分类')->select(ArticleCategoryController::articleFormOptions());

                // 标签筛选使用关系查询（whereHas），不在 PHP 层循环过滤。
                $filter->where('tag_id', function ($query) {
                    $query->whereHas('tags', function ($q) {
                        $q->where('article_tags.id', $this->input);
                    });
                }, '标签')->select(ArticleTagController::tagOptions());

                $filter->equal('status', '状态')->select(ContentStatus::options());
                $filter->like('author', '作者');
                $filter->equal('is_recommended', '推荐')->select([0 => '否', 1 => '是']);
                $filter->equal('is_top', '置顶')->select([0 => '否', 1 => '是']);
                $filter->between('published_at', '发布时间')->datetime();

                // 微信同步状态存储在独立的 wechat_articles 表，同样使用关系查询。
                $filter->where('wechat_sync_status', function ($query) {
                    $query->whereHas('wechatArticle', function ($q) {
                        $q->where('sync_status', $this->input);
                    });
                }, '微信同步状态')->select(WechatSyncStatus::options());

                $filter->between('created_at', '创建时间')->datetime();

                // 回收站 scope：默认列表因 SoftDeletes 全局作用域已自动排除已删除记录，
                // 切到该 scope 时改为只查询 deleted_at 不为空的记录，用于恢复。
                $filter->scope('trashed', '回收站')->onlyTrashed();
            });

            // 已软删除的记录：隐藏编辑/查看/删除，改为“恢复”。V1.0 不提供永久删除入口
            // （与文章分类模块保持一致的克制策略）。
            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $model = $actions->row;

                if (! $model instanceof Article) {
                    return;
                }

                if ($model->trashed()) {
                    $actions->disableView();
                    $actions->disableEdit();
                    $actions->disableDelete();
                    $actions->append(ArticleController::renderRestoreAction($model));
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
        /** @var Article $article */
        $article = Article::withTrashed()
            ->with(['category', 'coverMedia', 'wechatCoverMedia', 'wechatArticle', 'tags', 'hairstyles', 'hairColors'])
            ->findOrFail($id);

        return Show::make($id, new Article(), function (Show $show) use ($article) {
            $show->field('id');
            $show->field('category_name', '分类')->as(fn () => $article->category->name ?? '-');
            $show->field('title', '标题');
            $show->field('title_en', '英文标题');
            $show->field('slug', 'Slug');
            $show->field('author', '作者')->as(fn ($value) => $value ?: '-');
            $show->field('source', '来源')->as(fn ($value) => $value ?: '-');
            $show->field('source_url', '原文地址')->as(fn ($value) => $value ?: '-');
            $show->field('cover_display', '网站封面')->as(function () use ($article) {
                return ArticleController::renderCoverPreview($article->coverMedia, '暂未设置网站封面');
            })->unescape();
            $show->field('summary', '网站摘要')->as(fn ($value) => $value ?: '-');
            $show->field('content_format', '正文格式')->using(ContentFormat::options());
            $show->field('content', '网站正文')->as(function ($value) {
                return ArticleController::renderTextPreview((string) $value);
            })->unescape();

            $show->field('wechat_excerpt', '公众号摘要')->as(fn ($value) => $value ?: '-');
            $show->field('wechat_cover_display', '公众号封面')->as(function () use ($article) {
                return ArticleController::renderCoverPreview($article->wechatCoverMedia, '暂未设置公众号封面');
            })->unescape();
            $show->field('wechat_content', '公众号正文')->as(function ($value) {
                return ArticleController::renderTextPreview((string) $value);
            })->unescape();

            $show->field('tags_display', '文章标签')->as(function () use ($article) {
                return $article->tags->pluck('name')->implode('、') ?: '-';
            });
            $show->field('hairstyles_display', '关联发型')->as(function () use ($article) {
                return $article->hairstyles->pluck('name')->implode('、') ?: '-';
            });
            $show->field('hair_colors_display', '关联发色')->as(function () use ($article) {
                return $article->hairColors->pluck('name')->implode('、') ?: '-';
            });
            $show->field('media_link', '正文图片/图集/附件')->as(function () use ($article) {
                $url = admin_url('article-media?article_id='.$article->id);

                return '<a href="'.e($url).'" target="_blank">进入管理页面</a>';
            })->unescape();

            $show->field('status', '状态')->using(ContentStatus::options());
            $show->field('is_recommended', '是否推荐')->using([0 => '否', 1 => '是']);
            $show->field('is_top', '是否置顶')->using([0 => '否', 1 => '是']);
            $show->field('sort', '排序');
            $show->field('review_remark', '审核备注')->as(fn ($value) => $value ?: '-');
            $show->field('reviewed_at', '审核时间')->as(fn ($value) => $value ?: '-');
            $show->field('published_at', '发布时间')->as(fn ($value) => $value ?: '-');
            $show->field('view_count', '浏览量');
            $show->field('like_count', '点赞量');

            $show->field('seo_title', 'SEO 标题')->as(fn ($value) => $value ?: '-');
            $show->field('seo_keywords', 'SEO 关键词')->as(fn ($value) => $value ?: '-');
            $show->field('seo_description', 'SEO 描述')->as(fn ($value) => $value ?: '-');

            $show->field('wechat_sync_display', '微信同步状态')->as(function () use ($article) {
                return ArticleController::renderWechatSyncBadge($article->wechatArticle);
            })->unescape();

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

        return Form::make(new Article(), function (Form $form) use ($self) {
            $id = $form->getKey();

            /** @var Article|null $article */
            $article = $id
                ? Article::withTrashed()->with(['coverMedia', 'wechatCoverMedia'])->find($id)
                : null;

            $form->display('id');

            $form->tab('基本信息', function (Form $form) use ($self, $article) {
                $form->select('category_id', '分类')
                    ->options(ArticleCategoryController::articleFormOptions())
                    ->default(0)
                    ->required()
                    ->help('文章必须归属到一个分类；分类是否启用只影响“发布”时的校验，不影响归属关系');
                $form->text('title', '标题')->required()->rules('max:255');
                $form->text('title_en', '英文标题')->rules('max:255');
                $form->text('slug', 'Slug')
                    ->required()
                    ->rules(['required', 'max:255', 'regex:/^[a-z0-9-]+$/'])
                    ->help('仅允许小写字母、数字和短横线，用于 SEO URL；唯一性由保存时的业务校验负责（编辑时排除自身）');
                $form->textarea('summary', '网站摘要')->rows(3)->rules('max:500');
                $form->text('author', '作者')->rules('max:100');
                $form->text('source', '来源')->rules('max:100');
                $form->url('source_url', '原文地址')->rules('max:255');

                $self->appendCoverFields(
                    $form,
                    $article?->coverMedia,
                    'cover_upload',
                    'cover_select_media_id',
                    'clear_cover',
                    'articles/cover-upload',
                    '网站封面'
                );
            });

            $form->tab('网站正文', function (Form $form) {
                $form->select('content_format', '正文格式')
                    ->options(ContentFormat::options())
                    ->default(ContentFormat::Markdown->value)
                    ->required();

                $form->markdown('content', '网站正文')
                    ->options(['imageUpload' => false, 'saveHTMLToTextarea' => false])
                    ->help(
                        '已关闭编辑器自带的“插入图片”上传按钮（该按钮默认走独立上传接口，不会登记进媒体库，'.
                        '与项目“图片必须统一由媒体服务管理”的原则冲突）；请先在“媒体与关联”标签页的'.
                        '“正文图片 / 图集 / 附件管理”中关联或上传图片，复制预览区展示的图片地址后手工粘贴到此处引用；'.
                        '发布前必须保证本字段非空'
                    );
            });

            $form->tab('公众号内容', function (Form $form) use ($self, $article) {
                $form->textarea('wechat_excerpt', '公众号摘要')
                    ->rows(3)
                    ->rules(['max:120'])
                    ->help('最多 120 个字符，用于公众号文章摘要展示');

                $self->appendCoverFields(
                    $form,
                    $article?->wechatCoverMedia,
                    'wechat_cover_upload',
                    'wechat_cover_select_media_id',
                    'wechat_clear_cover',
                    'articles/wechat-cover-upload',
                    '公众号封面'
                );

                $form->markdown('wechat_content', '公众号正文')
                    ->options(['imageUpload' => false, 'saveHTMLToTextarea' => false])
                    ->help('与网站正文独立保存，互不覆盖；图片上传按钮已关闭，原因与网站正文一致。本阶段不实现真实公众号同步');
            });

            $form->tab('媒体与关联', function (Form $form) use ($id) {
                $tagIds = [];
                $hairstyleIds = [];
                $hairColorIds = [];

                if ($id) {
                    /** @var Article|null $current */
                    $current = Article::withTrashed()->find($id);

                    if ($current) {
                        $tagIds = $current->tags()->pluck('article_tags.id')->all();
                        $hairstyleIds = $current->hairstyles()->pluck('hairstyles.id')->all();
                        $hairColorIds = $current->hairColors()->pluck('hair_colors.id')->all();
                    }
                }

                $form->multipleSelect('tags', '文章标签')
                    ->options(ArticleTagController::tagOptions())
                    ->default($tagIds)
                    ->help('可搜索多选；保存时由 ArticleService 在同一事务内同步，只接受真实存在的标签');

                if ($id) {
                    $form->html(
                        ArticleController::renderMediaManagementPanel((int) $id),
                        '正文图片 / 图集 / 附件管理'
                    );
                } else {
                    $form->html(
                        '<div class="alert alert-info" style="margin-bottom:0;">'.
                        '请先保存文章基本信息，保存成功后系统会跳转到编辑页，再回到本标签页管理正文图片 / 图集 / 附件。'.
                        '</div>',
                        '正文图片 / 图集 / 附件管理'
                    );
                }

                $form->multipleSelect('hairstyle_ids', '关联发型')
                    ->options(function ($value) {
                        $ids = array_values(array_filter(array_map('intval', (array) $value)));

                        if ($ids === []) {
                            return [];
                        }

                        return Hairstyle::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
                    })
                    ->default($hairstyleIds)
                    ->ajax('articles/hairstyle-options')
                    ->help('仅可关联状态为“启用”的发型，支持关键字搜索；保存时由 ArticleService 再次校验 ID 合法性');

                $form->multipleSelect('hair_color_ids', '关联发色')
                    ->options(function ($value) {
                        $ids = array_values(array_filter(array_map('intval', (array) $value)));

                        if ($ids === []) {
                            return [];
                        }

                        return HairColor::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
                    })
                    ->default($hairColorIds)
                    ->ajax('articles/hair-color-options')
                    ->help('仅可关联状态为“启用”的发色，支持关键字搜索；保存时由 ArticleService 再次校验 ID 合法性');
            });

            $form->tab('发布设置', function (Form $form) use ($id, $article) {
                if ($id) {
                    $form->select('status', '状态')
                        ->options(ContentStatus::options())
                        ->default($article?->status?->value ?? ContentStatus::Draft->value)
                        ->help(
                            '状态流转规则由 ArticleService 统一校验：草稿→待审核→已发布↔已下线，待审核可打回草稿；'.
                            '发布前必须分类启用、标题/Slug/网站正文/封面齐备且发布时间非空，不允许绕过校验直接改状态'
                        );
                } else {
                    $form->html(
                        '<div class="alert alert-info" style="margin-bottom:0;">'.
                        '<b>状态：</b>草稿（新建文章的初始状态固定为草稿，与是否勾选其它选项无关，保存后可在编辑页调整状态）'.
                        '</div>',
                        '状态'
                    );
                }

                $form->datetime('published_at', '发布时间')->help('发布为“已发布”状态前必须设置；可提前设置用于排期，不会自动填充为当前时间');
                $form->switch('is_recommended', '是否推荐');
                $form->switch('is_top', '是否置顶');
                $form->number('sort', '排序值')->min(0)->default(0);
                $form->textarea('review_remark', '审核备注')
                    ->rows(3)
                    ->rules('max:500')
                    ->help('状态流转打回草稿（待审核 → 草稿）时会作为审核意见记录');

                $form->display('view_count', '浏览量（只读）');
                $form->display('like_count', '点赞量（只读）');
            });

            $form->tab('SEO', function (Form $form) {
                $form->text('seo_title', 'SEO 标题')->rules('max:255')->help('留空时前台可回退使用文章标题，不会自动覆盖已填写内容');
                $form->text('seo_keywords', 'SEO 关键词')->rules('max:255')->help('建议使用英文逗号分隔');
                $form->textarea('seo_description', 'SEO 描述')->rows(3)->rules('max:500')->help('留空时前台可回退使用网站摘要，不会自动覆盖已填写内容');
            });

            $form->ignore([
                'tags', 'hairstyle_ids', 'hair_color_ids',
                'cover_upload', 'cover_select_media_id', 'clear_cover',
                'wechat_cover_upload', 'wechat_cover_select_media_id', 'wechat_clear_cover',
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
     * 恢复一个已软删除的文章：调用 ArticleService::restore()，恢复前会重新校验
     * 所属分类是否仍然存在且未删除，并检查 slug 是否冲突。
     *
     * @param  int  $id
     */
    public function restore($id): RedirectResponse
    {
        try {
            $this->articleService->restore((int) $id);
            admin_toastr('恢复成功');
        } catch (ModelNotFoundException $e) {
            admin_toastr('文章不存在或未处于回收站中', 'error');
        } catch (ValidationException $e) {
            admin_toastr($this->firstValidationMessage($e), 'error');
        }

        return redirect(admin_url('articles'));
    }

    /**
     * “网站封面”上传字段的专用上传接口。
     */
    public function uploadCover()
    {
        return $this->handleGenericCoverUpload('cover_upload');
    }

    /**
     * “公众号封面”上传字段的专用上传接口，与网站封面共用同一套校验/落盘逻辑，
     * 仅字段名不同（两者互相独立，不共享同一个媒体记录）。
     */
    public function uploadWechatCover()
    {
        return $this->handleGenericCoverUpload('wechat_cover_upload');
    }

    /**
     * 关联发型的远程搜索接口（select2 ajax），仅返回状态为“启用”的发型。
     */
    public function hairstyleOptions(Request $request)
    {
        $keyword = trim((string) $request->get('q', ''));

        $query = Hairstyle::query()->where('status', HairstyleStatus::Enabled->value);

        if ($keyword !== '') {
            $query->where('name', 'like', "%{$keyword}%");
        }

        $items = $query->orderByDesc('id')->limit(20)->get(['id', 'name'])->map(function (Hairstyle $hairstyle) {
            return ['id' => $hairstyle->id, 'text' => $hairstyle->name];
        });

        return response()->json($items);
    }

    /**
     * 关联发色的远程搜索接口（select2 ajax），仅返回状态为“启用”的发色。
     */
    public function hairColorOptions(Request $request)
    {
        $keyword = trim((string) $request->get('q', ''));

        $query = HairColor::query()->where('status', CommonStatus::Enabled->value);

        if ($keyword !== '') {
            $query->where('name', 'like', "%{$keyword}%");
        }

        $items = $query->orderByDesc('id')->limit(20)->get(['id', 'name'])->map(function (HairColor $hairColor) {
            return ['id' => $hairColor->id, 'text' => $hairColor->name];
        });

        return response()->json($items);
    }

    /**
     * 在指定的 Form 分组内追加“当前封面预览 + 上传新文件 + 从媒体库选择 + 清除”
     * 四件套字段，网站封面和公众号封面共用同一套交互，只是字段名和上传接口不同。
     */
    private function appendCoverFields(
        Form $form,
        ?MediaFile $currentCover,
        string $uploadField,
        string $selectField,
        string $clearField,
        string $uploadUrl,
        string $label
    ): void {
        $form->html(ArticleController::renderCoverPreview($currentCover, '暂未设置'.$label), '当前'.$label);

        $form->image($uploadField, '上传新'.$label)
            ->url($uploadUrl)
            ->autoSave(false)
            ->accept('jpg,jpeg,png,webp')
            ->help('点击“上传”后立即正式创建媒体记录（可在媒体库复用，不会被重复创建）；留空表示不更换封面');

        $form->selectTable($selectField, '从媒体库选择'.$label)
            ->title('选择'.$label.'媒体')
            ->dialogWidth('60%')
            ->from(MediaImageTable::make())
            ->pluck('original_name', 'id')
            ->help('仅可选择状态为“启用”的图片类型媒体；同时上传了新文件时，以上传的文件优先');

        $form->switch($clearField, '清除'.$label)
            ->help('开启后，若同时没有上传新文件或选择新媒体，将清除当前'.$label.'（不会删除媒体文件本身）');
    }

    /**
     * “上传新封面”字段的通用上传接口实现，网站封面/公众号封面共用。
     */
    private function handleGenericCoverUpload(string $inputName)
    {
        if (request()->filled('key')) {
            return Admin::json()->send();
        }

        $file = request()->file('_file_');

        if (! $file) {
            return JsonResponse::make()->error('未接收到上传文件')->send();
        }

        $validator = Validator::make(
            [$inputName => $file],
            [$inputName => 'required|image|mimes:jpg,jpeg,png,webp|mimetypes:image/jpeg,image/png,image/webp|max:'.MediaFileService::MAX_UPLOAD_SIZE_KB]
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
     * 保存前的统一处理：提取全部表单字段，在单一事务内完成
     * 基础字段更新 + 标签/发型/发色关联同步 + 状态流转，任一环节失败整体回滚。
     *
     * 新建文章直接调用 ArticleService::create()（其内部已经是一个完整事务，
     * 包含标签/发型/发色同步和公众号预留记录创建），不需要额外包裹事务。
     *
     * 编辑文章需要额外调用 syncHairstyles()/syncHairColors()（各自内部也有事务），
     * 以及可能的 changeStatus() 调用；这些调用外层再包一层 DB::transaction()，
     * 依赖 Laravel/MySQL 的 SAVEPOINT 机制保证整体原子性——任一环节抛出异常，
     * 外层事务回滚，不会出现“基础信息已保存但标签/发型/发色未同步”的半成功状态。
     *
     * 本方法始终返回非空 JsonResponse，短路 Dcat 默认的 store()/update() 流程。
     */
    private function handleSaving(Form $form): JsonResponse
    {
        $id = $form->getKey();

        $tagIds = $this->extractIntArray(request()->input('tags', []));
        $hairstyleIds = $this->extractIntArray(request()->input('hairstyle_ids', []));
        $hairColorIds = $this->extractIntArray(request()->input('hair_color_ids', []));

        $coverUploadId = (int) request()->input('cover_upload', 0);
        $coverSelectId = (int) request()->input('cover_select_media_id', 0);
        $clearCover = (bool) request()->input('clear_cover', false);

        $wechatCoverUploadId = (int) request()->input('wechat_cover_upload', 0);
        $wechatCoverSelectId = (int) request()->input('wechat_cover_select_media_id', 0);
        $wechatClearCover = (bool) request()->input('wechat_clear_cover', false);

        $attributes = [
            'category_id' => (int) ($form->input('category_id') ?? 0),
            'title' => (string) $form->input('title'),
            'title_en' => (string) ($form->input('title_en') ?? ''),
            'slug' => (string) $form->input('slug'),
            'author' => (string) ($form->input('author') ?? ''),
            'source' => (string) ($form->input('source') ?? ''),
            'source_url' => (string) ($form->input('source_url') ?? ''),
            'summary' => (string) ($form->input('summary') ?? ''),
            'content_format' => (int) ($form->input('content_format') ?? ContentFormat::Markdown->value),
            'content' => (string) ($form->input('content') ?? ''),
            'wechat_excerpt' => (string) ($form->input('wechat_excerpt') ?? ''),
            'wechat_content' => (string) ($form->input('wechat_content') ?? ''),
            'is_recommended' => (bool) ((int) ($form->input('is_recommended') ?? 0)),
            'is_top' => (bool) ((int) ($form->input('is_top') ?? 0)),
            'sort' => (int) ($form->input('sort') ?? 0),
            'published_at' => $form->input('published_at') ?: null,
            'seo_title' => (string) ($form->input('seo_title') ?? ''),
            'seo_keywords' => (string) ($form->input('seo_keywords') ?? ''),
            'seo_description' => (string) ($form->input('seo_description') ?? ''),
        ];

        $reviewRemark = (string) ($form->input('review_remark') ?? '');
        $targetStatusValue = $form->input('status');
        $reviewerId = (int) (Admin::user()->id ?? 0);

        try {
            $article = DB::transaction(function () use (
                $id,
                $attributes,
                $tagIds,
                $hairstyleIds,
                $hairColorIds,
                $coverUploadId,
                $coverSelectId,
                $clearCover,
                $wechatCoverUploadId,
                $wechatCoverSelectId,
                $wechatClearCover,
                $reviewRemark,
                $targetStatusValue,
                $reviewerId
            ) {
                if ($id) {
                    /** @var Article $current */
                    $current = Article::withTrashed()->lockForUpdate()->findOrFail($id);

                    $attributes['cover_media_id'] = $this->resolveCoverMediaId(
                        $coverUploadId,
                        $coverSelectId,
                        $clearCover,
                        (int) $current->cover_media_id
                    );
                    $attributes['wechat_cover_media_id'] = $this->resolveCoverMediaId(
                        $wechatCoverUploadId,
                        $wechatCoverSelectId,
                        $wechatClearCover,
                        (int) $current->wechat_cover_media_id
                    );

                    $article = $this->articleService->update($current, $attributes);
                    $this->articleService->syncTags($article, $tagIds);
                    $this->articleService->syncHairstyles($article, $hairstyleIds);
                    $this->articleService->syncHairColors($article, $hairColorIds);

                    $targetStatus = ContentStatus::tryFrom((int) $targetStatusValue);

                    if ($targetStatus) {
                        $article = $this->articleService->changeStatus($article->id, $targetStatus, [
                            'reviewed_by' => $reviewerId,
                            'review_remark' => $reviewRemark,
                        ]);
                    }

                    return $article;
                }

                $attributes['cover_media_id'] = $this->resolveCoverMediaId($coverUploadId, $coverSelectId, $clearCover, 0);
                $attributes['wechat_cover_media_id'] = $this->resolveCoverMediaId($wechatCoverUploadId, $wechatCoverSelectId, $wechatClearCover, 0);

                return $this->articleService->create($attributes, $tagIds, $hairstyleIds, $hairColorIds);
            });
        } catch (ValidationException $e) {
            return JsonResponse::make()->error($this->firstValidationMessage($e));
        } catch (ModelNotFoundException $e) {
            return JsonResponse::make()->error('文章不存在');
        } catch (\Throwable $e) {
            report($e);

            return JsonResponse::make()->error(
                config('app.debug') ? ('保存失败：'.$e->getMessage()) : '保存失败，请查看系统日志。'
            );
        }

        $response = JsonResponse::make()->success($id ? '更新成功' : '创建成功，请继续在“媒体与关联”标签页管理正文图片');

        return $id
            ? $response->refresh()
            : $response->redirect(admin_url('articles/'.$article->id.'/edit'));
    }

    /**
     * 软删除前的统一处理：调用 ArticleService::delete()（只执行软删除，不删除
     * MediaFile / WechatArticle / 标签发型发色关联，规则完全由 Service 维护）。
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
            foreach ($ids as $articleId) {
                $this->articleService->delete($articleId);
            }
        } catch (ModelNotFoundException $e) {
            return JsonResponse::make()->error('文章不存在');
        }

        return JsonResponse::make()->success('删除成功')->refresh();
    }

    /**
     * @param  mixed  $input
     * @return array<int, int>
     */
    private function extractIntArray($input): array
    {
        return array_values(array_unique(array_map('intval', array_filter((array) $input))));
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

    /**
     * 微信同步状态徽标：只读取已预加载的 wechatArticle 关联，不在渲染过程中触发新查询；
     * 尚不存在预留记录时（理论上创建/状态流转后必然存在）给出明确提示，不报错。
     */
    public static function renderWechatSyncBadge($wechatArticle): string
    {
        if (! $wechatArticle) {
            return '<span class="label label-default">未初始化</span>';
        }

        /** @var WechatSyncStatus $status */
        $status = $wechatArticle->sync_status;

        return '<span class="label label-'.e($status->color()).'">'.e($status->label()).'</span>';
    }

    /**
     * 正文/公众号正文详情预览：保留原始换行，转义防止 XSS，不作为可执行 HTML 渲染。
     */
    public static function renderTextPreview(string $value): string
    {
        if (trim($value) === '') {
            return '-';
        }

        return '<pre style="white-space:pre-wrap;word-break:break-all;max-height:400px;overflow:auto;">'.e($value).'</pre>';
    }

    /**
     * “正文图片 / 图集 / 附件管理”入口面板：跳转到 ArticleMediaController 子页面，
     * 不在本表单内重复实现媒体关联的增删改逻辑。
     */
    public static function renderMediaManagementPanel(int $articleId): string
    {
        $url = admin_url('article-media?article_id='.$articleId);

        return '<a href="'.e($url).'" target="_blank" class="btn btn-primary">'.
            '<i class="feather icon-image"></i> 进入正文图片 / 图集 / 附件管理</a>'.
            '<div style="margin-top:6px;color:#666;">在新页面中关联/上传图片、设置 ALT 文本和说明、排序或移除关联；'.
            '移除关联不会删除媒体库中的原始文件。</div>';
    }

    public static function renderRestoreAction(Article $model): string
    {
        $url = admin_url('articles/'.$model->id.'/restore');
        $token = csrf_token();

        return <<<HTML
<form method="POST" action="{$url}" style="display:inline-block;margin:0 5px;" onsubmit="return confirm('确定要恢复该文章吗？');">
    <input type="hidden" name="_token" value="{$token}">
    <input type="hidden" name="_method" value="PUT">
    <button type="submit" class="btn btn-link" style="padding:0;border:0;background:none;color:#28a745;" title="恢复"><i class="feather icon-rotate-ccw"></i></button>
</form>
HTML;
    }
}
