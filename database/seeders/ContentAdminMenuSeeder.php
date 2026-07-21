<?php

declare(strict_types=1);

namespace Database\Seeders;

use Dcat\Admin\Models\Menu;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 内容模块后台菜单初始化 Seeder。
 *
 * 与 HairColorAdminMenuSeeder 完全同构：写入真正的 admin_menu 表（Dcat 后台
 * “菜单管理”页面实际读写的表），不使用 app/Admin/bootstrap.php 里 Admin::menu()
 * 的静态数组机制（该机制不具备幂等查找能力，详见 HairColorAdminMenuSeeder 头部
 * 说明）。按稳定标识幂等查找：父菜单按 title + parent_id=0 定位，子菜单按 uri
 * 定位，找到即直接复用真实自增 id，不覆盖任何已有菜单记录的字段。
 *
 * 本 Seeder 不在本次任务中执行，仅提供代码；如需生效，需要项目负责人评估后
 * 手动执行（见完成报告“建议人工执行的安全命令”）。
 */
class ContentAdminMenuSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $parent = $this->firstOrCreateTopMenu('内容中心', 'feather icon-file-text');

            $this->firstOrCreateChildMenu($parent->id, '文章分类', 'article-categories', 'feather icon-folder');
            $this->firstOrCreateChildMenu($parent->id, '文章标签', 'article-tags', 'feather icon-tag');
            $this->firstOrCreateChildMenu($parent->id, '文章管理', 'articles', 'feather icon-file-text');
            $this->firstOrCreateChildMenu($parent->id, '视频内容', 'videos', 'feather icon-video');
            $this->firstOrCreateChildMenu($parent->id, '公众号同步', 'wechat-articles', 'feather icon-message-circle');
        });

        // 部分环境启用了菜单缓存（config('admin.menu.cache.enable')），写入后主动清理，
        // 避免管理员需要等待缓存过期才能看到新增的“内容中心”菜单。
        (new Menu())->flushCache();
    }

    /**
     * 顶级菜单没有天然唯一的 uri（Dcat 约定纯分组节点 uri 为空字符串），因此按
     * “parent_id = 0 且 title 相同” 幂等定位；找到即直接复用，不覆盖 icon/order 等已有字段。
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
