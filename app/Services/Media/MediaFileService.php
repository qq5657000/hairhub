<?php

namespace App\Services\Media;

use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaSourceType;
use App\Enums\Media\MediaStatus;
use App\Enums\Media\MediaVisibility;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleMedia;
use App\Models\HairColor;
use App\Models\HairColorCategory;
use App\Models\Hairstyle;
use App\Models\HairstyleCategory;
use App\Models\HairstyleMedia;
use App\Models\MediaFile;
use App\Models\Video;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * 媒体资源业务 Service。
 *
 * 负责媒体文件的登记入库、URL 生成、类型识别和安全删除，是 media_files 表
 * 唯一允许写入/删除的业务入口（对应 doc/v1.0/database/05-媒体资源模块.md 十八、Service 设计要求）：
 * - storeUploadedFile()：将已经落盘的上传文件登记为 media_files 记录；
 * - deleteMedia()：删除媒体前校验业务引用，再依次删除物理文件和数据库记录；
 * - buildUrl()：统一根据 storage + path 动态生成访问地址；
 * - detectFileType()：根据 MIME 和扩展名识别文件类型。
 *
 * 第一版不引入队列 Job：缩略图生成同步完成（QUEUE_CONNECTION 默认 sync，
 * 异步收益有限，同步实现更符合项目"简单优先"原则），如后续文件量增大，
 * 可以将缩略图生成部分平移到 GenerateMediaThumbnailJob 而不改变对外方法签名。
 */
