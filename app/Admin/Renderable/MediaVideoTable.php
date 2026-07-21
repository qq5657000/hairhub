<?php

namespace App\Admin\Renderable;

use App\Admin\Controllers\MediaFileController;
use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaStatus;
use App\Models\MediaFile;
use Dcat\Admin\Grid;
use Dcat\Admin\Grid\LazyRenderable;

/**
 * 视频媒体选择表格（Dcat SelectTable 弹窗内嵌表格）。
 *
 * 与 MediaImageTable 完全同构，唯一区别是 file_type 过滤条件改为视频类型，
 * 供 VideoController“本地视频媒体”字段的“从媒体库选择”弹窗复用，不重新实现
 * 媒体查询/预览/分页逻辑，也不修改 MediaImageTable（避免影响发型/发色/文章
 * 封面选择场景）。
 */
class MediaVideoTable extends LazyRenderable
{
    public function grid(): Grid
    {
        return Grid::make(new MediaFile(), function (Grid $grid) {
            $grid->model()
                ->where('file_type', MediaFileType::Video->value)
                ->where('status', MediaStatus::Active->value)
                ->orderByDesc('id');

            $grid->column('id')->sortable();
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
}
