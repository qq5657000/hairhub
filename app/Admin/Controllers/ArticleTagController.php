<?php

namespace App\Admin\Controllers;

use App\Enums\Common\CommonStatus;
use App\Models\ArticleTag;
use App\Services\Content\ArticleTagService;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Http\JsonResponse;
use Dcat\Admin\Layout\Content;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * 文章标签 Dcat Admin 后台管理。
 *
 * 严格复用第一阶段已完成的 ArticleTagService，删除规则与已有发型标签模块
 * （HairstyleTagController）保持一致：标签仍被文章引用时拒绝删除。
 * article_tags 不使用 SoftDeletes（与 hairstyle_tags 一致），因此本控制器
 * 不提供回收站/恢复入口。
 *
 * saving()/deleting() 回调始终返回非空 JsonResponse，短路 Dcat 默认的
 * store()/update()/destroy() 持久化流程，确保“保存/删除”只有 Service 这一个
 * 真正的写入入口。
 */
class ArticleTagController extends AdminController
{
    private ArticleTagService $tagService;

    public function __construct(ArticleTagService $tagService)
    {
        $this->tagService = $tagService;
    }

    public function index(Content $content)
    {
        return $content
            ->header('文章标签')
            ->description('列表')
            ->body($this->grid());
    }

    /**
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new ArticleTag(), function (Grid $grid) {
            // withCount() 统计关联文章数量，避免在下面的列渲染中逐行触发关联查询（N+1）。
            $grid->model()
                ->withCount('articles')
                ->orderByDesc('sort')
                ->orderByDesc('id');

            $grid->column('id')->sortable();
            $grid->column('name', '标签名称');
            $grid->column('slug', 'Slug');
            $grid->column('articles_count', '文章数量')->sortable();
            $grid->column('status', '状态')->using(CommonStatus::options())->label([
                CommonStatus::Disabled->value => 'default',
                CommonStatus::Enabled->value => 'success',
            ]);
            $grid->column('sort', '排序')->sortable();
            $grid->column('created_at', '创建时间');
            $grid->column('updated_at', '更新时间');

            $grid->setActionClass(Grid\Displayers\Actions::class);

            $grid->filter(function (Grid\Filter $filter) {
                $filter->like('name', '标签名称');
                $filter->like('slug', 'Slug');
                $filter->equal('status', '状态')->select(CommonStatus::options());
            });
        });
    }

    /**
     * @param  mixed  $id
     * @return Show
     */
    protected function detail($id)
    {
        return Show::make($id, new ArticleTag(), function (Show $show) {
            $show->field('id');
            $show->field('name', '标签名称');
            $show->field('slug', 'Slug');
            $show->field('description', '标签说明');
            $show->field('status', '状态')->using(CommonStatus::options());
            $show->field('sort', '排序');
            $show->field('created_at', '创建时间');
            $show->field('updated_at', '更新时间');
        });
    }

    /**
     * @return Form
     */
    protected function form()
    {
        $self = $this;

        return Form::make(new ArticleTag(), function (Form $form) use ($self) {
            $id = $form->getKey();

            $form->display('id');

            $form->text('name', '标签名称')->required()->rules('max:100');
            $form->text('slug', 'Slug')
                ->required()
                ->rules(['required', 'max:150', 'regex:/^[a-z0-9-]+$/'])
                ->help('仅允许小写字母、数字和短横线；唯一性由保存时的业务校验负责');
            $form->textarea('description', '标签说明')->rows(3)->rules('max:500');

            $form->radio('status', '状态')
                ->options(CommonStatus::options())
                ->default(CommonStatus::Enabled->value);

            $form->number('sort', '排序值')->min(0)->default(0);

            $form->saving(function (Form $form) use ($self) {
                return $self->handleSaving($form);
            });

            $form->deleting(function (Form $form) use ($self) {
                return $self->handleDeleting($form);
            });
        });
    }

    /**
     * 保存前的统一处理：调用 ArticleTagService::create()/update()。
     *
     * 本方法始终返回非空 JsonResponse，短路 Dcat 默认的 store()/update() 流程。
     */
    private function handleSaving(Form $form): JsonResponse
    {
        $id = $form->getKey();

        $attributes = [
            'name' => (string) $form->input('name'),
            'slug' => (string) $form->input('slug'),
            'description' => (string) ($form->input('description') ?? ''),
            'status' => (int) ($form->input('status') ?? CommonStatus::Enabled->value),
            'sort' => (int) ($form->input('sort') ?? 0),
        ];

        try {
            if ($id) {
                /** @var ArticleTag $tag */
                $tag = ArticleTag::query()->findOrFail($id);
                $this->tagService->update($tag, $attributes);
            } else {
                $tag = $this->tagService->create($attributes);
            }
        } catch (ValidationException $e) {
            return JsonResponse::make()->error($this->firstValidationMessage($e));
        } catch (\Throwable $e) {
            report($e);

            return JsonResponse::make()->error(
                config('app.debug') ? ('保存失败：'.$e->getMessage()) : '保存失败，请查看系统日志。'
            );
        }

        $response = JsonResponse::make()->success($id ? '更新成功' : '创建成功');

        return $id
            ? $response->refresh()
            : $response->redirect(admin_url('article-tags/'.$tag->id.'/edit'));
    }

    /**
     * 软删除前的统一处理：调用 ArticleTagService::delete()（该标签仍被文章引用时拒绝删除）。
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
            foreach ($ids as $tagId) {
                $this->tagService->delete($tagId);
            }
        } catch (ValidationException $e) {
            return JsonResponse::make()->error($this->firstValidationMessage($e));
        } catch (ModelNotFoundException $e) {
            return JsonResponse::make()->error('标签不存在');
        }

        return JsonResponse::make()->success('删除成功')->refresh();
    }

    private function firstValidationMessage(ValidationException $e): string
    {
        return collect($e->errors())->collapse()->first() ?? '操作失败';
    }

    /**
     * 供 ArticleController 表单“文章标签”多选字段使用：展示全部标签（含已禁用，
     * 标注“已禁用”后仍展示）。标签数量在 V1.0 预期规模不大，直接一次性加载全部
     * 选项供多选组件本地搜索，不需要 AJAX 远程搜索（与发型标签在
     * HairstyleController::tagOptions() 的处理方式一致）。
     *
     * @return array<int, string>
     */
    public static function tagOptions(): array
    {
        return ArticleTag::query()
            ->orderByDesc('sort')
            ->orderByDesc('id')
            ->get(['id', 'name', 'status'])
            ->mapWithKeys(function (ArticleTag $tag) {
                $suffix = (int) $tag->status === CommonStatus::Enabled->value ? '' : '（已禁用）';

                return [$tag->id => $tag->name.$suffix];
            })
            ->all();
    }
}
