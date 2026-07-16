<?php

use App\Admin\Controllers\HairstyleCategoryController;
use App\Admin\Controllers\HairstyleController;
use App\Enums\Hairstyle\HairstyleGender;
use App\Enums\Hairstyle\HairstyleStatus;
use App\Enums\Media\MediaFileType;
use App\Enums\Media\MediaStatus;
use App\Models\Hairstyle;
use App\Models\HairstyleCategory;
use App\Models\HairstyleTag;
use App\Models\MediaFile;

$fails = [];

function checkTrue(array &$fails, bool $condition, string $label): void
{
    echo ($condition ? 'PASS' : 'FAIL').': '.$label."\n";
    if (! $condition) {
        $fails[] = $label;
    }
}

echo "=== 1. 分类：不设置封面，真实 store() ===\n";
$categoryController = app(HairstyleCategoryController::class);
$catFormMethod = new ReflectionMethod($categoryController, 'form');
$catFormMethod->setAccessible(true);

$form1 = $catFormMethod->invoke($categoryController);
$payload1 = [
    'name' => 'FIXV_CAT_NOCOVER_'.uniqid(),
    'slug' => 'fixv-cat-nocover-'.uniqid(),
    'parent_id' => '0',
    'status' => '1',
    'sort' => '3',
    'cover_upload' => '',
    'cover_select_media_id' => '',
    'clear_cover' => '0',
];
$resp1 = $form1->store($payload1);
$content1 = json_decode($resp1->getContent(), true);
checkTrue($fails, ($content1['status'] ?? false) === true, '分类创建（无封面）HTTP 层返回成功');
$cat1 = HairstyleCategory::query()->where('name', $payload1['name'])->first();
checkTrue($fails, (bool) $cat1, '分类记录已创建');
checkTrue($fails, $cat1 && (int) $cat1->cover_media_id === 0, 'cover_media_id 为约定的无封面值 0，实际='.($cat1?->cover_media_id ?? 'null'));

echo "\n=== 2. 分类：从媒体库选择封面，真实 store() ===\n";
$media1 = MediaFile::create([
    'file_no' => 'FIXV'.uniqid(),
    'path' => 'test/'.uniqid().'.jpg',
    'file_type' => MediaFileType::Image->value,
    'status' => MediaStatus::Active->value,
]);
$form2 = $catFormMethod->invoke($categoryController);
$payload2 = [
    'name' => 'FIXV_CAT_SELECT_'.uniqid(),
    'slug' => 'fixv-cat-select-'.uniqid(),
    'parent_id' => '0',
    'status' => '1',
    'sort' => '3',
    'cover_upload' => '',
    'cover_select_media_id' => (string) $media1->id,
    'clear_cover' => '0',
];
$resp2 = $form2->store($payload2);
$content2 = json_decode($resp2->getContent(), true);
checkTrue($fails, ($content2['status'] ?? false) === true, '分类创建（选择媒体）HTTP 层返回成功：'.($content2['data']['message'] ?? $resp2->getContent()));
$cat2 = HairstyleCategory::query()->where('name', $payload2['name'])->first();
checkTrue($fails, $cat2 && (int) $cat2->cover_media_id === $media1->id, 'cover_media_id 等于所选媒体 ID，实际='.($cat2?->cover_media_id ?? 'null'));

echo "\n=== 3. 分类：编辑已有分类，不修改封面，保留原值 ===\n";
$form3 = $catFormMethod->invoke($categoryController);
$updatePayload = [
    'name' => $cat2->name,
    'slug' => $cat2->slug,
    'parent_id' => '0',
    'status' => '1',
    'sort' => '9',
    'cover_upload' => '',
    'cover_select_media_id' => '',
    'clear_cover' => '0',
];
$resp3 = $form3->update($cat2->id, $updatePayload);
$content3 = json_decode($resp3->getContent(), true);
checkTrue($fails, ($content3['status'] ?? false) === true, '分类更新（未修改封面）HTTP 层返回成功');
$cat2->refresh();
checkTrue($fails, (int) $cat2->cover_media_id === $media1->id, '未修改封面时 cover_media_id 保留原值，实际='.$cat2->cover_media_id);
checkTrue($fails, (int) $cat2->sort === 9, 'sort 字段真实提交值已保存，实际='.$cat2->sort);

