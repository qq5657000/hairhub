<?php

namespace Database\Seeders;

use App\Models\ArticleTag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class ArticleTagSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<int, array{0:string,1:string,2:string}> $tags */
        $tags = require database_path('seeders/data/article_tags.php');

        $this->validateTags($tags);

        DB::transaction(function () use ($tags): void {
            $baseSort = 2000;

            foreach ($tags as $index => [$name, $slug, $description]) {
                $tag = ArticleTag::query()
                    ->firstOrNew([
                        'slug' => $slug,
                    ]);

                $isNew = ! $tag->exists;

                // 内容定义允许 Seeder 更新。
                $tag->name = $name;
                $tag->description = $description;

                // 已存在标签保留后台人工修改的状态与排序。
                if ($isNew) {
                    $tag->status = 1;
                    $tag->sort = max(0, $baseSort - $index);
                }

                $tag->save();
            }
        });
    }

    private function validateTags(array $tags): void
    {
        $seenSlugs = [];

        foreach ($tags as $index => $tag) {
            if (
                ! is_array($tag)
                || count($tag) !== 3
                || ! isset($tag[0], $tag[1], $tag[2])
            ) {
                throw new LogicException(
                    sprintf('文章标签配置第 %d 项格式错误。', $index + 1)
                );
            }

            [$name, $slug] = $tag;

            if ($name === '' || $slug === '') {
                throw new LogicException(
                    sprintf('文章标签配置第 %d 项名称或 slug 为空。', $index + 1)
                );
            }

            if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                throw new LogicException(
                    sprintf('文章标签 slug 格式错误：%s', $slug)
                );
            }

            if (isset($seenSlugs[$slug])) {
                throw new LogicException(
                    sprintf('文章标签 slug 重复：%s', $slug)
                );
            }

            $seenSlugs[$slug] = true;
        }
    }
}
