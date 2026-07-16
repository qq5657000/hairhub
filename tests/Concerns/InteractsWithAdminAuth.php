<?php

namespace Tests\Concerns;

use Dcat\Admin\Models\Administrator;
use Dcat\Admin\Models\Role;

/**
 * 为真实 HTTP Feature Test 提供 Dcat-Admin 后台登录态。
 *
 * Dcat-Admin 的权限中间件（Dcat\Admin\Http\Middleware\Permission）在
 * $user->isAdministrator() 为 true 时会直接放行所有权限校验（见 vendor 源码），
 * 因此这里只需要创建一个绑定了 administrator 角色的后台用户，并用 Laravel 内置的
 * actingAs($user, 'admin') 登录到 config('admin.auth.guard') 对应的 'admin' guard，
 * 不需要额外为每个测试路由手工创建 admin_permissions 记录。
 */
trait InteractsWithAdminAuth
{
    protected function actingAsAdmin(): Administrator
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => Role::ADMINISTRATOR],
            ['name' => 'Administrator']
        );

        $admin = Administrator::create([
            'username' => 'tester_'.uniqid(),
            'password' => bcrypt('password'),
            'name' => 'Feature Test Admin',
        ]);

        $admin->roles()->attach($role->id);

        $this->actingAs($admin, 'admin');

        return $admin;
    }
}
