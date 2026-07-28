<?php

namespace Database\Seeders;

use App\Models\ArticleCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ArticleCategorySeeder extends Seeder
{
    /**
     * 内容中心标准文章分类。
     *
     * 说明：
     * 1. 只支持两级分类；
     * 2. slug 作为稳定标识；
     * 3. 已存在分类不会覆盖 status / sort / cover_media_id；
     * 4. 不删除任何已有分类；
     * 5. 视频专区使用 videos 模块，不进入 article_categories。
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => '男生发型',
                'name_en' => 'Men Hairstyles',
                'slug' => 'men-hairstyles',
                'description' => '男生发型设计、脸型搭配、烫染、穿搭、日常造型及头发护理内容。',
                'sort' => 400,
                'children' => [
                    [
                        'name' => '男生脸型与发型',
                        'name_en' => 'Men Face Shape Hairstyles',
                        'slug' => 'men-face-shape-hairstyles',
                        'description' => '圆脸、长脸、方脸、国字脸、菱形脸等不同脸型男生的发型搭配建议。',
                        'sort' => 390,
                    ],
                    [
                        'name' => '男生发型推荐',
                        'name_en' => 'Men Hairstyle Recommendations',
                        'slug' => 'men-hairstyle-recommendations',
                        'description' => '男生热门发型、发型排行榜、不同年龄与发质的发型推荐。',
                        'sort' => 380,
                    ],
                    [
                        'name' => '男生烫发',
                        'name_en' => 'Men Perms',
                        'slug' => 'men-perms',
                        'description' => '纹理烫、锡纸烫、摩根烫、气垫烫等男生烫发内容。',
                        'sort' => 370,
                    ],
                    [
                        'name' => '男生染发',
                        'name_en' => 'Men Hair Color',
                        'slug' => 'men-hair-color',
                        'description' => '男生染发、显白发色、不漂发色以及不同肤色发色推荐。',
                        'sort' => 360,
                    ],
                    [
                        'name' => '男生穿搭与发型',
                        'name_en' => 'Men Style Matching',
                        'slug' => 'men-style-matching',
                        'description' => '男生发型与服装、眼镜、胡须、肤色及不同风格穿搭的搭配建议。',
                        'sort' => 350,
                    ],
                    [
                        'name' => '男生头发打理与护理',
                        'name_en' => 'Men Hair Care',
                        'slug' => 'men-hair-care',
                        'description' => '男生吹发、蓬松、定型、控油、去屑及日常头发护理技巧。',
                        'sort' => 340,
                    ],
                ],
            ],

            [
                'name' => '女生发型',
                'name_en' => 'Women Hairstyles',
                'slug' => 'women-hairstyles',
                'description' => '女生剪发、脸型搭配、烫发、染发、穿搭及头发护理内容。',
                'sort' => 300,
                'children' => [
                    [
                        'name' => '女生脸型与发型',
                        'name_en' => 'Women Face Shape Hairstyles',
                        'slug' => 'women-face-shape-hairstyles',
                        'description' => '圆脸、长脸、方脸、菱形脸、鹅蛋脸等女生脸型的发型搭配建议。',
                        'sort' => 290,
                    ],
                    [
                        'name' => '女生发型推荐',
                        'name_en' => 'Women Hairstyle Recommendations',
                        'slug' => 'women-hairstyle-recommendations',
                        'description' => '女生短发、中长发、长发、减龄发型、显脸小发型和热门发型推荐。',
                        'sort' => 280,
                    ],
                    [
                        'name' => '女生烫发',
                        'name_en' => 'Women Perms',
                        'slug' => 'women-perms',
                        'description' => '法式卷、羊毛卷、蛋卷头、大波浪等女生烫发和卷发内容。',
                        'sort' => 270,
                    ],
                    [
                        'name' => '女生染发',
                        'name_en' => 'Women Hair Color',
                        'slug' => 'women-hair-color',
                        'description' => '女生染发、肤色与发色搭配、流行发色和染后护理内容。',
                        'sort' => 260,
                    ],
                    [
                        'name' => '女生穿搭与发型',
                        'name_en' => 'Women Style Matching',
                        'slug' => 'women-style-matching',
                        'description' => '女生发型与裙装、职业装、休闲穿搭、妆容和不同场景的搭配内容。',
                        'sort' => 250,
                    ],
                    [
                        'name' => '女生头发打理与护理',
                        'name_en' => 'Women Hair Care',
                        'slug' => 'women-hair-care',
                        'description' => '女生洗护、染烫护理、卷发打理、刘海打理、毛躁与干枯护理内容。',
                        'sort' => 240,
                    ],
                ],
            ],

            [
                'name' => '儿童发型',
                'name_en' => 'Kids Hairstyles',
                'slug' => 'kids-hairstyles',
                'description' => '男孩、女孩、学生发型以及儿童头发护理和亲子发型内容。',
                'sort' => 200,
                'children' => [
                    [
                        'name' => '男孩发型',
                        'name_en' => 'Boys Hairstyles',
                        'slug' => 'boys-hairstyles',
                        'description' => '幼儿及儿童男孩短发、清爽发型和热门男童发型推荐。',
                        'sort' => 190,
                    ],
                    [
                        'name' => '女孩发型',
                        'name_en' => 'Girls Hairstyles',
                        'slug' => 'girls-hairstyles',
                        'description' => '儿童女孩短发、长发、扎发以及可爱发型推荐。',
                        'sort' => 180,
                    ],
                    [
                        'name' => '学生发型',
                        'name_en' => 'Student Hairstyles',
                        'slug' => 'student-hairstyles',
                        'description' => '小学生、初中生、高中生以及开学季适合的学生发型。',
                        'sort' => 170,
                    ],
                    [
                        'name' => '儿童头发护理',
                        'name_en' => 'Kids Hair Care',
                        'slug' => 'kids-hair-care',
                        'description' => '儿童头发毛躁、洗护、梳理及日常头发护理知识。',
                        'sort' => 160,
                    ],
                    [
                        'name' => '亲子发型与热门趋势',
                        'name_en' => 'Family Hairstyles',
                        'slug' => 'family-hairstyles',
                        'description' => '亲子发型、兄妹发型、节日发型和儿童热门发型趋势。',
                        'sort' => 150,
                    ],
                ],
            ],

            [
                'name' => 'AI发型',
                'name_en' => 'AI Hairstyles',
                'slug' => 'ai-hairstyles',
                'description' => 'AI换发型、AI试发型、AI换发色以及趣味发型体验内容。',
                'sort' => 100,
                'children' => [
                    [
                        'name' => 'AI试发型',
                        'name_en' => 'AI Hairstyle Try On',
                        'slug' => 'ai-hairstyle-try-on',
                        'description' => '上传照片在线试发型、剪发前预览和AI发型推荐相关内容。',
                        'sort' => 90,
                    ],
                    [
                        'name' => 'AI换发色',
                        'name_en' => 'AI Hair Color Try On',
                        'slug' => 'ai-hair-color-try-on',
                        'description' => 'AI在线换发色、染发前试色和不同发色效果预览内容。',
                        'sort' => 80,
                    ],
                    [
                        'name' => '趣味换发型',
                        'name_en' => 'Fun Hairstyle Try On',
                        'slug' => 'fun-hairstyle-try-on',
                        'description' => '明星同款、复古、长短发变化等趣味AI换发型内容。',
                        'sort' => 70,
                    ],
                    [
                        'name' => 'AI发型指南',
                        'name_en' => 'AI Hairstyle Guides',
                        'slug' => 'ai-hairstyle-guides',
                        'description' => 'AI换发型工具使用技巧、照片拍摄要求以及效果选择指南。',
                        'sort' => 60,
                    ],
                ],
            ],
        ];

        DB::transaction(function () use ($categories): void {
            foreach ($categories as $parentData) {
                $parent = $this->saveCategory(
                    data: $parentData,
                    parentId: 0
                );

                foreach ($parentData['children'] as $childData) {
                    $this->saveCategory(
                        data: $childData,
                        parentId: $parent->id
                    );
                }
            }
        });
    }

    private function saveCategory(array $data, int $parentId): ArticleCategory
    {
        // withTrashed 防止已有同 slug 的软删除分类导致 UNIQUE KEY 冲突。
        $category = ArticleCategory::withTrashed()
            ->firstOrNew([
                'slug' => $data['slug'],
            ]);

        $isNew = ! $category->exists;

        // 属于分类结构信息，可以随 Seeder 更新。
        $category->parent_id = $parentId;
        $category->name = $data['name'];
        $category->name_en = $data['name_en'];
        $category->description = $data['description'];

        // 已有分类保留运营人员修改过的状态、排序和封面。
        if ($isNew) {
            $category->status = 1;
            $category->sort = $data['sort'];
            $category->cover_media_id = 0;
        }

        $category->save();

        return $category;
    }
}
