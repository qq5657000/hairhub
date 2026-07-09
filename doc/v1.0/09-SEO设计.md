# HairHub V1.0 SEO 设计

> Version: 1.0.0
> Last Update: 2026-07-09
> Document Path: `docs/v1.0/09-SEO设计.md`

---

# 一、SEO 目标

HairHub V1.0 的 SEO 目标：

> 通过发型、发色、文章、视频、专题等内容资产，持续获取搜索引擎自然流量。

SEO 不是附加功能，而是 HairHub 的核心增长引擎。

---

# 二、SEO 页面范围

V1.0 需要重点优化以下页面：

```text
首页

发型列表页

发型详情页

发色列表页

发色详情页

文章列表页

文章详情页

视频列表页

视频详情页

专题页
```

---

# 三、URL 设计

## 3.1 URL 原则

URL 设计遵循：

```text
简短

稳定

可读

包含关键词

不频繁修改
```

---

## 3.2 路由建议

```text
/

/ai/hairstyle

/ai/hair-color

/hairstyles

/hairstyles/{slug}

/hair-colors

/hair-colors/{slug}

/articles

/articles/{slug}

/videos

/videos/{slug}

/topics

/topics/{slug}
```

---

## 3.3 Slug 规范

Slug 使用英文、小写、短横线。

例如：

```text
korean-short-hair

round-face-hairstyles

brown-hair-color

first-time-hair-dye
```

不建议使用：

```text
中文URL

空格

下划线

特殊符号

过长URL
```

---

# 四、Meta 信息设计

## 4.1 Title

Title 要包含页面核心关键词。

首页示例：

```text
HairHub 发型中心 - AI换发型、AI换发色、发型设计与发型推荐
```

发型详情页示例：

```text
韩系短发适合什么脸型？韩系短发效果图与打理方法 - HairHub
```

文章详情页示例：

```text
圆脸男生适合什么发型？显脸小男生发型推荐 - HairHub
```

---

## 4.2 Description

Description 用于概括页面内容。

示例：

```text
HairHub 提供 AI 换发型、AI 换发色、发型图库、发色推荐和发型知识内容，帮助你在剪发、染发前提前看到适合自己的样子。
```

---

## 4.3 Keywords

V1.0 可以保留 keywords 字段，但不作为核心优化重点。

---

# 五、页面标题结构

每个页面必须只有一个 H1。

推荐结构：

```text
H1：页面核心标题

H2：主要内容分区

H3：细分内容标题
```

发型详情页示例：

```text
H1：韩系短发

H2：韩系短发适合什么脸型？

H2：韩系短发适合哪些人？

H2：韩系短发怎么打理？

H2：相关推荐
```

---

# 六、Canonical

所有可收录页面需要设置 canonical。

示例：

```html
<link rel="canonical" href="https://www.aihairhub.com/hairstyles/korean-short-hair">
```

避免：

```text
同一内容多个URL

带参数URL被重复收录

分页造成重复内容
```

---

# 七、分页 SEO

列表页分页建议：

```text
/hairstyles?page=2

/articles?page=3
```

分页页面保留 canonical 指向当前页。

不要全部指向第一页。

分页页面 Title 可增加页码：

```text
发型图库 - 第2页 - HairHub
```

---

# 八、图片 SEO

HairHub 是图片密集型网站，图片 SEO 非常重要。

每张核心图片应包含：

```text
alt

width

height

合理文件名

缩略图

懒加载
```

---

## 8.1 图片 alt

示例：

```text
韩系短发女生发型效果图

圆脸男生短发推荐

亚麻棕发色效果图
```

不要使用：

```text
image

photo

1.jpg

未命名
```

---

## 8.2 图片文件名

建议：

```text
korean-short-hair-woman.jpg

round-face-men-short-hair.jpg

ash-brown-hair-color.jpg
```

---

## 8.3 缩略图

列表页使用：

```text
thumbnail_url
```

详情页使用：

```text
url
```

避免首页和列表页加载大图。

---

# 九、内链设计

内链是 HairHub SEO 的关键。

页面之间应形成内容网络。

---

## 9.1 发型详情页内链

推荐链接：

```text
同分类发型

相同脸型推荐

相同风格标签

相关文章

AI试发型
```

---

## 9.2 文章详情页内链

推荐链接：

```text
相关发型

相关发色

相关视频

相关专题

AI换发型入口
```

---

## 9.3 专题页内链

专题页聚合：

```text
发型

发色

文章

视频
```

专题页是重要 SEO 落地页。

