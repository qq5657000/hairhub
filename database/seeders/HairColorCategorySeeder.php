<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Common\CommonStatus;
use App\Models\HairColorCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 发色分类初始化 Seeder。
 *
 * V1.0 发色分类为两级结构（对应 doc/v1.0/database/02-发色模块.md）：
 *
 * ```text
 * 自然色系
 * ├── 黑色系
 * ├── 棕色系
 * └── 亚麻色系
 *
 * 浅色系
 * ├── 金色系
 * ├── 灰色系
 * └── 白金色系
 *
 * 个性色系
 * ├── 红色系
 * ├── 紫色系
 * ├── 蓝色系
 * ├── 粉色系
 * └── 绿色系
 * ```
 *
 * 共 3 个一级分类、11 个二级分类，合计 14 条。
 *
 * 幂等性保证（只补充缺失数据，不覆盖后台运营数据）：
 * - 统一使用 `firstOrCreate(['slug' => ...], [...])`：分类始终通过 slug（unique 索引）
 *   定位，若已存在（含已软删除的记录）则直接返回现有记录，不会覆盖其 name/name_en/
 *   description/status/sort/parent_id/cover_media_id 等任何字段——即使这些字段是
 *   本次修复前错误写入的值，也只能在“尚未正式运行过 Seeder”的当前阶段通过调整下面
 *   的定义数据来源头修正，Seeder 逻辑本身不会在重复执行时替用户覆盖运营数据；
 * - 使用 `withTrashed()` 定位：分类支持软删除，若目标 slug 对应记录已被软删除，
 *   `firstOrCreate` 必须能查到该记录并直接返回，不能因为看不到而重新创建触发
 *   slug 唯一索引冲突；
 * - 二级分类的 parent_id 取自对应一级分类 firstOrCreate() 返回的真实自增 ID，
 *   不硬编码任何主键；
 * - 不使用 truncate/delete，重复执行不会产生重复分类。
 */
class HairColorCategorySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $tree = $this->categoryTree();
            $topCount = count($tree);

            foreach ($tree as $topPosition => $top) {
                $parent = $this->firstOrCreateCategory($top, 0, ($topCount - $topPosition) * 100);

                $children = $top['children'];
                $childCount = count($children);

                foreach ($children as $childPosition => $child) {
                    $this->firstOrCreateCategory($child, $parent->id, ($childCount - $childPosition) * 10);
                }
            }
        });
    }

    /**
     * @param  array{slug: string, name: string, name_en: string, description: string}  $definition
     */
    private function firstOrCreateCategory(array $definition, int $parentId, int $sort): HairColorCategory
    {
        /** @var HairColorCategory $category */
        $category = HairColorCategory::withTrashed()->firstOrCreate(
            ['slug' => $definition['slug']],
            [
                'parent_id' => $parentId,
                'name' => $definition['name'],
                'name_en' => $definition['name_en'],
                'description' => $definition['description'],
                'cover_media_id' => 0,
                'status' => CommonStatus::Enabled->value,
                'sort' => $sort,
            ]
        );

        return $category;
    }

    /**
     * 分类树定义：每个一级分类携带 children（二级分类）。
     *
     * @return array<int, array{slug: string, name: string, name_en: string, description: string, children: array<int, array{slug: string, name: string, name_en: string, description: string}>}>
     */
    private function categoryTree(): array
    {
        return [
            [
                'slug' => 'natural-tones',
                'name' => '自然色系',
                'name_en' => 'Natural Tones',
                'description' => '贴近亚洲人原生发色的自然色系，低调不挑人，适合日常和职场场景。',
                'children' => [
                    [
                        'slug' => 'black-tones',
                        'name' => '黑色系',
                        'name_en' => 'Black Tones',
                        'description' => '经典黑色系发色，稳重耐看，是最不容易出错的基础发色选择。',
                    ],
                    [
                        'slug' => 'brown-tones',
                        'name' => '棕色系',
                        'name_en' => 'Brown Tones',
                        'description' => '最主流的棕色系发色，冷暖层次丰富，适合大多数肤色和场合。',
                    ],
                    [
                        'slug' => 'linen-tones',
                        'name' => '亚麻色系',
                        'name_en' => 'Linen Tones',
                        'description' => '带灰调的亚麻色系，低饱和度、显白显气质，是近年最受欢迎的染发色系之一。',
                    ],
                ],
            ],
            [
                'slug' => 'light-tones',
                'name' => '浅色系',
                'name_en' => 'Light Tones',
                'description' => '偏浅偏亮的发色合集，视觉提亮气色，适合想要低调改变发色的用户。',
                'children' => [
                    [
                        'slug' => 'gold-tones',
                        'name' => '金色系',
                        'name_en' => 'Gold Tones',
                        'description' => '偏暖调的金色系发色，视觉华丽有层次，通常需要一定程度的漂发才能呈现理想效果。',
                    ],
                    [
                        'slug' => 'grey-tones',
                        'name' => '灰色系',
                        'name_en' => 'Grey Tones',
                        'description' => '高级冷调的灰色系发色，个性鲜明，对漂发程度和后期维护要求较高。',
                    ],
                    [
                        'slug' => 'platinum-tones',
                        'name' => '白金色系',
                        'name_en' => 'Platinum Tones',
                        'description' => '接近漂白效果的白金色系发色，视觉冲击力最强，日常维护成本也最高。',
                    ],
                ],
            ],
            [
                'slug' => 'personality-tones',
                'name' => '个性色系',
                'name_en' => 'Personality Tones',
                'description' => '饱和度更高、视觉冲击力更强的个性发色，适合追求风格和拍照效果的用户。',
                'children' => [
                    [
                        'slug' => 'red-tones',
                        'name' => '红色系',
                        'name_en' => 'Red Tones',
                        'description' => '热情高调的红色系发色，显气色，适合喜欢鲜明风格的用户。',
                    ],
                    [
                        'slug' => 'purple-tones',
                        'name' => '紫色系',
                        'name_en' => 'Purple Tones',
                        'description' => '神秘个性的紫色系发色，饱和度和明暗层次多样，适合追求独特风格的用户。',
                    ],
                    [
                        'slug' => 'blue-tones',
                        'name' => '蓝色系',
                        'name_en' => 'Blue Tones',
                        'description' => '清冷通透的蓝色系发色，视觉高级且辨识度高，适合喜欢冷调风格的用户。',
                    ],
                    [
                        'slug' => 'pink-tones',
                        'name' => '粉色系',
                        'name_en' => 'Pink Tones',
                        'description' => '甜美少女感的粉色系发色，适合想要尝试可爱风格或活动造型的用户。',
                    ],
                    [
                        'slug' => 'green-tones',
                        'name' => '绿色系',
                        'name_en' => 'Green Tones',
                        'description' => '小众前卫的绿色系发色，辨识度极高，适合追求强烈个人风格的用户。',
                    ],
                ],
            ],
        ];
    }
}
