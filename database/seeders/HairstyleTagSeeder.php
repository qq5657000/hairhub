<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Hairstyle\HairstyleTagStatus;
use App\Enums\Hairstyle\HairstyleTagType;
use App\Models\HairstyleTag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 发型标签初始化 Seeder。
 *
 * 按脸型 / 风格 / 场景 / 功效 / 打理与适配 / 刘海与工艺六个标签组初始化，
 * 对应项目已有的 App\Enums\Hairstyle\HairstyleTagType 全部 6 个枚举值，
 * 刻意避免所有标签使用同一个 type。
 *
 * 幂等性保证：
 * - 标签始终通过 slug 定位（unique 索引），存在则更新基础资料，不存在则创建；
 * - 不使用 truncate/delete，重复执行不会产生重复标签；
 * - 本 Seeder 不维护发型与标签的关联关系（由 HairstyleSeeder 负责），
 *   因此重复执行也不会影响后台人工创建的标签关联。
 */
class HairstyleTagSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ($this->tagGroups() as $group) {
                $tags = $group['tags'];
                $total = count($tags);

                foreach ($tags as $index => $tag) {
                    $this->upsertTag($tag, $group['type'], sort: ($total - $index) * 10);
                }
            }
        });
    }

    /**
     * @param  array{name: string, name_en: string, slug: string}  $definition
     */
    private function upsertTag(array $definition, HairstyleTagType $type, int $sort): void
    {
        HairstyleTag::query()->updateOrCreate(
            ['slug' => $definition['slug']],
            [
                'name' => $definition['name'],
                'name_en' => $definition['name_en'],
                'type' => $type->value,
                'status' => HairstyleTagStatus::Enabled->value,
                'sort' => $sort,
            ]
        );
    }

    /**
     * 标签组定义，数组内顺序即组内重要程度顺序（sort 按此顺序整体倒序生成）。
     *
     * @return array<int, array{type: HairstyleTagType, tags: array<int, array{name: string, name_en: string, slug: string}>}>
     */
    private function tagGroups(): array
    {
        return [
            // 脸型标签
            [
                'type' => HairstyleTagType::FaceShape,
                'tags' => [
                    ['name' => '圆脸', 'name_en' => 'Round Face', 'slug' => 'round-face'],
                    ['name' => '方脸', 'name_en' => 'Square Face', 'slug' => 'square-face'],
                    ['name' => '长脸', 'name_en' => 'Long Face', 'slug' => 'long-face'],
                    ['name' => '鹅蛋脸', 'name_en' => 'Oval Face', 'slug' => 'oval-face'],
                    ['name' => '菱形脸', 'name_en' => 'Diamond Face', 'slug' => 'diamond-face'],
                    ['name' => '心形脸', 'name_en' => 'Heart-shaped Face', 'slug' => 'heart-face'],
                    ['name' => '瓜子脸', 'name_en' => 'Melon-seed Face', 'slug' => 'melon-seed-face'],
                    ['name' => '大脸', 'name_en' => 'Large Face', 'slug' => 'large-face'],
                    ['name' => '小脸', 'name_en' => 'Small Face', 'slug' => 'small-face'],
                    ['name' => '高颧骨', 'name_en' => 'High Cheekbones', 'slug' => 'high-cheekbones'],
                    ['name' => '宽额头', 'name_en' => 'Wide Forehead', 'slug' => 'wide-forehead'],
                    ['name' => '窄额头', 'name_en' => 'Narrow Forehead', 'slug' => 'narrow-forehead'],
                ],
            ],
            // 风格标签
            [
                'type' => HairstyleTagType::Style,
                'tags' => [
                    ['name' => '清爽', 'name_en' => 'Fresh', 'slug' => 'fresh-style'],
                    ['name' => '自然', 'name_en' => 'Natural', 'slug' => 'natural-style'],
                    ['name' => '减龄', 'name_en' => 'Youthful', 'slug' => 'youthful-style'],
                    ['name' => '甜美', 'name_en' => 'Sweet', 'slug' => 'sweet-style'],
                    ['name' => '温柔', 'name_en' => 'Gentle', 'slug' => 'gentle-style'],
                    ['name' => '优雅', 'name_en' => 'Elegant', 'slug' => 'elegant-style'],
                    ['name' => '知性', 'name_en' => 'Intellectual', 'slug' => 'intellectual-style'],
                    ['name' => '轻熟', 'name_en' => 'Mature Chic', 'slug' => 'mature-chic-style'],
                    ['name' => '成熟稳重', 'name_en' => 'Mature & Steady', 'slug' => 'steady-style'],
                    ['name' => '商务', 'name_en' => 'Business', 'slug' => 'business-style'],
                    ['name' => '韩系', 'name_en' => 'Korean Style', 'slug' => 'korean-style'],
                    ['name' => '日系', 'name_en' => 'Japanese Style', 'slug' => 'japanese-style'],
                    ['name' => '法式', 'name_en' => 'French Style', 'slug' => 'french-style'],
                    ['name' => '复古', 'name_en' => 'Retro', 'slug' => 'retro-style'],
                    ['name' => '潮流', 'name_en' => 'Trendy', 'slug' => 'trendy-style'],
                    ['name' => '个性', 'name_en' => 'Distinctive', 'slug' => 'distinctive-style'],
                    ['name' => '酷帅', 'name_en' => 'Cool & Sharp', 'slug' => 'cool-style'],
                    ['name' => '文艺', 'name_en' => 'Artistic', 'slug' => 'artistic-style'],
                    ['name' => '校园', 'name_en' => 'Campus Style', 'slug' => 'campus-style'],
                    ['name' => '可爱', 'name_en' => 'Cute', 'slug' => 'cute-style'],
                ],
            ],
            // 场景标签
            [
                'type' => HairstyleTagType::Scene,
                'tags' => [
                    ['name' => '日常通勤', 'name_en' => 'Daily Commute', 'slug' => 'daily-commute'],
                    ['name' => '职场办公', 'name_en' => 'Office Work', 'slug' => 'office-work'],
                    ['name' => '约会', 'name_en' => 'Date', 'slug' => 'date-scene'],
                    ['name' => '婚礼', 'name_en' => 'Wedding', 'slug' => 'wedding-scene'],
                    ['name' => '校园', 'name_en' => 'School', 'slug' => 'school-scene'],
                    ['name' => '旅行', 'name_en' => 'Travel', 'slug' => 'travel-scene'],
                    ['name' => '拍照', 'name_en' => 'Photography', 'slug' => 'photography-scene'],
                    ['name' => '正式场合', 'name_en' => 'Formal Occasion', 'slug' => 'formal-occasion'],
                    ['name' => '休闲居家', 'name_en' => 'Casual at Home', 'slug' => 'casual-home-scene'],
                    ['name' => '夏季清爽', 'name_en' => 'Summer Fresh', 'slug' => 'summer-fresh-scene'],
                ],
            ],
            // 发型特点标签（功效类）
            [
                'type' => HairstyleTagType::Effect,
                'tags' => [
                    ['name' => '显脸小', 'name_en' => 'Face-slimming', 'slug' => 'face-slimming'],
                    ['name' => '显发量', 'name_en' => 'Volume-boosting', 'slug' => 'volume-boosting'],
                    ['name' => '显年轻', 'name_en' => 'Age-reducing', 'slug' => 'age-reducing'],
                    ['name' => '修饰额头', 'name_en' => 'Forehead-flattering', 'slug' => 'forehead-flattering'],
                    ['name' => '修饰颧骨', 'name_en' => 'Cheekbone-flattering', 'slug' => 'cheekbone-flattering'],
                    ['name' => '修饰下颌', 'name_en' => 'Jawline-flattering', 'slug' => 'jawline-flattering'],
                    ['name' => '拉长脸型', 'name_en' => 'Face-lengthening', 'slug' => 'face-lengthening'],
                    ['name' => '缩短脸型', 'name_en' => 'Face-shortening', 'slug' => 'face-shortening'],
                    ['name' => '头顶蓬松', 'name_en' => 'Crown Volume', 'slug' => 'crown-volume'],
                    ['name' => '两侧服帖', 'name_en' => 'Sleek Sides', 'slug' => 'sleek-sides'],
                ],
            ],
            // 发型特点标签（打理与适配类）
            [
                'type' => HairstyleTagType::Maintenance,
                'tags' => [
                    ['name' => '无需烫发', 'name_en' => 'No Perm Needed', 'slug' => 'no-perm-needed'],
                    ['name' => '需要烫发', 'name_en' => 'Perm Required', 'slug' => 'perm-required'],
                    ['name' => '容易打理', 'name_en' => 'Easy to Maintain', 'slug' => 'easy-to-maintain'],
                    ['name' => '低维护', 'name_en' => 'Low Maintenance', 'slug' => 'low-maintenance'],
                    ['name' => '适合发量少', 'name_en' => 'Suitable for Thin Hair', 'slug' => 'suitable-thin-hair'],
                    ['name' => '适合发量多', 'name_en' => 'Suitable for Thick Hair', 'slug' => 'suitable-thick-hair'],
                    ['name' => '适合细软发', 'name_en' => 'Suitable for Fine Hair', 'slug' => 'suitable-fine-hair'],
                    ['name' => '适合粗硬发', 'name_en' => 'Suitable for Coarse Hair', 'slug' => 'suitable-coarse-hair'],
                    ['name' => '适合自然卷', 'name_en' => 'Suitable for Natural Curl', 'slug' => 'suitable-natural-curl'],
                    ['name' => '适合戴眼镜', 'name_en' => 'Suitable for Glasses Wearers', 'slug' => 'suitable-glasses'],
                ],
            ],
            // 刘海与工艺标签
            [
                'type' => HairstyleTagType::General,
                'tags' => [
                    ['name' => '无刘海', 'name_en' => 'No Bangs', 'slug' => 'no-bangs'],
                    ['name' => '空气刘海', 'name_en' => 'Air Bangs', 'slug' => 'air-bangs'],
                    ['name' => '八字刘海', 'name_en' => 'Figure-8 Bangs', 'slug' => 'figure-8-bangs'],
                    ['name' => '法式刘海', 'name_en' => 'French Bangs', 'slug' => 'french-bangs'],
                    ['name' => '齐刘海', 'name_en' => 'Blunt Bangs', 'slug' => 'blunt-bangs'],
                    ['name' => '侧分刘海', 'name_en' => 'Side-swept Bangs', 'slug' => 'side-swept-bangs'],
                    ['name' => '中分', 'name_en' => 'Middle Part', 'slug' => 'middle-part'],
                    ['name' => '三七分', 'name_en' => '3:7 Side Part', 'slug' => 'side-part-37'],
                    ['name' => '纹理烫', 'name_en' => 'Texture Perm', 'slug' => 'texture-perm'],
                    ['name' => '摩根烫', 'name_en' => 'Morgan Perm', 'slug' => 'morgan-perm'],
                    ['name' => '锡纸烫', 'name_en' => 'Foil Perm', 'slug' => 'foil-perm'],
                    ['name' => '羊毛卷', 'name_en' => 'Wool Curl Perm', 'slug' => 'wool-curl-perm'],
                    ['name' => '蛋卷烫', 'name_en' => 'Egg Roll Perm', 'slug' => 'egg-roll-perm'],
                    ['name' => '大波浪', 'name_en' => 'Big Waves', 'slug' => 'big-waves'],
                    ['name' => '层次剪', 'name_en' => 'Layered Cut', 'slug' => 'layered-cut'],
                    ['name' => '两侧铲青', 'name_en' => 'Shaved Sides', 'slug' => 'shaved-sides'],
                ],
            ],
        ];
    }
}
