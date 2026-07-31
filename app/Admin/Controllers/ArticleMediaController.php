<?php

namespace App\Admin\Controllers;

use App\Admin\Controllers\Concerns\FormatsEnumBadges;
use App\Enums\Article\ArticleMediaType;
use App\Enums\Media\MediaStatus;
use App\Models\Article;
use App\Models\ArticleMedia;
use App\Models\MediaFile;
use App\Services\Content\ArticleMediaService;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Http\JsonResponse;
use Dcat\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 文章媒体关联管理（文章编辑页“媒体与关联”标签页中“图集 / 附件 / 正文图片关联管理”
 * 的独立子页面入口）。
 *
 * 角色说明（“正文编辑体验”任务后已更新）：网站正文 / 公众号正文已改为直接使用
 * Dcat-Plus 自带 TinyMCE（见 ArticleController::editorImageUpload()），运营人员
 * 在正文编辑器里点击“图片”按钮上传即可，图片会实时登记进 media_files；文章保存
 * 成功后 ArticleMediaService::syncContentImagesFromHtml() 会自动解析正文中的
 * <img> 标签并增量同步 ContentImage 类型的 article_media 关联，不再需要运营人员
 * 先来本页面手工关联/上传图片再复制地址粘贴回正文。
 *
 * 本控制器现在的职责：
 * - 查看正文图片（ContentImage）自动同步的关联结果；
 * - 管理图集（Gallery）、附件（Attachment）——这两类用途没有对应的富文本编辑器
 *   入口，仍然需要在本页面手工关联/上传；
 * - 修改任意关联记录的 ALT 文本、说明（caption）、排序；
 * - 手动补充或移除关联（移除只删除 article_media 记录，不删除 media_files 原始文件）。
 *
 * 交互模式复用已验证可用的 HairstyleMediaController，所有会影响 article_media
 * 一致性的写操作全部委托给 ArticleMediaService，不在本控制器或 Form 回调中重复
 * 实现校验逻辑。
 *
 * 访问方式：/admin/article-media?article_id={文章ID}（通过文章编辑页“媒体与关联”
 * 标签页跳转）。
 */
class ArticleMediaController extends AdminController
{
    use FormatsEnumBadges;

    private ArticleMediaService $service;

    public function __construct(ArticleMediaService $service)
    {
        $this->service = $service;
    }

    public function index(Content $content)
    {
        return $content
            ->header('文章媒体管理')
            ->description('查看正文图片自动同步结果 / 管理图集与附件 / 编辑 ALT・说明・排序 / 移除关联')
            ->body($this->grid());
    }

