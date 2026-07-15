<?php

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Dcat\Admin\Admin;

Admin::routes();

Route::group([
    'prefix'     => config('admin.route.prefix'),
    'namespace'  => config('admin.route.namespace'),
    'middleware' => config('admin.route.middleware'),
], function (Router $router) {

    $router->get('/', 'HomeController@index');
    $router->resource('/member-user',MemberUserController::class);

    # 发型中心
    $router->resource('/hairstyles', HairstyleController::class);
    $router->put('hairstyles/{id}/restore', 'HairstyleController@restore');
    $router->delete('hairstyles/{id}/force-delete', 'HairstyleController@forceDelete');
    $router->resource('/hairstyle-categories', HairstyleCategoryController::class);
    $router->resource('/hairstyle-tags', HairstyleTagController::class);

    # 发型媒体关联管理（发型编辑页“媒体管理”标签页的独立子页面）
    # media-options 与 resource() 生成的 GET /hairstyle-media/{id} 是同样的两段式路径，
    # 必须注册在 resource() 之前，否则会被 {id} 通配路由抢先匹配。
    $router->get('hairstyle-media/media-options', 'HairstyleMediaController@mediaOptions');
    $router->resource('/hairstyle-media', HairstyleMediaController::class);
    $router->put('hairstyle-media/{id}/set-primary', 'HairstyleMediaController@setPrimary');

    # 媒体中心
    $router->resource('/media-files', MediaFileController::class);

    # 开放接口
    $router->get('openapi-docs', 'OpenApiDocsController@index');

    # 全局配置
    $router->get('web-config', 'WebConfigController@index');
    $router->post('web-config/save', 'WebConfigController@saveData');
});
