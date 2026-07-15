<?php

use Dcat\Admin\Admin;
use Dcat\Admin\Grid;
use Dcat\Admin\Form;
use Dcat\Admin\Grid\Filter;
use Dcat\Admin\Show;

/**
 * Dcat-admin - admin builder based on Laravel.
 * @author jqh <https://github.com/jqhph>
 *
 * Bootstraper for Admin.
 *
 * Here you can remove builtin form field:
 *
 * extend custom field:
 * Dcat\Admin\Form::extend('php', PHPEditor::class);
 * Dcat\Admin\Grid\Column::extend('php', PHPEditor::class);
 * Dcat\Admin\Grid\Filter::extend('php', PHPEditor::class);
 *
 * Or require js and css assets:
 * Admin::css('/packages/prettydocs/css/styles.css');
 * Admin::js('/packages/prettydocs/js/main.js');
 *
 */
Admin::menu(function (\Dcat\Admin\Layout\Menu $menu) {
    $menu->add([
        [
            'id'        => 1,
            'title'     => '用户中心',
            'icon'      => 'feather icon-users',
            'uri'       => '',
            'parent_id' => 0,
        ],
        [
            'id'        => 2,
            'title'     => '用户管理',
            'icon'      => 'feather icon-user',
            'uri'       => 'member-user',
            'parent_id' => 1,
        ],
        [
            'id'        => 3,
            'title'     => '网站配置',
            'icon'      => 'feather icon-settings',
            'uri'       => 'web-config',
            'parent_id' => 0,
        ],
        [
            'id'        => 4,
            'title'     => '开放接口文档',
            'icon'      => 'feather icon-layers',
            'uri'       => '/openapi-docs',
            'parent_id' => 0,
        ],
        [
            'id'        => 5,
            'title'     => '发型中心',
            'icon'      => 'feather icon-scissors',
            'uri'       => '',
            'parent_id' => 0,
        ],
        [
            'id'        => 10,
            'title'     => '发型管理',
            'icon'      => 'feather icon-scissors',
            'uri'       => 'hairstyles',
            'parent_id' => 5,
        ],
        [
            'id'        => 6,
            'title'     => '发型分类',
            'icon'      => 'feather icon-folder',
            'uri'       => 'hairstyle-categories',
            'parent_id' => 5,
        ],
        [
            'id'        => 7,
            'title'     => '发型标签',
            'icon'      => 'feather icon-tag',
            'uri'       => 'hairstyle-tags',
            'parent_id' => 5,
        ],
        [
            'id'        => 8,
            'title'     => '媒体中心',
            'icon'      => 'feather icon-image',
            'uri'       => '',
            'parent_id' => 0,
        ],
        [
            'id'        => 9,
            'title'     => '媒体资源',
            'icon'      => 'feather icon-image',
            'uri'       => 'media-files',
            'parent_id' => 8,
        ],
]);
});