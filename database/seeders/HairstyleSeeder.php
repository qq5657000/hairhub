<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Hairstyle\FaceShape;
use App\Enums\Hairstyle\HairstyleAgeRange;
use App\Enums\Hairstyle\HairstyleGender;
use App\Enums\Hairstyle\HairstyleHairLength;
use App\Enums\Hairstyle\HairstyleHairType;
use App\Enums\Hairstyle\HairstyleHairVolume;
use App\Enums\Hairstyle\HairstyleMaintenanceLevel;
use App\Enums\Hairstyle\HairstyleStatus;
use App\Enums\Hairstyle\HairstyleStyleType;
use App\Enums\Hairstyle\SuitableScene;
use App\Models\Hairstyle;
use App\Models\HairstyleCategory;
use App\Models\HairstyleTag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 发型初始化 Seeder：初始化男士 / 女士 / 儿童三大类共 36 条发型基础资料，
 * 并同步每条发型的标签关联。
 *
 * 不创建 media_files 和 hairstyle_media 任何数据：后台维护发型时只需要
 * 上传图片、关联到已有发型、设置封面即可，本 Seeder 只负责把“文字资料”准备好。
 *
 * 幂等性保证：
 * - 发型始终通过 slug 定位（unique 索引），存在则更新基础资料，不存在则创建；
 * - category_id 通过分类 slug 从 HairstyleCategorySeeder 已写入的数据中查找实际 ID；
 * - 标签关联通过标签 slug 从 HairstyleTagSeeder 已写入的数据中查找实际 ID，
 *   并使用 syncWithoutDetaching()，不会删除后台人工追加的标签；
 * - cover_media_id 只在首次创建时初始化为 null，重复执行不会重置后台已设置的封面；
 * - published_at 只在首次创建时写入当前时间，重复执行不会刷新发布时间；
 * - 不使用 truncate/delete，重复执行不会产生重复发型。
 */
class HairstyleSeeder extends Seeder
{
    /**
     * 男士发型统一反向提示词模板：避免变形发丝、重复头发、假发感、秃斑、
     * 错误发际线、模糊，以及改变人物身份/五官。
     */
    private const NEGATIVE_MEN = 'deformed hair strands, duplicated or doubled hair, wig-like texture, patchy bald spots, unnatural or shifted hairline, blurry, low quality, changing the person\'s face shape or identity, distorted facial features';

    private const NEGATIVE_WOMEN = 'deformed hair strands, duplicated or doubled hair, wig-like texture, patchy bald spots, unnatural or shifted hairline, tangled or frizzy flyaways, blurry, low quality, changing the person\'s face shape or identity, distorted facial features';

    private const NEGATIVE_KIDS = 'deformed hair strands, duplicated or doubled hair, wig-like texture, patchy bald spots, unnatural or shifted hairline, blurry, low quality, aging the child\'s face, changing the child\'s face shape or identity, distorted facial features';

