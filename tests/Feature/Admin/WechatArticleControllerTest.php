<?php

namespace Tests\Feature\Admin;

use App\Admin\Controllers\WechatArticleController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * WechatArticleController 覆盖范围：
 * - truncateErrorMessage()：错误信息过长时截断，短信息原样返回（并转义）；
 * - 路由层禁止新增/编辑/删除：app/Admin/routes.php 只为本控制器注册了
 *   index()/show() 两个只读入口，本测试直接断言 admin.*（store/update/destroy）
 *   路由未注册，即使有人直接构造写请求也会被 Laravel 路由层拒绝（404），
 *   不会触达任何写入逻辑——这是比“隐藏 UI 按钮”更强的保证。
 *
 * 数据结构/同步预留记录的创建与幂等已由 Phase 1 的
 * tests/Feature/Services/Content/ArticleServiceTest.php（ensureWechatArticleRecord）覆盖。
 */
class WechatArticleControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_truncate_error_message_keeps_short_message_as_is(): void
    {
        $this->assertSame('同步失败', WechatArticleController::truncateErrorMessage('同步失败'));
        $this->assertSame('-', WechatArticleController::truncateErrorMessage(''));
    }

    public function test_truncate_error_message_truncates_long_message_with_ellipsis(): void
    {
        $long = str_repeat('错', 60);

        $result = WechatArticleController::truncateErrorMessage($long);

        $this->assertStringEndsWith('…', $result);
        $this->assertLessThan(mb_strlen($long), mb_strlen($result));
    }

    public function test_truncate_error_message_escapes_html(): void
    {
        $result = WechatArticleController::truncateErrorMessage('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $result);
    }

    public function test_write_routes_are_not_registered(): void
    {
        $uris = collect(Route::getRoutes())->map(function ($route) {
            return $route->methods()[0].' '.$route->uri();
        });

        $this->assertFalse(
            $uris->contains(fn ($uri) => str_starts_with($uri, 'POST ') && str_contains($uri, 'wechat-articles')),
            '不应存在任何 POST wechat-articles 路由（禁止新增）'
        );
        $this->assertFalse(
            $uris->contains(fn ($uri) => in_array(explode(' ', $uri)[0], ['PUT', 'PATCH'], true) && str_contains($uri, 'wechat-articles')),
            '不应存在任何 PUT/PATCH wechat-articles 路由（禁止编辑）'
        );
        $this->assertFalse(
            $uris->contains(fn ($uri) => str_starts_with($uri, 'DELETE ') && str_contains($uri, 'wechat-articles')),
            '不应存在任何 DELETE wechat-articles 路由（禁止删除）'
        );
    }
}
