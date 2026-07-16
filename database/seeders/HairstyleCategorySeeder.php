<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Hairstyle\HairstyleCategoryStatus;
use App\Models\HairstyleCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 发型分类初始化 Seeder。
 *
 * 幂等性保证：
 * - 分类始终通过 slug 定位（unique 索引），存在则更新基础资料，不存在则创建；
 * - 父分类通过父分类 slug 在内存映射中查找实际 ID，不依赖固定数据库 ID；
 * - cover_media_id 只在“首次创建”时初始化为 0，已存在的分类（可能已在后台
 *   上传/选择过封面）重复执行时不会被重置，避免抹掉后台人工新增的封面数据；
 * - 不使用 truncate/delete，重复执行不会产生重复分类，也不会影响后台人工新增的子分类。
 */
class HairstyleCategorySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $flat = $this->flattenTree($this->categoryTree());
            $total = count($flat);
            $slugToId = [];

            foreach ($flat as $position => $node) {
                $parentId = $node['parent_slug'] !== null ? $slugToId[$node['parent_slug']] : 0;
                $sort = ($total - $position) * 10;

                $slugToId[$node['slug']] = $this->upsertCategory($node, $parentId, $sort);
            }
        });
    }

    /**
     * 把分类树展开成“父分类紧跟其子分类”的有序扁平列表，
     * 保证遍历时父分类始终先于子分类被处理，子分类可以直接从
     * 内存映射中查到父分类刚写入后的真实 ID。
     *
     * @param  array<int, array<string, mixed>>  $tree
     * @return array<int, array{slug: string, parent_slug: string|null, name: string, name_en: string, description: string}>
     */
    private function flattenTree(array $tree, ?string $parentSlug = null): array
    {
        $flat = [];

        foreach ($tree as $node) {
            $flat[] = [
                'slug' => $node['slug'],
                'parent_slug' => $parentSlug,
                'name' => $node['name'],
                'name_en' => $node['name_en'],
                'description' => $node['description'],
            ];

            if (! empty($node['children'])) {
                $flat = array_merge($flat, $this->flattenTree($node['children'], $node['slug']));
            }
        }

        return $flat;
    }

    /**
     * 按 slug 定位并写入一条分类记录，返回其真实数据库 ID。
     *
     * @param  array{slug: string, name: string, name_en: string, description: string}  $definition
     */
    private function upsertCategory(array $definition, int $parentId, int $sort): int
    {
        // 用 withTrashed() 定位：分类支持软删除，若后台曾软删除过同 slug 的记录，
        // 普通 query() 会因看不到该记录而尝试重新 create()，导致触发 slug 唯一索引冲突。
        // 这里只更新基础字段，不触碰 deleted_at，不会“复活”被软删除的分类。
        /** @var HairstyleCategory|null $category */
        $category = HairstyleCategory::withTrashed()->where('slug', $definition['slug'])->first();

        $attributes = [
            'parent_id' => $parentId,
            'name' => $definition['name'],
            'name_en' => $definition['name_en'],
            'description' => $definition['description'],
            'status' => HairstyleCategoryStatus::Enabled->value,
            'sort' => $sort,
        ];

        if ($category) {
            $category->fill($attributes);
            $category->save();

            return (int) $category->id;
        }

        $attributes['slug'] = $definition['slug'];
        // 新建分类时才初始化封面为 0（“暂无封面”），已存在的分类可能已经在后台
        // 上传或选择过封面，重复执行 Seeder 不应把它重置回 0。
        $attributes['cover_media_id'] = 0;

        $category = HairstyleCategory::create($attributes);

        return (int) $category->id;
    }

    /**
     * 分类树定义：女士 / 男士 / 儿童三大顶级分类及其子分类，
     * 数组顺序即目标展示顺序（sort 会按此顺序整体倒序生成）。
     *
     * @return array<int, array{slug: string, name: string, name_en: string, description: string, children: array<int, array{slug: string, name: string, name_en: string, description: string}>}>
     */
    private function categoryTree(): array
    {
        return [
            [
                'slug' => 'women-hairstyles',
                'name' => '女士发型',
                'name_en' => 'Women\'s Hairstyles',
                'description' => '收录女士短发、中短发、锁骨发、长发、卷发及刘海发型，帮助你根据脸型、年龄和风格找到最适合自己的发型。',
                'children' => [
                    [
                        'slug' => 'women-short-hairstyles',
                        'name' => '女士短发',
                        'name_en' => 'Women\'s Short Hairstyles',
                        'description' => '干净利落的女士短发合集，显脸小、打理简单，适合日常通勤和职场场景。',
                    ],
                    [
                        'slug' => 'women-medium-short-hairstyles',
                        'name' => '女士中短发',
                        'name_en' => 'Women\'s Medium-short Hairstyles',
                        'description' => '介于短发和长发之间的中短发型，兼顾清爽和柔美气质，适合大多数脸型。',
                    ],
                    [
                        'slug' => 'women-clavicle-hairstyles',
                        'name' => '女士锁骨发',
                        'name_en' => 'Women\'s Clavicle-length Hairstyles',
                        'description' => '长度刚到锁骨的人气发型，知性又不失轻熟感，是通勤和约会都适合的万能长度。',
                    ],
                    [
                        'slug' => 'women-long-hairstyles',
                        'name' => '女士长发',
                        'name_en' => 'Women\'s Long Hairstyles',
                        'description' => '黑长直、刘海长发等经典长发造型，展现优雅气质，适合正式场合和日常搭配。',
                    ],
                    [
                        'slug' => 'women-curly-hairstyles',
                        'name' => '女士卷发',
                        'name_en' => 'Women\'s Curly Hairstyles',
                        'description' => '大波浪、羊毛卷、蛋卷烫等卷发造型，视觉显发量，适合想要增加发型层次感的你。',
                    ],
                    [
                        'slug' => 'women-bangs-hairstyles',
                        'name' => '女士刘海发型',
                        'name_en' => 'Women\'s Bangs Hairstyles',
                        'description' => '空气刘海、法式刘海、八字刘海等刘海造型合集，重点修饰额头和脸型比例。',
                    ],
                ],
            ],
            [
                'slug' => 'men-hairstyles',
                'name' => '男士发型',
                'name_en' => 'Men\'s Hairstyles',
                'description' => '收录男士短发、烫发、商务发型和潮流发型，方便根据脸型、发量、职业和日常场景挑选合适造型。',
                'children' => [
                    [
                        'slug' => 'men-short-hairstyles',
                        'name' => '男士短发',
                        'name_en' => 'Men\'s Short Hairstyles',
                        'description' => '清爽干净的男士短发合集，打理简单，是日常和职场都不会出错的基础造型。',
                    ],
                    [
                        'slug' => 'men-medium-hairstyles',
                        'name' => '男士中长发',
                        'name_en' => 'Men\'s Medium-length Hairstyles',
                        'description' => '长度适中、更有个性的男士发型，适合想要跳出常规短发的造型尝试。',
                    ],
                    [
                        'slug' => 'men-perm-hairstyles',
                        'name' => '男士烫发',
                        'name_en' => 'Men\'s Perm Hairstyles',
                        'description' => '纹理烫、摩根烫、锡纸烫等男士烫发造型，视觉增加发量和层次感。',
                    ],
                    [
                        'slug' => 'men-business-hairstyles',
                        'name' => '男士商务发型',
                        'name_en' => 'Men\'s Business Hairstyles',
                        'description' => '背头、三七分等成熟稳重的商务造型，适合职场和正式场合。',
                    ],
                    [
                        'slug' => 'men-trendy-hairstyles',
                        'name' => '男士潮流发型',
                        'name_en' => 'Men\'s Trendy Hairstyles',
                        'description' => '飞机头、铲青等潮流个性造型，适合喜欢突出风格和拍照效果的用户。',
                    ],
                    [
                        'slug' => 'men-volume-hairstyles',
                        'name' => '男士显发量发型',
                        'name_en' => 'Men\'s Volumizing Hairstyles',
                        'description' => '专为发量偏少的男士设计的蓬松显发量造型，兼顾自然和打理便利。',
                    ],
                ],
            ],
            [
                'slug' => 'kids-hairstyles',
                'name' => '儿童发型',
                'name_en' => 'Kids\' Hairstyles',
                'description' => '收录男童、女童和幼儿发型，重点兼顾清爽、可爱、容易打理以及校园生活需求。',
                'children' => [
                    [
                        'slug' => 'boys-hairstyles',
                        'name' => '男童发型',
                        'name_en' => 'Boys\' Hairstyles',
                        'description' => '清爽自然的男童发型合集，好打理不易乱，适合日常和校园生活。',
                    ],
                    [
                        'slug' => 'girls-hairstyles',
                        'name' => '女童发型',
                        'name_en' => 'Girls\' Hairstyles',
                        'description' => '可爱减龄的女童发型合集，兼顾颜值和日常打理的便利程度。',
                    ],
                    [
                        'slug' => 'toddler-hairstyles',
                        'name' => '幼儿发型',
                        'name_en' => 'Toddler Hairstyles',
                        'description' => '专为幼儿设计的清爽简单发型，减少打理负担，突出宝宝的自然可爱。',
                    ],
                    [
                        'slug' => 'student-hairstyles',
                        'name' => '学生发型',
                        'name_en' => 'Student Hairstyles',
                        'description' => '符合校园形象规范的学生发型，自然干净，兼顾日常活动的便利性。',
                    ],
                    [
                        'slug' => 'kids-short-hairstyles',
                        'name' => '儿童清爽短发',
                        'name_en' => 'Kids\' Short Hairstyles',
                        'description' => '夏季和运动场景都适合的儿童清爽短发，打理省心，出汗也不闷热。',
                    ],
                    [
                        'slug' => 'kids-long-hairstyles',
                        'name' => '儿童长发造型',
                        'name_en' => 'Kids\' Long Hairstyles',
                        'description' => '公主风、长发造型合集，适合喜欢留长发、注重拍照效果的孩子。',
                    ],
                ],
            ],
        ];
    }
}
