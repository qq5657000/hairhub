<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ContentTaxonomySeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ArticleCategorySeeder::class,
            ArticleTagSeeder::class,
        ]);
    }
}
