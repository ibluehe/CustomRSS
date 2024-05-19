<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * CustomRSS Plugin for Typecho
 * 
 * 生成站点的 rss.xml 文件，用于 rss 订阅优化。
 * 
 * @package CustomSitemap
 * @author 蓝河
 * @version 1.0.0
 * @link https://bluehe.cn/
 * 
 */
class CustomRSS_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法
     */
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Archive')->footer = array('CustomRSS_Plugin', 'generateRSS');
    }

    /**
     * 禁用插件方法
     */
    public static function deactivate()
    {
        @unlink(__TYPECHO_ROOT_DIR__ . '/rss.xml');
    }

    /**
     * 插件配置方法
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $numOfPosts = new Typecho_Widget_Helper_Form_Element_Text('numOfPosts', NULL, '10', _t('文章数量'), _t('设定生成 RSS 文件时包含的文章数量'));
        $form->addInput($numOfPosts->addRule('isInteger', _t('文章数量必须是整数')));
    }

    /**
     * 个人用户的配置方法
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    /**
     * 生成 RSS 文件的方法
     */
    public static function generateRSS()
    {

        $db = Typecho_Db::get();
        $options = Typecho_Widget::widget('Widget_Options');
        $numOfPosts = $options->plugin('CustomRSS')->numOfPosts;

        $rows = $db->fetchAll($db->select()
            ->from('table.contents')
            ->where('type = ?', 'post')
            ->where('status = ?', 'publish')
            ->order('created', Typecho_Db::SORT_DESC)
            ->limit($numOfPosts));

        require_once 'Parsedown.php';
        $Parsedown = new Parsedown();

        // 固定作者信息
        $author = 'hi@bluehe.cn(蓝河)';

        $rssFeed = '<?xml version="1.0" encoding="UTF-8" ?>' . PHP_EOL;
        $rssFeed .= '<rss version="2.0"' . PHP_EOL;
        $rssFeed .= 'xmlns:content="http://purl.org/rss/1.0/modules/content/"' . PHP_EOL;
        $rssFeed .= 'xmlns:dc="http://purl.org/dc/elements/1.1/"' . PHP_EOL;
        $rssFeed .= 'xmlns:atom="http://www.w3.org/2005/Atom"' . PHP_EOL;
        $rssFeed .= 'xmlns:slash="http://purl.org/rss/1.0/modules/slash/">' . PHP_EOL;
        $rssFeed .= '<channel>' . PHP_EOL;
        $rssFeed .= '<title>' . htmlspecialchars($options->title) . '</title>' . PHP_EOL;
        $rssFeed .= '<link>' . htmlspecialchars($options->siteUrl) . '</link>' . PHP_EOL;
        $rssFeed .= '<description>' . htmlspecialchars($options->description) . '</description>' . PHP_EOL;
        $rssFeed .= '<atom:link href="' . htmlspecialchars($options->siteUrl . 'rss.xml') . '" rel="self" type="application/rss+xml" />' . PHP_EOL;

        foreach ($rows as $row) {
            $permalink = Typecho_Router::url('post', $row, $options->index);

            $rssFeed .= '<item>' . PHP_EOL;
            $rssFeed .= '<title>' . htmlspecialchars($row['title']) . '</title>' . PHP_EOL;
            $rssFeed .= '<link>' . htmlspecialchars($permalink) . '</link>' . PHP_EOL;
            $rssFeed .= '<guid isPermaLink="false">' . htmlspecialchars($permalink) . '</guid>' . PHP_EOL;
            $rssFeed .= '<pubDate>' . date(DATE_RSS, $row['created']) . '</pubDate>' . PHP_EOL;
            $rssFeed .= '<dc:creator>' . htmlspecialchars(Typecho_Widget::widget('Widget_Abstract_Users')->filter(array('uid' => $row['authorId']))->screenName) . '</dc:creator>' . PHP_EOL;

            $categories = $db->fetchAll($db->select()
                ->from('table.metas')
                ->join('table.relationships', 'table.metas.mid = table.relationships.mid')
                ->where('table.relationships.cid = ?', $row['cid'])
                ->where('table.metas.type = ?', 'category'));

            if (!empty($categories)) {
                foreach ($categories as $category) {
                    $rssFeed .= '<category><![CDATA[' . $category['name'] . ']]></category>' . PHP_EOL;
                }
            }

            $tags = $db->fetchAll($db->select()
                ->from('table.metas')
                ->join('table.relationships', 'table.metas.mid = table.relationships.mid')
                ->where('table.relationships.cid = ?', $row['cid'])
                ->where('table.metas.type = ?', 'tag'));

            if (!empty($tags)) {
                $tagNames = array();
                foreach ($tags as $tag) {
                    $tagNames[] = $tag['name'];
                }
                $rssFeed .= '<tags><![CDATA[' . implode(',', $tagNames) . ']]></tags>' . PHP_EOL;
            }

            $commentsNum = $db->fetchObject($db->select(array('COUNT(*)' => 'num'))
                ->from('table.comments')
                ->where('cid = ?', $row['cid'])
                ->where('status = ?', 'approved'))->num;

            $commentsLink = $permalink . '#comments';
            $rssFeed .= '<slash:comments>' . htmlspecialchars($commentsNum) . '</slash:comments>' . PHP_EOL;
            $rssFeed .= '<comments>' . htmlspecialchars($commentsLink) . '</comments>' . PHP_EOL;

            if (!empty($row['text'])) {
                $htmlContent = $Parsedown->text($row['text']);
                $excerpt = mb_substr(strip_tags($htmlContent), 0, 128, 'UTF-8') . '...';
                $rssFeed .= '<description><![CDATA[' . $excerpt . ']]></description>' . PHP_EOL;
                $rssFeed .= '<content:encoded><![CDATA[' . PHP_EOL . $htmlContent . PHP_EOL . ']]></content:encoded>' . PHP_EOL;
            }

            $rssFeed .= '<author>' . htmlspecialchars($author) . '</author>' . PHP_EOL;

            $rssFeed .= '</item>' . PHP_EOL;
        }

        $rssFeed .= '</channel>' . PHP_EOL;
        $rssFeed .= '</rss>' . PHP_EOL;

        file_put_contents(__TYPECHO_ROOT_DIR__ . '/rss.xml', $rssFeed);
    }
}
