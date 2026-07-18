<?php

namespace Tests\Feature\Seeders;

use Database\Seeders\HairColorAdminMenuSeeder;
use Dcat\Admin\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HairColorAdminMenuSeeder 幂等性验证。
 *
 * 覆盖：
 * - 按 title（顶级菜单）/uri（子菜单）幂等查找，重复执行不产生重复菜单；
 * - 子菜单的 parent_id 使用真实查找/创建得到的父菜单 id，不依赖任何硬编码数字；
 * - 不覆盖执行前已经存在的、与本 Seeder 无关的菜单记录（如 Dcat 安装自带的默认菜单）；
 * - 找到已存在的“发色中心”/子菜单后直接复用，不覆盖其 icon/order 等字段
 *   （模拟后台管理员手工调整过菜单图标或排序后，重新执行 Seeder 不应该改回去）。
 */
class HairColorAdminMenuSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_parent_and_child_menus_with_real_parent_id(): void
    {
        $existingCount = Menu::query()->count();

        (new HairColorAdminMenuSeeder())->run();

        $parent = Menu::query()->where('parent_id', 0)->where('title', '发色中心')->first();
        $this->assertNotNull($parent, '应当创建“发色中心”顶级菜单');

        $categoryMenu = Menu::query()->where('uri', 'hair-color-categories')->first();
        $colorMenu = Menu::query()->where('uri', 'hair-colors')->first();

        $this->assertNotNull($categoryMenu);
        $this->assertNotNull($colorMenu);
        $this->assertSame($parent->id, $categoryMenu->parent_id, '子菜单必须使用真实查找到的父菜单 id，而不是硬编码数字');
        $this->assertSame($parent->id, $colorMenu->parent_id);

        $this->assertSame($existingCount + 3, Menu::query()->count(), '本次应当只新增 3 条菜单（1 个父级 + 2 个子级）');
    }

    public function test_seeder_is_idempotent_and_does_not_duplicate_menus(): void
    {
        (new HairColorAdminMenuSeeder())->run();
        $countAfterFirstRun = Menu::query()->count();

        (new HairColorAdminMenuSeeder())->run();

        $this->assertSame($countAfterFirstRun, Menu::query()->count());
        $this->assertSame(1, Menu::query()->where('parent_id', 0)->where('title', '发色中心')->count());
        $this->assertSame(1, Menu::query()->where('uri', 'hair-color-categories')->count());
        $this->assertSame(1, Menu::query()->where('uri', 'hair-colors')->count());
    }

    public function test_seeder_does_not_overwrite_existing_menu_fields_on_rerun(): void
    {
        (new HairColorAdminMenuSeeder())->run();

        /** @var Menu $categoryMenu */
        $categoryMenu = Menu::query()->where('uri', 'hair-color-categories')->first();
        $categoryMenu->icon = 'feather icon-star';
        $categoryMenu->title = '发色分类（手工改名）';
        $categoryMenu->save();

        (new HairColorAdminMenuSeeder())->run();

        $categoryMenu->refresh();
        $this->assertSame('feather icon-star', $categoryMenu->icon);
        $this->assertSame('发色分类（手工改名）', $categoryMenu->title);
    }

    public function test_seeder_does_not_touch_unrelated_existing_menus(): void
    {
        $unrelated = Menu::create([
            'parent_id' => 0,
            'title' => '与发色模块无关的菜单',
            'icon' => 'feather icon-box',
            'uri' => 'some-other-module',
            'show' => 1,
        ]);

        (new HairColorAdminMenuSeeder())->run();

        $unrelated->refresh();
        $this->assertSame('与发色模块无关的菜单', $unrelated->title);
        $this->assertSame(0, $unrelated->parent_id);
    }
}
