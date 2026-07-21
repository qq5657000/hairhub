<?php

namespace Tests\Feature\Seeders;

use Database\Seeders\ContentAdminMenuSeeder;
use Dcat\Admin\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ContentAdminMenuSeeder 幂等性验证，与 HairColorAdminMenuSeederTest 完全同构。
 *
 * 覆盖：
 * - 按 title（顶级菜单）/uri（子菜单）幂等查找，重复执行不产生重复菜单；
 * - 5 个子菜单的 parent_id 均使用真实查找/创建得到的父菜单 id，不依赖任何硬编码数字；
 * - 不覆盖执行前已经存在的、与本 Seeder 无关的菜单记录；
 * - 找到已存在的“内容中心”/子菜单后直接复用，不覆盖其 icon/order 等字段。
 */
class ContentAdminMenuSeederTest extends TestCase
{
    use RefreshDatabase;

    private const CHILD_URIS = [
        'article-categories',
        'article-tags',
        'articles',
        'videos',
        'wechat-articles',
    ];

    public function test_seeder_creates_parent_and_child_menus_with_real_parent_id(): void
    {
        $existingCount = Menu::query()->count();

        (new ContentAdminMenuSeeder())->run();

        $parent = Menu::query()->where('parent_id', 0)->where('title', '内容中心')->first();
        $this->assertNotNull($parent, '应当创建“内容中心”顶级菜单');

        foreach (self::CHILD_URIS as $uri) {
            $menu = Menu::query()->where('uri', $uri)->first();
            $this->assertNotNull($menu, "应当创建 uri={$uri} 的子菜单");
            $this->assertSame($parent->id, $menu->parent_id, '子菜单必须使用真实查找到的父菜单 id，而不是硬编码数字');
        }

        $this->assertSame($existingCount + 1 + count(self::CHILD_URIS), Menu::query()->count(), '本次应当只新增 1 个父级 + 5 个子级菜单');
    }

    public function test_seeder_is_idempotent_and_does_not_duplicate_menus(): void
    {
        (new ContentAdminMenuSeeder())->run();
        $countAfterFirstRun = Menu::query()->count();

        (new ContentAdminMenuSeeder())->run();

        $this->assertSame($countAfterFirstRun, Menu::query()->count());
        $this->assertSame(1, Menu::query()->where('parent_id', 0)->where('title', '内容中心')->count());

        foreach (self::CHILD_URIS as $uri) {
            $this->assertSame(1, Menu::query()->where('uri', $uri)->count());
        }
    }

    public function test_seeder_does_not_overwrite_existing_menu_fields_on_rerun(): void
    {
        (new ContentAdminMenuSeeder())->run();

        /** @var Menu $articleMenu */
        $articleMenu = Menu::query()->where('uri', 'articles')->first();
        $articleMenu->icon = 'feather icon-star';
        $articleMenu->title = '文章管理（手工改名）';
        $articleMenu->save();

        (new ContentAdminMenuSeeder())->run();

        $articleMenu->refresh();
        $this->assertSame('feather icon-star', $articleMenu->icon);
        $this->assertSame('文章管理（手工改名）', $articleMenu->title);
    }

    public function test_seeder_does_not_touch_unrelated_existing_menus(): void
    {
        $unrelated = Menu::create([
            'parent_id' => 0,
            'title' => '与内容模块无关的菜单',
            'icon' => 'feather icon-box',
            'uri' => 'some-other-module',
            'show' => 1,
        ]);

        (new ContentAdminMenuSeeder())->run();

        $unrelated->refresh();
        $this->assertSame('与内容模块无关的菜单', $unrelated->title);
        $this->assertSame(0, $unrelated->parent_id);
    }
}
