<?php

namespace App\Admin\Controllers;

use App\Enums\Hairstyle\HairstyleTagStatus;
use App\Enums\Hairstyle\HairstyleTagType;
use App\Models\HairstyleTag;
use App\Models\HairstyleTagRelation;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Http\JsonResponse;
use Dcat\Admin\Layout\Content;

class HairstyleTagController extends AdminController
{
    /**
     * page index
     */
    public function index(Content $content)
    {
        return $content
            ->header('发型标签')
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
        return Grid::make(new HairstyleTag(), function (Grid $grid) {
            $grid->model()
                ->withCount('tagRelations')
                ->orderBy('sort', 'desc')
                ->orderBy('id', 'desc');

            $grid->column('id')->sortable();
            $grid->column('name', '标签名称');
            $grid->column('name_en', '英文名称');
            $grid->column('slug');
            $grid->column('type', '标签类型')->using(HairstyleTagType::options());
            $grid->column('status')->using(HairstyleTagStatus::options());
            $grid->column('tag_relations_count', '关联发型数')->sortable();
            $grid->column('sort')->sortable();
            $grid->column('created_at');
            $grid->column('updated_at');

            $grid->setActionClass(Grid\Displayers\Actions::class);

            $grid->filter(function (Grid\Filter $filter) {
                $filter->like('name', '标签名称');
                $filter->like('slug', 'Slug');
                $filter->equal('type', '标签类型')->select(HairstyleTagType::options());
                $filter->equal('status', '状态')->select(HairstyleTagStatus::options());
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
        return Show::make($id, new HairstyleTag(), function (Show $show) {
            $show->field('id');
            $show->field('name', '标签名称');
            $show->field('name_en', '英文名称');
            $show->field('slug');
            $show->field('type')->using(HairstyleTagType::options());
            $show->field('status')->using(HairstyleTagStatus::options());
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
        return Form::make(new HairstyleTag(), function (Form $form) {
            $form->display('id');

            $form->text('name', '标签名称')->required()->rules('max:100');
            $form->text('name_en', '英文名称')->rules('max:150');
            $form->text('slug', 'Slug')
                ->required()
                ->rules('max:150|unique:hairstyle_tags,slug,{{id}}')
                ->help('仅建议使用小写字母、数字和短横线');

            $form->radio('type', '标签类型')
                ->options(HairstyleTagType::options())
                ->rules('required|in:'.implode(',', HairstyleTagType::values()))
                ->default(HairstyleTagType::General->value);

            $form->radio('status', '状态')
                ->options(HairstyleTagStatus::options())
                ->rules('required|in:'.implode(',', HairstyleTagStatus::values()))
                ->default(HairstyleTagStatus::Enabled->value);

            $form->number('sort', '排序值')->min(0)->default(0);

            $form->deleting(function (Form $form) {
                $ids = collect(explode(',', (string) $form->getKey()))
                    ->filter()
                    ->map(fn ($value) => (int) $value)
                    ->values();

                if ($ids->isEmpty()) {
                    return;
                }

                if (HairstyleTagRelation::query()->whereIn('tag_id', $ids)->exists()) {
                    return JsonResponse::make()->error('该标签已关联发型，请先解除关联后再删除');
                }
            });
        });
    }
}
