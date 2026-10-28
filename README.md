# CustomRSS for Typecho

CustomRSS 是 Typecho 首发生成 `rss.xml` 的 RSS 订阅插件：正确显示分类、标签与作者信息，
支持获取自定义字段的文章描述与头图 URL，同时解决部分聚合平台无法读取 Typecho Feed 的问题。

在此之上，本增强版进一步支持 **RSS 2.0 / Atom 1.0 / JSON Feed 1.1** 三种输出，
并补齐了密码文章过滤、永久链接自动修正、表情与屏蔽词过滤、分类黑名单、封面图智能提取、
发文即重建、永久不变的 GUID 等能力。

| 项目 | 信息 |
| --- | --- |
| 作者 | 云心怀鹤 |
| 作者网站 | https://bluehe.cn/ |
| 插件发布页 | https://bluehe.cn/archives/rss-typecho |
| 最新版本 | 2.3.2 |
| 开源许可 | GPL-3.0-or-later |

### 运行环境要求

| 依赖 | 要求 | 说明 |
| --- | --- | --- |
| PHP | **5.6 ~ 8.4**（推荐 7.4+） | 代码未使用任何 PHP 7+ 专有语法，向下兼容到 5.6；已验证兼容 PHP 8.0 / 8.1 / 8.2 / 8.3 / 8.4 |
| Typecho | **1.2.0 及以上** | ⚠️ 关键：PHP 8 兼容性取决于 Typecho 核心，**Typecho 1.1 及更早版本在 PHP 8 下本身就会报错**，与插件无关 |
| 扩展 | `mbstring`（非强制） | 摘要截取优先使用 `mb_*`；**未安装时自动降级**为字符级实现，不会报错（v2.3.1 起） |
| 目录权限 | 站点根目录可写 | 需写入 `rss.xml` / `atom.xml` / `feed.json` |

> **关于 PHP 8 的说明**：插件主体不使用任何在 PHP 8 中被移除或弃用的语法
> （无 `create_function`、`each()`、字符串花括号偏移 `$str{0}`、反序 `implode()`、
> `${var}` 字符串插值、未定义裸常量、可选参数前置等），比较运算全部使用 `===` 严格比较，
> 不受 PHP 8 松散比较语义变更影响。内置的 Parsedown 已升级至 **1.8.0 正式版**，
> 该版本使用 `?array` 显式可空类型声明，避免了 PHP 8.4 的「隐式 nullable 参数」弃用警告。

---

## 效果预览

### 符合 Feed Validation Service 标准