    /**
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new ArticleMedia(), function (Grid $grid) {
            $articleId = (int) request('article_id');

            // 本页始终按 article_id 过滤，只服务于单篇文章的媒体关联管理，
            // 不存在需要跨文章展示全部 article_media 的场景。
            $grid->model()
                ->with('media')
                ->where('article_id', $articleId)
                ->orderBy('media_type')
                ->orderBy('sort')
                ->orderByDesc('id');

            $grid->column('id')->sortable();
            $grid->column('preview', '预览')->display(function () {
                return ArticleMediaController::renderPreviewFor($this);
            });
            $grid->column('media_type', '用途')->display(function ($value) {
                return ArticleMediaController::enumLabel($value, ArticleMediaType::class);
            });
            $grid->column('alt_text', 'ALT 文本');
            $grid->column('caption', '说明');
            $grid->column('sort', '排序')->sortable();
            $grid->column('created_at', '关联时间');

            $grid->disableCreateButton();
            $grid->disableBatchDelete();

            $grid->tools(function (Grid\Tools $tools) use ($articleId) {
                $article = Article::withTrashed()->find($articleId);
                $title = $article ? $article->title : '未知文章';
                $createUrl = admin_url('article-media/create?article_id='.$articleId);
                $backUrl = $article ? admin_url('articles/'.$articleId.'/edit') : admin_url('articles');

                $tools->append(
                    '<a href="'.e($createUrl).'" class="btn btn-primary"><i class="feather icon-plus"></i> 关联媒体</a> '
                    .'<a href="'.e($backUrl).'" class="btn btn-default"><i class="feather icon-arrow-left"></i> 返回文章「'.e($title).'」</a>'
                );
            });

            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $actions->disableView();
            });
        });
    }

    /**
     * @param  mixed  $id
     * @return Show
     */
    protected function detail($id)
    {
        return Show::make($id, new ArticleMedia(), function (Show $show) {
            /** @var ArticleMedia $relation */
            $relation = $show->model()->load('article', 'media');

            $show->field('id');
            $show->field('article_title', '所属文章')->as(function () use ($relation) {
                return $relation->article->title ?? '-';
            });
            $show->field('media_display', '媒体')->as(function () use ($relation) {
                return $relation->media
                    ? sprintf('#%d %s', $relation->media->id, $relation->media->original_name ?: $relation->media->filename)
                    : '-';
            });
            $show->field('media_type', '用途')->as(function ($value) {
                return ArticleMediaController::enumLabel($value, ArticleMediaType::class);
            });
            $show->field('alt_text', 'ALT 文本');
            $show->field('caption', '说明');
            $show->field('sort', '排序值');
            $show->field('created_at', '关联时间');
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

        return Form::make(new ArticleMedia(), function (Form $form) use ($self) {
            $id = $form->getKey();

            /** @var ArticleMedia|null $existing */
            $existing = $id ? ArticleMedia::query()->find($id) : null;
            $articleId = $existing ? (int) $existing->article_id : (int) request('article_id');
            $article = Article::withTrashed()->find($articleId);

            $form->display('id');
            $form->html('<b>所属文章：</b>'.($article ? e($article->title).' （#'.$articleId.'）' : '未知，请从文章编辑页的“媒体与关联”标签页进入本页面'));
            $form->hidden('article_id')->value($articleId);

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
                    ->ajax('article-media/media-options')
                    ->required()
                    ->help('仅可选择状态为“启用”的媒体资源，支持关键字搜索；“正文图片/图集”用途仅可选择图片类型');
            } else {
                $media = $existing?->media;
                $form->display('media_display', '媒体')->with(function () use ($media) {
                    return $media ? sprintf('#%d %s', $media->id, $media->original_name ?: $media->filename) : '-';
                });
            }

            $form->select('media_type', '用途')->options(ArticleMediaType::options())->default(ArticleMediaType::ContentImage->value)->required();
            $form->text('alt_text', 'ALT 文本')->rules('max:255')->help('用于图片 SEO，建议简要描述图片内容');
            $form->textarea('caption', '说明')->rows(2)->rules('max:500');
            $form->number('sort', '排序值')->min(0)->default(0);

            if ($existing?->media) {
                $preview = ArticleMediaController::renderMediaUrlHint($existing->media);
                $form->html($preview, '图片访问地址');
            }

            $form->saving(function (Form $form) use ($self) {
                return $form->isCreating() ? $self->handleCreating($form) : $self->handleUpdating($form);
            });

            $form->deleting(function (Form $form) use ($self) {
                return $self->handleDeleting($form);
            });
        });
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
     * 新建关联：调用 ArticleMediaService::attachMedia()，短路 Dcat 默认的 store() 流程。
     */
    private function handleCreating(Form $form): JsonResponse
    {
        $articleId = (int) $form->input('article_id');
        $mediaId = (int) $form->input('media_id');
        $mediaTypeValue = (int) $form->input('media_type');

        if (! $articleId || ! $mediaId) {
            return JsonResponse::make()->error('缺少文章或媒体信息');
        }

        $type = ArticleMediaType::tryFrom($mediaTypeValue);

        if (! $type) {
            return JsonResponse::make()->error('用途取值不合法');
        }

        try {
            $this->service->attachMedia($articleId, $mediaId, $type, $this->extractAttributes($form));
        } catch (ValidationException $e) {
            return JsonResponse::make()->error($this->firstValidationMessage($e));
        } catch (\Throwable $e) {
            return JsonResponse::make()->error('关联失败：'.$e->getMessage());
        }

        return JsonResponse::make()
            ->success('关联成功')
            ->redirect(admin_url('article-media?article_id='.$articleId));
    }

    /**
     * 更新关联：统一调用 ArticleMediaService::updateRelation()（仅更新 alt_text/caption/sort，
     * 不允许通过本入口修改 media_id/media_type，避免绕过 attachMedia() 的类型/状态校验）。
     */
    private function handleUpdating(Form $form): JsonResponse
    {
        $id = (int) $form->getKey();

        try {
            $this->service->updateRelation($id, $this->extractAttributes($form));
        } catch (\Throwable $e) {
            return JsonResponse::make()->error('保存失败：'.$e->getMessage());
        }

        return JsonResponse::make()->success('保存成功')->refresh();
    }

    /**
     * 移除关联：统一调用 ArticleMediaService::detachMedia()，兼容单个/批量删除请求；
     * 只删除 article_media 关联记录，不会删除 media_files 原始记录。
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

        $relations = ArticleMedia::query()->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $relationId) {
            $relation = $relations->get($relationId);

            if (! $relation) {
                continue;
            }

            /** @var ArticleMediaType $type */
            $type = $relation->media_type;

            try {
                $this->service->detachMedia((int) $relation->article_id, (int) $relation->media_id, $type);
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
            'alt_text' => (string) ($form->input('alt_text') ?? ''),
            'caption' => (string) ($form->input('caption') ?? ''),
            'sort' => (int) ($form->input('sort') ?? 0),
        ];
    }

    private function firstValidationMessage(ValidationException $e): string
    {
        return collect($e->errors())->collapse()->first() ?? '操作失败';
    }

    /**
     * 列表“预览”列：优先展示缩略图，无媒体或非图片时展示占位文字，只读取已预加载的 media 关联。
     */
    public static function renderPreviewFor(ArticleMedia $model): string
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

    /**
     * 编辑页展示图片完整访问地址，供需要在图集/附件等场景之外核对或引用地址时查看，
     * 正文图片本身已不再需要经由本页面手工复制粘贴（详见类头部注释）。
     */
    public static function renderMediaUrlHint(MediaFile $media): string
    {
        $url = $media->url ?: '';

        if ($url === '') {
            return '<div class="alert alert-warning" style="margin-bottom:0;">该媒体暂无可用访问地址</div>';
        }

        return '<input type="text" readonly value="'.e($url).'" class="form-control" onclick="this.select();" style="max-width:520px;" />'
            .'<div style="margin-top:4px;color:#666;">点击选中后复制，可用于核对地址或其他手工引用场景</div>';
    }
}
