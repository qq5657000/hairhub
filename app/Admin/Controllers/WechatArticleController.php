<?php

namespace App\Admin\Controllers;

use App\Admin\Controllers\Concerns\FormatsEnumBadges;
use App\Enums\Wechat\WechatPublishStatus;
use App\Enums\Wechat\WechatSyncStatus;
use App\Models\WechatArticle;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Layout\Content;

/**
 * 微信公众号同步状态只读预留页面。
 *
 * 本阶段仅维护数据结构和默认状态展示（Pending / Unpublished），不接入任何真实
 * 微信接口，也不提供“假同步成功”按钮——本控制器只注册了 index()/show() 两个
 * 只读入口（见 app/Admin/routes.php），刻意不注册 create()/store()/edit()/
 * update()/destroy() 对应的路由，从路由层面阻断新增、编辑、删除，而不仅仅是
 * 隐藏 UI 按钮：即使有人直接构造 POST/PUT/DELETE 请求，Laravel 也会因为路由
 * 不存在直接返回 404，不会触达任何写入逻辑。
 *
 * WechatArticle 记录的唯一写入入口仍然是 ArticleService::ensureWechatArticleRecord()
 * （创建文章 / 状态流转时自动维护），本控制器不提供任何触碰 sync_status /
 * publish_status 的表单或按钮。
 */
class WechatArticleController extends AdminController
{
    use FormatsEnumBadges;

    public function index(Content $content)
    {
        return $content
            ->header('公众号同步状态')
            ->description('只读预留，公众号接口尚未接入')
            ->body($this->grid());
    }

    /**
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new WechatArticle(), function (Grid $grid) {
            // with() 预加载 article，避免在下面的列 display() 中逐行触发关联查询（N+1）。
            $grid->model()
                ->with('article')
                ->orderByDesc('id');

            $grid->tools(function (Grid\Tools $tools) {
                $tools->append(
                    '<div class="alert alert-info" style="margin:0 0 10px;">'.
                    '公众号接口尚未接入，当前仅用于预留和查看同步状态。'.
                    '</div>'
                );
            });

            $grid->column('id')->sortable();
            $grid->column('article_title', '文章标题')->display(function () {
                if (! $this->article) {
                    return '文章不存在或已被删除（ID：'.$this->article_id.'）';
                }

                $url = admin_url('articles/'.$this->article_id.'/edit');

                return '<a href="'.e($url).'" target="_blank">'.e($this->article->title).'</a>';
            });
            $grid->column('sync_status', '同步状态')
                ->using(WechatSyncStatus::options())
                ->label(WechatArticleController::enumColorMap(WechatSyncStatus::class));
            $grid->column('publish_status', '发布状态')
                ->using(WechatPublishStatus::options())
                ->label(WechatArticleController::enumColorMap(WechatPublishStatus::class));
            $grid->column('wechat_media_id', '微信素材 ID')->display(fn ($value) => $value ?: '-');
            $grid->column('wechat_article_id', '微信文章 ID')->display(fn ($value) => $value ?: '-');
            $grid->column('wechat_url', '微信文章地址')->display(function ($value) {
                if (! $value) {
                    return '-';
                }

                return '<a href="'.e($value).'" target="_blank">打开链接</a>';
            });
            $grid->column('synced_at', '最近同步时间')->display(fn ($value) => $value ?: '-');
            $grid->column('published_at', '微信发布时间')->display(fn ($value) => $value ?: '-');
            $grid->column('error_code', '错误码')->display(fn ($value) => $value ?: '-');
            $grid->column('error_message', '错误信息')->display(function ($value) {
                return WechatArticleController::truncateErrorMessage((string) ($value ?? ''));
            });
            $grid->column('updated_at', '更新时间');

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('sync_status', '同步状态')->select(WechatSyncStatus::options());
                $filter->equal('publish_status', '发布状态')->select(WechatPublishStatus::options());
            });

            // 只读预留：默认禁止新增、删除、直接编辑（包括“成功”状态），且对应的
            // create/store/edit/update/destroy 路由本身也未注册，双重阻断。
            $grid->disableCreateButton();
            $grid->disableBatchDelete();
            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $actions->disableEdit();
                $actions->disableDelete();
            });
        });
    }

    /**
     * @param  mixed  $id
     * @return Show
     */
    protected function detail($id)
    {
        /** @var WechatArticle $record */
        $record = WechatArticle::query()->with('article')->findOrFail($id);

        return Show::make($id, new WechatArticle(), function (Show $show) use ($record) {
            $show->field('id');
            $show->field('article_title', '文章标题')->as(function () use ($record) {
                return $record->article->title ?? ('文章不存在或已被删除（ID：'.$record->article_id.'）');
            });
            $show->field('sync_status', '同步状态')->using(WechatSyncStatus::options());
            $show->field('publish_status', '发布状态')->using(WechatPublishStatus::options());
            $show->field('wechat_media_id', '微信素材 ID')->as(fn ($value) => $value ?: '-');
            $show->field('wechat_article_id', '微信文章 ID')->as(fn ($value) => $value ?: '-');
            $show->field('wechat_url', '微信文章地址')->as(function ($value) {
                return $value ? '<a href="'.e($value).'" target="_blank">'.e($value).'</a>' : '-';
            })->unescape();
            $show->field('synced_at', '最近同步时间')->as(fn ($value) => $value ?: '-');
            $show->field('published_at', '微信发布时间')->as(fn ($value) => $value ?: '-');
            $show->field('error_code', '错误码')->as(fn ($value) => $value ?: '-');
            $show->field('error_message', '错误信息（完整）')->as(function ($value) {
                return $value ? '<pre style="white-space:pre-wrap;word-break:break-all;">'.e($value).'</pre>' : '-';
            })->unescape();
            $show->field('created_at', '创建时间');
            $show->field('updated_at', '更新时间');

            $show->panel()->tools(function ($tools) {
                $tools->disableEdit();
                $tools->disableDelete();
            });
        });
    }

    /**
     * 列表“错误信息”列过长时截断，完整内容需点击查看详情（Show 页面）。
     */
    public static function truncateErrorMessage(string $message): string
    {
        if ($message === '') {
            return '-';
        }

        if (mb_strlen($message) <= 40) {
            return e($message);
        }

        return e(mb_substr($message, 0, 40)).'…';
    }
}
