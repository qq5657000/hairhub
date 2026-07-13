# HairHub V1.0 SEO 与运营模块 MySQL 表结构详细设计

> Version: 1.0.0  
> 适用项目：HairHub 发型中心  
> 技术栈：Laravel 10 + Dcat Admin + MySQL 8.0+  
> 文档路径：`/docs/v1.0/database/06-SEO与运营模块.md`

---

# 一、模块目标

SEO 与运营模块用于统一管理 HairHub 的页面 SEO、首页运营区块和系统级配置，包括：

- 发型、发色、文章、视频、专题等页面的 SEO 信息
- canonical、robots 等页面级元信息
- 首页推荐区块
- 首页 Banner
- 热门发型、热门发色、热门文章等运营配置
- 网站名称、Logo、默认 SEO 等低频系统配置
- 后续多语言和跨境扩展预留

V1.0 涉及以下数据表：

```text
seo_meta
home_blocks
settings
```

---

# 二、模块边界

本模块负责：

- 页面 SEO 元信息
- 首页运营区块配置
- 低频系统配置
- 运营排序和上下架
- 默认 SEO 配置

本模块不负责：

- 访问统计
- 搜索排名监控
- 广告投放
- A/B 测试
- 完整页面搭建器
- 用户个性化推荐
- 密钥和敏感凭证管理
- 多语言独立翻译内容

以上能力后续由：

```text
统计模块
搜索与数据分析服务
广告模块
实验平台
推荐系统
环境变量或安全配置中心
多语言模块
```

负责。

---

# 三、数据关系

```text
seo_meta
   │
   └── model_type + model_id
          ├── hairstyles
          ├── hair_colors
          ├── articles
          ├── videos
          ├── topics
          └── categories


home_blocks
   │
   └── content JSON
          ├── 推荐对象 ID
          ├── 数量
          ├── 展示方式
          └── 跳转配置


settings
   └── key + value + type
```

说明：

- `seo_meta` 使用逻辑多态关联。
- `home_blocks` 使用 JSON 保存弱结构化运营配置。
- `settings` 仅保存低频、非敏感系统配置。
- 不创建数据库物理外键。

---

# 四、统一设计约定

## 4.1 基础规范

- 存储引擎：`InnoDB`
- 字符集：`utf8mb4`
- 主键：`BIGINT UNSIGNED AUTO_INCREMENT`
- 不创建数据库物理外键
- 状态字段使用 `TINYINT UNSIGNED`
- 状态值由 PHP Enum 管理
- 核心查询字段必须建立索引
- JSON 只保存弱查询配置
- 密钥、密码和敏感凭证禁止写入 settings
- SEO 元信息与业务主表解耦
- 首页运营配置不得复制核心业务数据

## 4.2 状态规则

通用状态：

```text
0 = disabled
1 = enabled
```

PHP Enum：

```php
enum CommonStatus: int
{
    case Disabled = 0;
    case Enabled = 1;
}
```

---

# 五、seo_meta SEO 元信息表

## 5.1 表用途

统一保存不同业务页面的 SEO 元信息。

支持对象：

```text
hairstyle
hair_color
article
video
topic
hairstyle_category
hair_color_category
article_category
custom_page
```

## 5.2 字段结构

| 字段 | 类型 | 是否为空 | 默认值 | 说明 |
|---|---|---:|---:|---|
| id | BIGINT UNSIGNED | 否 | 自增 | 主键 |
| model_type | TINYINT UNSIGNED | 否 | 0 | 关联对象类型 |
| model_id | BIGINT UNSIGNED | 否 | 0 | 关联对象 ID |
| locale | VARCHAR(10) | 否 | `zh-CN` | 语言地区 |
| title | VARCHAR(255) | 否 | `''` | SEO 标题 |
| description | VARCHAR(500) | 否 | `''` | SEO 描述 |
| keywords | VARCHAR(500) | 否 | `''` | SEO 关键词 |
| canonical | VARCHAR(500) | 否 | `''` | 规范化 URL |
| robots | VARCHAR(100) | 否 | `index,follow` | robots 指令 |
| og_title | VARCHAR(255) | 否 | `''` | Open Graph 标题 |
| og_description | VARCHAR(500) | 否 | `''` | Open Graph 描述 |
| og_image_media_id | BIGINT UNSIGNED | 否 | 0 | Open Graph 图片 |
| schema_data | JSON | 是 | NULL | 结构化数据配置 |
| created_at | TIMESTAMP | 是 | NULL | 创建时间 |
| updated_at | TIMESTAMP | 是 | NULL | 更新时间 |

