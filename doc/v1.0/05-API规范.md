# HairHub V1.0 API 设计规范

> Version: 1.0.0
> Last Update: 2026-07-08

---

# 一、设计目标

HairHub API 是整个系统唯一的数据入口。

所有客户端统一调用 API。

包括：

- Web
- H5
- 微信小程序
- 后续 App
- 后续开放平台

Controller 只负责请求处理。

业务逻辑统一放入 Service。

---

# 二、API设计原则

遵循：

- RESTful
- JSON
- 无状态（Stateless）
- Version First
- Easy To Understand

所有接口：

```

/api/v1/

```

---

# 三、统一返回格式

成功：

```json
{
    "code":0,
    "message":"success",
    "data":{}
}
```

失败：

```json
{
    "code":10001,
    "message":"Parameter Error",
    "data":null
}
```

分页：

```json
{
    "code":0,
    "message":"success",
    "data":{
        "list":[],
        "pagination":{
            "page":1,
            "page_size":20,
            "total":100,
            "total_page":5
        }
    }
}
```

---

# 四、状态码规范

```
0            成功

10000        参数错误

10001        数据不存在

10002        数据已存在

10003        上传失败

10004        AI任务失败

10005        AI服务异常

10006        微信接口异常

50000        系统异常
```

---

# 五、接口模块

```
API

├── 首页

├── AI

├── 发型

├── 发色

├── 文章

├── 视频

├── 专题

├── SEO

└── System
```

---

# 六、首页接口

## 首页数据

GET

```
/api/v1/home
```

返回：

```
Banner

AI入口

热门发型

热门发色

最新文章

最新视频

推荐内容
```

---

# 七、AI接口

## AI生成

POST

```
/api/v1/ai/generate
```

参数：

```
type

image

target_id
```

返回：

```
task_no

status
```

---

## 查询任务

GET

```
/api/v1/ai/tasks/{task_no}
```

返回：

```
任务状态

结果图片
```

---

## 获取支持模型（预留）

GET

```
/api/v1/ai/providers
```

---

# 八、发型接口

## 分类

GET

```
/api/v1/hairstyle/categories
```

---

## 分类详情

GET

```
/api/v1/hairstyle/categories/{id}
```

---

## 发型列表

GET

```
/api/v1/hairstyles
```

支持：

```
category

tag

gender

face_shape

keyword

page
```

---

## 发型详情

GET

```
/api/v1/hairstyles/{id}
```

---

## 热门发型

GET

```
/api/v1/hairstyles/hot
```

---

# 九、发色接口

## 分类

GET

```
/api/v1/hair-color/categories
```

---

## 发色列表

GET

```
/api/v1/hair-colors
```

支持：

```
category

skin_color

keyword
```

---

## 发色详情

GET

```
/api/v1/hair-colors/{id}
```

---

## 推荐发色

GET

```
/api/v1/hair-colors/recommend
```

---

# 十、文章接口

## 文章列表

GET

```
/api/v1/articles
```

支持：

```
category

tag

keyword

page
```

---

## 文章详情

GET

```
/api/v1/articles/{id}
```

---

## 最新文章

GET

```
/api/v1/articles/latest
```

---

# 十一、视频接口

GET

```
/api/v1/videos
```

---

详情：

GET

```
/api/v1/videos/{id}
```

---

# 十二、专题推荐

GET

```
/api/v1/topics
```

详情：

GET

```
/api/v1/topics/{id}
```

---

# 十三、SEO接口（内部）

```
/api/v1/seo/*
```

用于：

- sitemap

- robots

- JSON-LD

生成。

---

# 十四、系统接口

网站配置：

GET

```
/api/v1/system/config
```

返回：

```
网站名称

Logo

首页配置

二维码

AI配置
```

---

# 十五、图片上传

POST

```
/api/v1/upload/image
```

返回：

```
url

thumbnail

width

height
```

支持：

- 自动压缩
- 自动生成缩略图
- 自动按日期目录存储

目录：

```
uploads/

2026/

07/

09/
```

---

# 十六、微信公众号（后台）

同步文章：

POST

```
/api/v1/wechat/articles/publish
```

查询状态：

GET

```
/api/v1/wechat/articles/status/{id}
```

---

# 十七、接口命名规范

统一：

```
名词

不用动词
```

例如：

```
GET

/articles

POST

/articles
```

只有：

AI

上传

微信同步

允许动作型接口：

```
generate

upload

publish

sync
```

---

# 十八、版本规划

当前：

```
/api/v1/
```

未来：

```
/api/v2/
```

保持兼容。

---

# 十九、未来预留接口

```
用户

收藏

评论

点赞

登录

AI历史

推荐

国际化
```

暂不实现。

---

# 二十、总结

HairHub API 遵循：

- RESTful
- JSON
- Version First
- Service Layer
- 前后端分离

API 只负责提供能力，不负责页面展示。

所有客户端共享同一套 API，保证系统长期可维护、可扩展。