echo "\n=== 4. 分类：清除封面 ===\n";
$form4 = $catFormMethod->invoke($categoryController);
$clearPayload = $updatePayload;
$clearPayload['clear_cover'] = '1';
$resp4 = $form4->update($cat2->id, $clearPayload);
$content4 = json_decode($resp4->getContent(), true);
checkTrue($fails, ($content4['status'] ?? false) === true, '分类更新（清除封面）HTTP 层返回成功');
$cat2->refresh();
checkTrue($fails, (int) $cat2->cover_media_id === 0, '清除封面后 cover_media_id = 0，实际='.$cat2->cover_media_id);

echo "\n=== 5. 发型：GET create 页面应包含封面标签与字段（通过表单渲染验证） ===\n";
$hairstyleController = app(HairstyleController::class);
$hFormMethod = new ReflectionMethod($hairstyleController, 'form');
$hFormMethod->setAccessible(true);
$createForm = $hFormMethod->invoke($hairstyleController);
$createHtml = (string) $createForm->render();
checkTrue($fails, str_contains($createHtml, '封面与媒体'), '新建页包含"封面与媒体"标签');
checkTrue($fails, str_contains($createHtml, 'cover_upload'), '新建页包含 cover_upload 字段');
checkTrue($fails, str_contains($createHtml, 'cover_select_media_id'), '新建页包含 cover_select_media_id 字段');
checkTrue($fails, str_contains($createHtml, '新建后将使用本次上传或选择的图片作为封面'), '新建页展示"新建后将使用..."提示文案');

echo "\n=== 6. 发型：真实 store() 新建 + 选择封面 + 标签 + 非默认属性值 ===\n";
$category = HairstyleCategory::create([
    'name' => 'FIXV_CATEGORY_'.uniqid(),
    'slug' => 'fixv-category-'.uniqid(),
]);
$tag = HairstyleTag::create([
    'name' => 'FIXV_TAG_'.uniqid(),
    'slug' => 'fixv-tag-'.uniqid(),
]);
$mediaForHairstyle = MediaFile::create([
    'file_no' => 'FIXV'.uniqid(),
    'path' => 'test/'.uniqid().'.jpg',
    'file_type' => MediaFileType::Image->value,
    'status' => MediaStatus::Active->value,
]);

$hForm = $hFormMethod->invoke($hairstyleController);
$hPayload = [
    'category_id' => (string) $category->id,
    'name' => 'FIXV_HAIRSTYLE_'.uniqid(),
    'name_en' => 'FixvEnglishName',
    'slug' => 'fixv-hairstyle-'.uniqid(),
    'description' => '',
    'gender' => (string) HairstyleGender::Female->value,
    'status' => (string) HairstyleStatus::Disabled->value,
    'sort' => '77',
    'seo_title' => 'FIXV_SEO_TITLE',
    'tags' => [$tag->id],
    'cover_upload' => '',
    'cover_select_media_id' => (string) $mediaForHairstyle->id,
];
$hResp = $hForm->store($hPayload);
$hContent = json_decode($hResp->getContent(), true);
checkTrue($fails, ($hContent['status'] ?? false) === true, '发型创建 HTTP 层返回成功：'.($hContent['data']['message'] ?? $hResp->getContent()));

$savedHairstyle = Hairstyle::query()->where('name', $hPayload['name'])->first();
checkTrue($fails, (bool) $savedHairstyle, '发型记录已创建');
checkTrue($fails, $savedHairstyle && (int) $savedHairstyle->gender === HairstyleGender::Female->value, 'gender 提交值已正确保存，实际='.($savedHairstyle?->gender ?? 'null'));
checkTrue($fails, $savedHairstyle && (int) $savedHairstyle->sort === 77, 'sort 提交值已正确保存，实际='.($savedHairstyle?->sort ?? 'null'));
checkTrue($fails, $savedHairstyle && $savedHairstyle->name_en === 'FixvEnglishName', 'name_en 提交值已正确保存，实际='.var_export($savedHairstyle?->name_en, true));
checkTrue($fails, $savedHairstyle && $savedHairstyle->seo_title === 'FIXV_SEO_TITLE', 'seo_title 提交值已正确保存，实际='.var_export($savedHairstyle?->seo_title, true));
checkTrue($fails, $savedHairstyle && (int) $savedHairstyle->cover_media_id === $mediaForHairstyle->id, 'cover_media_id 已通过 HairstyleMediaService 同步，实际='.($savedHairstyle?->cover_media_id ?? 'null'));
checkTrue($fails, $savedHairstyle && $savedHairstyle->tags()->where('hairstyle_tags.id', $tag->id)->exists(), '标签已正确同步（非空数组）');

