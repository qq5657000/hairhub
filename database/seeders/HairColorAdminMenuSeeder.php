<?php

declare(strict_types=1);

namespace Database\Seeders;

use Dcat\Admin\Models\Menu;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 发色模块后台菜单初始化 Seeder。
 *
 * 背景说明（本次 Review 发现）：项目现有“发型中心”菜单是通过
 * `app/Admin/bootstrap.php` 里的 `Admin::menu()` 静态数组渲染的（对应 Dcat 的
 * `LEFT_SIDEBAR_MENU_BOTTOM` 区块），并没有写入真正的 `admin_menu` 数据库表——
 * 实测该表当前只有 Dcat 安装时自带的 8 条默认记录（Index/Admin/Users/Roles/
 * Permission/Menu/Extensions/hairuser），不包含任何“发型中心”相关菜单。
 *
 * 该静态数组机制要求每个节点显式声明数字 id/parent_id 来手工拼接父子关系，
 * 不具备“按 URI 幂等查找、自动分配真实主键”的能力，不适合用来新增“发色中心”
 * 菜单（继续往同一个数组里塞硬编码数字，会随着后续模块增多不断增加 id 冲突风险）。
 *
 * 因此发色模块改为通过本 Seeder 写入真正的 `admin_menu` 表（Dcat 后台“菜单管理”
 * 页面实际读写的表），这样可以：
 * - 使用 `Menu::query()->where(...)->first()` 按稳定标识（父菜单按 title +
 *   parent_id=0 定位，子菜单按 uri 定位）幂等查找，找到则复用真实自增 id，
 *   找不到才创建，重复执行不会产生重复菜单；
 * - 不覆盖任何已有菜单记录的字段（找到即直接复用，不做 update）；
 * - 不依赖任何硬编码的数字 id，父子关系通过查找到的真实 parent id 建立；
 * - 新增的“发色中心”及其子菜单与 Dcat 内置的角色/权限体系天然兼容（可在
 *   后台“菜单管理”正常编辑、可在“角色管理”里正常分配权限），不需要额外
 *   对接“发型中心”那种静态数组机制。
 *
 * 本 Seeder 不在本次任务中执行，仅提供代码；如需生效，需要项目负责人评估后
 * 手动执行（见完成报告“建议人工执行的安全命令”）。
 */
class HairColorAdminMenuSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $parent = $this->firstOrCreateTopMenu('发色中心', 'feather icon-droplet');

            $this->firstOrCreateChildMenu($parent->id, '发色分类', 'hair-color-categories', 'feather icon-folder');
            $this->firstOrCreateChildMenu($parent->id, '发色管理', 'hair-colors', 'feather icon-droplet');
        });

        // 部分环境启用了菜单缓存（config('admin.menu.cache.enable')），写入后主动清理，
        // 避免管理员需要等待缓存过期才能看到新增的“发色中心”菜单。
        (new Menu())->flushCache();
    }

    /**
     * 顶级菜单没有天然唯一的 uri（Dcat 约定纯分组节点 uri 为空字符串，多个分组节点
     * 的 uri 都是空字符串，不能作为唯一定位依据），因此按 “parent_id = 0 且 title
     * 相同” 幂等定位；找到即直接复用，不覆盖 icon/order 等已有字段。
     */
    private function firstOrCreateTopMenu(string $title, string $icon): Menu
    {
        /** @var Menu|null $menu */
        $menu = Menu::query()->where('parent_id', 0)->where('title', $title)->first();

        if ($menu) {
            return $menu;
        }

        return Menu::create([
            'parent_id' => 0,
            'title' => $title,
            'icon' => $icon,
            'uri' => '',
            'show' => 1,
        ]);
    }

    /**
     * 子菜单（真正对应一个后台页面）使用 uri 幂等定位——uri 在业务上应当是唯一的，
     * 找到即直接复用真实 id，不覆盖已有字段；parent_id 使用上面查找/创建得到的
     * 真实父菜单 id，不硬编码任何数字。
     */
    private function firstOrCreateChildMenu(int $parentId, string $title, string $uri, string $icon): Menu
    {
        /** @var Menu|null $menu */
        $menu = Menu::query()->where('uri', $uri)->first();

        if ($menu) {
            return $menu;
        }

        return Menu::create([
            'parent_id' => $parentId,
            'title' => $title,
            'icon' => $icon,
            'uri' => $uri,
            'show' => 1,
        ]);
    }
}
