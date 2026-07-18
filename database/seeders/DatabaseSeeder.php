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

        // 发色中心初始化数据（HairColorCategorySeeder / HairColorSeeder）不在此注册。
        // 本次 Review 决定：数据库全局 Seeder 默认会在 `php artisan migrate:fresh --seed`
        // 等场景被无差别执行，发色分类/发色数据属于长期运营资产，即使 Seeder 本身已经
        // 做到“已存在记录不覆盖”，仍不希望它在无人明确决策的情况下被动触发。
        // 需要初始化发色示例数据时，请显式单独执行：
        //   php artisan db:seed --class=HairColorCategorySeeder
        //   php artisan db:seed --class=HairColorSeeder
        // （发色后台菜单 HairColorAdminMenuSeeder 同理，也不在此注册，见其类注释。）
    }
}