## 5.3 字段说明

### model_type

建议枚举：

```text
0 = unknown
1 = hairstyle
2 = hair_color
3 = article
4 = video
5 = topic
6 = hairstyle_category
7 = hair_color_category
8 = article_category
9 = custom_page
```

### locale

V1.0 默认：

```text
zh-CN
```

预留：

```text
en-US
zh-TW
ja-JP
ko-KR
```

规则：

- 即使 V1.0 只有中文，也保留 locale。
- 同一对象同一语言只能有一条 SEO 记录。

### canonical

规则：

- 保存规范化绝对 URL 或相对业务路径，项目必须统一。
- 推荐保存相对路径，最终由 URL Service 拼接域名。
- 不保存带追踪参数的 URL。
- 不保存临时签名 URL。

### robots

常见值：

```text
index,follow
noindex,follow
noindex,nofollow
```

### schema_data

用于保存结构化数据配置，例如：

```json
{
  "@type": "Article",
  "author": "HairHub",
  "datePublished": "2026-07-11"
}
```

规则：

- 仅保存页面结构化数据配置。
- 不复制完整业务内容。
- 输出前必须做结构校验。
- 不直接存储完整 `<script>` 标签。

## 5.4 索引设计

```sql
PRIMARY KEY (`id`),
UNIQUE KEY `uk_model_locale` (`model_type`, `model_id`, `locale`),
KEY `idx_og_image_media_id` (`og_image_media_id`)
```

说明：

- 同一对象同一语言只有一条 SEO 数据。
- `og_image_media_id` 为媒体逻辑关联字段。
- 不为 title、description、keywords 建普通索引。

## 5.5 建表 SQL 参考

