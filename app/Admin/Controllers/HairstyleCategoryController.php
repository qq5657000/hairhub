<?php

namespace App\Admin\Controllers;

use App\Enums\Hairstyle\HairstyleCategoryStatus;
use App\Enums\Media\MediaFileType;
use App\Models\Hairstyle;
use App\Models\HairstyleCategory;
use App\Models\MediaFile;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Http\JsonResponse;
use Dcat\Admin\Layout\Content;

class HairstyleCategoryController extends AdminController
{
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
        return Show::make($id, new HairstyleCategory(), function (Show $show) {
            $show->field('id');
            $show->field('parent_id', '父分类 ID');
            $show->field('name', '分类名称');
            $show->field('name_en', '英文名称');
            $show->field('slug');
            $show->field('description', '分类简介');
            $show->field('cover_media_id', '封面媒体 ID');
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
        return Form::make(new HairstyleCategory(), function (Form $form) {
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

            if ($id) {
                $category = HairstyleCategory::query()->with('coverMedia')->find($id);

                if ($category && $category->coverMedia) {
                    $previewUrl = $category->coverMedia->thumbnail_url ?: $category->coverMedia->url;

                    $form->html(
                        '<img src="'.e($previewUrl).'" style="max-width:120px;max-height:120px;border-radius:4px;" />',
                        '当前封面预览'
                    );
                }
            }

            $form->select('cover_media_id', '封面媒体')
                ->options($this->coverMediaOptions())
                ->default(0)
                ->help('从已有媒体资源中选择封面，0 表示暂无封面');

            $form->radio('status', '状态')
                ->options(HairstyleCategoryStatus::options())
                ->default(HairstyleCategoryStatus::Enabled->value);

            $form->number('sort', '排序值')->min(0)->default(0);

            $form->saving(function (Form $form) {
                $id = $form->getKey();

                if (! $id) {
                    return;
                }

                $parentId = (int) $form->parent_id;

                if ($parentId === (int) $id) {
                    return JsonResponse::make()->error('父分类不能设置为自身');
                }

                $descendantIds = HairstyleCategory::query()->where('parent_id', $id)->pluck('id')->all();

                if ($parentId && in_array($parentId, $descendantIds, true)) {
                    return JsonResponse::make()->error('父分类不能设置为自身的子分类');
                }
            });

            $form->deleting(function (Form $form) {
                $ids = collect(explode(',', (string) $form->getKey()))
                    ->filter()
                    ->map(fn ($value) => (int) $value)
                    ->values();

                if ($ids->isEmpty()) {
                    return;
                }

                if (HairstyleCategory::query()->whereIn('parent_id', $ids)->exists()) {
                    return JsonResponse::make()->error('存在子分类，请先删除或转移子分类后再操作');
                }

                if (Hairstyle::query()->whereIn('category_id', $ids)->exists()) {
                    return JsonResponse::make()->error('该分类下存在未删除的发型，请先处理发型数据后再操作');
                }
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
     * 封面媒体下拉选项（第一版：从已有图片类媒体中选择，暂无独立媒体选择组件）.
     *
     * @return array<int, string>
     */
    protected function coverMediaOptions(): array
    {
        $options = MediaFile::query()
            ->where('file_type', MediaFileType::Image->value)
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->mapWithKeys(function (MediaFile $media) {
                return [$media->id => sprintf('#%d %s', $media->id, $media->original_name ?: $media->filename)];
            })
            ->all();

        return [0 => '无封面'] + $options;
    }
}
