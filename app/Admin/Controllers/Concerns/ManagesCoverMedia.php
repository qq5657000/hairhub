<?php

namespace App\Admin\Controllers\Concerns;

use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaSourceType;
use App\Enums\Media\MediaStatus;
use App\Enums\Media\MediaVisibility;
use App\Models\MediaFile;
use App\Services\Media\MediaFileService;
use Dcat\Admin\Admin;
use Dcat\Admin\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * 单张封面选择通用能力（上传新文件 > 库内选择 > 清除 > 保留原值）。
 *
 * 从已验证可用的 HairstyleController / HairstyleCategoryController 封面处理流程中
 * 抽取出的最小可复用逻辑，供发色分类（HairColorCategoryController）和发色
 * （HairColorController）两个新控制器共用，不修改发型模块任何现有文件。
 *
 * 使用方约定：
 * - 只负责“计算/校验最终应写入 cover_media_id 的值”，不直接读写任何 Model，
 *   最终写入统一交给调用方通过业务 Service（HairColorService / HairColorCategoryService）完成；
 * - 上传接口 handleCoverUpload() 与 MediaFileService::storeFromUploadedFile() 一致，
 *   在同一个请求内完成“落盘 + 登记入库”，返回 media_files.id，不会产生“先落盘、
 *   提交表单才登记入库”两阶段流程下的重复创建/孤儿媒体问题。
 */
trait ManagesCoverMedia
{
    /**
     * 纯决策函数（不做任何 I/O），按照“上传新文件 > 库内选择 > 清除 > 保留原值”的
     * 优先级判断本次保存应采取的封面动作。
     *
     * @return array{action: string, media_id: int} action 为 upload/select/clear/keep 之一
     */
    public static function decideCoverAction(int $uploadMediaId, int $selectMediaId, bool $clearCover, int $currentCoverMediaId): array
    {
        if ($uploadMediaId > 0) {
            return ['action' => 'upload', 'media_id' => $uploadMediaId];
        }

        if ($selectMediaId > 0) {
            return ['action' => 'select', 'media_id' => $selectMediaId];
        }

        if ($clearCover) {
            return ['action' => 'clear', 'media_id' => 0];
        }

        return ['action' => 'keep', 'media_id' => $currentCoverMediaId];
    }

    /**
     * 结合纯决策函数与实际校验（媒体是否存在/为图片/状态可用），计算出最终应写入
     * cover_media_id 的值；只读取媒体信息，不做任何写入，便于在事务内安全调用。
     *
     * @throws ValidationException 上传/选择的媒体不合法
     */
    public function resolveCoverMediaId(int $uploadMediaId, int $selectMediaId, bool $clearCover, int $currentCoverMediaId): int
    {
        $decision = static::decideCoverAction($uploadMediaId, $selectMediaId, $clearCover, $currentCoverMediaId);

        return match ($decision['action']) {
            'upload' => $this->assertUsableCoverMedia($uploadMediaId)->id,
            'select' => $this->assertUsableCoverMedia($selectMediaId)->id,
            default => $decision['media_id'],
        };
    }

    /**
     * 校验封面媒体存在、为图片类型且状态可用（仅 MediaStatus::Active 视为可用）。
     *
     * @throws ValidationException
     */
    public function assertUsableCoverMedia(int $mediaId): MediaFile
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

        if ((int) $media->status !== MediaStatus::Active->value) {
            throw ValidationException::withMessages([
                'cover_select_media_id' => ['封面只能选择状态为启用的媒体'],
            ]);
        }

        return $media;
    }

    /**
     * “上传新封面”字段的通用上传接口：点击“上传”即在同一请求内完成落盘 + MediaFileService
     * 正式入库，返回 media_files.id 供前端写入隐藏字段，供后续 resolveCoverMediaId() 使用。
     */
    public function handleCoverUpload(MediaFileService $mediaFileService)
    {
        if (request()->filled('key')) {
            return Admin::json()->send();
        }

        $file = request()->file('_file_');

        if (! $file) {
            return JsonResponse::make()->error('未接收到上传文件')->send();
        }

        $validator = Validator::make(
            ['cover_upload' => $file],
            ['cover_upload' => 'required|image|mimes:jpg,jpeg,png,webp|mimetypes:image/jpeg,image/png,image/webp|max:'.MediaFileService::MAX_UPLOAD_SIZE_KB]
        );

        if ($validator->fails()) {
            return JsonResponse::make()->error($validator->errors()->first())->send();
        }

        try {
            $media = $mediaFileService->storeFromUploadedFile($file, 'public', 'media/'.now()->format('Y/m/d'), [
                'source_type' => MediaSourceType::HairColor->value,
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

    public function firstValidationMessage(ValidationException $e): string
    {
        return collect($e->errors())->collapse()->first() ?? '操作失败';
    }

    /**
     * 封面预览：缩略图 + 媒体 ID + 原始文件名；无封面时给出明确提示。
     * 所有拼接内容均使用 e() 转义，不直接把数据库值拼接成不安全 HTML。
     */
    public static function renderCoverPreview(?MediaFile $cover, string $emptyHint = '暂未设置封面'): string
    {
        if (! $cover) {
            return '<div class="alert alert-warning" style="margin-bottom:0;">'.e($emptyHint).'</div>';
        }

        $src = $cover->thumbnail_url ?: $cover->url;
        $img = $src ? '<img src="'.e($src).'" style="max-width:120px;max-height:120px;border-radius:4px;" />' : '-';

        return $img
            .'<div style="margin-top:6px;color:#666;">媒体 ID：#'.(int) $cover->id.'　原始文件名：'.e($cover->original_name ?: $cover->filename).'</div>';
    }
}
