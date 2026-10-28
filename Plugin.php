<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 生成站点的 rss.xml / atom.xml / feed.json 文件（增强版）
 *
 * @package CustomRSS
 * @version 2.3.2
 * @author 云心怀鹤
 * @link https://bluehe.cn/
 * @license GPL-3.0-or-later
 *
 * 功能一览：
 *   1. 跳过密码保护文章
 *   2. 永久链接自动识别（伪静态 / index.php 前缀 / 自定义文章路径）
 *   3. 过滤 Mirages / OwO 表情占位符与系统 Emoji
 *   4. 后台自定义屏蔽词（支持正则）
 *   5. 输出模式：仅摘要 / 摘要 + 全文
 *   6. 封面图两种模式：banner 字段 / 正文自动提取首图（可智能兜底）
 *   7. 发文、改文、删文时自动重建，无需等待访问触发
 *   8. 频道信息补全：image / copyright / ttl / managingEditor
 *   9. 分类与标签黑名单过滤
 *  10. 多格式输出：RSS 2.0 / Atom 1.0 / JSON Feed 1.1
 *  11. 摘要智能断句，不在半个词处截断
 *  12. 永久不变的 GUID：tag:域名,年份:文章ID，改链接风格也不会重复推送
 *  13. 订阅源自动发现：前台 <head> 自动输出 alternate link，无需改主题
 */
class CustomRSS_Plugin implements Typecho_Plugin_Interface
{
    /** 插件名 */
    const PLUGIN_NAME = 'CustomRSS';

    /** 默认摘要长度 */
    const DEFAULT_EXCERPT_LENGTH = 128;

    /* ------------------------------------------------------------------ *
     * 插件生命周期
     * ------------------------------------------------------------------ */

    public static function activate()
    {
        // 前台访问时生成（兜底）
        Typecho_Plugin::factory('Widget_Archive')->footer = array('CustomRSS_Plugin', 'generateRSS');

        // 在 <head> 输出订阅源自动发现标签
        Typecho_Plugin::factory('Widget_Archive')->header = array('CustomRSS_Plugin', 'renderHeaderLinks');

        // 后台发布 / 保存 / 删除文章时立即重建
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('CustomRSS_Plugin', 'onContentChange');
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishSave    = array('CustomRSS_Plugin', 'onContentChange');
        Typecho_Plugin::factory('Widget_Abstract_Contents')->finishDelete   = array('CustomRSS_Plugin', 'onContentChange');
        Typecho_Plugin::factory('Widget_Contents_Post_Admin')->finishDelete = array('CustomRSS_Plugin', 'onContentChange');

        return _t('CustomRSS 已启用，请先在插件设置中配置参数，然后访问一次前台页面即可生成订阅文件。');
    }

    public static function deactivate()
    {
        // 删除默认文件名的三种格式
        @unlink(__TYPECHO_ROOT_DIR__ . '/rss.xml');
        @unlink(__TYPECHO_ROOT_DIR__ . '/atom.xml');
        @unlink(__TYPECHO_ROOT_DIR__ . '/feed.json');

        // 同时清理用户自定义过文件名的文件，避免残留失效订阅源
        try {
            $options = Typecho_Widget::widget('Widget_Options');
            $cfg = self::settings($options);
            foreach (self::targetFiles($cfg) as $file) {
                @unlink($file);
            }
        } catch (Exception $e) {
            // 配置读取失败时忽略，默认文件已删除
        }

        return _t('CustomRSS 已禁用，生成的订阅文件已删除。');
    }

    /* ------------------------------------------------------------------ *
     * 后台配置
     * ------------------------------------------------------------------ */

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        /** ---------- 基础 ---------- */
        $numOfPosts = new Typecho_Widget_Helper_Form_Element_Text(
            'numOfPosts',
            null,
            '10',
            _t('文章数量'),
            _t('设定生成订阅文件时包含的文章数量（密码保护文章、黑名单分类文章会被跳过，不计入其中）')
        );
        $form->addInput($numOfPosts->addRule('isInteger', _t('文章数量必须是整数')));

        $feedMode = new Typecho_Widget_Helper_Form_Element_Radio(
            'feedMode',
            array(
                'full'    => _t('摘要 + 全文（&lt;description&gt; 摘要，&lt;content:encoded&gt; 全文）'),
                'excerpt' => _t('仅摘要（只输出 &lt;description&gt;，不输出正文）')
            ),
            'full',
            _t('RSS 输出模式')
        );
        $form->addInput($feedMode);

        $excerptLength = new Typecho_Widget_Helper_Form_Element_Text(
            'excerptLength',
            null,
            (string) self::DEFAULT_EXCERPT_LENGTH,
            _t('摘要字数'),
            _t('正文无自定义摘要（postjj 字段）时自动截取的字数，会在句子边界智能断句')
        );
        $form->addInput($excerptLength->addRule('isInteger', _t('摘要字数必须是整数')));