```sql
CREATE TABLE `seo_meta` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `model_type` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `model_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `locale` VARCHAR(10) NOT NULL DEFAULT 'zh-CN',
    `title` VARCHAR(255) NOT NULL DEFAULT '',
    `description` VARCHAR(500) NOT NULL DEFAULT '',
    `keywords` VARCHAR(500) NOT NULL DEFAULT '',
    `canonical` VARCHAR(500) NOT NULL DEFAULT '',
    `robots` VARCHAR(100) NOT NULL DEFAULT 'index,follow',
    `og_title` VARCHAR(255) NOT NULL DEFAULT '',
    `og_description` VARCHAR(500) NOT NULL DEFAULT '',
    `og_image_media_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `schema_data` JSON NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_model_locale` (`model_type`, `model_id`, `locale`),
    KEY `idx_og_image_media_id` (`og_image_media_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 5.6 业务规则

- `model_type + model_id + locale` 必须唯一。
- SEO 标题为空时回退到业务对象标题。
- SEO 描述为空时回退到业务摘要。
- canonical 为空时由路由生成。
- OG 标题和描述为空时回退到 SEO 字段。
- OG 图片为空时回退到业务封面。
- 删除业务对象时不自动删除 SEO 记录，可由清理任务处理。
- 页面软删除后前台不得继续输出该 SEO 记录。

---

# 六、home_blocks 首页运营区块表

## 6.1 表用途

控制首页和部分频道页的运营区块。

支持：

- Banner
- 热门发型
- 热门发色
- 最新文章
- 视频教程
- 专题入口
- AI 工具入口
- 微信小店入口
- 自定义图文区块

## 6.2 字段结构

| 字段 | 类型 | 是否为空 | 默认值 | 说明 |
|---|---|---:|---:|---|
| id | BIGINT UNSIGNED | 否 | 自增 | 主键 |
| code | VARCHAR(100) | 否 | 无 | 区块唯一编码 |
| type | TINYINT UNSIGNED | 否 | 0 | 区块类型 |
| locale | VARCHAR(10) | 否 | `zh-CN` | 语言地区 |
| title | VARCHAR(255) | 否 | `''` | 区块标题 |
| subtitle | VARCHAR(255) | 否 | `''` | 区块副标题 |
| content | JSON | 是 | NULL | 区块配置 |
| status | TINYINT UNSIGNED | 否 | 1 | 状态 |
| sort | INT UNSIGNED | 否 | 0 | 排序值 |
| starts_at | TIMESTAMP | 是 | NULL | 开始展示时间 |
| ends_at | TIMESTAMP | 是 | NULL | 结束展示时间 |
| created_at | TIMESTAMP | 是 | NULL | 创建时间 |
| updated_at | TIMESTAMP | 是 | NULL | 更新时间 |
| deleted_at | TIMESTAMP | 是 | NULL | 软删除时间 |

## 6.3 code 设计

示例：

```text
home_hero
home_hot_hairstyles
home_hot_colors
home_latest_articles
home_video_tutorials
home_topics
home_ai_tools
home_shop
```

规则：

- 同一 locale 下唯一。
- 用于前端稳定读取。
- 不应频繁修改。
- 不使用自增 ID 作为前端区块标识。

## 6.4 type 枚举

建议：

```text
0 = custom
1 = banner
2 = hairstyle_list
3 = hair_color_list
4 = article_list
5 = video_list
6 = topic_list
7 = tool_entry
8 = shop_entry
9 = rich_text
```

## 6.5 content 设计

### 发型列表区块

```json
{
  "source": "manual",
  "ids": [1, 2, 3, 4],
  "limit": 8,
  "display_mode": "grid",
  "more_url": "/hairstyles"
}
```

### 自动推荐区块

```json
{
  "source": "query",
  "filters": {
    "is_recommended": 1,
    "gender": 2
  },
  "limit": 8,
  "display_mode": "grid"
}
```

### Banner

```json
{
  "items": [
    {
      "title": "AI 换发型",
      "image_media_id": 100,
      "link": "/ai/hairstyle",
      "open_type": "internal"
    }
  ]
}
```

规则：

- 只保存运营配置和对象 ID。
- 不保存业务标题、正文等完整副本。
- 保存前必须按 type 校验 JSON 结构。
- 前台查询时忽略已删除或未发布对象。
- 单个区块对象数量必须限制。

## 6.6 索引设计

```sql
PRIMARY KEY (`id`),
UNIQUE KEY `uk_code_locale` (`code`, `locale`),
KEY `idx_status_time_sort` (`status`, `starts_at`, `ends_at`, `sort`),
KEY `idx_type_status_sort` (`type`, `status`, `sort`),
KEY `idx_deleted_at` (`deleted_at`)
```

## 6.7 建表 SQL 参考

```sql
CREATE TABLE `home_blocks` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(100) NOT NULL,
    `type` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `locale` VARCHAR(10) NOT NULL DEFAULT 'zh-CN',
    `title` VARCHAR(255) NOT NULL DEFAULT '',
    `subtitle` VARCHAR(255) NOT NULL DEFAULT '',
    `content` JSON NULL,
    `status` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `sort` INT UNSIGNED NOT NULL DEFAULT 0,
    `starts_at` TIMESTAMP NULL DEFAULT NULL,
    `ends_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_code_locale` (`code`, `locale`),
    KEY `idx_status_time_sort` (`status`, `starts_at`, `ends_at`, `sort`),
    KEY `idx_type_status_sort` (`type`, `status`, `sort`),
    KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 6.8 展示规则

区块可展示条件：

```text
status = enabled
deleted_at IS NULL
starts_at 为空或 <= 当前时间
ends_at 为空或 >= 当前时间
```

排序：

```text
sort DESC, id DESC
```

---

# 七、settings 系统配置表

## 7.1 表用途

保存低频、非敏感的系统配置。

适合保存：

- 网站名称
- 网站副标题
- Logo 媒体 ID
- 默认 SEO 标题
- 默认 SEO 描述
- 默认语言
- 联系方式
- 页脚文案
- 功能开关
- 默认分页数量

不适合保存：

- 数据库密码
- Redis 密码
- 微信 AppSecret
- API Token
- 对象存储 Secret
- 支付密钥
- 私钥证书

敏感配置必须放在：

```text
.env
安全配置中心
密钥管理服务
```

## 7.2 字段结构

| 字段 | 类型 | 是否为空 | 默认值 | 说明 |
|---|---|---:|---:|---|
| id | BIGINT UNSIGNED | 否 | 自增 | 主键 |
| group | VARCHAR(100) | 否 | `general` | 配置分组 |
| key | VARCHAR(150) | 否 | 无 | 配置键 |
| value | LONGTEXT | 是 | NULL | 配置值 |
| type | TINYINT UNSIGNED | 否 | 0 | 值类型 |
| description | VARCHAR(500) | 否 | `''` | 配置说明 |
| is_public | TINYINT UNSIGNED | 否 | 0 | 是否允许前端公开读取 |
| sort | INT UNSIGNED | 否 | 0 | 排序值 |
| created_at | TIMESTAMP | 是 | NULL | 创建时间 |
| updated_at | TIMESTAMP | 是 | NULL | 更新时间 |

## 7.3 key 设计

示例：

```text
site.name
site.subtitle
site.logo_media_id
site.default_locale
seo.default_title
seo.default_description
seo.default_keywords
footer.copyright
feature.ai_hairstyle_enabled
feature.ai_hair_color_enabled
pagination.default_size
```

规则：

- 使用点号分层。
- 全局唯一。
- 只能使用小写字母、数字、下划线和点号。
- 不允许随意修改已有 key。
- 业务代码通过统一 Config Service 读取。

## 7.4 type 枚举

建议：

```text
0 = string
1 = integer
2 = boolean
3 = json
4 = text
5 = media_id
```

## 7.5 value 处理

示例：

```text
string  -> HairHub
integer -> 20
boolean -> 1
json    -> {"a":1}
media_id -> 100
```

规则：

- 数据库统一保存字符串或文本。
- 读取时根据 `type` 转换。
- JSON 保存前必须校验。
- boolean 统一保存 `0` 或 `1`。
- media_id 读取时通过媒体服务转换为 URL。

## 7.6 索引设计

```sql
PRIMARY KEY (`id`),
UNIQUE KEY `uk_key` (`key`),
KEY `idx_group_sort` (`group`, `sort`),
KEY `idx_public_group` (`is_public`, `group`)
```

## 7.7 建表 SQL 参考

```sql
CREATE TABLE `settings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `group` VARCHAR(100) NOT NULL DEFAULT 'general',
    `key` VARCHAR(150) NOT NULL,
    `value` LONGTEXT NULL,
    `type` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `description` VARCHAR(500) NOT NULL DEFAULT '',
    `is_public` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `sort` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_key` (`key`),
    KEY `idx_group_sort` (`group`, `sort`),
    KEY `idx_public_group` (`is_public`, `group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 7.8 业务规则

- `key` 必须唯一。
- 不使用软删除。
- 配置删除应谨慎，优先禁用对应业务功能或清空值。
- 敏感配置禁止入库。
- `is_public = 1` 的配置可通过前端配置接口返回。
- 前端接口必须使用白名单，不能只依赖 is_public。
- 修改配置后应清理缓存。

---

# 八、Laravel Model 关系

## 8.1 SeoMeta

```php
public function ogImageMedia()
{
    return $this->belongsTo(MediaFile::class, 'og_image_media_id');
}
```

`model_type + model_id` 不建议直接使用 Eloquent Morph，因为本项目使用整数枚举，避免类名字符串与数据库耦合。

## 8.2 HomeBlock

无强制 Eloquent 关联。

`content` 中的媒体和业务对象由 Service 按区块类型解析。

## 8.3 Setting

无业务关联。

如果 `type = media_id`，由 SettingService 调用 MediaService 解析。

---

# 九、Laravel Model 要求

## 9.1 SeoMeta

建议 casts：

```php
protected $casts = [
    'model_type' => SeoModelType::class,
    'model_id' => 'integer',
    'og_image_media_id' => 'integer',
    'schema_data' => 'array',
];
```

不使用 `SoftDeletes`。

## 9.2 HomeBlock

必须包含：

```text
SoftDeletes
fillable
casts
```

建议 casts：

```php
protected $casts = [
    'type' => HomeBlockType::class,
    'content' => 'array',
    'status' => CommonStatus::class,
    'sort' => 'integer',
    'starts_at' => 'datetime',
    'ends_at' => 'datetime',
];
```

## 9.3 Setting

建议 casts：

```php
protected $casts = [
    'type' => SettingType::class,
    'is_public' => 'boolean',
    'sort' => 'integer',
];
```

不使用 `SoftDeletes`。

---

# 十、枚举建议

## 10.1 SeoModelType

```php
enum SeoModelType: int
{
    case Unknown = 0;
    case Hairstyle = 1;
    case HairColor = 2;
    case Article = 3;
    case Video = 4;
    case Topic = 5;
    case HairstyleCategory = 6;
    case HairColorCategory = 7;
    case ArticleCategory = 8;
    case CustomPage = 9;
}
```

## 10.2 HomeBlockType

```php
enum HomeBlockType: int
{
    case Custom = 0;
    case Banner = 1;
    case HairstyleList = 2;
    case HairColorList = 3;
    case ArticleList = 4;
    case VideoList = 5;
    case TopicList = 6;
    case ToolEntry = 7;
    case ShopEntry = 8;
    case RichText = 9;
}
```

## 10.3 SettingType

```php
enum SettingType: int
{
    case String = 0;
    case Integer = 1;
    case Boolean = 2;
    case Json = 3;
    case Text = 4;
    case MediaId = 5;
}
```

---

# 十一、Service 设计要求

建议：

```text
SeoMetaService
HomeBlockService
SettingService
SettingCacheService
```

## 11.1 SeoMetaService

负责：

- 创建或更新 SEO 元信息
- 解析业务对象默认值
- 生成 canonical
- 合并默认 SEO
- 生成 OG 和 schema 数据
- 清理失效 SEO 记录

## 11.2 HomeBlockService

负责：

- 校验不同 type 的 content 结构
- 解析引用对象
- 忽略已删除或未发布对象
- 控制展示时间
- 返回前端所需结构
- 缓存首页运营数据

## 11.3 SettingService

负责：

- 按 key 读取配置
- 按 type 转换值
- 更新配置
- 校验敏感 key
- 清理配置缓存
- 返回允许公开的前端配置

---

# 十二、缓存策略

## 12.1 SEO 缓存

建议缓存键：

```text
seo:{locale}:{model_type}:{model_id}
```

修改 SEO 或业务对象后清理。

## 12.2 首页区块缓存

建议缓存键：

```text
home_blocks:{locale}
```

缓存内容：

- 当前有效区块
- 已解析的对象数据
- 展示顺序

修改、上下架或定时生效时必须清理。

## 12.3 settings 缓存

建议缓存键：

```text
settings:all
settings:group:{group}
settings:key:{key}
```

读取配置优先走缓存。

---

# 十三、Dcat Admin 管理要求

## 13.1 SEO 管理

Grid：

- 对象类型
- 对象 ID
- locale
- SEO 标题
- canonical
- 更新时间

筛选：

- model_type
- model_id
- locale
- title

Form：

- 对象类型
- 对象 ID
- locale
- title
- description
- keywords
- canonical
- robots
- OG 标题
- OG 描述
- OG 图片
- schema_data

要求：

- `model_type + model_id + locale` 唯一校验。
- schema_data 使用 JSON 编辑器并做结构校验。
- 对象选择应根据 model_type 动态加载。
- 不允许填写不存在的业务对象。

## 13.2 首页区块管理

Grid：

- code
- type
- locale
- 标题
- 状态
- 排序
- 生效时间
- 失效时间

Form：

- code
- type
- locale
- 标题
- 副标题
- content 可视化配置
- 状态
- 排序
- 开始和结束时间

要求：

- `content` 根据 type 使用不同表单。
- 不建议让运营人员直接编辑原始 JSON。
- `starts_at` 不得晚于 `ends_at`。
- `code + locale` 唯一。

## 13.3 系统配置管理

Grid：

- group
- key
- type
- value 摘要
- 是否公开
- 说明
- 更新时间

Form：

- group
- key
- value
- type
- description
- is_public
- sort

要求：

- 根据 type 显示不同输入控件。
- 敏感 key 黑名单禁止保存。
- key 创建后原则上不允许修改。
- 公开配置必须经过白名单校验。
- 更新后清理缓存。

---

# 十四、Migration 创建顺序

建议：

```text
1. create_media_files_table
2. create_seo_meta_table
3. create_home_blocks_table
4. create_settings_table
```

说明：

- `seo_meta` 的 OG 图片依赖媒体模块。
- 其他业务表可以先后独立创建。
- 不创建物理外键。

---

# 十五、Seeder 建议

## 15.1 默认首页区块

```text
home_hero
home_hot_hairstyles
home_hot_colors
home_latest_articles
home_video_tutorials
home_topics
home_ai_tools
home_shop
```

Seeder 使用 `updateOrCreate`，以 `code + locale` 作为稳定识别条件。

## 15.2 默认系统配置

建议初始化：

```text
site.name
site.subtitle
site.default_locale
seo.default_title
seo.default_description
seo.default_keywords
feature.ai_hairstyle_enabled
feature.ai_hair_color_enabled
pagination.default_size
```

规则：

- 不写入密钥。
- 不写死生产域名。
- 不写入真实支付或微信配置。
- 可重复执行。

---

# 十六、删除策略

## 16.1 seo_meta

- 不使用软删除。
- 业务对象删除后可保留一段时间。
- 定期清理无效对象关联。
- 后台可删除错误或重复记录。

## 16.2 home_blocks

- 使用软删除。
- 删除不影响引用的业务对象。
- 恢复后重新校验 content 引用对象。

## 16.3 settings

- 不使用软删除。
- 不建议后台直接删除核心配置。
- 删除前必须评估代码依赖。
- 优先保留 key 并调整 value。

---

# 十七、性能规则

- SEO 查询必须通过唯一索引定位。
- 首页区块应整体缓存。
- settings 应缓存，禁止每次请求查库。
- JSON 字段不得用于高频复杂 SQL。
- 首页区块对象解析必须批量查询，禁止循环逐条查询。
- 不在首页请求中多次读取同一个设置。
- 前端只返回允许公开的配置。
- schema_data 输出前做缓存和校验。

---

# 十八、安全规则

- settings 禁止保存敏感凭证。
- 前端公开配置必须白名单过滤。
- canonical 和跳转链接必须防止开放重定向。
- Banner 外链必须校验协议和域名。
- rich_text 必须经过 HTML 清洗。
- schema_data 不允许直接注入脚本。
- SEO 字段输出时必须转义。
- robots 值必须限制在允许列表。
- JSON 配置必须做结构与数量限制。
- 后台配置修改应写操作日志。

---

# 十九、V1.0 暂不设计

```text
seo_redirects
seo_sitemaps
seo_keyword_rankings
seo_page_audits
seo_search_console_data
home_block_items
page_builder_pages
page_builder_components
experiments
ab_test_variants
ad_slots
ad_campaigns
setting_versions
setting_audit_logs
多语言独立翻译表
```

后续按 SEO 规模和运营复杂度扩展。

---

# 二十、Cursor 开发约束

Cursor 开发本模块时必须：

1. 阅读本文档和 `.cursor/rules`。
2. 检查现有 Migration、Model、Enum、Service、Controller。
3. 不得自行修改字段、类型、默认值和索引。
4. 不创建数据库物理外键。
5. `seo_meta` 使用整数类型的逻辑多态关联。
6. `home_blocks.content` 必须按 type 校验。
7. `settings` 禁止保存敏感配置。
8. settings key 必须使用统一命名规范。
9. 配置更新后必须清理缓存。
10. 首页对象解析必须批量查询，禁止 N+1。
11. Dcat Controller 只负责页面配置并调用 Service。
12. rich_text、链接和 schema_data 必须做安全校验。
13. home_blocks 使用软删除。
14. seo_meta 和 settings 不使用软删除。
15. 不修改无关模块。
16. 完成后输出文件清单、命令和风险点。

---

# 二十一、开发验收清单

- [ ] 3 张表 Migration 可执行和回滚
- [ ] `seo_meta` 对象语言唯一约束正确
- [ ] `home_blocks` code + locale 唯一
- [ ] `settings.key` 唯一
- [ ] 无数据库物理外键
- [ ] SEO 回退逻辑正确
- [ ] canonical 生成正确
- [ ] OG 图片关系正确
- [ ] schema_data 结构校验正确
- [ ] 首页区块时间控制正确
- [ ] 首页区块 JSON 按类型校验
- [ ] 首页对象批量查询，无 N+1
- [ ] settings 类型转换正确
- [ ] 敏感配置无法保存
- [ ] 前端公开配置经过白名单
- [ ] 配置修改后缓存清理
- [ ] home_blocks 支持软删除
- [ ] seo_meta 和 settings 不使用软删除
- [ ] Dcat 表单与字段一致
- [ ] Seeder 可重复执行

---

# 二十二、设计结论

HairHub V1.0 SEO 与运营模块采用：

```text
seo_meta
+
home_blocks
+
settings
```

该结构能够满足：

- 页面级 SEO 管理
- 多业务对象 SEO 复用
- 多语言预留
- 首页区块运营
- Banner 和推荐内容配置
- 系统低频配置
- Dcat Admin 后台管理
- 缓存和前端配置输出

V1.0 优先保证 SEO 数据独立、首页运营灵活、配置读取高效和敏感信息安全，不提前引入复杂页面搭建器、广告系统和 SEO 监控系统。