class MediaFileService
{
    /**
     * 允许上传的扩展名，集中配置，禁止在其他地方复制该列表。
     */
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'webm', 'mov'];

    /**
     * 允许上传的 MIME 类型（基于文件内容检测，不仅依赖扩展名）。
     */
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'video/mp4',
        'video/webm',
        'video/quicktime',
    ];

    /**
     * 上传大小上限（KB），供 Dcat 表单字段规则与本 Service 双重校验共用。
     * 第一版硬编码，后续可迁移到系统配置（settings 表）。
     */
    public const MAX_UPLOAD_SIZE_KB = 51200; // 50MB

    private const THUMBNAIL_MAX_EDGE = 480;

    /**
     * 将已经落盘的上传文件登记为 media_files 记录。
     *
     * 说明：物理落盘由 Dcat Admin 的 File 表单字段（WebUploader 异步上传）完成，
     * 本方法只负责在文件已经存在于 $disk/$path 的前提下完成剩余流程：
     * 校验大小与类型 -> 识别文件类型 -> 提取宽高 -> 生成缩略图 -> 计算哈希 -> 生成 file_no -> 写入数据库。
     * 数据库写入失败或校验不通过时，会清理已落盘的原文件与缩略图，不留下孤立物理文件或脏记录。
     *
     * @param  array<string, mixed>  $context  original_name/source_type/source_id/visibility/status/expired_at/metadata
     *
     * @throws ValidationException 文件不存在 / 大小或类型不合法
     */
    public function storeUploadedFile(string $disk, string $path, array $context = []): MediaFile
    {
        if (! Storage::disk($disk)->exists($path)) {
            throw ValidationException::withMessages([
                'upload_file' => ['文件未成功上传或已丢失，请重新上传'],
            ]);
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeType = (string) (Storage::disk($disk)->mimeType($path) ?: '');
        $size = (int) Storage::disk($disk)->size($path);

        try {
            $this->assertUploadIsAllowed($extension, $mimeType, $size);
        } catch (ValidationException $e) {
            $this->deleteDiskFileQuietly($disk, $path);

            throw $e;
        }

        $fileType = $this->detectFileType($mimeType, $extension);

        [$width, $height] = $fileType === MediaFileType::Image
            ? $this->extractImageDimensions($disk, $path)
            : [0, 0];

        [$thumbnailPath, $thumbnailUrl] = $fileType === MediaFileType::Image
            ? $this->generateThumbnail($disk, $path, $width, $height)
            : ['', ''];

        $attributes = [
            'file_no' => $this->generateFileNo(),
            'file_type' => $fileType->value,
            'storage' => $disk,
            'path' => $path,
            'url' => $this->buildStorageUrl($disk, $path),
            'original_name' => (string) ($context['original_name'] ?? ''),
            'filename' => basename($path),
            'extension' => $extension,
            'mime_type' => $mimeType,
            'size' => $size,
            'width' => $width,
            'height' => $height,
            // 第一版不做视频/音频时长提取（避免引入 ffmpeg 等未经批准的第三方依赖），统一记为 0。
            'duration' => 0,
            'hash' => $this->hashDiskFile($disk, $path),
            'thumbnail_path' => $thumbnailPath,
            'thumbnail_url' => $thumbnailUrl,
            'source_type' => (int) ($context['source_type'] ?? MediaSourceType::AdminUpload->value),
            'source_id' => (int) ($context['source_id'] ?? 0),
            'visibility' => (int) ($context['visibility'] ?? MediaVisibility::Public->value),
            'status' => (int) ($context['status'] ?? MediaStatus::Active->value),
            'metadata' => $context['metadata'] ?? null,
            'expired_at' => $context['expired_at'] ?? null,
        ];

        try {
            return MediaFile::create($attributes);
        } catch (\Throwable $e) {
            $this->deleteDiskFileQuietly($disk, $path);

            if ($thumbnailPath !== '') {
                $this->deleteDiskFileQuietly($disk, $thumbnailPath);
            }

            throw $e;
        }
    }

    /**
     * 将一个尚未落盘的上传文件（$_FILES / UploadedFile）一次性落盘并登记为 media_files 记录。
     *
     * 与 storeUploadedFile() 的区别：storeUploadedFile() 假定文件已经由调用方写入磁盘，
     * 只负责"登记入库"；本方法额外负责"落盘"这一步，用于替代 Dcat 表单 File/Image 字段
     * 默认的"异步上传只落盘、提交表单时才登记入库"两阶段流程——该默认流程会导致同一个
     * disk+path 在"上传"和"提交表单"两个独立请求里被分别处理，一旦表单被重复提交
     * （例如 Dcat 编辑表单默认的 autoUpdateColumn 上传后台静默更新 + 用户手动点击提交，
     * 或用户在其他字段校验失败后未重新上传就再次提交），会对同一个 storage+path 重复调用
     * MediaFile::create()，触发 uk_storage_path 唯一索引冲突，且失败清理逻辑还会误删
     * 第一次已经成功入库的原文件/缩略图，产生"数据库有记录、物理文件已丢失"的孤儿媒体。
     *
     * 采用本方法后，"落盘 + 登记入库"在同一次请求内一次性完成，之后的业务表单提交只需要
     * 携带返回的 media_id 做校验和关联，不会再触发第二次 MediaFile::create()。
     *
     * @param  array<string, mixed>  $context  同 storeUploadedFile()
     *
     * @throws ValidationException 文件不合法 / 落盘失败
     */
    public function storeFromUploadedFile(UploadedFile $file, string $disk, string $directory, array $context = []): MediaFile
    {
        $directory = trim($directory, '/');
        $extension = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'bin'));
        $filename = $this->generateUniqueDiskFilename($extension);

        if (! Storage::disk($disk)->putFileAs($directory, $file, $filename)) {
            throw ValidationException::withMessages([
                'upload_file' => ['文件保存失败，请重试'],
            ]);
        }

        $path = $directory !== '' ? $directory.'/'.$filename : $filename;
        $context['original_name'] = $context['original_name'] ?? $file->getClientOriginalName();

        return $this->storeUploadedFile($disk, $path, $context);
    }

    /**
     * 校验媒体是否允许删除（存在且未被业务引用），不执行任何删除动作。
     *
     * 用于批量删除前的"预检查"，避免出现部分删除的中间状态。
     *
     * @throws ModelNotFoundException 媒体不存在
     * @throws ValidationException 仍被业务引用
     */
    public function assertMediaDeletable(int $mediaId): void
    {
        $media = MediaFile::query()->find($mediaId);

        if (! $media) {
            throw new ModelNotFoundException('媒体文件不存在');
        }

        $this->assertNotReferenced($mediaId);
    }

    /**
     * 删除媒体文件：先校验业务引用，再删除物理文件（原图 + 缩略图），最后删除数据库记录。
     *
     * 删除顺序遵循 doc/v1.0/database/05-媒体资源模块.md 15.2：检查引用 -> 删除缩略图 -> 删除原文件 -> 删除数据库记录。
     * 物理文件删除失败会直接抛出异常并保留数据库记录，不允许"只删数据库不处理文件"的静默成功。
     *
     * @throws ModelNotFoundException 媒体不存在
     * @throws ValidationException 仍被业务引用 / 物理文件删除失败
     */
    public function deleteMedia(int $mediaId): void
    {
        /** @var MediaFile|null $media */
        $media = MediaFile::query()->find($mediaId);

        if (! $media) {
            throw new ModelNotFoundException('媒体文件不存在');
        }

        $this->assertNotReferenced($mediaId);

        if ($media->thumbnail_path !== '') {
            $this->deleteDiskFile($media->storage, $media->thumbnail_path);
        }

        $this->deleteDiskFile($media->storage, $media->path);

        $media->delete();
    }

    /**
     * 根据 storage + path 动态生成访问地址（与 MediaFile::getUrlAttribute() 逻辑保持一致，
     * 供后台/业务代码在拿到 storage+path 而非完整模型时复用）。
     */
    public function buildUrl(string $disk, string $path): string
    {
        return $this->buildStorageUrl($disk, $path);
    }

    /**
     * 根据 MIME 类型和扩展名识别文件类型，禁止仅按扩展名判断。
     */
    public function detectFileType(string $mimeType, string $extension): MediaFileType
    {
        $mimeType = strtolower($mimeType);
        $extension = strtolower($extension);

        if (Str::startsWith($mimeType, 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return MediaFileType::Image;
        }

        if (Str::startsWith($mimeType, 'video/') || in_array($extension, ['mp4', 'webm', 'mov'], true)) {
            return MediaFileType::Video;
        }

        if (Str::startsWith($mimeType, 'audio/')) {
            return MediaFileType::Audio;
        }

        if (in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx'], true)) {
            return MediaFileType::Document;
        }

        return MediaFileType::Unknown;
    }

    /**
     * @throws ValidationException
     */
    private function assertUploadIsAllowed(string $extension, string $mimeType, int $size): void
    {
        if ($size <= 0 || $size > self::MAX_UPLOAD_SIZE_KB * 1024) {
            throw ValidationException::withMessages([
                'upload_file' => ['文件大小不合法或超出限制（最大 '.(int) (self::MAX_UPLOAD_SIZE_KB / 1024).'MB）'],
            ]);
        }

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'upload_file' => ['不支持的文件扩展名：'.$extension],
            ]);
        }

        if (! in_array(strtolower($mimeType), self::ALLOWED_MIME_TYPES, true)) {
            throw ValidationException::withMessages([
                'upload_file' => ['不支持的文件类型：'.$mimeType],
            ]);
        }
    }

    private function generateUniqueDiskFilename(string $extension): string
    {
        return md5(uniqid('', true)).'.'.$extension;
    }

    private function generateFileNo(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $candidate = 'MED'.now()->format('Ymd').Str::upper(Str::random(6));

            if (! MediaFile::query()->where('file_no', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('媒体编号生成失败，请重试');
    }

    private function hashDiskFile(string $disk, string $path): ?string
    {
        try {
            $absolute = Storage::disk($disk)->path($path);

            if (is_file($absolute)) {
                return hash_file('sha256', $absolute) ?: null;
            }
        } catch (\Throwable $e) {
            // 部分驱动（如对象存储）不支持 path()，走流式读取兜底
        }

        $stream = Storage::disk($disk)->readStream($path);

        if (! $stream) {
            return null;
        }

        $context = hash_init('sha256');

        while (! feof($stream)) {
            hash_update($context, fread($stream, 8192));
        }

        fclose($stream);

        return hash_final($context);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function extractImageDimensions(string $disk, string $path): array
    {
        try {
            $absolute = Storage::disk($disk)->path($path);
            $info = @getimagesize($absolute);
        } catch (\Throwable $e) {
            $info = false;
        }

        if (! $info) {
            return [0, 0];
        }

        return [(int) $info[0], (int) $info[1]];
    }

    /**
     * 生成缩略图，仅使用已随 PHP 提供的 GD 扩展，不引入新的第三方依赖。
     * 生成失败仅记录日志，不影响原文件入库（对应设计文档 13-缩略图策略）。
     *
     * @return array{0: string, 1: string} [thumbnail_path, thumbnail_url]
     */
    private function generateThumbnail(string $disk, string $path, int $width, int $height): array
    {
        if ($width <= 0 || $height <= 0 || ! function_exists('imagecreatetruecolor')) {
            return ['', ''];
        }

        try {
            $absolute = Storage::disk($disk)->path($path);
            $content = file_get_contents($absolute);
            $source = $content !== false ? @imagecreatefromstring($content) : false;

            if (! $source) {
                return ['', ''];
            }

            $ratio = min(1, self::THUMBNAIL_MAX_EDGE / max($width, $height));
            $thumbWidth = max(1, (int) round($width * $ratio));
            $thumbHeight = max(1, (int) round($height * $ratio));

            $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
            imagecopyresampled($thumb, $source, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);

            $pathInfo = pathinfo($path);
            $useWebp = function_exists('imagewebp');
            $thumbExtension = $useWebp ? 'webp' : 'jpg';
            $dirname = $pathInfo['dirname'] === '.' ? '' : $pathInfo['dirname'].'/';
            $thumbRelativePath = $dirname.$pathInfo['filename'].'_thumb.'.$thumbExtension;

            ob_start();
            $useWebp ? imagewebp($thumb, null, 80) : imagejpeg($thumb, null, 80);
            $thumbContent = ob_get_clean();

            imagedestroy($source);
            imagedestroy($thumb);

            if (! $thumbContent) {
                return ['', ''];
            }

            Storage::disk($disk)->put($thumbRelativePath, $thumbContent);

            return [$thumbRelativePath, $this->buildStorageUrl($disk, $thumbRelativePath)];
        } catch (\Throwable $e) {
            Log::warning('媒体缩略图生成失败', ['disk' => $disk, 'path' => $path, 'error' => $e->getMessage()]);

            return ['', ''];
        }
    }

    private function buildStorageUrl(string $disk, string $path): string
    {
        if ($path === '') {
            return '';
        }

        try {
            return Storage::disk($disk)->url($path);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertNotReferenced(int $mediaId): void
    {
        $references = [];

        if (HairstyleMedia::query()->where('media_id', $mediaId)->exists()) {
            $references[] = '发型图库（hairstyle_media）';
        }

        if (Hairstyle::query()->where('cover_media_id', $mediaId)->exists()) {
            $references[] = '发型封面（hairstyles.cover_media_id）';
        }

        if (HairstyleCategory::query()->where('cover_media_id', $mediaId)->exists()) {
            $references[] = '发型分类封面（hairstyle_categories.cover_media_id）';
        }

        if (HairColor::query()->where('cover_media_id', $mediaId)->exists()) {
            $references[] = '发色封面（hair_colors.cover_media_id）';
        }

        if (HairColorCategory::query()->where('cover_media_id', $mediaId)->exists()) {
            $references[] = '发色分类封面（hair_color_categories.cover_media_id）';
        }

        // 内容模块（本次 Review 补充，Phase 1 遗留缺口）：文章分类封面、文章网站/公众号
        // 封面、文章正文图片/图集/附件关联、视频封面与本地视频媒体均未纳入引用检查，
        // 会导致在媒体资源页面直接删除仍被文章/视频引用的媒体后出现悬空引用
        // （前台渲染出 404 图片/断链视频），因此在此处补充，不改变已有发型/发色模块的判断逻辑。
        if (ArticleCategory::query()->where('cover_media_id', $mediaId)->exists()) {
            $references[] = '文章分类封面（article_categories.cover_media_id）';
        }

        if (Article::query()->where('cover_media_id', $mediaId)->exists()) {
            $references[] = '文章网站封面（articles.cover_media_id）';
        }

        if (Article::query()->where('wechat_cover_media_id', $mediaId)->exists()) {
            $references[] = '文章公众号封面（articles.wechat_cover_media_id）';
        }

        if (ArticleMedia::query()->where('media_id', $mediaId)->exists()) {
            $references[] = '文章正文图片/图集/附件（article_media）';
        }

        if (Video::query()->where('cover_media_id', $mediaId)->exists()) {
            $references[] = '视频封面（videos.cover_media_id）';
        }

        if (Video::query()->where('video_media_id', $mediaId)->exists()) {
            $references[] = '视频本地视频文件（videos.video_media_id）';
        }

        if ($references !== []) {
            throw ValidationException::withMessages([
                'media_id' => ['该媒体仍被以下业务引用，请先解除引用后再删除：'.implode('、', $references)],
            ]);
        }
    }

    /**
     * 删除物理文件，删除失败时直接抛出异常（不静默成功）。
     *
     * @throws ValidationException
     */
    private function deleteDiskFile(string $disk, string $path): void
    {
        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            return;
        }

        if (! Storage::disk($disk)->delete($path)) {
            throw ValidationException::withMessages([
                'path' => ['物理文件删除失败，请检查存储权限后重试'],
            ]);
        }
    }

    /**
     * 清理上传/入库失败时已落盘的文件，此路径下的失败只记录日志，不再抛出新异常掩盖原始错误。
     */
    private function deleteDiskFileQuietly(string $disk, string $path): void
    {
        try {
            if ($path !== '' && Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        } catch (\Throwable $e) {
            Log::warning('清理失败上传文件时出错', ['disk' => $disk, 'path' => $path, 'error' => $e->getMessage()]);
        }
    }
}