---

# 十、结构化数据 JSON-LD

V1.0 建议支持：

```text
WebSite

BreadcrumbList

Article

VideoObject

CollectionPage

ImageObject
```

---

## 10.1 WebSite

首页使用。

用于描述站点信息。

---

## 10.2 BreadcrumbList

所有详情页使用。

例如：

```text
首页 > 发型图库 > 韩系短发
```

---

## 10.3 Article

文章详情页使用。

包含：

```text
headline

description

image

author

datePublished

dateModified
```

---

## 10.4 VideoObject

视频详情页使用。

包含：

```text
name

description

thumbnailUrl

uploadDate

duration
```

---

## 10.5 CollectionPage

列表页和专题页使用。

---

# 十一、Sitemap

## 11.1 sitemap.xml

V1.0 生成：

```text
/sitemap.xml
```

包含：

```text
首页

发型详情页

发色详情页

文章详情页

视频详情页

专题页
```

---

## 11.2 后续拆分

内容增多后可拆分：

```text
/sitemap-index.xml

/sitemap-hairstyles.xml

/sitemap-hair-colors.xml

/sitemap-articles.xml

/sitemap-videos.xml

/sitemap-topics.xml
```

---

# 十二、robots.txt

建议：

```text
User-agent: *
Allow: /

Disallow: /admin
Disallow: /api/
Disallow: /storage/uploads/user/
Disallow: /storage/uploads/ai/

Sitemap: https://www.aihairhub.com/sitemap.xml
```

用户上传图片和 AI 临时结果不建议默认收录。

---

# 十三、AI 生成内容 SEO 策略

V1.0 不建议把用户上传图片和 AI 生成结果作为公开 SEO 页面。

原因：

```text
图片质量不可控

可能涉及用户隐私

内容重复度高

不利于稳定SEO
```

AI 工具页面可以收录。

用户个人生成结果页默认不收录。

---

# 十四、文章 SEO 策略

文章应围绕长尾关键词写作。

示例关键词：

```text
圆脸适合什么发型

男生发量少适合什么发型

第一次染发选什么颜色

戴眼镜男生发型推荐

儿童运动发型怎么扎
```

文章结构建议：

```text
标题包含关键词

开头直接回答问题

正文分小标题

每段配图

加入相关推荐

结尾引导AI工具和微信生态
```

---

# 十五、专题 SEO 策略

专题页适合承接高价值关键词。

例如：

```text
2026女生短发推荐

男生发型大全

圆脸发型推荐

第一次染发指南

儿童发型大全
```

专题页内容应聚合：

```text
发型卡片

发色卡片

相关文章

视频教程

AI工具入口
```

---

# 十六、首页 SEO 策略

首页重点关键词：

```text
AI换发型

AI换发色

发型设计

发型推荐

发型图库

发色推荐
```

首页不要堆砌关键词。

应自然展示：

```text
AI工具入口

热门发型

热门发色

最新文章

最新视频

二维码转化
```

---

# 十七、404 页面

需要自定义 404 页面。

内容包括：

```text
页面不存在提示

返回首页

AI换发型入口

热门发型推荐

热门文章推荐
```

不要使用默认空白 404。

---

# 十八、性能 SEO

页面性能影响收录和用户体验。

V1.0 重点优化：

```text
图片压缩

缩略图

懒加载

减少首屏请求

缓存热门数据

避免N+1查询
```

---

# 十九、SEO 后台管理

后台需要支持：

```text
SEO Title

SEO Description

Keywords

Canonical

Slug
```

覆盖：

```text
发型

发色

文章

视频

专题
```

如果未填写 SEO 字段，则使用默认模板自动生成。

---

# 二十、SEO 开发优先级

## 第一优先级

```text
URL Slug

Title

Description

H1

图片 Alt

Canonical

Sitemap

robots.txt
```

---

## 第二优先级

```text
Breadcrumb

JSON-LD

内链推荐

专题页
```

---

## 第三优先级

```text
高级SEO后台

Sitemap拆分

SEO数据统计

多语言SEO
```

---

# 二十一、SEO 总结

HairHub SEO 的核心不是技术堆叠，而是持续产出高质量内容。

V1.0 SEO 闭环：

```text
发型/发色数据

↓

文章/视频内容

↓

专题聚合

↓

搜索引擎收录

↓

自然搜索流量

↓

AI工具体验

↓

微信生态转化
```

最终目标：

> 让 HairHub 通过长期内容资产积累，持续获得稳定自然流量。