![feed](https://github.com/ibluehe/CustomRSS/assets/170248713/64b4914d-d112-44aa-88eb-b81675e6ca57)

### Agr Reader 订阅

<div style="display: flex; justify-content: space-between;">
  <img src="https://github.com/ibluehe/CustomRSS/assets/170248713/16947820-e31e-44d5-8a54-14fb20ba846f" alt="图片1" style="width: 45%;">
  <img src="https://github.com/ibluehe/CustomRSS/assets/170248713/81c56cfb-dd79-4a78-aab2-d2ac93ee3b13" alt="图片2" style="width: 45%;">
</div>

---

## 一、安装 / 升级

1. 将整个目录上传到 Typecho 插件目录：
   ```
   usr/plugins/CustomRSS/Plugin.php
   usr/plugins/CustomRSS/Parsedown.php
   ```
2. 登录 Typecho 后台 →「控制台 → 插件」，找到 **CustomRSS** 并启用。
3. 进入插件设置按需配置（见「设置项速查」）。
4. 验证订阅文件：访问一次前台任意页面，或直接发布一篇文章，文件即生成：
   - `https://你的域名/rss.xml`
   - `https://你的域名/atom.xml`
   - `https://你的域名/feed.json`

> **更新 rss.xml 时请注意：请先禁用插件，再重新启用。** 升级版本后同样建议这样操作一次，确保新挂载的钩子生效。
>
> 缺失 `Parsedown.php` 时插件会自动降级为内置简易 Markdown 解析，不会报错。

### 让订阅器自动发现订阅源

**v2.3.0 起插件默认自动输出**（设置项「订阅源自动发现」），前台 `<head>` 会自动带上对应格式的 `<link rel="alternate">`，
浏览器、RSS 阅读器、AI 抓取器都能自动找到订阅地址，**不用再改主题**。
若你的主题 `header.php` 里已经手写过这些标签，请在插件设置里关掉以免重复。

如需手动指定，可在主题目录（通常是 `usr/themes/你的主题名`）的 `header.php` 的 `<head>` 内加入：

```html
<link rel="alternate" type="application/rss+xml" title="云心怀鹤 RSS Feed" href="https://bluehe.cn/rss.xml">
<link rel="alternate" type="application/atom+xml" title="Atom" href="<?php $this->options->siteUrl(); ?>atom.xml" />
<link rel="alternate" type="application/feed+json" title="JSON Feed" href="<?php $this->options->siteUrl(); ?>feed.json" />
<link rel="sitemap" type="application/xml" title="站点地图" href="<?php $this->options->siteUrl(); ?>sitemap.xml" />
```

加上站点地图链接，能让搜索引擎更好地抓取和索引你的站点。

---

## 二、功能总览

### 1. 跳过密码保护文章
默认开启。查询时多取 `数量 × 3`（至少 30）条，过滤掉 `password` 非空的文章后再截取到设定数量，**保证条数满额**。

### 2. 修复文章链接 404 / `Path '/{year}/{month}/{day}/1283.html' not found`
旧代码 `Typecho_Router::url('post', $row, $options->siteUrl)` 有两个坑：

| 问题 | 原因 | 处理 |
| --- | --- | --- |
| 未开伪静态时链接 404 | 生成的 URL 缺少 `index.php` 前缀 | `链接模式` 可选「自动识别 / 强制带 index.php / 强制不带」 |
| `/{year}/{month}/{day}/1283.html` 找不到 | 数据库行里没有 `year/month/day/category/directory` 键 | 由 `created` 时间戳与文章分类实时计算后再替换 |
| slug / .html 后缀不一致 | 站点自定义路径读取失败 | 自动读取站点永久链接设置，也可后台手填 |

自动逻辑：读取路由表后把 `[cid:digital]`、`[slug:string]` 统一归一化为 `{cid}`、`{slug}`，
因此 `/archives/{slug}`、`/archives/{slug}.html`、`/{year}/{month}/{day}/{slug}.html`、`/archives/{cid}/` 全部兼容。

### 3. 永久不变的 GUID（v2.2.0，推荐默认开启）
GUID 是订阅器判断「这是不是一篇新文章」的唯一依据。旧版直接用文章链接做 GUID，
**一旦你修改永久链接风格（例如从 `/archives/1/` 换成 `/archives/slug.html`），
所有 GUID 全部变化，订阅者会被整站重新推送一遍旧文章。**

现默认改用 RFC 4151 的 tag URI 形式，三种输出格式共用：

```
tag:bluehe.cn,2024:1283
    ↑域名      ↑发布年份  ↑文章ID
```

域名、发布年份、文章 ID 三者都永不改变，因此以后无论怎么改永久链接、开不开伪静态、换不换主题，都不会重复推送。
后台可切回「沿用文章链接」的旧行为（一般不推荐）。

> **切换说明：** 从「沿用文章链接」切到「永久 ID」时，会触发**一次性**的重复推送，
> 数量仅等于订阅源里现有的文章数（默认 10 篇），之后永久稳定。选一次就好，别来回切。

### 4. 过滤 Mirages / OwO 表情
默认开启，清除 `(嘿嘿@(吐舌))`、`(滑稽@(阴险))` 这类占位符，兼容 `&#40;...&#41;` 实体形式。
可选「同时移除系统 Emoji」（🙂 🎉 等 4 字节字符）。

### 5. 后台自定义屏蔽词
每行一个，支持两种写法：
```
photos
/\\[\s*photos\s*\][\s\S]*?\[\s*\/\s*photos\s*\]/
```
- 普通文本：直接删除
- `/.../` 包裹：按正则删除

另保留独立的「移除 `[photos]...[/photos]` 与 `[cid="x"]`」开关：摘要中整段删除，正文保留内部图片 URL。

### 6. 输出模式
- **摘要 + 全文**（默认）：`<description>` 摘要，`<content:encoded>` 全文
- **仅摘要**：只输出 `<description>`，三种格式体积都大幅减小

摘要优先取自定义字段 `postjj`，没有则截取正文纯文本，字数后台可调（默认 128）。

### 7. 封面图两种模式
后台「封面图来源」三选一：

| 模式 | 行为 |
| --- | --- |
| **智能**（推荐，默认） | 优先用 `banner` 自定义字段，没有时自动取正文第一张图 |
| **仅 banner 字段** | 后台自定义字段填图片链接；没填就不输出 |
| **仅正文首图** | 忽略 banner，一律从正文提取第一张图 |

输出字段：`enclosure` + `media:content` + `media:thumbnail`（RSS/Atom）、`image`（JSON Feed）。
Feedly、Inoreader 会用它做缩略图。**相对路径会自动补全为带域名的绝对地址**。

### 8. 发文即自动重建
挂载 `finishPublish` / `finishSave` / `finishDelete` 钩子：发布、保存、删除文章后立刻重建订阅文件，
不再依赖"有人访问前台才生成"。整个过程 try/catch 包裹，任何异常都不会打断你发文。
因此可以放心把「生成缓存」设为 3600。

### 9. 频道信息补全

| 字段 | 设置项 | 说明 |
| --- | --- | --- |
| `<image>` / `<icon>` | 站点 Logo 地址 | Feedly 显示站点图标 |
| `<copyright>` / `<rights>` | 版权声明 | 留空自动用「© 年份 站点标题」 |
| `<managingEditor>` | 编辑者邮箱 | 格式 `you@example.com (名字)` |
| `<ttl>` | 刷新间隔 | 默认 60 分钟 |

### 10. 分类 / 标签黑名单
「排除的分类 / 标签」每行一个，填**名称**或**缩略名**均可，命中的文章不进订阅源。
例如填「随笔」「日记」，这两类文章就不会出现在 RSS 里。

### 11. 多格式输出
后台勾选即可同时生成：

| 格式 | 文件 | 规范 |
| --- | --- | --- |
| RSS 2.0 | `rss.xml`（文件名可改） | content / dc / slash / atom / media 命名空间 |
| Atom 1.0 | `atom.xml` | 含 `published` / `updated` / `author`，正文按规范转义 |
| JSON Feed 1.1 | `feed.json` | 含 `content_html` / `date_modified` / `tags` / `image` |

### 12. 摘要智能断句
截取摘要时会向前回溯 24 个字符，优先在 `。！？!?…；;` 处断开；英文场景退而在空格处断开；
实在找不到断点才硬切并补 `...`。不再出现半个词、半个句子的摘要。

### 13. 订阅源自动发现（v2.3.0）
默认开启。前台 `<head>` 自动输出已启用格式对应的 `<link rel="alternate">` 标签，
浏览器地址栏、RSS 阅读器、AI 抓取器都能自动识别订阅地址，无需手动修改主题文件。
主题里已手写过的话，在插件设置里关掉即可。

---

## 三、其他改进

- **订阅源生成失败不再拖垮前台**：所有钩子回调全程 try/catch（含 PHP 7+ 的 `Throwable`），
  生成出错只会静默跳过，绝不会让页面白屏或打断发文。
- **Windows 服务器写入修复**：`rename()` 在 Windows 上无法覆盖已存在文件，
  现改为先删除再重命名，失败时退回直接写入，保证订阅源不会停在旧版本。
- **正则失败保护**：所有 `preg_replace` 走安全包装，遇到非法 UTF-8 等导致正则失败时返回原值，
  不会再出现「整篇正文被替换成空」的情况。
- **Markdown 解析次数减半**：摘要与正文文本相同时（未开启短代码清理）只解析一次；
  「仅摘要 + 封面图不取正文」时干脆不解析正文。
- **批量查询**：分类标签、自定义字段、评论数、作者信息各一次查询完成，10 篇文章从约 40 次查询降到约 6 次。
- **原子写入**：先写 `xxx.tmp` 再 `rename`，避免订阅器读到半个文件。
- **CDATA 安全**：正文中的 `]]>` 安全转义，非法控制字符自动剔除。
- **摘要安全**：摘要统一按纯文本转义后输出，避免正文标签逃逸进订阅源。
- **降级兼容**：无 Parsedown 时用内置简易 Markdown 解析；钩子回调全程静默，不影响后台操作。
- **卸载不留残留**：禁用插件时会连同自定义文件名的订阅文件一起清理。

---

## 四、设置项速查

| 设置项 | 默认值 | 说明 |
| --- | --- | --- |
| 文章数量 | 10 | 不含密码文章与黑名单分类文章 |
| RSS 输出模式 | 摘要 + 全文 | 或「仅摘要」 |
| 摘要字数 | 128 | 无 `postjj` 字段时生效，智能断句 |
| **文章唯一标识（GUID）** | **永久 ID** | `tag:域名,年份:ID`；可切回沿用链接 |
| 输出格式 | RSS | 可同时勾 Atom、JSON Feed |
| RSS 文件名 | rss.xml | Atom 固定 atom.xml，JSON 固定 feed.json |
| 链接模式 | 自动识别 | 404 时改「强制带 index.php」 |
| 自定义文章路径 | 留空 | 留空 = 跟随站点永久链接设置 |
| 封面图来源 | 智能 | banner 字段 / 正文首图 / 智能兜底 |
| 站点 Logo 地址 | 空 | 留空不输出 `<image>` |
| 版权声明 | 空 | 留空自动「© 年份 站点标题」 |
| 编辑者邮箱 | 空 | `you@example.com (名字)` |
| 建议刷新间隔 | 60 分钟 | |
| 密码保护文章 | 跳过 | |
| 排除的分类 / 标签 | 空 | 名称或缩略名，每行一个 |
| 表情过滤 | 开启 | `(嘿嘿@(吐舌))` 形式 |
| 系统 Emoji | 关闭 | 4 字节 emoji |
| 屏蔽词 | 空 | 每行一个，支持正则 |
| 短代码清理 | 开启 | `[photos]` / `[cid="x"]` |
| 订阅源自动发现 | 开启 | 前台 head 自动输出 alternate 标签 |
| 生成缓存 | 0 秒 | 建议 3600（发文本就会自动重建） |

---

## 五、常见问题

**Q：改了设置后文件没变化？**
A：缓存未过期。临时把「生成缓存」设为 0，或删掉站点根目录的 `rss.xml` 后刷新前台；也可以直接改一篇文章触发重建。
（别忘了老规矩：**先禁用插件，再重新启用**。）

**Q：开启了伪静态但链接还是 404？**
A：服务器 rewrite 规则没生效。把「链接模式」设为「强制带 index.php」即可绕过。

**Q：RSS 里图片裂了？**
A：正文图片是站点相对路径（`/usr/uploads/...`），部分阅读器无法解析。目前封面图（enclosure / media）已自动绝对化；
正文内图片如需全部绝对化，可在主题层处理。

**Q：切换到「永久 ID」后，订阅者会不会收到一堆重复推送？**
A：会，但**只有一次**，数量等于订阅源里现有的文章数（默认 10 篇）。
这是从"链接即 ID"迁移到"永久 ID"必须付的一次性代价，付完就永久免疫改链接风格带来的重复推送。**越早切越好。**
切完请务必不要再切回去，来回切会反复触发。

**Q：以后换域名，GUID 会变吗？**
A：会。tag URI 规范（RFC 4151）要求 ID 里包含域名以保证全球唯一，换域名时一次性重复推送无法避免。
这一点上「永久 ID」和「沿用文章链接」两种模式表现一致。

**Q：想让订阅器显示全文？**
A：输出模式选「摘要 + 全文」，`<content:encoded>` 是标准全文字段，绝大多数阅读器都支持。

---

## 六、版本记录

- **v2.3.2**（2026.10.28）频道 `<language>` 由硬编码 `zh-cn` 改为读取 Typecho 语言设置并按 RFC 5646
  规范化输出（如 `zh-CN`、`en-US`）；`<enclosure length>` 由固定的 `0` 改为读取站内图片真实字节数
  （远程图床仍为 0，绝不发起网络请求），满足 RSS 2.0 规范对 length 的要求，消除严格验证器警告。
- **v2.3.1**（2026.10.28）PHP 8 兼容性确认与加固：内置 Parsedown 由 `1.8.0-beta-7` 升级至 **`1.8.0` 正式版**，
  该版本使用 `?array` 显式可空类型声明，消除 PHP 8.4「隐式 nullable 参数」弃用警告；
  新增 UTF-8 字符串工具层，未安装 mbstring 扩展时自动降级（原为直接 fatal）；
  补充运行环境要求说明（PHP 5.6~8.4，Typecho 需 1.2.0 及以上）。
- **v2.3.0**（2026.10.28）新增订阅源自动发现标签；所有钩子全程异常兜底（生成失败不再导致前台白屏）；
  修复 Windows 服务器 `rename()` 无法覆盖旧文件导致订阅源不更新的问题；正则失败保护；
  Markdown 解析次数减半；禁用插件时清理自定义文件名的残留文件；作者署名统一为云心怀鹤。
- **v2.2.0**（2026.10.28）文章唯一标识改用永久不变的 `tag:域名,年份:文章ID`（RFC 4151），
  彻底解决修改永久链接风格后订阅器整站重复推送的问题；三种输出格式共用同一 GUID，后台可切回旧行为。
- **v2.1.0**（2026.10.28）多格式输出（Atom 1.0 / JSON Feed 1.1）、发文即自动重建、封面图双模式、
  频道信息补全（image / copyright / ttl / managingEditor）、分类标签黑名单、摘要智能断句、批量查询优化。
- **v2.0.0**（2026.10.28）跳过密码保护文章、永久链接 404 修复、Mirages/OwO 表情过滤、
  后台屏蔽词（支持正则）、摘要 / 全文输出模式切换、生成缓存、原子写入与 CDATA 安全转义。
- **v1.3.2** 增强版：`[photos]` / `[cid="x"]` 短代码清理、图片 alt 与响应式样式处理、
  Parsedown 解析、自定义字段描述与头图、分类标签输出。
- **v1.0.3**（2025.01.03）解决第一行 Markdown 没有被正确解析的问题。
- **v1.0.2**（2024.08.17）确保仅当 Parsedown 类不存在时才会加载 Parsedown.php，避免重复加载与类冲突，方式更简洁且功能完备。
- **v1.0.1**（2024.05.20）新增获取自定义字段的文章描述、获取自定义字段的文章头图 URL、正确显示作者信息（去除固定作者信息），
  添加 `lastBuildDate` 标签：使用 `date(DATE_RSS)` 生成当前时间并写入 RSS，以满足 `slash:comments` 标签的要求。
- **v1.0.0**（2024.05.19）发布插件。

---

## 七、支持

<div style="display: flex; justify-content: space-between;">
  <img src="https://github.com/ibluehe/CustomRSS/assets/170248713/20a886a8-13a5-4469-a664-9157d6e21cfb" alt="图片1" style="width: 45%;">
  <img src="https://github.com/ibluehe/CustomRSS/assets/170248713/9a722d82-b745-4d60-821b-9fd273fa605e" alt="图片2" style="width: 45%;">
</div>

如果本插件帮到了你，不妨赞赏鼓励一下作者 ^-^

---

## 八、开源许可协议

GPL-3.0-or-later
