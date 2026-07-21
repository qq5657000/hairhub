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
    # cover-upload 与 resource() 生成的 GET /hairstyles/{id} 是同样的两段式路径，
    # 必须注册在 resource() 之前，避免被 {id} 通配路由抢先匹配（参考 hairstyle-media/media-options）。
    #
    # 同时必须显式注册 PUT/PATCH：Dcat 的 WebUploader 字段（vendor/dcat-plus/laravel-admin/
    # src/Form/Field/WebUploader.php::setDefaultServer()）在表单为"编辑"状态时，
    # 会自动向上传/删除请求的 formData 里注入 _method=PUT。Laravel 在 Illuminate\Http\Request::
    # capture() 中默认开启了 HTTP 方法伪装（enableHttpMethodParameterOverride），
    # 这会让浏览器发出的 POST 请求在路由匹配阶段被当作 PUT 处理。若这里只注册 POST，
    # 该 PUT 请求就会被 resource() 生成的 PUT /hairstyles/{id} 通配路由抢先匹配
    # （把字面量 "cover-upload" 当成 {id}），进而在控制器里 findOrFail('cover-upload') 抛出
    # ModelNotFoundException，被 Laravel 异常处理器转换成 404 响应——这正是编辑页真实浏览器
    # 上传封面时报 404 的根因。补充 PUT/PATCH 路由指向同一个 uploadCover()，无需改动上传业务逻辑。
    $router->post('hairstyles/cover-upload', 'HairstyleController@uploadCover');
    $router->match(['put', 'patch'], 'hairstyles/cover-upload', 'HairstyleController@uploadCover');
    $router->resource('/hairstyles', HairstyleController::class);
    $router->put('hairstyles/{id}/restore', 'HairstyleController@restore');
    $router->delete('hairstyles/{id}/force-delete', 'HairstyleController@forceDelete');
    # hairstyle-categories/cover-upload 同理，也需要补充 PUT/PATCH（原因见上方注释）。
    $router->post('hairstyle-categories/cover-upload', 'HairstyleCategoryController@uploadCover');
    $router->match(['put', 'patch'], 'hairstyle-categories/cover-upload', 'HairstyleCategoryController@uploadCover');
    $router->resource('/hairstyle-categories', HairstyleCategoryController::class);
    $router->resource('/hairstyle-tags', HairstyleTagController::class);

    # 发色中心
    # cover-upload 与 resource() 生成的 GET /hair-color-categories/{id} 是同样的两段式路径，
    # 必须注册在 resource() 之前，避免被 {id} 通配路由抢先匹配（原因见上方发型分类同类注释）。
    # V1.0 发色模块只保留软删除和恢复，不提供永久删除路由（详见 Service/Controller 头部注释）。
    $router->post('hair-color-categories/cover-upload', 'HairColorCategoryController@uploadCover');
    $router->match(['put', 'patch'], 'hair-color-categories/cover-upload', 'HairColorCategoryController@uploadCover');
    $router->resource('/hair-color-categories', HairColorCategoryController::class);
    $router->put('hair-color-categories/{id}/restore', 'HairColorCategoryController@restore');

    $router->post('hair-colors/cover-upload', 'HairColorController@uploadCover');
    $router->match(['put', 'patch'], 'hair-colors/cover-upload', 'HairColorController@uploadCover');
    $router->resource('/hair-colors', HairColorController::class);
    $router->put('hair-colors/{id}/restore', 'HairColorController@restore');

    # 发型媒体关联管理（发型编辑页“媒体管理”标签页的独立子页面）
    # media-options 与 resource() 生成的 GET /hairstyle-media/{id} 是同样的两段式路径，
    # 必须注册在 resource() 之前，否则会被 {id} 通配路由抢先匹配。
    $router->get('hairstyle-media/media-options', 'HairstyleMediaController@mediaOptions');
    $router->resource('/hairstyle-media', HairstyleMediaController::class);
    $router->put('hairstyle-media/{id}/set-primary', 'HairstyleMediaController@setPrimary');

    # 内容中心
    # cover-upload / media-options / *-options 均与 resource() 生成的 GET /xxx/{id} 是同样的
    # 两段式路径，必须注册在 resource() 之前，避免被 {id} 通配路由抢先匹配（原因见上方
    # 发型中心 hairstyles/cover-upload 的详细注释）；PUT/PATCH 同理需要补充注册。
    $router->post('article-categories/cover-upload', 'ArticleCategoryController@uploadCover');
    $router->match(['put', 'patch'], 'article-categories/cover-upload', 'ArticleCategoryController@uploadCover');
    $router->resource('/article-categories', ArticleCategoryController::class);
    $router->put('article-categories/{id}/restore', 'ArticleCategoryController@restore');

    # article_tags 不使用 SoftDeletes（与 hairstyle_tags 一致），不提供回收站/恢复路由。
    $router->resource('/article-tags', ArticleTagController::class);

    $router->post('articles/cover-upload', 'ArticleController@uploadCover');
    $router->match(['put', 'patch'], 'articles/cover-upload', 'ArticleController@uploadCover');
    $router->post('articles/wechat-cover-upload', 'ArticleController@uploadWechatCover');
    $router->match(['put', 'patch'], 'articles/wechat-cover-upload', 'ArticleController@uploadWechatCover');
    $router->get('articles/hairstyle-options', 'ArticleController@hairstyleOptions');
    $router->get('articles/hair-color-options', 'ArticleController@hairColorOptions');
    $router->resource('/articles', ArticleController::class);
    $router->put('articles/{id}/restore', 'ArticleController@restore');

    # 文章媒体关联管理（文章编辑页“媒体与关联”标签页的独立子页面，详见该控制器头部注释）。
    $router->get('article-media/media-options', 'ArticleMediaController@mediaOptions');
    $router->resource('/article-media', ArticleMediaController::class);

    $router->post('videos/cover-upload', 'VideoController@uploadCover');
    $router->match(['put', 'patch'], 'videos/cover-upload', 'VideoController@uploadCover');
    $router->resource('/videos', VideoController::class);
    $router->put('videos/{id}/restore', 'VideoController@restore');

    # 公众号同步状态：只读预留，只注册 index()/show() 两个只读入口，刻意不注册
    # create/store/edit/update/destroy 对应的路由（详见该控制器头部注释）。
    $router->get('wechat-articles', 'WechatArticleController@index');
    $router->get('wechat-articles/{id}', 'WechatArticleController@show');

    # 媒体中心
    $router->resource('/media-files', MediaFileController::class);

    # 开放接口
    $router->get('openapi-docs', 'OpenApiDocsController@index');

    # 全局配置
    $router->get('web-config', 'WebConfigController@index');
    $router->post('web-config/save', 'WebConfigController@saveData');
});
