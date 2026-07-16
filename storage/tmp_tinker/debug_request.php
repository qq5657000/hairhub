<?php

use App\Admin\Controllers\HairstyleCategoryController;
use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaStatus;
use App\Models\MediaFile;

$media = MediaFile::create([
    'file_no' => 'DBG'.uniqid(),
    'path' => 'test/'.uniqid().'.jpg',
    'file_type' => MediaFileType::Image->value,
    'status' => MediaStatus::Active->value,
]);

$controller = app(HairstyleCategoryController::class);
$formMethod = new ReflectionMethod($controller, 'form');
$formMethod->setAccessible(true);
$form = $formMethod->invoke($controller);

echo 'global request() before store, object id: '.spl_object_id(request())."\n";

$payload = [
    'name' => 'DBG_CAT_'.uniqid(),
    'slug' => 'dbg-cat-'.uniqid(),
    'parent_id' => '0',
    'status' => '1',
    'sort' => '3',
    'cover_upload' => '',
    'cover_select_media_id' => (string) $media->id,
    'clear_cover' => '0',
];

// 手动模拟框架内部逻辑，逐步打印，定位真实断点。
$reflectRequest = new ReflectionProperty($form, 'request');
$reflectRequest->setAccessible(true);
$formRequestBefore = $reflectRequest->getValue($form);
echo 'form->request object id (构造时): '.spl_object_id($formRequestBefore)."\n";
echo 'form->request === request() ? '.var_export($formRequestBefore === request(), true)."\n";

$resp = $form->store($payload);

$formRequestAfter = $reflectRequest->getValue($form);
echo 'form->request object id (store后): '.spl_object_id($formRequestAfter)."\n";
echo 'global request() object id (store后): '.spl_object_id(request())."\n";
echo 'global request()->input(cover_select_media_id): '.var_export(request()->input('cover_select_media_id'), true)."\n";
echo 'form->request->input(cover_select_media_id): '.var_export($formRequestAfter->input('cover_select_media_id'), true)."\n";
echo 'response: '.$resp->getContent()."\n";

$saved = \App\Models\HairstyleCategory::query()->where('name', $payload['name'])->first();
echo 'saved category cover_media_id: '.var_export($saved?->cover_media_id, true)."\n";
echo 'media id used: '.$media->id."\n";

MediaFile::where('file_no', 'like', 'DBG%')->forceDelete();
\App\Models\HairstyleCategory::withTrashed()->where('name', 'like', 'DBG_CAT_%')->forceDelete();

exit(0);