        /** ---------- 输出格式 ---------- */
        $outputFormats = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'outputFormats',
            array(
                'rss'  => _t('RSS 2.0（rss.xml）'),
                'atom' => _t('Atom 1.0（atom.xml）'),
                'json' => _t('JSON Feed 1.1（feed.json）')
            ),
            array('rss'),
            _t('输出格式'),
            _t('可同时勾选多个，文件均生成在站点根目录')
        );
        $form->addInput($outputFormats);

        $fileName = new Typecho_Widget_Helper_Form_Element_Text(
            'fileName',
            null,
            'rss.xml',
            _t('RSS 文件名'),
            _t('相对于站点根目录，例如 rss.xml、feed.xml（Atom 固定为 atom.xml，JSON 固定为 feed.json）')
        );
        $form->addInput($fileName);

        /** ---------- 链接 ---------- */
        $linkMode = new Typecho_Widget_Helper_Form_Element_Radio(
            'linkMode',
            array(
                'auto'    => _t('自动识别（推荐：跟随站点「是否使用地址重写」设置）'),
                'noindex' => _t('强制不带 index.php（已开启伪静态）'),
                'index'   => _t('强制带 index.php（未开启伪静态 / 链接 404 时选此项）')
            ),
            'auto',
            _t('文章链接模式'),
            _t('若订阅文件中的文章链接打开是 404：<br />
                ① 站点「设置 → 永久链接」未开启地址重写 → 选「强制带 index.php」<br />
                ② 已开启伪静态但服务器未配置 rewrite 规则 → 也请选「强制带 index.php」')
        );
        $form->addInput($linkMode);

        $permalink = new Typecho_Widget_Helper_Form_Element_Text(
            'permalink',
            null,
            '',
            _t('自定义文章路径（留空 = 自动读取站点设置）'),
            _t('默认自动读取「设置 → 永久链接 → 自定义文章路径」。<br />
                若读取失败或想为订阅源单独指定，可在此填写，例如：<br />
                <code>/archives/{slug}.html</code>　<code>/archives/{slug}/</code>　<code>/archives/{cid}/</code>　<code>/{year}/{month}/{day}/{slug}.html</code><br />
                可用参数：{cid} {slug} {category} {directory} {year} {month} {day}')
        );
        $form->addInput($permalink);

        /** ---------- 唯一标识 ---------- */
        $guidMode = new Typecho_Widget_Helper_Form_Element_Radio(
            'guidMode',
            array(
                'tag'  => _t('永久 ID：tag:域名,年份:文章ID（推荐）'),
                'link' => _t('沿用文章链接（旧版行为）')
            ),
            'tag',
            _t('文章唯一标识（GUID）'),
            _t('GUID 是订阅器判断「这是不是一篇新文章」的唯一依据，三种输出格式共用。<br />
                用文章链接时，一旦你修改永久链接风格（例如从 <code>/archives/1/</code> 换成 <code>/archives/slug.html</code>），
                所有 GUID 都会变，订阅者那边会被<b>整站重新推送一遍旧文章</b>。<br />
                改用 tag: 形式的永久 ID 后，无论链接怎么改都不会重复推送。<br />
                <b>切换提示：从「沿用文章链接」切到「永久 ID」时，会触发一次性的重复推送
                （仅限订阅源里现有的那几篇），之后永久稳定。选一次就好，别来回切。</b>')
        );
        $form->addInput($guidMode);

        /** ---------- 封面图 ---------- */
        $imageSource = new Typecho_Widget_Helper_Form_Element_Radio(
            'imageSource',
            array(
                'auto'    => _t('智能（推荐）：优先用 banner 字段，没有时自动取正文首图'),
                'banner'  => _t('仅用 banner 字段（后台自定义字段填图片链接，没有就不输出）'),
                'content' => _t('仅从正文自动提取第一张图')
            ),
            'auto',
            _t('封面图来源'),
            _t('输出的 enclosure 与 media 标签会被 Feedly、Inoreader 等用作缩略图；<br />
                相对路径会自动补全为带域名的绝对地址')
        );
        $form->addInput($imageSource);

        /** ---------- 频道信息 ---------- */
        $siteLogo = new Typecho_Widget_Helper_Form_Element_Text(
            'siteLogo',
            null,
            '',
            _t('站点 Logo 地址'),
            _t('填写完整 URL，例如 https://example.com/logo.png。<br />
                留空则不输出 &lt;image&gt; 标签（订阅器会用默认图标）')
        );
        $form->addInput($siteLogo);

        $copyright = new Typecho_Widget_Helper_Form_Element_Text(
            'copyright',
            null,
            '',
            _t('版权声明'),
            _t('留空则自动使用「© 当前年份 站点标题」')
        );
        $form->addInput($copyright);

        $managingEditor = new Typecho_Widget_Helper_Form_Element_Text(
            'managingEditor',
            null,
            '',
            _t('编辑者邮箱'),
            _t('格式：you@example.com (你的名字)，留空则不输出')
        );
        $form->addInput($managingEditor);

        $ttl = new Typecho_Widget_Helper_Form_Element_Text(
            'ttl',
            null,
            '60',
            _t('建议刷新间隔（分钟）'),
            _t('订阅器多久来抓一次，一般 60 即可')
        );
        $form->addInput($ttl->addRule('isInteger', _t('刷新间隔必须是整数')));

        /** ---------- 过滤 ---------- */
        $skipPassword = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'skipPassword',
            array('1' => _t('跳过设置了访问密码的文章')),
            array('1'),
            _t('密码保护文章')
        );
        $form->addInput($skipPassword);

        $excludeMetas = new Typecho_Widget_Helper_Form_Element_Textarea(
            'excludeMetas',
            null,
            '',
            _t('排除的分类 / 标签'),
            _t('每行一个，填写分类或标签的「名称」或「缩略名」，命中的文章不会出现在订阅源中。<br />
                例如填「随笔」「日记」即可让这两类文章不进 RSS')
        );
        $form->addInput($excludeMetas);

        $removeEmoji = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'removeEmoji',
            array('1' => _t('移除主题表情占位符（如 (嘿嘿@(吐舌))）')),
            array('1'),
            _t('表情过滤'),
            _t('Mirages / OwO / Joe 等主题使用 <code>(文本@(表情))</code> 形式的表情，订阅器无法渲染，建议移除')
        );
        $form->addInput($removeEmoji);

        $removeSystemEmoji = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'removeSystemEmoji',
            array('1' => _t('同时移除系统 Emoji（🙂 🎉 等 4 字节字符）')),
            array(),
            _t('系统 Emoji')
        );
        $form->addInput($removeSystemEmoji);

        $blockWords = new Typecho_Widget_Helper_Form_Element_Textarea(
            'blockWords',
            null,
            '',
            _t('屏蔽词 / 屏蔽内容'),
            _t('每行一个，生成时自动从摘要和正文中删除。支持两种写法：<br />
                普通文本：<code>photos</code><br />
                正则表达式：用斜杠包裹，如 <code>/\\[\\s*photos\\s*\\][\\s\\S]*?\\[\\s*\\/\\s*photos\\s*\\]/</code><br />
                （留空则不屏蔽任何内容）')
        );
        $form->addInput($blockWords);

        $stripPhotos = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'stripPhotos',
            array('1' => _t('移除 [photos]...[/photos] 包裹标记与 [cid="x"] 标记')),
            array('1'),
            _t('短代码清理'),
            _t('摘要中删除整段内容，正文中保留内部图片 URL 仅去除包裹标记')
        );
        $form->addInput($stripPhotos);

        /** ---------- 性能与发现 ---------- */
        $autoDiscover = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'autoDiscover',
            array('1' => _t('在前台页面 &lt;head&gt; 中自动输出订阅源发现标签')),
            array('1'),
            _t('订阅源自动发现'),
            _t('开启后无需手动修改主题，浏览器、RSS 阅读器就能自动识别订阅地址。<br />
                若你的主题 header.php 里已经手写过这些标签，请关闭以免重复输出。')
        );
        $form->addInput($autoDiscover);

        $cacheTTL = new Typecho_Widget_Helper_Form_Element_Text(
            'cacheTTL',
            null,
            '0',
            _t('生成缓存（秒）'),
            _t('0 = 每次访问都重新生成（默认，与旧版一致）。<br />
                发布 / 修改 / 删除文章时会自动重建，因此建议放心设为 3600（1 小时）')
        );
        $form->addInput($cacheTTL->addRule('isInteger', _t('缓存时间必须是整数')));
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    /* ------------------------------------------------------------------ *
     * 触发入口
     * ------------------------------------------------------------------ */

    /**
     * footer 钩子：前台访问时生成
     *
     * 这里必须吞掉所有异常：订阅源只是附属功能，
     * 绝不能因为它出错就让整个前台页面白屏。
     */
    public static function generateRSS()
    {
        try {
            self::build(false);
        } catch (Exception $e) {
            // 静默失败，不影响页面渲染
        } catch (Throwable $e) {
            // PHP 7+ 的 Error 也一并兜住
        }
    }

    /**
     * header 钩子：输出订阅源自动发现标签
     *
     * 让浏览器、RSS 阅读器、AI 抓取器能自动找到订阅地址，
     * 免去手动修改主题 header.php 的麻烦。
     */
    public static function renderHeaderLinks()
    {
        try {
            $options = Typecho_Widget::widget('Widget_Options');
            $cfg = self::settings($options);
            if (!$cfg['autoDiscover']) {
                return;
            }

            $siteUrl = rtrim((string) $options->siteUrl, '/') . '/';
            $title = self::xmlText($options->title);

            foreach ($cfg['outputFormats'] as $format) {
                if ($format === 'rss') {
                    echo '<link rel="alternate" type="application/rss+xml" title="' . $title
                       . ' RSS Feed" href="' . $siteUrl . ltrim($cfg['fileName'], '/') . '" />' . PHP_EOL;
                } elseif ($format === 'atom') {
                    echo '<link rel="alternate" type="application/atom+xml" title="' . $title
                       . ' Atom Feed" href="' . $siteUrl . 'atom.xml" />' . PHP_EOL;
                } else {
                    echo '<link rel="alternate" type="application/feed+json" title="' . $title
                       . ' JSON Feed" href="' . $siteUrl . 'feed.json" />' . PHP_EOL;
                }
            }
        } catch (Exception $e) {
            // 静默失败，绝不影响页面头部渲染
        } catch (Throwable $e) {
            // 同上
        }
    }

    /**
     * 后台发布 / 保存 / 删除文章时立即重建
     *
     * 回调不声明参数，兼容不同钩子传入的参数个数；
     * 全程 try/catch，任何异常都不得影响正常的发布流程。
     */
    public static function onContentChange()
    {
        try {
            self::build(true);
        } catch (Exception $e) {
            // 静默失败，下次前台访问时仍会重建
        } catch (Throwable $e) {
            // 同上
        }
    }

    /* ------------------------------------------------------------------ *
     * 主流程
     * ------------------------------------------------------------------ */

    private static function build($force)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $cfg = self::settings($options);

        $targets = self::targetFiles($cfg);
        if (empty($targets)) {
            return;
        }

        // 缓存判断：以第一个目标文件为基准
        $mainFile = reset($targets);
        if (!$force && $cfg['cacheTTL'] > 0 && is_file($mainFile)
            && (time() - (int) @filemtime($mainFile)) < $cfg['cacheTTL']) {
            return;
        }

        $data = self::collect($options, $cfg);

        foreach ($targets as $format => $file) {
            if ($format === 'rss') {
                $content = self::renderRss($data, $cfg);
            } elseif ($format === 'atom') {
                $content = self::renderAtom($data, $cfg);
            } else {
                $content = self::renderJson($data, $cfg);
            }
            self::writeFile($file, $content);
        }
    }

    /**
     * 采集频道与文章数据
     */
    private static function collect($options, array $cfg)
    {
        $db = Typecho_Db::get();

        // 多取一些，过滤掉密码文章 / 黑名单分类后再裁剪到目标数量
        $fetchLimit = max((int) $cfg['numOfPosts'] * 3, 30);
        $rows = $db->fetchAll($db->select()
            ->from('table.contents')
            ->where('type = ?', 'post')
            ->where('status = ?', 'publish')
            ->order('created', Typecho_Db::SORT_DESC)
            ->limit($fetchLimit));

        $posts = array();
        foreach ($rows as $row) {
            if ($cfg['skipPassword'] && !empty($row['password'])) {
                continue;
            }
            $posts[] = $row;
            if (count($posts) >= (int) $cfg['numOfPosts']) {
                break;
            }
        }

        $channel = array(
            'title'       => (string) $options->title,
            'link'        => (string) $options->siteUrl,
            'description' => (string) $options->description,
            'language'    => self::feedLanguage($options),
            'lastBuild'   => time(),
        );

        if (empty($posts)) {
            $channel['selfUrl'] = $options->siteUrl . ltrim($cfg['fileName'], '/');
            return array('channel' => $channel, 'items' => array());
        }

        $cids = array();
        foreach ($posts as $row) {
            $cids[] = (int) $row['cid'];
        }
        $in = implode(',', $cids);

        /* ---- 批量：分类与标签 ---- */
        $metasByCid = array();
        $metaRows = $db->fetchAll($db->select()
            ->from('table.metas')
            ->join('table.relationships', 'table.metas.mid = table.relationships.mid')
            ->where('table.relationships.cid IN (' . $in . ')')
            ->where("table.metas.type IN ('category', 'tag')"));
        foreach ($metaRows as $meta) {
            $metasByCid[(int) $meta['cid']][] = $meta;
        }

        /* ---- 批量：自定义字段 banner / postjj ---- */
        $fieldsByCid = array();
        $fieldRows = $db->fetchAll($db->select()
            ->from('table.fields')
            ->where('cid IN (' . $in . ')')
            ->where("name IN ('banner', 'postjj')"));
        foreach ($fieldRows as $field) {
            $fieldsByCid[(int) $field['cid']][$field['name']] = $field;
        }

        /* ---- 批量：评论数 ---- */
        $commentsByCid = array();
        $commentRows = $db->fetchAll($db->select('cid', array('COUNT(*)' => 'num'))
            ->from('table.comments')
            ->where('status = ?', 'approved')
            ->where('cid IN (' . $in . ')')
            ->group('table.comments.cid'));
        foreach ($commentRows as $c) {
            $commentsByCid[(int) $c['cid']] = (int) $c['num'];
        }

        /* ---- 批量：作者 ---- */
        $authors = array();
        $uids = array();
        foreach ($posts as $row) {
            $uids[(int) $row['authorId']] = (int) $row['authorId'];
        }
        if (!empty($uids)) {
            $userRows = $db->fetchAll($db->select('uid', 'screenName', 'mail')
                ->from('table.users')
                ->where('uid IN (' . implode(',', $uids) . ')'));
            foreach ($userRows as $u) {
                $authors[(int) $u['uid']] = $u;
            }
        }

        $parsedown = self::parsedown();
        $siteUrl = (string) $options->siteUrl;

        $items = array();
        foreach ($posts as $row) {
            $cid = (int) $row['cid'];

            /* 黑名单分类 / 标签过滤 */
            $metas = isset($metasByCid[$cid]) ? $metasByCid[$cid] : array();
            if (self::isExcluded($metas, $cfg['excludeMetas'])) {
                continue;
            }

            $permalink = self::buildPermalink($row, $options, $cfg, $metas);
            $guid = self::buildGuid($row, $siteUrl, $cfg['guidMode'], $permalink);

            /* 作者 */
            $authorName = '';
            $authorMail = '';
            if (isset($authors[(int) $row['authorId']])) {
                $authorName = (string) $authors[(int) $row['authorId']]['screenName'];
                $authorMail = (string) $authors[(int) $row['authorId']]['mail'];
            }

            /* 分类与标签 */
            $categories = array();
            foreach ($metas as $meta) {
                $categories[] = (string) $meta['name'];
            }

            /* 正文与摘要：尽量只做一次 Markdown 解析 */
            $rawText = preg_replace('/<!--markdown-->/i', '', (string) $row['text']);
            $textSummary = self::applyFilters($rawText, $cfg, false);
            // 未开启短代码清理时，摘要文本与正文文本完全一致，可复用解析结果
            $textContent = $cfg['stripPhotos'] ? self::applyFilters($rawText, $cfg, true) : $textSummary;

            // 仅摘要模式 + 封面图不取正文首图时，完全不必解析正文
            $needHtml = ($cfg['feedMode'] === 'full') || ($cfg['imageSource'] !== 'banner');

            $postjj = '';
            if (isset($fieldsByCid[$cid]['postjj']) && !empty($fieldsByCid[$cid]['postjj']['str_value'])) {
                $postjj = (string) $fieldsByCid[$cid]['postjj']['str_value'];
            }

            $html = '';
            $plainForDesc = null;

            if ($postjj !== '') {
                // 有自定义摘要，直接用它，无需解析正文
                $plainForDesc = self::toPlain(self::applyFilters($postjj, $cfg, false));
            } elseif ($needHtml && $textSummary === $textContent) {
                // 摘要与正文同源：解析一次，两边共用
                $html = self::buildContent($textContent, $parsedown, $cfg);
                $plainForDesc = self::toPlain($html);
            } else {
                // 摘要与正文内容不同（短代码清理），单独解析摘要
                $plainForDesc = self::toPlain(self::toHtml($textSummary, $parsedown));
            }

            if ($needHtml && $html === '') {
                $html = self::buildContent($textContent, $parsedown, $cfg);
            }

            $description = self::buildDescription($plainForDesc, $cfg);

            /* 封面图 */
            $banner = '';
            if (isset($fieldsByCid[$cid]['banner']) && !empty($fieldsByCid[$cid]['banner']['str_value'])) {
                $banner = (string) $fieldsByCid[$cid]['banner']['str_value'];
            }
            $firstImage = self::extractFirstImage($html);
            $image = self::resolveImage($banner, $firstImage, $cfg['imageSource'], $siteUrl);

            $items[] = array(
                'cid'         => $cid,
                'title'       => (string) $row['title'],
                'link'        => $permalink,
                'guid'        => $guid,
                'created'     => (int) $row['created'],
                'modified'    => isset($row['modified']) ? (int) $row['modified'] : (int) $row['created'],
                'creator'     => $authorName,
                'creatorMail' => $authorMail,
                'categories'  => $categories,
                'description' => $description,
                'html'        => $html,
                'comments'    => isset($commentsByCid[$cid]) ? $commentsByCid[$cid] : 0,
                'commentsUrl' => $permalink . '#comments',
                'image'       => $image,
                'summaryText' => self::summaryText($description),
            );
        }

        if (!empty($items) && !empty($items[0]['modified'])) {
            $channel['lastBuild'] = $items[0]['modified'];
        } elseif (!empty($items)) {
            $channel['lastBuild'] = $items[0]['created'];
        }
        $channel['selfUrl'] = $options->siteUrl . ltrim($cfg['fileName'], '/');

        return array('channel' => $channel, 'items' => $items);
    }

    /* ------------------------------------------------------------------ *
     * 渲染：RSS 2.0
     * ------------------------------------------------------------------ */

    private static function renderRss(array $data, array $cfg)
    {
        $channel = $data['channel'];
        $items = $data['items'];

        $xml = '<?xml version="1.0" encoding="UTF-8" ?>' . PHP_EOL;
        $xml .= '<rss version="2.0"' . PHP_EOL;
        $xml .= '    xmlns:content="http://purl.org/rss/1.0/modules/content/"' . PHP_EOL;
        $xml .= '    xmlns:dc="http://purl.org/dc/elements/1.1/"' . PHP_EOL;
        $xml .= '    xmlns:atom="http://www.w3.org/2005/Atom"' . PHP_EOL;
        $xml .= '    xmlns:slash="http://purl.org/rss/1.0/modules/slash/"' . PHP_EOL;
        $xml .= '    xmlns:media="http://search.yahoo.com/mrss/">' . PHP_EOL;
        $xml .= '<channel>' . PHP_EOL;
        $xml .= '<title>' . self::xmlText($channel['title']) . '</title>' . PHP_EOL;
        $xml .= '<link>' . self::xmlText($channel['link']) . '</link>' . PHP_EOL;
        $xml .= '<description>' . self::xmlText($channel['description']) . '</description>' . PHP_EOL;
        $xml .= '<language>' . self::xmlText($channel['language']) . '</language>' . PHP_EOL;
        $xml .= '<generator>CustomRSS v2.3.2 (Typecho)</generator>' . PHP_EOL;
        $xml .= '<atom:link href="' . self::xmlText($channel['selfUrl'])
              . '" rel="self" type="application/rss+xml" />' . PHP_EOL;
        $xml .= '<lastBuildDate>' . date(DATE_RSS, $channel['lastBuild']) . '</lastBuildDate>' . PHP_EOL;

        /* 频道信息补全 */
        if ($cfg['copyright'] !== '') {
            $xml .= '<copyright>' . self::xmlText($cfg['copyright']) . '</copyright>' . PHP_EOL;
        }
        if ($cfg['managingEditor'] !== '') {
            $xml .= '<managingEditor>' . self::xmlText($cfg['managingEditor']) . '</managingEditor>' . PHP_EOL;
        }
        if ($cfg['ttl'] > 0) {
            $xml .= '<ttl>' . (int) $cfg['ttl'] . '</ttl>' . PHP_EOL;
        }
        if ($cfg['siteLogo'] !== '') {
            $xml .= '<image>' . PHP_EOL;
            $xml .= '<url>' . self::xmlText($cfg['siteLogo']) . '</url>' . PHP_EOL;
            $xml .= '<title>' . self::xmlText($channel['title']) . '</title>' . PHP_EOL;
            $xml .= '<link>' . self::xmlText($channel['link']) . '</link>' . PHP_EOL;
            $xml .= '</image>' . PHP_EOL;
        }

        foreach ($items as $item) {
            $xml .= '<item>' . PHP_EOL;
            $xml .= '<title>' . self::xmlText($item['title']) . '</title>' . PHP_EOL;
            $xml .= '<link>' . self::xmlText($item['link']) . '</link>' . PHP_EOL;
            $xml .= '<guid isPermaLink="' . ($cfg['guidMode'] === 'link' ? 'true' : 'false') . '">'
                  . self::xmlText($item['guid']) . '</guid>' . PHP_EOL;
            $xml .= '<pubDate>' . date(DATE_RSS, $item['created']) . '</pubDate>' . PHP_EOL;
            if ($item['creator'] !== '') {
                $xml .= '<dc:creator>' . self::xmlText($item['creator']) . '</dc:creator>' . PHP_EOL;
            }
            foreach ($item['categories'] as $name) {
                $xml .= '<category><![CDATA[' . self::cdata($name) . ']]></category>' . PHP_EOL;
            }
            if ($item['description'] !== '') {
                $xml .= '<description><![CDATA[' . $item['description'] . ']]></description>' . PHP_EOL;
            }
            if ($cfg['feedMode'] === 'full' && $item['html'] !== '') {
                $xml .= '<content:encoded><![CDATA[' . PHP_EOL . $item['html'] . PHP_EOL . ']]></content:encoded>' . PHP_EOL;
            }
            $xml .= '<slash:comments>' . (int) $item['comments'] . '</slash:comments>' . PHP_EOL;
            $xml .= '<comments>' . self::xmlText($item['commentsUrl']) . '</comments>' . PHP_EOL;

            if (!empty($item['image'])) {
                $xml .= '<enclosure url="' . self::xmlText($item['image']['url'])
                      . '" length="' . (int) $item['image']['length'] . '" type="' . self::xmlText($item['image']['type']) . '" />' . PHP_EOL;
                $xml .= '<media:content url="' . self::xmlText($item['image']['url'])
                      . '" medium="image" type="' . self::xmlText($item['image']['type']) . '">' . PHP_EOL;
                $xml .= '<media:thumbnail url="' . self::xmlText($item['image']['url']) . '" />' . PHP_EOL;
                $xml .= '</media:content>' . PHP_EOL;
            }

            $xml .= '</item>' . PHP_EOL;
        }

        $xml .= '</channel>' . PHP_EOL;
        $xml .= '</rss>' . PHP_EOL;

        return $xml;
    }

    /* ------------------------------------------------------------------ *
     * 渲染：Atom 1.0
     * ------------------------------------------------------------------ */

    private static function renderAtom(array $data, array $cfg)
    {
        $channel = $data['channel'];
        $items = $data['items'];

        $xml = '<?xml version="1.0" encoding="UTF-8" ?>' . PHP_EOL;
        $xml .= '<feed xmlns="http://www.w3.org/2005/Atom"' . PHP_EOL;
        $xml .= '      xmlns:media="http://search.yahoo.com/mrss/">' . PHP_EOL;
        $xml .= '<title>' . self::xmlText($channel['title']) . '</title>' . PHP_EOL;
        $xml .= '<subtitle>' . self::xmlText($channel['description']) . '</subtitle>' . PHP_EOL;
        $xml .= '<link href="' . self::xmlText($channel['link']) . '" rel="alternate" type="text/html" />' . PHP_EOL;
        $xml .= '<link href="' . self::xmlText(rtrim($channel['link'], '/') . '/atom.xml')
              . '" rel="self" type="application/atom+xml" />' . PHP_EOL;
        $xml .= '<id>' . self::xmlText($channel['link']) . '</id>' . PHP_EOL;
        $xml .= '<updated>' . date(DATE_ATOM, $channel['lastBuild']) . '</updated>' . PHP_EOL;
        $xml .= '<generator uri="https://bluehe.cn/">CustomRSS v2.3.2</generator>' . PHP_EOL;
        if ($cfg['siteLogo'] !== '') {
            $xml .= '<icon>' . self::xmlText($cfg['siteLogo']) . '</icon>' . PHP_EOL;
        }
        if ($cfg['copyright'] !== '') {
            $xml .= '<rights>' . self::xmlText($cfg['copyright']) . '</rights>' . PHP_EOL;
        }

        foreach ($items as $item) {
            $xml .= '<entry>' . PHP_EOL;
            $xml .= '<title type="html">' . self::xmlText($item['title']) . '</title>' . PHP_EOL;
            $xml .= '<link href="' . self::xmlText($item['link']) . '" rel="alternate" type="text/html" />' . PHP_EOL;
            $xml .= '<id>' . self::xmlText($item['guid']) . '</id>' . PHP_EOL;
            $xml .= '<published>' . date(DATE_ATOM, $item['created']) . '</published>' . PHP_EOL;
            $xml .= '<updated>' . date(DATE_ATOM, $item['modified']) . '</updated>' . PHP_EOL;
            if ($item['creator'] !== '') {
                $xml .= '<author><name>' . self::xmlText($item['creator']) . '</name>';
                if ($item['creatorMail'] !== '') {
                    $xml .= '<email>' . self::xmlText($item['creatorMail']) . '</email>';
                }
                $xml .= '</author>' . PHP_EOL;
            }
            foreach ($item['categories'] as $name) {
                $xml .= '<category term="' . self::xmlText($name) . '" />' . PHP_EOL;
            }
            if ($item['summaryText'] !== '') {
                $xml .= '<summary type="text">' . self::xmlText($item['summaryText']) . '</summary>' . PHP_EOL;
            }
            if ($cfg['feedMode'] === 'full' && $item['html'] !== '') {
                $xml .= '<content type="html">' . self::xmlText($item['html']) . '</content>' . PHP_EOL;
            }
            if (!empty($item['image'])) {
                $xml .= '<media:content url="' . self::xmlText($item['image']['url'])
                      . '" medium="image" type="' . self::xmlText($item['image']['type']) . '" />' . PHP_EOL;
                $xml .= '<media:thumbnail url="' . self::xmlText($item['image']['url']) . '" />' . PHP_EOL;
            }
            $xml .= '</entry>' . PHP_EOL;
        }

        $xml .= '</feed>' . PHP_EOL;

        return $xml;
    }

    /* ------------------------------------------------------------------ *
     * 渲染：JSON Feed 1.1
     * ------------------------------------------------------------------ */

    private static function renderJson(array $data, array $cfg)
    {
        $channel = $data['channel'];

        $feed = array(
            'version'       => 'https://jsonfeed.org/version/1.1',
            'title'         => $channel['title'],
            'home_page_url' => $channel['link'],
            'feed_url'      => rtrim($channel['link'], '/') . '/feed.json',
            'description'   => $channel['description'],
            'language'      => $channel['language'],
        );
        if ($cfg['siteLogo'] !== '') {
            $feed['icon'] = $cfg['siteLogo'];
        }

        $items = array();
        foreach ($data['items'] as $item) {
            $entry = array(
                'id'             => $item['guid'],
                'url'            => $item['link'],
                'title'          => $item['title'],
                'date_published' => date(DATE_ATOM, $item['created']),
                'date_modified'  => date(DATE_ATOM, $item['modified']),
            );
            if ($cfg['feedMode'] === 'full' && $item['html'] !== '') {
                $entry['content_html'] = $item['html'];
            }
            if ($item['summaryText'] !== '') {
                $entry['summary'] = $item['summaryText'];
            }
            if (!empty($item['image'])) {
                $entry['image'] = $item['image']['url'];
            }
            if (!empty($item['categories'])) {
                $entry['tags'] = $item['categories'];
            }
            if ($item['creator'] !== '') {
                $entry['authors'] = array(array('name' => $item['creator']));
            }
            $items[] = $entry;
        }
        $feed['items'] = $items;

        $json = json_encode(
            $feed,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        return $json === false ? '{}' : $json . PHP_EOL;
    }

    /* ------------------------------------------------------------------ *
     * 配置读取
     * ------------------------------------------------------------------ */

    private static function settings($options)
    {
        $plugin = null;
        try {
            $plugin = $options->plugin(self::PLUGIN_NAME);
        } catch (Exception $e) {
            $plugin = null;
        }

        $get = function ($key, $default) use ($plugin) {
            $value = $default;
            if (!empty($plugin)) {
                try {
                    $tmp = $plugin->{$key};
                    if (!is_object($tmp) && $tmp !== null) {
                        $value = $tmp;
                    }
                } catch (Exception $e) {
                    $value = $default;
                }
            }
            if (is_string($value) && !is_array($default)) {
                $value = trim($value);
                if ($value === '' && $default !== '') {
                    $value = $default;
                }
            }
            return $value;
        };

        $numOfPosts = (int) $get('numOfPosts', '10');
        if ($numOfPosts <= 0) {
            $numOfPosts = 10;
        }

        $excerptLength = (int) $get('excerptLength', (string) self::DEFAULT_EXCERPT_LENGTH);
        if ($excerptLength <= 0) {
            $excerptLength = self::DEFAULT_EXCERPT_LENGTH;
        }

        $feedMode = $get('feedMode', 'full');
        if ($feedMode !== 'excerpt' && $feedMode !== 'full') {
            $feedMode = 'full';
        }

        $linkMode = $get('linkMode', 'auto');
        if (!in_array($linkMode, array('auto', 'index', 'noindex'))) {
            $linkMode = 'auto';
        }

        $imageSource = $get('imageSource', 'auto');
        if (!in_array($imageSource, array('auto', 'banner', 'content'))) {
            $imageSource = 'auto';
        }

        $guidMode = $get('guidMode', 'tag');
        if ($guidMode !== 'tag' && $guidMode !== 'link') {
            $guidMode = 'tag';
        }

        $fileName = $get('fileName', 'rss.xml');
        $fileName = str_replace(array('..', '\\'), array('', '/'), $fileName);
        if ($fileName === '' || $fileName === '/') {
            $fileName = 'rss.xml';
        }

        $cacheTTL = (int) $get('cacheTTL', '0');
        if ($cacheTTL < 0) {
            $cacheTTL = 0;
        }

        $ttl = (int) $get('ttl', '60');
        if ($ttl < 0) {
            $ttl = 0;
        }

        $formats = $get('outputFormats', array('rss'));
        if (!is_array($formats)) {
            $formats = array((string) $formats);
        }
        $formats = array_values(array_intersect($formats, array('rss', 'atom', 'json')));
        if (empty($formats)) {
            $formats = array('rss');
        }

        $copyright = (string) $get('copyright', '');
        if ($copyright === '') {
            $copyright = '© ' . date('Y') . ' ' . $options->title;
        }

        return array(
            'numOfPosts'        => $numOfPosts,
            'feedMode'          => $feedMode,
            'excerptLength'     => $excerptLength,
            'linkMode'          => $linkMode,
            'permalink'         => $get('permalink', ''),
            'imageSource'       => $imageSource,
            'guidMode'          => $guidMode,
            'outputFormats'     => $formats,
            'siteLogo'          => (string) $get('siteLogo', ''),
            'copyright'         => $copyright,
            'managingEditor'    => (string) $get('managingEditor', ''),
            'ttl'               => $ttl,
            'excludeMetas'      => self::parseLines($get('excludeMetas', '')),
            'skipPassword'      => self::isChecked($get('skipPassword', array('1'))),
            'removeEmoji'       => self::isChecked($get('removeEmoji', array('1'))),
            'removeSystemEmoji' => self::isChecked($get('removeSystemEmoji', array())),
            'stripPhotos'       => self::isChecked($get('stripPhotos', array('1'))),
            'blockWords'        => self::parseLines($get('blockWords', '')),
            'autoDiscover'      => self::isChecked($get('autoDiscover', array('1'))),
            'fileName'          => $fileName,
            'cacheTTL'          => $cacheTTL,
        );
    }

    /**
     * 需要生成的文件：format => 绝对路径
     */
    private static function targetFiles(array $cfg)
    {
        $files = array();
        foreach ($cfg['outputFormats'] as $format) {
            if ($format === 'rss') {
                $name = $cfg['fileName'];
            } elseif ($format === 'atom') {
                $name = 'atom.xml';
            } else {
                $name = 'feed.json';
            }
            $files[$format] = __TYPECHO_ROOT_DIR__ . '/' . ltrim($name, '/');
        }
        return $files;
    }

    private static function isChecked($value)
    {
        if (is_array($value)) {
            return in_array('1', $value) || in_array(1, $value) || in_array('true', $value);
        }
        return (bool) $value && $value !== '0' && $value !== 'false';
    }

    private static function parseLines($value)
    {
        $lines = array();
        if (is_array($value)) {
            $raw = $value;
        } else {
            $raw = preg_split('/[\r\n,]+/', (string) $value);
        }
        foreach ($raw as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    /* ------------------------------------------------------------------ *
     * 链接生成
     * ------------------------------------------------------------------ */

    private static function buildPermalink(array $row, $options, array $cfg, array $metas)
    {
        // 1) 取链接模板：后台手填 > 站点永久链接设置 > 默认
        $template = trim($cfg['permalink']);
        if ($template === '') {
            $template = self::sitePostTemplate($options);
        }

        // 2) 规范化：/archives/[cid:digital]/ → /archives/{cid}/
        $template = preg_replace('/\[\s*([_a-z0-9-]+)\s*(?::[^\]]+)?\]/i', '{$1}', $template);
        if ($template === '' || $template[0] !== '/') {
            $template = '/' . ltrim($template, '/');
        }

        // 3) 准备替换参数
        $created   = (int) $row['created'];
        $slug      = !empty($row['slug']) ? $row['slug'] : (string) $row['cid'];
        $category  = 'default';
        $directory = 'default';
        $mid       = '0';

        $slugs = array();
        foreach ($metas as $meta) {
            if (isset($meta['type']) && $meta['type'] === 'category') {
                $slugs[] = !empty($meta['slug']) ? $meta['slug'] : 'default';
                if ($mid === '0' && isset($meta['mid'])) {
                    $mid = (string) $meta['mid'];
                }
            }
        }
        if (!empty($slugs)) {
            $category  = $slugs[0];
            $directory = implode('/', $slugs);
        }

        $map = array(
            '{cid}'       => (string) $row['cid'],
            '{slug}'      => $slug,
            '{category}'  => $category,
            '{directory}' => $directory,
            '{year}'      => date('Y', $created),
            '{month}'     => date('m', $created),
            '{day}'       => date('d', $created),
            '{mid}'       => $mid,
        );

        // 4) 替换
        $path = str_replace(array_keys($map), array_values($map), $template);

        // 5) 兜底：仍有未替换占位符时退回 /archives/{cid}/
        if (strpos($path, '{') !== false || strpos($path, '[') !== false || trim($path, '/') === '') {
            $path = '/archives/' . $row['cid'] . '/';
        }

        // 6) 前缀（是否带 index.php）
        $base = self::buildBase($options, $cfg['linkMode']);

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    /**
     * 生成永久不变的文章唯一标识
     *
     * tag URI 规范（RFC 4151）：tag:{域名},{日期}:{具体标识}
     * 例：tag:bluehe.cn,2024:1283
     *
     * 采用「文章创建年份 + 文章 ID」，两者都永不改变，
     * 因此无论以后怎么调整永久链接风格、是否开启伪静态、站点换不换主题，
     * 订阅器都不会把旧文章当成新文章重新推送。
     */
    private static function buildGuid(array $row, $siteUrl, $mode, $permalink)
    {
        if ($mode !== 'tag') {
            return $permalink;
        }

        $host = (string) parse_url($siteUrl, PHP_URL_HOST);
        if ($host === '') {
            // siteUrl 缺少协议时降级解析
            $host = preg_replace('#^[a-z]+://#i', '', (string) $siteUrl);
            $host = preg_replace('#[/?\#].*$#', '', (string) $host);
        }
        $host = preg_replace('/:\d+$/', '', (string) $host);
        $host = strtolower(trim((string) $host, '/'));
        if ($host === '') {
            $host = 'localhost';
        }

        $year = date('Y', (int) $row['created']);
        $cid  = (int) $row['cid'];

        return 'tag:' . $host . ',' . $year . ':' . $cid;
    }

    private static function sitePostTemplate($options)
    {
        if (class_exists('Typecho_Router')) {
            try {
                $router = Typecho_Router::get('post');
                if (!empty($router['url'])) {
                    return $router['url'];
                }
            } catch (Exception $e) {
                // 走下面的兜底
            }
        }

        try {
            $table = $options->routingTable;
            if (is_string($table)) {
                $table = @unserialize($table);
            }
            if (is_array($table) && isset($table['post']['url'])) {
                return $table['post']['url'];
            }
        } catch (Exception $e) {
            // 忽略
        }

        return '/archives/[cid:digital]/';
    }

    private static function buildBase($options, $mode)
    {
        $siteUrl = rtrim((string) $options->siteUrl, '/');

        if ($mode === 'index') {
            return $siteUrl . '/index.php';
        }
        if ($mode === 'noindex') {
            return $siteUrl;
        }

        $index = '';
        try {
            $index = (string) $options->index;
        } catch (Exception $e) {
            $index = '';
        }

        if ($index !== '') {
            if (strpos($index, 'index.php') !== false) {
                $pos = strpos($index, 'index.php');
                return rtrim(substr($index, 0, $pos + strlen('index.php')), '/');
            }
            return rtrim($index, '/');
        }

        $rewrite = false;
        try {
            $rewrite = (bool) $options->rewrite;
        } catch (Exception $e) {
            $rewrite = false;
        }

        return $rewrite ? $siteUrl : $siteUrl . '/index.php';
    }

    /* ------------------------------------------------------------------ *
     * 内容处理
     * ------------------------------------------------------------------ */

    /**
     * 由纯文本生成摘要 HTML 片段（入参必须已过滤、已去标签）
     */
    private static function buildDescription($plain, array $cfg)
    {
        $excerpt = self::smartExcerpt((string) $plain, $cfg['excerptLength']);

        $excerpt = trim($excerpt);
        if ($excerpt === '') {
            return '';
        }

        $excerpt = htmlspecialchars($excerpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div style="color:#666;font-size:14px;line-height:1.6;margin-bottom:15px;">'
             . self::cdata($excerpt)
             . '</div>';
    }

    /**
     * 由已过滤的 Markdown 文本生成正文 HTML（入参必须已 applyFilters 过）
     */
    private static function buildContent($text, $parsedown, array $cfg)
    {
        if (trim($text) === '') {
            return '';
        }

        $html = self::toHtml($text, $parsedown);

        // 图片处理
        $html = preg_replace_callback(
            '/<img\s+([^>]+)>/i',
            array(__CLASS__, 'handleImage'),
            $html
        );

        // 段落 / 代码块样式
        $html = str_replace('<p>', '<p style="margin:0 0 15px 0;padding:0;">', $html);
        $html = str_replace(
            '<code>',
            '<code style="font-family:Consolas,Monaco,\'Andale Mono\',monospace;background:#f5f5f5;padding:2px 5px;border-radius:3px;">',
            $html
        );

        // 全局容器
        $html = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Oxygen-Sans,Ubuntu,Cantarell,sans-serif;'
              . 'line-height:1.6;color:#333;font-size:16px;margin:15px 0;">'
              . $html
              . '</div>';

        return self::cdata($html);
    }

    /**
     * 摘要智能断句：优先在句末标点处断开，避免切出半个词
     */
    private static function smartExcerpt($plain, $len)
    {
        $plain = trim($plain);
        $total = self::strLen($plain);
        if ($total <= $len) {
            return $plain;
        }

        $cut = self::subStr($plain, 0, $len);
        $windowSize = 24;
        $windowStart = max(0, self::strLen($cut) - $windowSize);
        $window = self::subStr($cut, $windowStart);

        // 句末标点优先
        $puncts = array('。', '！', '？', '!', '?', '…', '；', ';');
        $best = -1;
        foreach ($puncts as $p) {
            $pos = self::strRpos($window, $p);
            if ($pos !== false && $pos > $best) {
                $best = $pos;
            }
        }

        if ($best >= 0) {
            $cut = self::subStr($cut, 0, $windowStart + $best + 1);
            return rtrim($cut);
        }

        // 退而求其次：空格（英文场景）
        $spacePos = self::strRpos($window, ' ');
        if ($spacePos !== false && $spacePos > (int) (self::strLen($window) * 0.6)) {
            $cut = self::subStr($cut, 0, $windowStart + $spacePos);
            return rtrim($cut) . '...';
        }

        return rtrim($cut) . '...';
    }

    private static function applyFilters($text, array $cfg, $keepPhotoInner)
    {
        $text = (string) $text;

        if ($cfg['stripPhotos']) {
            if ($keepPhotoInner) {
                $text = self::safeReplace('/\[\s*photos\s*\]([\s\S]*?)\[\s*\/\s*photos\s*\]/iu', '$1', $text);
                $text = self::safeReplace('/\[\s*\/?\s*photos\s*\]/iu', '', $text);
                $text = self::safeReplace('/\[\s*cid\s*=\s*["\']?\d+["\']?\s*\]/iu', '', $text);
            } else {
                $text = self::safeReplace('/\[\s*photos\s*\][\s\S]*?\[\s*\/\s*photos\s*\]/iu', '', $text);
                $text = self::safeReplace('/\[\s*\/?\s*photos\s*\]/iu', '', $text);
                $text = self::safeReplace('/\[\s*cid\s*=\s*["\']?\d+["\']?\s*\]/iu', '', $text);
            }
        }

        $text = self::applyBlockWords($text, $cfg['blockWords']);

        if ($cfg['removeEmoji']) {
            $text = self::removeEmoticons($text);
        }
        if ($cfg['removeSystemEmoji']) {
            $text = self::removeSystemEmoji($text);
        }

        return $text;
    }

    private static function applyBlockWords($text, array $words)
    {
        if (empty($words)) {
            return $text;
        }
        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '') {
                continue;
            }
            if (strlen($word) > 2 && $word[0] === '/' && substr($word, -1) === '/') {
                $replaced = @preg_replace($word . 'u', '', $text);
                $text = ($replaced === null) ? $text : $replaced;
            } else {
                $text = str_replace($word, '', $text);
            }
        }
        return $text;
    }

    /**
     * 分类 / 标签黑名单判断（匹配名称或缩略名）
     */
    private static function isExcluded(array $metas, array $exclude)
    {
        if (empty($exclude)) {
            return false;
        }
        foreach ($metas as $meta) {
            $name = isset($meta['name']) ? self::strToLower((string) $meta['name']) : '';
            $slug = isset($meta['slug']) ? self::strToLower((string) $meta['slug']) : '';
            foreach ($exclude as $word) {
                $word = self::strToLower($word);
                if ($word === '') {
                    continue;
                }
                if (($name !== '' && $name === $word) || ($slug !== '' && $slug === $word)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 封面图决策：banner 字段 / 正文首图 / 智能兜底
     */
    private static function resolveImage($banner, $firstImage, $mode, $siteUrl)
    {
        $banner = trim((string) $banner);
        $firstImage = trim((string) $firstImage);

        $url = '';
        if ($mode === 'banner') {
            $url = $banner;
        } elseif ($mode === 'content') {
            $url = $firstImage;
        } else {
            $url = ($banner !== '') ? $banner : $firstImage;
        }

        if ($url === '') {
            return array();
        }

        $url = self::absolutize($url, $siteUrl);

        return array(
            'url'    => $url,
            'type'   => self::mimeByExt($url),
            'length' => self::localFileSize($url, $siteUrl),
        );
    }

    /**
     * 读取站内图片的真实字节数，用于 <enclosure length>。
     * 仅处理指向本站的相对/绝对 URL 映射到本地路径；
     * 远程图床（外链图片）保持 0，绝不发起任何网络请求。
     */
    private static function localFileSize($url, $siteUrl)
    {
        // 剥离查询串与锚点
        $path = self::safeReplace('/[?#].*$/', '', (string) $url);
        $path = rawurldecode($path);

        $base = str_replace('\\', '/', rtrim((string) $siteUrl, '/'));
        if (stripos($path, 'http://') === 0 || stripos($path, 'https://') === 0) {
            if (stripos($path, $base) !== 0) {
                return 0; // 远程图床
            }
            $path = substr($path, strlen($base));
        } elseif (strpos($path, '//') === 0) {
            // 协议相对地址 //domain/... 视为远程，不做域名比对（避免误判）
            return 0;
        }

        $path = '/' . ltrim($path, '/');

        // 防目录穿越：任何包含 .. 的路径直接放弃
        if (strpos($path, '..') !== false) {
            return 0;
        }

        $file = str_replace('\\', '/', __TYPECHO_ROOT_DIR__) . $path;
        if (!is_file($file)) {
            return 0;
        }

        $size = @filesize($file);
        return ($size === false || $size <= 0) ? 0 : (int) $size;
    }

    /**
     * 从正文 HTML 中提取第一张图片
     */
    private static function extractFirstImage($html)
    {
        if ($html === '') {
            return '';
        }
        if (preg_match('/<img[^>]+src\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            return $m[1];
        }
        return '';
    }

    private static function removeEmoticons($text)
    {
        $text = self::safeReplace('/\([^\s()@]*@\((?:[^()]*)\)\)/u', '', $text);
        $text = self::safeReplace('/\([^\s()@]*@\([^\s()]*\)\s*\)/u', '', $text);
        $text = self::safeReplace('/&#40;[^\s()@]*@&#40;(?:[^()]*?)&#41;&#41;/u', '', $text);
        $text = self::safeReplace('/&amp;#40;[^\s()@]*@&amp;#40;(?:[^()]*?)&amp;#41;&amp;#41;/u', '', $text);

        return $text;
    }

    private static function removeSystemEmoji($text)
    {
        return self::safeReplace(
            '/[\x{1F000}-\x{1FAFF}\x{1F1E6}-\x{1F1FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\x{20E3}]/u',
            '',
            $text
        );
    }

    /* ------------------------------------------------------------------ *
     * 工具方法
     * ------------------------------------------------------------------ */

    public static function handleImage($matches)
    {
        $attributes = $matches[1];
        $attrs = array();
        preg_match_all('/(\w+)\s*=\s*"([^"]*)"/', $attributes, $attrMatches, PREG_SET_ORDER);

        foreach ($attrMatches as $attr) {
            $attrs[strtolower($attr[1])] = $attr[2];
        }

        if (isset($attrs['alt'])) {
            preg_match_all('/[\x{4e00}-\x{9fa5}]/u', $attrs['alt'], $zh);
            $newAlt = implode('', $zh[0]);
            if (!empty($newAlt)) {
                $attrs['alt'] = $newAlt;
            } else {
                unset($attrs['alt']);
            }
        }

        $style = 'max-width:100%;height:auto;margin:10px 0;display:block;';
        if (isset($attrs['style'])) {
            $attrs['style'] .= ';' . $style;
        } else {
            $attrs['style'] = $style;
        }

        $newAttributes = '';
        foreach ($attrs as $name => $value) {
            $newAttributes .= ' ' . $name . '="' . $value . '"';
        }

        return '<img' . $newAttributes . '>';
    }

    /**
     * 把摘要 HTML 片段还原为纯文本（供 Atom summary / JSON Feed summary 使用）
     */
    private static function summaryText($description)
    {
        if ($description === '') {
            return '';
        }
        $plain = self::toPlain($description);
        return trim($plain);
    }

    private static function toPlain($html)
    {
        $plain = html_entity_decode((string) $html, ENT_QUOTES, 'UTF-8');
        $plain = strip_tags($plain);
        return trim(self::safeReplace('/\s+/u', ' ', $plain));
    }

    private static function toHtml($text, $parsedown)
    {
        if ($parsedown !== null) {
            return $parsedown->text($text);
        }
        return self::simpleMarkdown($text);
    }

    private static function parsedown()
    {
        if (class_exists('Parsedown')) {
            return new Parsedown();
        }

        foreach (array(__DIR__ . '/Parsedown.php', __DIR__ . '/libs/Parsedown.php') as $file) {
            if (is_file($file)) {
                require_once $file;
                if (class_exists('Parsedown')) {
                    return new Parsedown();
                }
            }
        }

        return null;
    }

    private static function simpleMarkdown($text)
    {
        $text = str_replace(array("\r\n", "\r"), "\n", $text);

        $text = preg_replace_callback('/```([\s\S]*?)```/', function ($m) {
            return '<pre><code>' . htmlspecialchars(trim($m[1]), ENT_QUOTES, 'UTF-8') . '</code></pre>';
        }, $text);

        $text = preg_replace('/^######\s*(.+)$/m', '<h6>$1</h6>', $text);
        $text = preg_replace('/^#####\s*(.+)$/m',  '<h5>$1</h5>', $text);
        $text = preg_replace('/^####\s*(.+)$/m',   '<h4>$1</h4>', $text);
        $text = preg_replace('/^###\s*(.+)$/m',    '<h3>$1</h3>', $text);
        $text = preg_replace('/^##\s*(.+)$/m',     '<h2>$1</h2>', $text);
        $text = preg_replace('/^#\s*(.+)$/m',      '<h1>$1</h1>', $text);

        $text = preg_replace('/!\[([^\]]*)\]\(([^)\s]+)[^)]*\)/', '<img src="$2" alt="$1" />', $text);
        $text = preg_replace('/\[([^\]]+)\]\(([^)\s]+)[^)]*\)/', '<a href="$2">$1</a>', $text);
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        $text = preg_replace('/^&gt;\s?(.*)$/m', '<blockquote>$1</blockquote>', $text);
        $text = preg_replace('/^-{3,}$/m', '<hr />', $text);

        $blocks = preg_split('/\n{2,}/', $text);
        $html = '';
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            if (preg_match('/^<(h[1-6]|pre|blockquote|hr|ul|ol|li|img|p)/i', $block)) {
                $html .= $block . "\n";
            } else {
                $html .= '<p>' . nl2br($block) . '</p>' . "\n";
            }
        }

        return $html;
    }

    private static function xmlText($value)
    {
        $value = self::stripInvalidChars((string) $value);
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function cdata($value)
    {
        $value = self::stripInvalidChars((string) $value);
        return str_replace(']]>', ']]]]><![CDATA[>', $value);
    }

    private static function stripInvalidChars($value)
    {
        return self::safeReplace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    }

    /**
     * 安全的 preg_replace：正则失败（如输入不是合法 UTF-8）时返回原值，
     * 避免误把整篇正文替换成 null / 空字符串
     */
    private static function safeReplace($pattern, $replacement, $subject)
    {
        $result = @preg_replace($pattern, $replacement, $subject);
        return ($result === null) ? $subject : $result;
    }

    /* ------------------------------------------------------------------ *
     * UTF-8 字符串工具
     *
     * 全部优先使用 mbstring；服务器未安装 mbstring 扩展时自动降级为
     * 基于 preg_split 的字符级实现，保证偏移量体系（字符数）始终一致，
     * 不会出现「字符偏移 / 字节偏移混用」导致的摘要错乱或 fatal error。
     * ------------------------------------------------------------------ */

    /** 字符数 */
    private static function strLen($s)
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($s, 'UTF-8');
        }
        return preg_match_all('/./us', $s, $m);
    }

    /** 按字符截取，$length 为 null 表示取到末尾 */
    private static function subStr($s, $start, $length = null)
    {
        if (function_exists('mb_substr')) {
            if ($length === null) {
                return mb_substr($s, $start, null, 'UTF-8');
            }
            return mb_substr($s, $start, $length, 'UTF-8');
        }

        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars)) {
            return '';
        }
        if ($length === null) {
            return implode('', array_slice($chars, $start));
        }
        return implode('', array_slice($chars, $start, $length));
    }

    /** 反向查找单字符，返回字符偏移（与 subStr 同一坐标系），未找到返回 false */
    private static function strRpos($haystack, $needle)
    {
        if (function_exists('mb_strrpos')) {
            return mb_strrpos($haystack, $needle, 0, 'UTF-8');
        }

        $chars = preg_split('//u', $haystack, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars)) {
            return false;
        }
        for ($i = count($chars) - 1; $i >= 0; $i--) {
            if ($chars[$i] === $needle) {
                return $i;
            }
        }
        return false;
    }

    /** 转小写（仅影响 ASCII，中文不受影响） */
    private static function strToLower($s)
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($s, 'UTF-8');
        }
        return strtolower($s);
    }

    /**
     * 相对路径补全为绝对 URL（enclosure / media 必须是绝对地址）
     */
    private static function absolutize($url, $siteUrl)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (strpos($url, '//') === 0) {
            $scheme = (stripos($siteUrl, 'https') === 0) ? 'https:' : 'http:';
            return $scheme . $url;
        }
        return rtrim($siteUrl, '/') . '/' . ltrim($url, '/');
    }

    private static function mimeByExt($url)
    {
        $ext = strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $map = array(
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'svg'  => 'image/svg+xml',
            'bmp'  => 'image/bmp',
        );
        return isset($map[$ext]) ? $map[$ext] : 'image/jpeg';
    }

    /**
     * 频道语言标签。
     *
     * RSS 2.0 / Atom 使用的语言标签遵循 RFC 5646（如 zh-CN、en-US：
     * 语言码小写、子标签大写）。Typecho 后台的语言设置存的是
     * zh_CN 这类下划线格式，这里读取并规范化；读取不到时默认 zh-CN。
     */
    private static function feedLanguage($options)
    {
        $lang = '';
        if (isset($options->lang)) {
            $lang = trim((string) $options->lang);
        }
        if ($lang === '') {
            $lang = 'zh-CN';
        }

        $lang = str_replace('_', '-', $lang);
        $parts = explode('-', $lang);
        if (count($parts) >= 2) {
            return strtolower($parts[0]) . '-' . strtoupper($parts[1]);
        }
        return strtolower($parts[0]);
    }

    private static function writeFile($file, $content)
    {
        $dir = dirname($file);
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }

        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            return false;
        }

        // Windows 上 rename() 无法覆盖已存在的目标文件，必须先删除
        if (is_file($file)) {
            @unlink($file);
        }

        if (!@rename($tmp, $file)) {
            // rename 失败（罕见）时退回直接写入，保证订阅源不会停在旧版本
            @unlink($tmp);
            if (@file_put_contents($file, $content, LOCK_EX) === false) {
                return false;
            }
        }

        @chmod($file, 0644);
        return true;
    }
}
