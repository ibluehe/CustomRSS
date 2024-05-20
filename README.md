## CustomRSS for Typecho

CustomRSS是Typecho首发生成rss.xml的RSS订阅插件，显示有分类、标签、正确显示作者信息、获取自定义字段的文章描述、获取自定义字段的文章头图 URL等，解决一些聚合平台无法读取Typecho的Feed问题。例如：积薪。

插件发布页：https://bluehe.cn/archives/rss-typecho

作者：蓝河

作者网站：https://bluehe.cn/ 

最新版本: 1.0.1

## 效果预览



## 使用方法

- 1、插件上传到typecho的plugins插件目录。

- 2、登录 Typecho 后台，进入“插件管理”页面，找到 CustomRSS 插件并激活它。

- 3、验证 RSS 文件，激活插件后，在浏览器中访问 http://yourdomain.com/rss.xml 以确保生成的 RSS 文件正确无误。

- 4、在你的 Typecho 模板目录（通常位于 usr/themes/你的主题名）中找到并编辑模板的头部文件，通常是 header.php 或类似名称的文件。在 <head> 部分增加站点地图和新的RSS订阅(自行修改信息):

<link rel="alternate" type="application/rss+xml" title="云心怀鹤 RSS Feed" href="https://bluehe.cn/rss.xml">

<link rel="sitemap" type="application/xml" title="站点地图" href="<?php $this->options->siteUrl(); ?>sitemap.xml" />


- 5、通过这些步骤，你的 Typecho 博客就成功包含了站点地图链接，使搜索引擎能够更好地抓取和索引你的网站内容。

### 更新rss.xml

> 注意：已安装旧版本的 **请先禁用插件！** 在更新文件后再启用。

------

## 支持

如果本插件帮到了你，不妨给点赞赏鼓励一下作者 ^-^

<img width="150" height="150" src="https://raw.githubusercontent.com/holmesian/Typecho-AMP/dev/alipay.jpg">
<img width="150" height="150" src="https://raw.githubusercontent.com/holmesian/Typecho-AMP/dev/wechat.jpg">

## 更新记录

2024.05.19 发布插件

2024.05.21 新增获取自定义字段的文章描述、获取自定义字段的文章头图 URL、正确显示作者信息，去除固定作者信息，添加 lastBuildDate 标签：使用 date(DATE_RSS) 来生成当前时间并添加到 RSS 文件中，以满足 slash:comments 标签的要求。

## 开源许可协议

GPL-3.0-or-later