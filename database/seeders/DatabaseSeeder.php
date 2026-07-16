<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // \App\Models\User::factory(10)->create();

        // \App\Models\User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);

        // 发型中心初始化数据：分类 -> 标签 -> 发型（含标签关联），顺序不可调换，
        // HairstyleSeeder 依赖前两者已写入的 slug -> id 映射。全部可重复执行。
        $this->call([
            HairstyleCategorySeeder::class,
            HairstyleTagSeeder::class,
            HairstyleSeeder::class,
        ]);
    }
}
