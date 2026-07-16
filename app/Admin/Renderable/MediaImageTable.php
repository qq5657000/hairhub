<?php

namespace App\Admin\Renderable;

use App\Admin\Controllers\MediaFileController;
use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaStatus;
use App\Models\MediaFile;
use Dcat\Admin\Grid;
use Dcat\Admin\Grid\LazyRenderable;

/**
 * 图片媒体选择表格（Dcat SelectTable 弹窗内嵌表格）。
 *
 * 供发型封面（HairstyleController）和分类封面（HairstyleCategoryController）的
 * “从媒体库选择”字段共同复用，只服务于封面选择场景，因此：
 * - 只查询 file_type = 图片、status = 启用的 media_files；
 * - 分页展示，不一次性加载全部媒体；
 * - 禁用增删等无关操作，仅保留搜索和单选。
 */
class MediaImageTable extends LazyRenderable
{
    public function grid(): Grid
    {
        return Grid::make(new MediaFile(), function (Grid $grid) {
            $grid->model()
                ->where('file_type', MediaFileType::Image->value)
                ->where('status', MediaStatus::Active->value)
                ->orderByDesc('id');

            $grid->column('id')->sortable();
            $grid->column('preview', '预览')->display(function () {
                return MediaImageTable::renderPreviewFor($this);
            });
            $grid->column('original_name', '原始文件名');
            $grid->column('size', '文件大小')->display(function ($value) {
                return MediaFileController::formatBytes((int) $value);
            });
            $grid->column('created_at', '上传时间');

            $grid->disableActions();
            $grid->disableCreateButton();
            $grid->disableBatchDelete();
            $grid->disableRefreshButton();

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id', 'ID');
                $filter->like('original_name', '原始文件名');
            });
        });
    }

    public static function renderPreviewFor(MediaFile $media): string
    {
        $src = $media->thumbnail_url ?: $media->url;

        if (! $src) {
            return '-';
        }

        return '<img src="'.e($src).'" style="width:50px;height:50px;object-fit:cover;border-radius:4px;" />';
    }
}
