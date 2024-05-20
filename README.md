# CustomRSS

插件介绍：首发Typecho生成rss.xml的RSS订阅，显示有分类、标签、作者信息、描述自定义获取字符等，解决一些聚合平台无法读取Typecho的Feed，例如积薪。

插件发布页：https://bluehe.cn/archives/rss-typecho

作者：蓝河

作者网站：https://bluehe.cn/ 

最新版本: 1.0.0

# 使用方法

1、插件上传到typecho的plugins插件目录。

2、打开Plugin.php，在 // 固定作者信息 $author = 'hi@bluehe.cn(蓝河)', 修改为你自己的信息保存。

3、登录 Typecho 后台，进入“插件管理”页面，找到 CustomRSS 插件并激活它。

4、验证 RSS 文件，激活插件后，在浏览器中访问 http://yourdomain.com/rss.xml 以确保生成的 RSS 文件正确无误。

5、在你的 Typecho 模板目录（通常位于 usr/themes/你的主题名）中找到并编辑模板的头部文件，通常是 header.php 或类似名称的文件。在 <head> 部分增加站点地图和新的RSS订阅(自行修改信息):

<link rel="alternate" type="application/rss+xml" title="云心怀鹤 RSS Feed" href="https://bluehe.cn/rss.xml">

<link rel="sitemap" type="application/xml" title="站点地图" href="<?php $this->options->siteUrl(); ?>sitemap.xml" />


6、通过这些步骤，你的 Typecho 博客就成功包含了站点地图链接，使搜索引擎能够更好地抓取和索引你的网站内容。

# 授权协议

采用知识共享CC BY-NC-ND 4.0 DEED协议进行许可。仅供学习参考，作者保留所有权利。 

授权介绍: https://creativecommons.org/licenses/by-nc-sa/4.0/deed.zh-hans