    public function run(): void
    {
        DB::transaction(function (): void {
            $categoryIds = HairstyleCategory::query()->pluck('id', 'slug')->all();
            $tagIds = HairstyleTag::query()->pluck('id', 'slug')->all();

            foreach ($this->hairstyleDefinitions() as $definition) {
                $hairstyle = $this->upsertHairstyle($definition, $categoryIds);
                $this->syncTags($hairstyle, $definition['tags'], $tagIds);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, int>  $categoryIds  分类 slug => id 映射
     */
    private function upsertHairstyle(array $definition, array $categoryIds): Hairstyle
    {
        if (! isset($categoryIds[$definition['category_slug']])) {
            throw new \RuntimeException(
                '发型分类不存在，请确认 HairstyleCategorySeeder 已先执行：'.$definition['category_slug']
            );
        }

        $attributes = [
            'category_id' => $categoryIds[$definition['category_slug']],
            'name' => $definition['name'],
            'name_en' => $definition['name_en'],
            'gender' => $definition['gender'],
            'age_range' => $definition['age_range'],
            'style_type' => $definition['style_type'],
            'hair_length' => $definition['hair_length'],
            'hair_type' => $definition['hair_type'],
            'hair_volume' => $definition['hair_volume'],
            'face_shape' => $definition['face_shape'],
            'maintenance_level' => $definition['maintenance_level'],
            'suitable_scene' => $definition['suitable_scene'],
            'description' => $definition['description'],
            'ai_prompt' => $definition['ai_prompt'],
            'ai_negative_prompt' => $definition['ai_negative_prompt'],
            'seo_title' => $definition['seo_title'],
            'seo_description' => $definition['seo_description'],
            'status' => HairstyleStatus::Enabled->value,
            'is_recommended' => $definition['is_recommended'] ? 1 : 0,
            'sort' => $definition['sort'],
        ];

        // 用 withTrashed() 定位：发型支持软删除，若后台曾软删除过同 slug 的记录，
        // 普通 query() 会因看不到该记录而尝试重新 create()，导致触发 slug 唯一索引冲突。
        // 这里只更新基础字段，不触碰 deleted_at，不会“复活”被软删除的发型。
        /** @var Hairstyle|null $hairstyle */
        $hairstyle = Hairstyle::withTrashed()->where('slug', $definition['slug'])->first();

        if ($hairstyle) {
            // 重复执行时不覆盖 cover_media_id / published_at：
            // 封面只能由后台通过 HairstyleMediaService 维护，发布时间只在首次创建时确定。
            $hairstyle->fill($attributes);
            $hairstyle->save();

            return $hairstyle;
        }

        $attributes['slug'] = $definition['slug'];
        $attributes['cover_media_id'] = null;
        $attributes['published_at'] = now();

        return Hairstyle::create($attributes);
    }

    /**
     * @param  array<int, string>  $tagSlugs
     * @param  array<string, int>  $tagIds  标签 slug => id 映射
     */
    private function syncTags(Hairstyle $hairstyle, array $tagSlugs, array $tagIds): void
    {
        $ids = [];

        foreach ($tagSlugs as $slug) {
            if (! isset($tagIds[$slug])) {
                throw new \RuntimeException(
                    '发型标签不存在，请确认 HairstyleTagSeeder 已先执行：'.$slug
                );
            }

            $ids[] = $tagIds[$slug];
        }

        // syncWithoutDetaching：只补充关联，不会移除后台人工额外添加的标签关联。
        $hairstyle->tags()->syncWithoutDetaching($ids);
    }

    /**
     * 汇总男士 / 女士 / 儿童三组发型定义，并按组内顺序整体倒序生成 sort。
     *
     * @return array<int, array<string, mixed>>
     */
    private function hairstyleDefinitions(): array
    {
        return array_merge(
            $this->withGroupSort($this->menDefinitions()),
            $this->withGroupSort($this->womenDefinitions()),
            $this->withGroupSort($this->childDefinitions()),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $definitions
     * @return array<int, array<string, mixed>>
     */
    private function withGroupSort(array $definitions): array
    {
        $total = count($definitions);

        foreach ($definitions as $index => &$definition) {
            $definition['sort'] = ($total - $index) * 10;
        }

        return $definitions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function menDefinitions(): array
    {
        $negative = self::NEGATIVE_MEN;

        return [
            [
                'slug' => 'men-fresh-short-crop',
                'name' => '男士清爽短碎发',
                'name_en' => 'Men\'s Fresh Short Crop',
                'category_slug' => 'men-short-hairstyles',
                'gender' => HairstyleGender::Male->value,
                'age_range' => HairstyleAgeRange::All->value,
                'style_type' => HairstyleStyleType::Casual->value,
                'hair_length' => HairstyleHairLength::Short->value,
                'hair_type' => HairstyleHairType::Straight->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Round->value, FaceShape::Square->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Easy->value,
                'suitable_scene' => [SuitableScene::Daily->value, SuitableScene::Work->value],
                'description' => '短碎发线条干净，发尾自然分散，露出额头和耳朵，视觉上更显精神清爽，是日常和职场都不容易出错的基础男士短发。',
                'ai_prompt' => 'short textured crop haircut for men, choppy fringe with natural separation, exposed forehead and ears, low volume at the crown, clean tapered sides, soft matte finish',
                'ai_negative_prompt' => $negative,
                'seo_title' => '男士清爽短碎发发型效果图 | 圆脸方脸都适合 - HairHub',
                'seo_description' => '男士清爽短碎发适合圆脸、方脸，打理简单不挑年龄，日常通勤百搭。上传照片即可用 HairHub AI换发型工具在线预览效果。',
                'is_recommended' => true,
                'tags' => ['fresh-style', 'face-slimming', 'easy-to-maintain', 'no-perm-needed', 'daily-commute', 'natural-style'],
            ],
            [
                'slug' => 'men-chestnut-head',
                'name' => '男士栗子头',
                'name_en' => 'Men\'s Chestnut Head',
                'category_slug' => 'men-short-hairstyles',
                'gender' => HairstyleGender::Male->value,
                'age_range' => HairstyleAgeRange::Young->value,
                'style_type' => HairstyleStyleType::Korean->value,
                'hair_length' => HairstyleHairLength::Short->value,
                'hair_type' => HairstyleHairType::Wavy->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Round->value, FaceShape::Oval->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Normal->value,
                'suitable_scene' => [SuitableScene::Daily->value, SuitableScene::School->value],
                'description' => '栗子头顶部保留一定厚度，发尾自然内扣，形状圆润饱满像栗子，是韩系减龄造型中的经典款，很适合想尝试软萌风格的男生。',
                'ai_prompt' => 'men\'s chestnut-shaped short haircut, rounded and full silhouette on top, soft inward-curled ends, medium crown volume, gentle natural texture, Korean style short haircut',
                'ai_negative_prompt' => $negative,
                'seo_title' => '男士栗子头发型效果图 | 韩系减龄圆脸显小 - HairHub',
                'seo_description' => '男士栗子头造型圆润软萌，适合圆脸、鹅蛋脸，减龄效果明显。用 HairHub AI换发型工具上传照片，快速预览栗子头上头效果。',
                'is_recommended' => false,
                'tags' => ['korean-style', 'youthful-style', 'face-slimming', 'easy-to-maintain', 'crown-volume', 'daily-commute'],
            ],
            [
                'slug' => 'men-buzz-cut',
                'name' => '男士寸头',
                'name_en' => 'Men\'s Buzz Cut',
                'category_slug' => 'men-short-hairstyles',
                'gender' => HairstyleGender::Male->value,
                'age_range' => HairstyleAgeRange::All->value,
                'style_type' => HairstyleStyleType::Cool->value,
                'hair_length' => HairstyleHairLength::Buzz->value,
                'hair_type' => HairstyleHairType::All->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Oval->value, FaceShape::Square->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Easy->value,
                'suitable_scene' => [SuitableScene::Daily->value, SuitableScene::Sport->value],
                'description' => '寸头是最省心的极短发型，全头长度均匀，轮廓利落硬朗，露出发际线和耳型，适合喜欢清爽利落感、不想花时间打理的男生。',
                'ai_prompt' => 'men\'s buzz cut hairstyle, uniformly short hair length all over the head, clean and sharp outline, visible natural hairline, minimal texture, no bangs',
                'ai_negative_prompt' => $negative,
                'seo_title' => '男士寸头发型效果图 | 利落酷帅显精神 - HairHub',
                'seo_description' => '男士寸头造型清爽利落，几乎零打理成本，适合方脸、鹅蛋脸。想知道自己适不适合剃寸头？用 HairHub AI换发型工具先看效果再决定。',
                'is_recommended' => false,
                'tags' => ['cool-style', 'low-maintenance', 'no-perm-needed', 'easy-to-maintain', 'fresh-style', 'face-slimming'],
            ],
            [
                'slug' => 'men-side-part-short-hair',
                'name' => '男士侧分短发',
                'name_en' => 'Men\'s Side-part Short Hair',
                'category_slug' => 'men-business-hairstyles',
                'gender' => HairstyleGender::Male->value,
                'age_range' => HairstyleAgeRange::Young->value,
                'style_type' => HairstyleStyleType::Business->value,
                'hair_length' => HairstyleHairLength::Short->value,
                'hair_type' => HairstyleHairType::Straight->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Round->value, FaceShape::Square->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Normal->value,
                'suitable_scene' => [SuitableScene::Work->value, SuitableScene::Business->value],
                'description' => '侧分短发用发缝清晰地分出主次层次，头顶保持轻微蓬松，两侧收拾干净，看起来精神又不失亲和力，是职场新人很稳的选择。',
                'ai_prompt' => 'men\'s side-parted short haircut, clean deep side part, slightly voluminous top, neatly trimmed sides, professional business-appropriate silhouette',
                'ai_negative_prompt' => $negative,
                'seo_title' => '男士侧分短发发型效果图 | 职场清爽显精神 - HairHub',
                'seo_description' => '男士侧分短发干净利落，适合圆脸、方脸，是职场通勤的稳妥选择。上传证件照或生活照，HairHub AI换发型工具帮你先看效果。',
                'is_recommended' => false,
                'tags' => ['business-style', 'fresh-style', 'side-swept-bangs', 'face-slimming', 'office-work', 'easy-to-maintain'],
            ],
            [
                'slug' => 'men-37-side-part',
                'name' => '男士三七侧分',
                'name_en' => 'Men\'s 3:7 Side Part',
                'category_slug' => 'men-business-hairstyles',
                'gender' => HairstyleGender::Male->value,
                'age_range' => HairstyleAgeRange::MiddleAge->value,
                'style_type' => HairstyleStyleType::Business->value,
                'hair_length' => HairstyleHairLength::Short->value,
                'hair_type' => HairstyleHairType::Straight->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Long->value, FaceShape::Square->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Normal->value,
                'suitable_scene' => [SuitableScene::Work->value, SuitableScene::Business->value],
                'description' => '三七分把头发按三七比例分开，露出饱满的额头轮廓，整体线条干净沉稳，很适合需要日常出席商务场合的男士。',
                'ai_prompt' => 'men\'s 3:7 ratio side-parted hairstyle, defined hair part on one side, smooth combed-back top, neat and professional finish, subtle natural shine',
                'ai_negative_prompt' => $negative,
                'seo_title' => '男士三七分发型效果图 | 商务稳重显气场 - HairHub',
                'seo_description' => '男士三七分造型沉稳大气，适合长脸、方脸，是商务人士的经典选择。用 HairHub AI换发型工具上传照片，提前预览三七分效果。',
                'is_recommended' => false,
                'tags' => ['business-style', 'steady-style', 'side-part-37', 'office-work', 'face-shortening', 'easy-to-maintain'],
            ],
            [
                'slug' => 'men-classic-slicked-back',
                'name' => '男士经典背头',
                'name_en' => 'Men\'s Classic Slicked-back Hair',
                'category_slug' => 'men-business-hairstyles',
                'gender' => HairstyleGender::Male->value,
                'age_range' => HairstyleAgeRange::MiddleAge->value,
                'style_type' => HairstyleStyleType::Retro->value,
                'hair_length' => HairstyleHairLength::Medium->value,
                'hair_type' => HairstyleHairType::Straight->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Square->value, FaceShape::Oval->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Normal->value,
                'suitable_scene' => [SuitableScene::Business->value, SuitableScene::Wedding->value],
                'description' => '背头把全部头发向后梳起，露出完整的额头和发际线轮廓，造型干净有气场，是正式场合和商务宴请的经典选择。',
                'ai_prompt' => 'men\'s classic slicked-back combover hairstyle, all hair combed backward off the forehead, glossy finish, defined hairline, medium length on top, neatly tapered sides',
                'ai_negative_prompt' => $negative,
                'seo_title' => '男士经典背头发型效果图 | 商务复古显气场 - HairHub',
                'seo_description' => '男士背头造型复古大气，适合方脸、鹅蛋脸，商务宴请和正式场合都很合适。先用 HairHub AI换发型工具试试背头效果再去理发店。',
                'is_recommended' => false,
                'tags' => ['business-style', 'retro-style', 'steady-style', 'wedding-scene', 'formal-occasion', 'forehead-flattering'],
            ],
            [
                'slug' => 'men-pompadour',
                'name' => '男士飞机头',
                'name_en' => 'Men\'s Pompadour',
                'category_slug' => 'men-trendy-hairstyles',
                'gender' => HairstyleGender::Male->value,
                'age_range' => HairstyleAgeRange::Young->value,
                'style_type' => HairstyleStyleType::Fashion->value,
                'hair_length' => HairstyleHairLength::Medium->value,
                'hair_type' => HairstyleHairType::Straight->value,
                'hair_volume' => HairstyleHairVolume::High->value,
                'face_shape' => [FaceShape::Round->value, FaceShape::Square->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Difficult->value,
                'suitable_scene' => [SuitableScene::Party->value, SuitableScene::Performance->value],
                'description' => '飞机头在头顶堆出明显高度，向后梳出流线造型，两侧收得干净利落，视觉冲击力强，适合喜欢张扬个性、拍照出片的男生。',
                'ai_prompt' => 'men\'s pompadour hairstyle, tall voluminous swept-back top with strong height, sleek finish, sharply tapered sides, dramatic silhouette',
                'ai_negative_prompt' => $negative,
                'seo_title' => '男士飞机头发型效果图 | 潮流个性显高度 - HairHub',
                'seo_description' => '男士飞机头造型潮流有个性，头顶堆高显气场，适合圆脸、方脸拍照出片。用 HairHub AI换发型工具先预览效果，再决定要不要剪。',
                'is_recommended' => true,
                'tags' => ['trendy-style', 'cool-style', 'crown-volume', 'distinctive-style', 'photography-scene', 'face-slimming'],
            ],
            [
                'slug' => 'men-textured-fringe-crop',
                'name' => '男士碎盖发型',
                'name_en' => 'Men\'s Textured Fringe Crop',
                'category_slug' => 'men-short-hairstyles',
                'gender' => HairstyleGender::Male->value,
                'age_range' => HairstyleAgeRange::All->value,
                'style_type' => HairstyleStyleType::Casual->value,
                'hair_length' => HairstyleHairLength::Short->value,
                'hair_type' => HairstyleHairType::Wavy->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Square->value, FaceShape::Long->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Easy->value,
                'suitable_scene' => [SuitableScene::Daily->value, SuitableScene::School->value],
                'description' => '碎盖发型用碎发遮盖额头，发丝层次分明、边缘不规则，看起来随性自然又不显刻意，是学生和年轻人都很喜欢的日常造型。',
                'ai_prompt' => 'men\'s textured fringe crop haircut, choppy fringe covering the forehead, uneven natural texture on top, low-to-medium volume, casual disheveled finish',
                'ai_negative_prompt' => $negative,
                'seo_title' => '男士碎盖发型效果图 | 自然随性显年轻 - HairHub',
                'seo_description' => '男士碎盖发型自然随性，适合方脸、长脸修饰额头比例。学生党和上班族都能日常驾驭，先用 HairHub AI换发型工具看看效果。',
                'is_recommended' => false,
                'tags' => ['natural-style', 'youthful-style', 'forehead-flattering', 'easy-to-maintain', 'daily-commute', 'no-perm-needed'],
            ],
            [
                'slug' => 'men-middle-part-hairstyle',
                'name' => '男士中分发型',
                'name_en' => 'Men\'s Middle Part Hairstyle',
                'category_slug' => 'men-medium-hairstyles',
                'gender' => HairstyleGender::Male->value,
                'age_range' => HairstyleAgeRange::Young->value,
                'style_type' => HairstyleStyleType::Korean->value,
                'hair_length' => HairstyleHairLength::Medium->value,
                'hair_type' => HairstyleHairType::Straight->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Long->value, FaceShape::Oval->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Normal->value,
                'suitable_scene' => [SuitableScene::Date->value, SuitableScene::Party->value],
                'description' => '中分把头发从正中间分开，两侧对称下垂，脸部轮廓被自然框住，是近几年很流行的韩系男生造型，个性感和辨识度都很高。',
                'ai_prompt' => 'men\'s center-parted medium-length hairstyle, hair evenly divided down the middle, symmetrical strands framing the face, soft natural movement, medium volume',
                'ai_negative_prompt' => $negative,
                'seo_title' => '男士中分发型效果图 | 韩系个性显脸型 - HairHub',
                'seo_description' => '男士中分发型韩系感强，适合长脸、鹅蛋脸，个性又有辨识度。上传照片，用 HairHub AI换发型工具在线预览中分效果。',
                'is_recommended' => false,
                'tags' => ['korean-style', 'distinctive-style', 'middle-part', 'face-shortening', 'date-scene', 'photography-scene'],
            ],
            [
                'slug' => 'men-texture-perm',
                'name' => '男士纹理烫',
                'name_en' => 'Men\'s Texture Perm',
                'category_slug' => 'men-perm-hairstyles',
                'gender' => HairstyleGender::Male->value,
                'age_range' => HairstyleAgeRange::Young->value,
                'style_type' => HairstyleStyleType::Korean->value,
                'hair_length' => HairstyleHairLength::Short->value,
                'hair_type' => HairstyleHairType::Wavy->value,
                'hair_volume' => HairstyleHairVolume::High->value,
                'face_shape' => [FaceShape::Round->value, FaceShape::Square->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Difficult->value,
                'suitable_scene' => [SuitableScene::Daily->value, SuitableScene::Work->value],
                'description' => '纹理烫通过小卷烫制出自然凌乱的层次感，头顶蓬松有型，特别适合发量偏细软、想要视觉增发的男生，是韩系烫发中最百搭的一款。',
                'ai_prompt' => 'men\'s textured perm hairstyle, small natural-looking curls throughout the top, voluminous crown, layered choppy texture, medium length, soft matte finish',
                'ai_negative_prompt' => $negative,
                'seo_title' => '男士纹理烫发型效果图 | 韩系显发量细软发救星 - HairHub',
                'seo_description' => '男士纹理烫显发量效果明显，特别适合细软发和圆脸、方脸。先用 HairHub AI换发型工具预览烫后效果，再决定要不要去理发店。',
                'is_recommended' => true,
                'tags' => ['texture-perm', 'volume-boosting', 'crown-volume', 'suitable-fine-hair', 'korean-style', 'daily-commute'],
            ],
            [
                'slug' => 'men-morgan-perm',
                'name' => '男士摩根烫',
                'name_en' => 'Men\'s Morgan Perm',
                'category_slug' => 'men-perm-hairstyles',
                'gender' => HairstyleGender::Male->value,
                'age_range' => HairstyleAgeRange::Young->value,
                'style_type' => HairstyleStyleType::Fashion->value,
                'hair_length' => HairstyleHairLength::Medium->value,
                'hair_type' => HairstyleHairType::Curly->value,
                'hair_volume' => HairstyleHairVolume::High->value,
                'face_shape' => [FaceShape::Round->value, FaceShape::Long->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Difficult->value,
                'suitable_scene' => [SuitableScene::Party->value, SuitableScene::Performance->value],
                'description' => '摩根烫卷度更明显、层次更夸张，发丝呈现自然卷曲的螺旋感，整体造型个性十足，适合想要大幅改变风格、拍照出片的男生。',
                'ai_prompt' => 'men\'s Morgan perm hairstyle, pronounced spiral curls throughout, dense layered texture, high crown volume, bold and voluminous silhouette',
                'ai_negative_prompt' => $negative,
                'seo_title' => '男士摩根烫发型效果图 | 潮流个性大卷造型 - HairHub',
                'seo_description' => '男士摩根烫卷度夸张有个性，适合圆脸、长脸拍照出片。烫发风险大，先用 HairHub AI换发型工具预览效果更放心。',
                'is_recommended' => false,
                'tags' => ['morgan-perm', 'trendy-style', 'crown-volume', 'volume-boosting', 'distinctive-style', 'photography-scene'],
            ],
            [
                'slug' => 'men-foil-perm',
                'name' => '男士锡纸烫',
                'name_en' => 'Men\'s Foil Perm',
                'category_slug' => 'men-perm-hairstyles',
                'gender' => HairstyleGender::Male->value,
                'age_range' => HairstyleAgeRange::Young->value,
                'style_type' => HairstyleStyleType::Fashion->value,
                'hair_length' => HairstyleHairLength::Medium->value,
                'hair_type' => HairstyleHairType::Wavy->value,
                'hair_volume' => HairstyleHairVolume::High->value,
                'face_shape' => [FaceShape::Square->value, FaceShape::Oval->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Difficult->value,
                'suitable_scene' => [SuitableScene::Daily->value, SuitableScene::Party->value],
                'description' => '锡纸烫用锡纸包裹分区定型，烫出层次分明的大弧度卷发，蓬松感和线条感兼顾，是这几年热度很高的男士烫发款式。',
                'ai_prompt' => 'men\'s foil perm hairstyle, defined large curls with clear separation, voluminous layered top, glossy textured finish, medium length',
                'ai_negative_prompt' => $negative,
                'seo_title' => '男士锡纸烫发型效果图 | 潮流蓬松显发量 - HairHub',
                'seo_description' => '男士锡纸烫蓬松有型，适合方脸、鹅蛋脸，视觉增发效果好。担心烫完不满意？先用 HairHub AI换发型工具预览锡纸烫效果。',
                'is_recommended' => false,
                'tags' => ['foil-perm', 'trendy-style', 'crown-volume', 'volume-boosting', 'cool-style', 'daily-commute'],
            ],
            [
                'slug' => 'men-undercut-fade',
                'name' => '男士两侧铲青短发',
                'name_en' => 'Men\'s Undercut Fade',
                'category_slug' => 'men-trendy-hairstyles',
                'gender' => HairstyleGender::Male->value,
                'age_range' => HairstyleAgeRange::Young->value,
                'style_type' => HairstyleStyleType::Cool->value,
                'hair_length' => HairstyleHairLength::Short->value,
                'hair_type' => HairstyleHairType::Straight->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Round->value, FaceShape::Square->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Normal->value,
                'suitable_scene' => [SuitableScene::Daily->value, SuitableScene::Performance->value],
                'description' => '两侧铲青把耳侧和后颈头发剃至极短，与头顶保留的长度形成强烈对比，轮廓利落有攻击性，是追求个性和潮流感男生的热门选择。',
                'ai_prompt' => 'men\'s undercut fade hairstyle, closely shaved sides and back, longer hair on top with clear contrast, sharp fade line, textured top styling',
                'ai_negative_prompt' => $negative,
                'seo_title' => '男士两侧铲青发型效果图 | 潮流酷帅显轮廓 - HairHub',
                'seo_description' => '男士两侧铲青轮廓利落，适合圆脸、方脸，酷帅有个性。想知道铲青适不适合自己？先用 HairHub AI换发型工具预览效果。',
                'is_recommended' => false,
                'tags' => ['shaved-sides', 'cool-style', 'distinctive-style', 'face-slimming', 'trendy-style', 'photography-scene'],
            ],
            [
                'slug' => 'men-volumizing-short-hair-thin',
                'name' => '发量少男士蓬松短发',
                'name_en' => 'Men\'s Volumizing Short Hairstyle for Thin Hair',
                'category_slug' => 'men-volume-hairstyles',
                'gender' => HairstyleGender::Male->value,
                'age_range' => HairstyleAgeRange::All->value,
                'style_type' => HairstyleStyleType::Casual->value,
                'hair_length' => HairstyleHairLength::Short->value,
                'hair_type' => HairstyleHairType::Fine->value,
                'hair_volume' => HairstyleHairVolume::Low->value,
                'face_shape' => [FaceShape::Round->value, FaceShape::Square->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Easy->value,
                'suitable_scene' => [SuitableScene::Daily->value, SuitableScene::Work->value],
                'description' => '专为发量偏少设计的蓬松短发，通过发根定向吹蓬和层次剪打造视觉增发效果，头顶更立体，即使发量不多也能显得饱满自然。',
                'ai_prompt' => 'men\'s short volumizing hairstyle for thinning hair, lifted roots creating fullness at the crown, soft layered texture, natural fluffy finish, medium-short length',
                'ai_negative_prompt' => $negative,
                'seo_title' => '发量少男士蓬松短发效果图 | 显发量救星发型 - HairHub',
                'seo_description' => '发量少也能显蓬松！这款男士短发专门照顾细软发和发量偏少的情况，适合圆脸、方脸。用 HairHub AI换发型工具先预览显发量效果。',
                'is_recommended' => false,
                'tags' => ['volume-boosting', 'suitable-thin-hair', 'crown-volume', 'easy-to-maintain', 'suitable-fine-hair', 'daily-commute'],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function womenDefinitions(): array
    {
        $negative = self::NEGATIVE_WOMEN;

        return [
            [
                'slug' => 'women-classic-bob',
                'name' => '女士经典波波头',
                'name_en' => 'Women\'s Classic Bob',
                'category_slug' => 'women-short-hairstyles',
                'gender' => HairstyleGender::Female->value,
                'age_range' => HairstyleAgeRange::Young->value,
                'style_type' => HairstyleStyleType::Retro->value,
                'hair_length' => HairstyleHairLength::Short->value,
                'hair_type' => HairstyleHairType::Straight->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Round->value, FaceShape::Square->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Easy->value,
                'suitable_scene' => [SuitableScene::Daily->value, SuitableScene::Work->value],
                'description' => '波波头长度齐下颌，发尾内扣，线条圆润又干净，是经久不衰的经典短发款式，日常打理只需简单吹整就能保持造型。',
                'ai_prompt' => 'women\'s classic bob haircut, hair length reaching the jawline, ends curled slightly inward, blunt clean cut, smooth rounded silhouette, medium volume',
                'ai_negative_prompt' => $negative,
                'seo_title' => '女士经典波波头发型效果图 | 减龄显脸小 - HairHub',
                'seo_description' => '女士波波头显脸小效果好，适合圆脸、方脸，减龄又好打理。上传照片，用 HairHub AI换发型工具秒速预览波波头效果。',
                'is_recommended' => true,
                'tags' => ['youthful-style', 'face-slimming', 'easy-to-maintain', 'korean-style', 'daily-commute', 'layered-cut'],
            ],
            [
                'slug' => 'women-french-short-hair',
                'name' => '女士法式短发',
                'name_en' => 'Women\'s French Short Hair',
                'category_slug' => 'women-short-hairstyles',
                'gender' => HairstyleGender::Female->value,
                'age_range' => HairstyleAgeRange::Young->value,
                'style_type' => HairstyleStyleType::Casual->value,
                'hair_length' => HairstyleHairLength::Short->value,
                'hair_type' => HairstyleHairType::Wavy->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Oval->value, FaceShape::Heart->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Normal->value,
                'suitable_scene' => [SuitableScene::Performance->value, SuitableScene::Daily->value],
                'description' => '法式短发强调随性不刻意的凌乱感，发丝层次细碎，露出脖颈线条，整体气质慵懒又高级，是想要拍出杂志感的女生首选。',
                'ai_prompt' => 'women\'s French-style short haircut, effortless tousled texture, fine choppy layers, exposed neckline, relaxed undone finish, medium-low volume',
                'ai_negative_prompt' => $negative,
                'seo_title' => '女士法式短发发型效果图 | 高级慵懒显气质 - HairHub',
                'seo_description' => '女士法式短发气质慵懒高级，适合鹅蛋脸、心形脸，拍照效果出众。先用 HairHub AI换发型工具预览法式短发上头效果。',
                'is_recommended' => false,
                'tags' => ['french-style', 'artistic-style', 'distinctive-style', 'photography-scene', 'layered-cut', 'easy-to-maintain'],
            ],
            [
                'slug' => 'women-first-love-short-hair',
                'name' => '女士初恋短发',
                'name_en' => 'Women\'s First-love Short Hair',
                'category_slug' => 'women-short-hairstyles',
                'gender' => HairstyleGender::Female->value,
                'age_range' => HairstyleAgeRange::Young->value,
                'style_type' => HairstyleStyleType::Korean->value,
                'hair_length' => HairstyleHairLength::Short->value,
                'hair_type' => HairstyleHairType::Wavy->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Round->value, FaceShape::Oval->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Easy->value,
                'suitable_scene' => [SuitableScene::Daily->value, SuitableScene::School->value],
                'description' => '初恋短发发尾微微内扣带一点空气感刘海，整体线条软萌自然，像少女漫画里的邻家女孩造型，减龄效果非常明显。',
                'ai_prompt' => 'women\'s soft short haircut with inward-curled ends, wispy air fringe, gentle rounded silhouette, girlish natural texture, medium volume',
                'ai_negative_prompt' => $negative,
                'seo_title' => '女士初恋短发发型效果图 | 韩系减龄邻家感 - HairHub',
                'seo_description' => '女士初恋头软萌减龄，适合圆脸、鹅蛋脸，校园和日常都合适。上传照片，用 HairHub AI换发型工具在线体验初恋头效果。',
                'is_recommended' => false,
                'tags' => ['korean-style', 'youthful-style', 'sweet-style', 'air-bangs', 'school-scene', 'face-slimming'],
            ],
            [
                'slug' => 'women-shoulder-length-hair',
                'name' => '女士齐肩发',
                'name_en' => 'Women\'s Shoulder-length Hair',
                'category_slug' => 'women-medium-short-hairstyles',
                'gender' => HairstyleGender::Female->value,
                'age_range' => HairstyleAgeRange::All->value,
                'style_type' => HairstyleStyleType::Casual->value,
                'hair_length' => HairstyleHairLength::Shoulder->value,
                'hair_type' => HairstyleHairType::Straight->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Oval->value, FaceShape::Square->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Easy->value,
                'suitable_scene' => [SuitableScene::Daily->value, SuitableScene::Work->value],
                'description' => '齐肩发长度刚好落在肩部，线条自然不夸张，既保留了长发的柔美又比长发更好打理，是通勤造型里出错率最低的选择。',
                'ai_prompt' => 'women\'s shoulder-length haircut, straight smooth strands ending at the shoulders, natural soft layers, medium volume, understated elegant finish',
                'ai_negative_prompt' => $negative,
                'seo_title' => '女士齐肩发发型效果图 | 自然知性好打理 - HairHub',
                'seo_description' => '女士齐肩发自然百搭，适合鹅蛋脸、方脸，通勤职场都合适。想知道齐肩发适不适合自己？用 HairHub AI换发型工具先看效果。',
                'is_recommended' => false,
                'tags' => ['natural-style', 'intellectual-style', 'easy-to-maintain', 'office-work', 'daily-commute', 'face-slimming'],
            ],
            [
                'slug' => 'women-clavicle-length-hair',
                'name' => '女士锁骨发',
                'name_en' => 'Women\'s Clavicle-length Hair',
                'category_slug' => 'women-clavicle-hairstyles',
                'gender' => HairstyleGender::Female->value,
                'age_range' => HairstyleAgeRange::Young->value,
                'style_type' => HairstyleStyleType::Casual->value,
                'hair_length' => HairstyleHairLength::Shoulder->value,
                'hair_type' => HairstyleHairType::Straight->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Square->value, FaceShape::Diamond->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Easy->value,
                'suitable_scene' => [SuitableScene::Daily->value, SuitableScene::Work->value],
                'description' => '锁骨发长度刚好到锁骨位置，是长发和中长发之间人气最高的黄金长度，修饰颧骨线条效果好，知性又带一点轻熟气质，通勤约会都适合。',
                'ai_prompt' => 'women\'s clavicle-length hairstyle, hair ending right at the collarbone, soft layered ends, straight smooth texture, medium volume, elegant subtle movement',
                'ai_negative_prompt' => $negative,
                'seo_title' => '女士锁骨发发型效果图 | 知性轻熟修颧骨 - HairHub',
                'seo_description' => '女士锁骨发是黄金长度，适合方脸、菱形脸，修饰颧骨效果明显。上传照片，用 HairHub AI换发型工具提前预览锁骨发效果。',
                'is_recommended' => true,
                'tags' => ['face-slimming', 'mature-chic-style', 'intellectual-style', 'daily-commute', 'cheekbone-flattering', 'easy-to-maintain', 'layered-cut'],
            ],
            [
                'slug' => 'women-layered-clavicle-hair',
                'name' => '女士层次锁骨发',
                'name_en' => 'Women\'s Layered Clavicle-length Hair',
                'category_slug' => 'women-clavicle-hairstyles',
                'gender' => HairstyleGender::Female->value,
                'age_range' => HairstyleAgeRange::Young->value,
                'style_type' => HairstyleStyleType::Casual->value,
                'hair_length' => HairstyleHairLength::Shoulder->value,
                'hair_type' => HairstyleHairType::Wavy->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Square->value, FaceShape::Diamond->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Normal->value,
                'suitable_scene' => [SuitableScene::Daily->value, SuitableScene::Date->value],
                'description' => '在锁骨发基础上加入更多层次剪，发尾带有自然的碎发感和微卷弧度，减少了厚重感，整体更轻盈灵动，适合想要一点小变化的锁骨发爱好者。',
                'ai_prompt' => 'women\'s layered clavicle-length hairstyle, soft face-framing layers, ends with gentle wave and texture, lightweight movement, medium volume',
                'ai_negative_prompt' => $negative,
                'seo_title' => '女士层次锁骨发发型效果图 | 轻盈灵动显气质 - HairHub',
                'seo_description' => '女士层次锁骨发轻盈灵动，适合方脸、菱形脸，比普通锁骨发更有层次感。用 HairHub AI换发型工具先预览层次锁骨发效果。',
                'is_recommended' => false,
                'tags' => ['layered-cut', 'mature-chic-style', 'intellectual-style', 'daily-commute', 'cheekbone-flattering', 'date-scene'],
            ],
            [
                'slug' => 'women-long-straight-black-hair',
                'name' => '女士黑长直',
                'name_en' => 'Women\'s Long Straight Black Hair',
                'category_slug' => 'women-long-hairstyles',
                'gender' => HairstyleGender::Female->value,
                'age_range' => HairstyleAgeRange::All->value,
                'style_type' => HairstyleStyleType::Other->value,
                'hair_length' => HairstyleHairLength::Long->value,
                'hair_type' => HairstyleHairType::Straight->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Oval->value, FaceShape::Heart->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Normal->value,
                'suitable_scene' => [SuitableScene::Wedding->value, SuitableScene::Daily->value],
                'description' => '黑长直是最经典百搭的长发造型，发丝顺滑无刘海，从头顶到发尾保持自然垂坠感，气质温柔优雅，几乎适合所有正式和日常场合。',
                'ai_prompt' => 'women\'s long straight black hair, sleek smooth strands with no bangs, natural glossy finish, hair flowing straight down past the shoulders, medium volume',
                'ai_negative_prompt' => $negative,
                'seo_title' => '女士黑长直发型效果图 | 优雅气质万能长发 - HairHub',
                'seo_description' => '女士黑长直优雅百搭，适合鹅蛋脸、心形脸，正式场合和日常都合适。上传照片，用 HairHub AI换发型工具预览黑长直效果。',
                'is_recommended' => true,
                'tags' => ['elegant-style', 'no-bangs', 'natural-style', 'wedding-scene', 'daily-commute', 'easy-to-maintain'],
            ],
            [
                'slug' => 'women-figure-8-bangs-long-hair',
                'name' => '女士八字刘海长发',
                'name_en' => 'Women\'s Figure-8 Bangs Long Hair',
                'category_slug' => 'women-long-hairstyles',
                'gender' => HairstyleGender::Female->value,
                'age_range' => HairstyleAgeRange::Young->value,
                'style_type' => HairstyleStyleType::Sweet->value,
                'hair_length' => HairstyleHairLength::Long->value,
                'hair_type' => HairstyleHairType::Straight->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Round->value, FaceShape::Square->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Normal->value,
                'suitable_scene' => [SuitableScene::Performance->value, SuitableScene::Daily->value],
                'description' => '八字刘海从中间向两侧自然分开，呈现出微微的弧度，修饰额头比例的同时不会显得太厚重，搭配长发显得甜美又不失灵动感。',
                'ai_prompt' => 'women\'s long hairstyle with figure-8 parted bangs, softly curved fringe sweeping to both sides, straight long hair underneath, medium volume, sweet gentle finish',
                'ai_negative_prompt' => $negative,
                'seo_title' => '女士八字刘海长发效果图 | 甜美修额头减龄 - HairHub',
                'seo_description' => '女士八字刘海长发甜美减龄，适合圆脸、方脸修饰额头。想看看自己适不适合八字刘海？用 HairHub AI换发型工具在线预览。',
                'is_recommended' => false,
                'tags' => ['figure-8-bangs', 'sweet-style', 'forehead-flattering', 'youthful-style', 'photography-scene', 'face-slimming'],
            ],
            [
                'slug' => 'women-air-bangs-long-hair',
                'name' => '女士空气刘海长发',
                'name_en' => 'Women\'s Air Bangs Long Hair',
                'category_slug' => 'women-long-hairstyles',
                'gender' => HairstyleGender::Female->value,
                'age_range' => HairstyleAgeRange::Young->value,
                'style_type' => HairstyleStyleType::Sweet->value,
                'hair_length' => HairstyleHairLength::Long->value,
                'hair_type' => HairstyleHairType::Straight->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Long->value, FaceShape::Square->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Normal->value,
                'suitable_scene' => [SuitableScene::Daily->value, SuitableScene::School->value],
                'description' => '空气刘海发丝稀薄、留有间隙，若隐若现地露出额头，视觉上能缩短脸型比例，搭配长发显得清透减龄，是长脸女生的刘海首选。',
                'ai_prompt' => 'women\'s long hairstyle with wispy air bangs, thin see-through fringe with visible gaps, soft face-framing strands, straight long hair, light airy volume',
                'ai_negative_prompt' => $negative,
                'seo_title' => '女士空气刘海长发效果图 | 缩短脸型清透减龄 - HairHub',
                'seo_description' => '女士空气刘海长发清透减龄，特别适合长脸、方脸缩短脸型比例。先用 HairHub AI换发型工具预览空气刘海上头效果。',
                'is_recommended' => false,
                'tags' => ['air-bangs', 'sweet-style', 'face-shortening', 'youthful-style', 'daily-commute', 'forehead-flattering'],
            ],
            [
                'slug' => 'women-big-wave-curls',
                'name' => '女士大波浪卷发',
                'name_en' => 'Women\'s Big Wave Curls',
                'category_slug' => 'women-curly-hairstyles',
                'gender' => HairstyleGender::Female->value,
                'age_range' => HairstyleAgeRange::Young->value,
                'style_type' => HairstyleStyleType::Retro->value,
                'hair_length' => HairstyleHairLength::Long->value,
                'hair_type' => HairstyleHairType::Wavy->value,
                'hair_volume' => HairstyleHairVolume::High->value,
                'face_shape' => [FaceShape::Square->value, FaceShape::Round->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Difficult->value,
                'suitable_scene' => [SuitableScene::Wedding->value, SuitableScene::Performance->value],
                'description' => '大波浪卷发从中段开始烫出大弧度弯曲，发尾自然蓬松飘逸，很有电影感和女人味，是拍照和婚礼等重要场合的加分造型。',
                'ai_prompt' => 'women\'s long hairstyle with big loose waves, voluminous curls starting from mid-length, glossy flowing texture, soft romantic movement, high volume',
                'ai_negative_prompt' => $negative,
                'seo_title' => '女士大波浪卷发发型效果图 | 优雅女人味显气质 - HairHub',
                'seo_description' => '女士大波浪卷发优雅有气场，适合方脸、圆脸修饰颧骨。婚礼、拍照都合适，先用 HairHub AI换发型工具预览大波浪效果。',
                'is_recommended' => true,
                'tags' => ['big-waves', 'retro-style', 'cheekbone-flattering', 'wedding-scene', 'photography-scene', 'volume-boosting'],
            ],
            [
                'slug' => 'women-wool-curl-perm',
                'name' => '女士羊毛卷',
                'name_en' => 'Women\'s Wool Curl Perm',
                'category_slug' => 'women-curly-hairstyles',
                'gender' => HairstyleGender::Female->value,
                'age_range' => HairstyleAgeRange::Young->value,
                'style_type' => HairstyleStyleType::Sweet->value,
                'hair_length' => HairstyleHairLength::Shoulder->value,
                'hair_type' => HairstyleHairType::Curly->value,
                'hair_volume' => HairstyleHairVolume::High->value,
                'face_shape' => [FaceShape::Long->value, FaceShape::Oval->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Difficult->value,
                'suitable_scene' => [SuitableScene::Daily->value, SuitableScene::Date->value],
                'description' => '羊毛卷烫出细密均匀的小卷，整体蓬松又有弹性，像羊毛一样柔软可爱，视觉增发效果显著，是近几年很受欢迎的韩系烫发款式。',
                'ai_prompt' => 'women\'s wool curl perm hairstyle, dense small springy curls throughout, fluffy voluminous texture, medium-short length, soft romantic silhouette',
                'ai_negative_prompt' => $negative,
                'seo_title' => '女士羊毛卷发型效果图 | 韩系显发量蓬松感 - HairHub',
                'seo_description' => '女士羊毛卷蓬松可爱，适合长脸、鹅蛋脸，显发量效果非常明显。烫发前先用 HairHub AI换发型工具预览羊毛卷上头效果。',
                'is_recommended' => false,
                'tags' => ['wool-curl-perm', 'sweet-style', 'volume-boosting', 'korean-style', 'crown-volume', 'date-scene'],
            ],
            [
                'slug' => 'women-egg-roll-perm',
                'name' => '女士蛋卷头',
                'name_en' => 'Women\'s Egg Roll Perm',
                'category_slug' => 'women-curly-hairstyles',
                'gender' => HairstyleGender::Female->value,
                'age_range' => HairstyleAgeRange::Young->value,
                'style_type' => HairstyleStyleType::Retro->value,
                'hair_length' => HairstyleHairLength::Shoulder->value,
                'hair_type' => HairstyleHairType::Curly->value,
                'hair_volume' => HairstyleHairVolume::High->value,
                'face_shape' => [FaceShape::Oval->value, FaceShape::Round->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Difficult->value,
                'suitable_scene' => [SuitableScene::Performance->value, SuitableScene::Party->value],
                'description' => '蛋卷头烫出圆润饱满的螺旋卷，发尾卷曲弧度均匀像蛋卷一样，造型感强、辨识度高，很适合想要出片效果和复古气质的女生。',
                'ai_prompt' => 'women\'s egg roll perm hairstyle, uniform round spiral curls throughout, bouncy defined texture, shoulder-length volume, playful retro silhouette',
                'ai_negative_prompt' => $negative,
                'seo_title' => '女士蛋卷头发型效果图 | 复古可爱高辨识度 - HairHub',
                'seo_description' => '女士蛋卷头复古可爱，适合鹅蛋脸、圆脸，拍照辨识度很高。想看看自己适不适合蛋卷头？用 HairHub AI换发型工具先试试。',
                'is_recommended' => false,
                'tags' => ['egg-roll-perm', 'cute-style', 'retro-style', 'volume-boosting', 'photography-scene', 'crown-volume'],
            ],
            [
                'slug' => 'women-french-bangs-hairstyle',
                'name' => '女士法式刘海发型',
                'name_en' => 'Women\'s French Bangs Hairstyle',
                'category_slug' => 'women-bangs-hairstyles',
                'gender' => HairstyleGender::Female->value,
                'age_range' => HairstyleAgeRange::Young->value,
                'style_type' => HairstyleStyleType::Casual->value,
                'hair_length' => HairstyleHairLength::Medium->value,
                'hair_type' => HairstyleHairType::Wavy->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Oval->value, FaceShape::Square->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Normal->value,
                'suitable_scene' => [SuitableScene::Performance->value, SuitableScene::Daily->value],
                'description' => '法式刘海比空气刘海略厚，弧度更柔和自然，两侧带一点弯曲弧线修饰太阳穴，慵懒随性中透着一点法式高级感。',
                'ai_prompt' => 'women\'s hairstyle with French-style bangs, soft curved fringe with gentle waves at the temples, medium length hair, relaxed effortless texture, medium volume',
                'ai_negative_prompt' => $negative,
                'seo_title' => '女士法式刘海发型效果图 | 高级慵懒修额头 - HairHub',
                'seo_description' => '女士法式刘海高级慵懒，适合鹅蛋脸、方脸，修饰额头效果自然。上传照片，用 HairHub AI换发型工具预览法式刘海效果。',
                'is_recommended' => false,
                'tags' => ['french-bangs', 'french-style', 'forehead-flattering', 'artistic-style', 'photography-scene', 'face-slimming'],
            ],
            [
                'slug' => 'women-no-bangs-side-part-long-hair',
                'name' => '女士无刘海侧分长发',
                'name_en' => 'Women\'s No-bangs Side-part Long Hair',
                'category_slug' => 'women-long-hairstyles',
                'gender' => HairstyleGender::Female->value,
                'age_range' => HairstyleAgeRange::All->value,
                'style_type' => HairstyleStyleType::Other->value,
                'hair_length' => HairstyleHairLength::Long->value,
                'hair_type' => HairstyleHairType::Straight->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Long->value, FaceShape::Square->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Easy->value,
                'suitable_scene' => [SuitableScene::Work->value, SuitableScene::Wedding->value],
                'description' => '无刘海侧分长发把额头完全露出，用发缝自然分出层次，脸型轮廓被拉长显得更加立体优雅，是正式场合和职场都很稳的长发选择。',
                'ai_prompt' => 'women\'s long hairstyle with no bangs, deep side part exposing the forehead, sleek straight strands, elegant symmetrical framing, medium volume',
                'ai_negative_prompt' => $negative,
                'seo_title' => '女士无刘海侧分长发效果图 | 优雅知性拉长脸型 - HairHub',
                'seo_description' => '女士无刘海侧分长发优雅知性，适合长脸、方脸拉长脸部比例。职场和正式场合都合适，先用 HairHub AI换发型工具预览效果。',
                'is_recommended' => false,
                'tags' => ['no-bangs', 'side-swept-bangs', 'elegant-style', 'intellectual-style', 'face-lengthening', 'office-work'],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function childDefinitions(): array
    {
        $negative = self::NEGATIVE_KIDS;

        return [
            [
                'slug' => 'boys-fresh-short-hair',
                'name' => '男童清爽短发',
                'name_en' => 'Boys\' Fresh Short Hair',
                'category_slug' => 'boys-hairstyles',
                'gender' => HairstyleGender::Child->value,
                'age_range' => HairstyleAgeRange::Child->value,
                'style_type' => HairstyleStyleType::Casual->value,
                'hair_length' => HairstyleHairLength::Short->value,
                'hair_type' => HairstyleHairType::Straight->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Round->value, FaceShape::Oval->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Easy->value,
                'suitable_scene' => [SuitableScene::Daily->value, SuitableScene::School->value],
                'description' => '男童清爽短发线条简单干净，露出额头和耳朵，透气不闷热，日常洗护和打理都很省心，是家长最常选的基础男童发型。',
                'ai_prompt' => 'boy\'s short haircut for a child, clean simple shape, exposed forehead and ears, neat natural texture, low volume, tidy trimmed sides',
                'ai_negative_prompt' => $negative,
                'seo_title' => '男童清爽短发发型效果图 | 干净好打理显精神 - HairHub',
                'seo_description' => '男童清爽短发好打理不闷热，适合圆脸、鹅蛋脸的小朋友。用 HairHub AI换发型工具帮孩子提前预览短发效果，理发不踩坑。',
                'is_recommended' => true,
                'tags' => ['fresh-style', 'easy-to-maintain', 'low-maintenance', 'school-scene', 'cute-style', 'face-slimming'],
            ],
            [
                'slug' => 'boys-natural-textured-fringe',
                'name' => '男童自然碎盖',
                'name_en' => 'Boys\' Natural Textured Fringe',
                'category_slug' => 'boys-hairstyles',
                'gender' => HairstyleGender::Child->value,
                'age_range' => HairstyleAgeRange::Child->value,
                'style_type' => HairstyleStyleType::Casual->value,
                'hair_length' => HairstyleHairLength::Short->value,
                'hair_type' => HairstyleHairType::Wavy->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Square->value, FaceShape::Long->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Easy->value,
                'suitable_scene' => [SuitableScene::Daily->value, SuitableScene::School->value],
                'description' => '自然碎盖用碎发轻轻盖住额头，发尾带一点自然的凌乱感，不会显得刻意造型，很适合活泼爱动、不喜欢头发被定型的小男生。',
                'ai_prompt' => 'boy\'s short haircut with natural textured fringe lightly covering the forehead, tousled playful texture, medium volume on top, casual kid-friendly finish',
                'ai_negative_prompt' => $negative,
                'seo_title' => '男童自然碎盖发型效果图 | 活泼可爱不刻意 - HairHub',
                'seo_description' => '男童自然碎盖活泼可爱，适合方脸、长脸的小朋友，头发凌乱也好看。先用 HairHub AI换发型工具预览孩子的碎盖发型效果。',
                'is_recommended' => false,
                'tags' => ['natural-style', 'cute-style', 'crown-volume', 'school-scene', 'easy-to-maintain', 'forehead-flattering'],
            ],
            [
                'slug' => 'boys-energetic-buzz-cut',
                'name' => '男童活力寸头',
                'name_en' => 'Boys\' Energetic Buzz Cut',
                'category_slug' => 'boys-hairstyles',
                'gender' => HairstyleGender::Child->value,
                'age_range' => HairstyleAgeRange::Child->value,
                'style_type' => HairstyleStyleType::Cool->value,
                'hair_length' => HairstyleHairLength::Buzz->value,
                'hair_type' => HairstyleHairType::Straight->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Round->value, FaceShape::Oval->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Easy->value,
                'suitable_scene' => [SuitableScene::Daily->value, SuitableScene::Sport->value],
                'description' => '寸头几乎不需要打理，头发全部剪短，运动出汗也不会闷热粘头，特别适合精力旺盛、喜欢跑跳的活泼男孩。',
                'ai_prompt' => 'boy\'s short buzz cut hairstyle, uniformly cropped hair length, clean simple outline, minimal texture, energetic and tidy look for a child',
                'ai_negative_prompt' => $negative,
                'seo_title' => '男童活力寸头发型效果图 | 运动清爽零打理 - HairHub',
                'seo_description' => '男童寸头零打理超清爽，适合圆脸、鹅蛋脸的活泼小朋友，运动出汗也不怕。用 HairHub AI换发型工具先看看孩子剪寸头的效果。',
                'is_recommended' => false,
                'tags' => ['cool-style', 'low-maintenance', 'no-perm-needed', 'easy-to-maintain', 'cute-style', 'school-scene'],
            ],
            [
                'slug' => 'girls-shoulder-length-short-hair',
                'name' => '女童齐肩短发',
                'name_en' => 'Girls\' Shoulder-length Short Hair',
                'category_slug' => 'girls-hairstyles',
                'gender' => HairstyleGender::Child->value,
                'age_range' => HairstyleAgeRange::Child->value,
                'style_type' => HairstyleStyleType::Casual->value,
                'hair_length' => HairstyleHairLength::Medium->value,
                'hair_type' => HairstyleHairType::Straight->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Round->value, FaceShape::Oval->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Easy->value,
                'suitable_scene' => [SuitableScene::Daily->value, SuitableScene::School->value],
                'description' => '女童齐肩短发长度刚好到肩，梳理方便不容易打结，扎起来或披着都好看，是很多家长给女孩的入门首选发型。',
                'ai_prompt' => 'girl\'s shoulder-length haircut for a child, straight smooth strands, simple blunt ends, easy-to-manage texture, medium volume',
                'ai_negative_prompt' => $negative,
                'seo_title' => '女童齐肩短发发型效果图 | 好梳理不易打结 - HairHub',
                'seo_description' => '女童齐肩短发好打理不打结，适合圆脸、鹅蛋脸的小女孩，扎发披发都好看。用 HairHub AI换发型工具帮孩子先看看效果。',
                'is_recommended' => false,
                'tags' => ['cute-style', 'easy-to-maintain', 'natural-style', 'school-scene', 'face-slimming', 'youthful-style'],
            ],
            [
                'slug' => 'girls-bob-haircut',
                'name' => '女童波波头',
                'name_en' => 'Girls\' Bob Haircut',
                'category_slug' => 'girls-hairstyles',
                'gender' => HairstyleGender::Child->value,
                'age_range' => HairstyleAgeRange::Child->value,
                'style_type' => HairstyleStyleType::Sweet->value,
                'hair_length' => HairstyleHairLength::Short->value,
                'hair_type' => HairstyleHairType::Straight->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Round->value, FaceShape::Square->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Easy->value,
                'suitable_scene' => [SuitableScene::Daily->value, SuitableScene::School->value],
                'description' => '女童波波头搭配整齐的齐刘海，发尾圆润内扣，造型简单又可爱，是幼儿园和小学阶段很受欢迎的女童发型之一。',
                'ai_prompt' => 'girl\'s bob haircut for a child with blunt bangs, rounded inward-curled ends, neat even fringe, soft cute silhouette, medium volume',
                'ai_negative_prompt' => $negative,
                'seo_title' => '女童波波头发型效果图 | 可爱减龄齐刘海 - HairHub',
                'seo_description' => '女童波波头可爱减龄，适合圆脸、方脸的小女孩，配上齐刘海显脸小效果好。用 HairHub AI换发型工具先预览孩子的波波头效果。',
                'is_recommended' => true,
                'tags' => ['cute-style', 'youthful-style', 'blunt-bangs', 'face-slimming', 'school-scene', 'easy-to-maintain'],
            ],
            [
                'slug' => 'girls-princess-long-hair',
                'name' => '女童公主长发',
                'name_en' => 'Girls\' Princess Long Hair',
                'category_slug' => 'kids-long-hairstyles',
                'gender' => HairstyleGender::Child->value,
                'age_range' => HairstyleAgeRange::Child->value,
                'style_type' => HairstyleStyleType::Sweet->value,
                'hair_length' => HairstyleHairLength::Long->value,
                'hair_type' => HairstyleHairType::Straight->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Oval->value, FaceShape::Round->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Normal->value,
                'suitable_scene' => [SuitableScene::Performance->value, SuitableScene::Party->value],
                'description' => '公主长发保留完整的长发长度，发尾自然垂顺，搭配发饰或编发都很出片，是很多小女孩梦想中的公主造型。',
                'ai_prompt' => 'girl\'s long princess-style hairstyle for a child, smooth flowing long hair, soft natural shine, gentle face-framing strands, medium volume',
                'ai_negative_prompt' => $negative,
                'seo_title' => '女童公主长发发型效果图 | 甜美出片显气质 - HairHub',
                'seo_description' => '女童公主长发甜美出片，适合鹅蛋脸、圆脸的小女孩，拍照和聚会都合适。用 HairHub AI换发型工具帮孩子预览长发效果。',
                'is_recommended' => false,
                'tags' => ['sweet-style', 'cute-style', 'photography-scene', 'volume-boosting', 'easy-to-maintain', 'youthful-style'],
            ],
            [
                'slug' => 'toddler-fresh-short-hair',
                'name' => '幼儿清爽短发',
                'name_en' => 'Toddler Fresh Short Hair',
                'category_slug' => 'toddler-hairstyles',
                'gender' => HairstyleGender::Child->value,
                'age_range' => HairstyleAgeRange::Child->value,
                'style_type' => HairstyleStyleType::Casual->value,
                'hair_length' => HairstyleHairLength::Short->value,
                'hair_type' => HairstyleHairType::Straight->value,
                'hair_volume' => HairstyleHairVolume::Low->value,
                'face_shape' => [FaceShape::Round->value, FaceShape::Oval->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Easy->value,
                'suitable_scene' => [SuitableScene::Daily->value],
                'description' => '幼儿发质通常又细又软，这款短发不需要复杂打理，简单修剪出整齐轮廓即可，减少宝宝抗拒剪发和日常梳理的负担。',
                'ai_prompt' => 'toddler\'s short simple haircut, neat trimmed outline, fine soft hair texture, low volume, gentle rounded shape suitable for a young child',
                'ai_negative_prompt' => $negative,
                'seo_title' => '幼儿清爽短发发型效果图 | 细软发也好打理 - HairHub',
                'seo_description' => '幼儿清爽短发简单好打理，特别适合细软发的宝宝，圆脸、鹅蛋脸都合适。用 HairHub AI换发型工具提前看看宝宝剪短发的效果。',
                'is_recommended' => false,
                'tags' => ['easy-to-maintain', 'low-maintenance', 'suitable-fine-hair', 'cute-style', 'natural-style', 'face-slimming'],
            ],
            [
                'slug' => 'student-natural-short-hair',
                'name' => '学生自然短发',
                'name_en' => 'Student Natural Short Hair',
                'category_slug' => 'student-hairstyles',
                'gender' => HairstyleGender::Child->value,
                'age_range' => HairstyleAgeRange::Teenager->value,
                'style_type' => HairstyleStyleType::Casual->value,
                'hair_length' => HairstyleHairLength::Short->value,
                'hair_type' => HairstyleHairType::Straight->value,
                'hair_volume' => HairstyleHairVolume::Medium->value,
                'face_shape' => [FaceShape::Round->value, FaceShape::Square->value],
                'maintenance_level' => HairstyleMaintenanceLevel::Easy->value,
                'suitable_scene' => [SuitableScene::School->value, SuitableScene::Daily->value],
                'description' => '学生自然短发线条干净利落，符合大多数学校的发型规范，打理简单不影响日常学习和运动，是青少年阶段最实用的发型选择。',
                'ai_prompt' => 'teenage student\'s natural short haircut, clean simple silhouette, neatly trimmed sides, tidy natural texture, medium volume, school-appropriate finish',
                'ai_negative_prompt' => $negative,
                'seo_title' => '学生自然短发发型效果图 | 校规友好显精神 - HairHub',
                'seo_description' => '学生自然短发符合校规又好打理，适合圆脸、方脸的青少年。开学前先用 HairHub AI换发型工具预览短发效果，理发更有把握。',
                'is_recommended' => false,
                'tags' => ['natural-style', 'easy-to-maintain', 'school-scene', 'face-slimming', 'fresh-style', 'low-maintenance'],
            ],
        ];
    }
}