if ($savedHairstyle) {
    checkTrue($fails, \App\Models\HairstyleMedia::query()
        ->where('hairstyle_id', $savedHairstyle->id)
        ->where('media_id', $mediaForHairstyle->id)
        ->where('is_primary', 1)
        ->exists(), 'hairstyle_media 关联已创建且 is_primary=1');
}

echo "\n=== 7. 发型媒体关联：title/alt_text/caption/sort 真实保存（回归验证 HairstyleMediaController 修复） ===\n";
if ($savedHairstyle) {
    $mediaController = app(\App\Admin\Controllers\HairstyleMediaController::class);
    $mFormMethod = new ReflectionMethod($mediaController, 'form');
    $mFormMethod->setAccessible(true);

    $secondMedia = MediaFile::create([
        'file_no' => 'FIXV'.uniqid(),
        'path' => 'test/'.uniqid().'.jpg',
        'file_type' => MediaFileType::Image->value,
        'status' => MediaStatus::Active->value,
    ]);

    request()->merge(['hairstyle_id' => $savedHairstyle->id]);
    $mForm = $mFormMethod->invoke($mediaController);
    $mPayload = [
        'hairstyle_id' => (string) $savedHairstyle->id,
        'media_id' => (string) $secondMedia->id,
        'type' => '1',
        'title' => 'FIXV_REAL_TITLE',
        'alt_text' => 'FIXV_REAL_ALT',
        'caption' => 'FIXV_REAL_CAPTION',
        'status' => '1',
        'sort' => '55',
        'is_primary' => '0',
    ];
    $mResp = $mForm->store($mPayload);
    $mContent = json_decode($mResp->getContent(), true);
    checkTrue($fails, ($mContent['status'] ?? false) === true, '发型媒体关联创建 HTTP 层返回成功：'.($mContent['data']['message'] ?? $mResp->getContent()));

    $relation = \App\Models\HairstyleMedia::query()
        ->where('hairstyle_id', $savedHairstyle->id)
        ->where('media_id', $secondMedia->id)
        ->first();

    checkTrue($fails, (bool) $relation, '关联记录已创建');
    checkTrue($fails, $relation && $relation->title === 'FIXV_REAL_TITLE', 'title 真实提交值已保存，实际='.var_export($relation?->title, true));
    checkTrue($fails, $relation && $relation->alt_text === 'FIXV_REAL_ALT', 'alt_text 真实提交值已保存，实际='.var_export($relation?->alt_text, true));
    checkTrue($fails, $relation && $relation->caption === 'FIXV_REAL_CAPTION', 'caption 真实提交值已保存，实际='.var_export($relation?->caption, true));
    checkTrue($fails, $relation && (int) $relation->sort === 55, 'sort 真实提交值已保存，实际='.($relation?->sort ?? 'null'));
}

echo "\n=== 汇总 ===\n";
if ($fails === []) {
    echo "全部通过！\n";
} else {
    echo "存在失败项：\n";
    foreach ($fails as $f) {
        echo ' - '.$f."\n";
    }
}

echo "\n=== 清理本次验证产生的测试数据 ===\n";
\App\Models\HairstyleMedia::whereIn('hairstyle_id', Hairstyle::withTrashed()->where('name', 'like', 'FIXV_%')->pluck('id'))->delete();
Hairstyle::withTrashed()->where('name', 'like', 'FIXV_%')->each(function ($h) {
    $h->tags()->detach();
    $h->forceDelete();
});
HairstyleCategory::withTrashed()->where('name', 'like', 'FIXV_%')->forceDelete();
HairstyleTag::where('name', 'like', 'FIXV_%')->delete();
MediaFile::where('file_no', 'like', 'FIXV%')->forceDelete();
echo "清理完成\n";

exit(0);
