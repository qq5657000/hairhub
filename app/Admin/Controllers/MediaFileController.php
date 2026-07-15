<?php

namespace App\Admin\Controllers;

use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaSourceType;
use App\Enums\Media\MediaStatus;
use App\Enums\Media\MediaVisibility;
use App\Models\MediaFile;
use App\Services\Media\MediaFileService;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Http\JsonResponse;
use Dcat\Admin\Layout\Content;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class MediaFileController extends AdminController
{
    /**
     * Dcat File 字段上传成功后，实际物理文件名与原始文件名的临时映射缓存前缀。
     *
     * File 字段的 AJAX 预上传（选择文件时）与表单主提交（点击保存时）是两次独立请求，
     * 主提交只能拿到已落盘文件的相对路径，拿不到浏览器端的原始文件名，
     * 因此在生成存储文件名的同时，把原始文件名临时缓存，主提交时再取出使用。
     */
    public const UPLOAD_META_CACHE_PREFIX = 'media_file_upload_meta:';

    private MediaFileService $service;

    public function __construct(MediaFileService $service)
    {
        $this->service = $service;
    }

    /**
     * page index
     */
    public function index(Content $content)
    {
        return $content
            ->header('媒体资源')
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
        return Grid::make(new MediaFile(), function (Grid $grid) {
            $grid->model()->orderBy('id', 'desc');

            $grid->column('id')->sortable();
            $grid->column('file_no', '文件编号');
            $grid->column('file_type', '文件类型')->using(MediaFileType::options());
            $grid->column('preview', '预览')->display(function () {
                // 列表 display() 闭包会被 Dcat 重新绑定到当前行模型，
                // 因此这里通过静态方法接收行模型（$this），而不是调用控制器实例方法。
                return MediaFileController::renderPreviewFor($this);
            });
            $grid->column('original_name', '原始文件名');
            $grid->column('storage', '存储驱动');
            $grid->column('path', '相对路径');
            $grid->column('size', '文件大小')->display(function ($value) {
                return MediaFileController::formatBytes((int) $value);
            });
            $grid->column('dimensions', '图片宽高')->display(function () {
                if ((int) $this->width <= 0 || (int) $this->height <= 0) {
                    return '-';
                }

                return $this->width.' × '.$this->height;
            });
            $grid->column('source_type', '来源类型')->using(MediaSourceType::options());
            $grid->column('visibility', '访问级别')->using(MediaVisibility::options());
            $grid->column('status', '状态')->using(MediaStatus::options());
            $grid->column('created_at', '创建时间');
            $grid->column('expired_at', '过期时间');

            $grid->setActionClass(Grid\Displayers\Actions::class);

            $grid->filter(function (Grid\Filter $filter) {
                $filter->like('file_no', '文件编号');
                $filter->like('original_name', '原始文件名');
                $filter->like('path', '相对路径');
                $filter->equal('file_type', '文件类型')->select(MediaFileType::options());
                $filter->equal('source_type', '来源类型')->select(MediaSourceType::options());
                $filter->equal('visibility', '访问级别')->select(MediaVisibility::options());
                $filter->equal('status', '状态')->select(MediaStatus::options());
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
        return Show::make($id, new MediaFile(), function (Show $show) {
            $show->field('id');
            $show->field('file_no', '文件编号');
            $show->field('file_type', '文件类型')->using(MediaFileType::options());
            $show->field('storage', '存储驱动');
            $show->field('path', '相对路径');
            $show->field('url', '访问地址（动态生成，数据库值只作缓存）');
            $show->field('original_name', '原始文件名');
            $show->field('filename', '实际文件名');
            $show->field('extension', '扩展名');
            $show->field('mime_type', 'MIME 类型');
            $show->field('size', '文件大小')->as(function ($value) {
                return MediaFileController::formatBytes((int) $value);
            });
            $show->field('width', '宽度（像素）');
            $show->field('height', '高度（像素）');
            $show->field('duration', '时长（秒，第一版视频/音频统一为 0）');
            $show->field('hash', 'SHA-256');
            $show->field('thumbnail_path', '缩略图相对路径');
            $show->field('thumbnail_url', '缩略图访问地址（动态生成）');
            $show->field('source_type', '来源类型')->using(MediaSourceType::options());
            $show->field('source_id', '来源业务 ID');
            $show->field('visibility', '访问级别')->using(MediaVisibility::options());
            $show->field('status', '状态')->using(MediaStatus::options());
            $show->field('metadata', '扩展元数据')->as(function ($value) {
                return $value ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '';
            })->unescape();
            $show->field('expired_at', '过期时间');
            $show->field('created_at', '创建时间');
            $show->field('updated_at', '更新时间');
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        // Dcat 在触发 saving()/deleting() 监听器时会把闭包的 $this 重新绑定到当前表单模型
        // （见 Form\Concerns\HasEvents::makeListener() 中的 $callback->bindTo($model)），
        // 因此这里显式捕获控制器实例，不能在 saving()/deleting() 闭包内直接使用 $this 调用控制器方法。
        $self = $this;

        return Form::make(new MediaFile(), function (Form $form) use ($self) {
            $id = $form->getKey();

            $form->display('id');

            if (! $id) {
                $self->buildUploadFields($form);
            } else {
                $self->buildReadonlySystemFields($form);
                $self->buildEditableFields($form);
            }

            $form->saving(function (Form $form) use ($self) {
                return $form->isCreating()
                    ? $self->handleCreating($form)
                    : $self->handleUpdating($form);
            });

            $form->deleting(function (Form $form) use ($self) {
                return $self->handleDeleting($form);
            });
        });
    }

    /**
     * 新增时的表单字段：文件上传 + 基础业务属性，系统字段全部由 Service 自动生成。
     */
    private function buildUploadFields(Form $form): void
    {
        $form->file('upload_file', '选择文件')
            ->disk('public')
            ->dir(function () {
                return 'media/'.now()->format('Y/m/d');
            })
            ->name(function (UploadedFile $file) {
                $storedName = md5(uniqid('', true)).'.'.strtolower($file->getClientOriginalExtension() ?: 'bin');

                Cache::put(
                    MediaFileController::UPLOAD_META_CACHE_PREFIX.$storedName,
                    ['original_name' => $file->getClientOriginalName()],
                    now()->addMinutes(30)
                );

                return $storedName;
            })
            ->accept(implode(',', MediaFileService::ALLOWED_EXTENSIONS))
            ->rules(
                'required|mimes:'.implode(',', MediaFileService::ALLOWED_EXTENSIONS)
                .'|mimetypes:'.implode(',', MediaFileService::ALLOWED_MIME_TYPES)
                .'|max:'.MediaFileService::MAX_UPLOAD_SIZE_KB
            )
            ->help('支持 '.implode('/', MediaFileService::ALLOWED_EXTENSIONS).'，大小不超过 '
                .(int) (MediaFileService::MAX_UPLOAD_SIZE_KB / 1024).'MB；第一版仅支持单文件上传，不支持替换已上传文件');

        $form->select('source_type', '来源类型')
            ->options(MediaSourceType::options())
            ->default(MediaSourceType::AdminUpload->value)
            ->required();

        $form->radio('visibility', '访问级别')
            ->options(MediaVisibility::options())
            ->default(MediaVisibility::Public->value)
            ->required();

        $form->radio('status', '状态')
            ->options(MediaStatus::options())
            ->default(MediaStatus::Active->value)
            ->required();

        $form->datetime('expired_at', '过期时间')
            ->help('留空表示永不过期');
    }

    /**
     * 编辑时展示的系统识别字段，全部只读，不允许人工修改
     * （file_no/storage/path/filename/extension/mime_type/size/width/height/duration/hash）。
     */
    private function buildReadonlySystemFields(Form $form): void
    {
        /** @var MediaFile $media */
        $media = $form->model();

        $form->display('file_no', '文件编号');
        $form->display('file_type_label', '文件类型')
            ->with(function () use ($media) {
                return MediaFileType::tryFrom((int) $media->file_type)?->label() ?? '未知';
            });
        $form->display('storage', '存储驱动');
        $form->display('path', '相对路径');
        $form->display('original_name', '原始文件名（只读，不支持编辑）');
        $form->display('filename', '实际文件名');
        $form->display('extension', '扩展名');
        $form->display('mime_type', 'MIME 类型');
        $form->display('size_display', '文件大小')
            ->with(function () use ($media) {
                return MediaFileController::formatBytes((int) $media->size);
            });
        $form->display('dimensions_display', '图片宽高')
            ->with(function () use ($media) {
                return ((int) $media->width > 0 && (int) $media->height > 0)
                    ? $media->width.' × '.$media->height
                    : '-';
            });
        $form->display('duration', '时长（秒）');
        $form->display('hash', 'SHA-256');
    }

    /**
     * 编辑时唯一允许修改的业务字段：visibility / status / expired_at / metadata（JSON 文本）。
     */
    private function buildEditableFields(Form $form): void
    {
        /** @var MediaFile $media */
        $media = $form->model();

        $form->radio('visibility', '访问级别')->options(MediaVisibility::options());
        $form->radio('status', '状态')->options(MediaStatus::options());
        $form->datetime('expired_at', '过期时间')->help('留空表示永不过期');

        $form->textarea('metadata_text', '扩展元数据（JSON，留空表示不修改）')
            ->default($media->metadata ? json_encode($media->metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '')
            ->rows(4)
            ->help('必须是合法 JSON 对象，留空表示保持原值不变');

        $form->ignore(['metadata_text']);
    }

    /**
     * @return JsonResponse|null 返回非空表示中断（新增始终由本方法完成落库，直接短路原生保存流程）
     */
    private function handleCreating(Form $form): ?JsonResponse
    {
        $path = $form->input('upload_file');

        if (! $path) {
            return JsonResponse::make()->error('请上传文件');
        }

        $basename = basename($path);
        $meta = Cache::pull(self::UPLOAD_META_CACHE_PREFIX.$basename, []);

        $expiredAt = $form->input('expired_at');

        try {
            $this->service->storeUploadedFile('public', $path, [
                'original_name' => $meta['original_name'] ?? '',
                'source_type' => (int) $form->input('source_type'),
                'visibility' => (int) $form->input('visibility'),
                'status' => (int) $form->input('status'),
                'expired_at' => $expiredAt !== '' ? $expiredAt : null,
            ]);
        } catch (ValidationException $e) {
            return JsonResponse::make()->error($this->firstValidationMessage($e));
        } catch (\Throwable $e) {
            return JsonResponse::make()->error('媒体文件保存失败：'.$e->getMessage());
        }

        return JsonResponse::make()->success('上传成功')->refresh();
    }

    /**
     * @return JsonResponse|null 返回非空表示中断；返回 null 表示放行，交由 Dcat 原生更新流程完成
     */
    private function handleUpdating(Form $form): ?JsonResponse
    {
        $expiredAt = $form->input('expired_at');

        if ($expiredAt === '') {
            $form->expired_at = null;
        }

        $metadataText = trim((string) $form->input('metadata_text'));

        if ($metadataText !== '') {
            $decoded = json_decode($metadataText, true);

            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                return JsonResponse::make()->error('扩展元数据必须是合法的 JSON 对象');
            }

            $form->metadata = $decoded;
        }

        return null;
    }

    private function handleDeleting(Form $form): ?JsonResponse
    {
        $ids = collect(explode(',', (string) $form->getKey()))
            ->filter()
            ->map(fn ($value) => (int) $value)
            ->values();

        if ($ids->isEmpty()) {
            return null;
        }

        // 先对全部 id 做引用校验，避免批量删除出现"部分删除"的中间状态。
        foreach ($ids as $id) {
            try {
                $this->service->assertMediaDeletable($id);
            } catch (ModelNotFoundException $e) {
                continue;
            } catch (ValidationException $e) {
                return JsonResponse::make()->error($this->firstValidationMessage($e));
            }
        }

        foreach ($ids as $id) {
            try {
                $this->service->deleteMedia($id);
            } catch (ModelNotFoundException $e) {
                continue;
            } catch (ValidationException $e) {
                return JsonResponse::make()->error($this->firstValidationMessage($e));
            }
        }

        return JsonResponse::make()->success('删除成功')->refresh();
    }

    private function firstValidationMessage(ValidationException $e): string
    {
        return collect($e->errors())->collapse()->first() ?? '操作失败';
    }

    /**
     * 列表"预览"列：图片展示缩略图（无缩略图时回退原图），其他类型展示类型文字标签。
     * 只读取已有数据库字段，不在 Grid 渲染过程中读取物理文件。
     */
    public static function renderPreviewFor(MediaFile $media): string
    {
        if ((int) $media->file_type === MediaFileType::Image->value) {
            $src = $media->thumbnail_url ?: $media->url;

            if ($src) {
                return '<img src="'.e($src).'" style="width:50px;height:50px;object-fit:cover;border-radius:4px;" />';
            }
        }

        $label = MediaFileType::tryFrom((int) $media->file_type)?->label() ?? '未知';

        return '<span class="label label-default">'.e($label).'</span>';
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1024 / 1024, 2).' MB';
    }
}
