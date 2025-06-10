<?php
namespace TypechoPlugin\SafeRedirect;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Plugin\Exception as PluginException;
use Utils\Helper;

// 防止直接运行
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 用于typecho的安全跳转
 * 
 * @package SafeRedirect
 * @author 猫东东
 * @version 1.0.0
 * @link https://github.com/xa1st/Typecho-Plugin-SafeRedirect
 */

class Plugin implements PluginInterface { 
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     */
    public static function activate() {
        // 获取当前 Typecho 版本
        $currentVersion = \Typecho\Common::VERSION;
        // 设定最低要求版本
        $requiredVersion = '1.0.0';
        // 版本比较
        if (version_compare(\Typecho\Common::VERSION, '1.0.0', '<')) {
            throw new Typecho_Plugin_Exception('此插件需要 Typecho ' . $requiredVersion . ' 或更高版本。当前版本：' . $currentVersion);
        }
        // 注册路由 - 使用路径参数
        Helper::addRoute('go-redirect', '/go/[target]', '\TypechoPlugin\SafeRedirect\Action', 'redirect');
        // 注册页脚脚本钩子，用于在底部插入js
        \Typecho\Plugin::factory('Widget_Archive')->footer = [__CLASS__, 'footer'];
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     */
    public static function deactivate() {
        // 删除路由
        Helper::removeRoute('go-redirect');
    }
    /**
     * 插件配置方法
     *
     * @param Form $form 配置面板
     */
     /**
     * 获取插件配置面板
     */
    public static function config(Form $form) {
        // 跳转延时设置
        $delay = new Text('delay', null, '5', _t('跳转延时,单位:秒'), _t('设置跳转页面的等待时间，设置为0则不进行延时'));
        $form->addInput($delay);
        // 默认按钮颜色
        $buttonColor = new Text('buttonColor', null, '#007bff', _t('按钮颜色'), _t('设置跳转按钮的颜色,以配合全站主题样式一致'));
        $form->addInput($buttonColor);
        // 跳转提示标题
        $title = new Text('title', null, '外链跳转', _t('提示页网页标题'), _t('设置跳转提示的网页标题'));
        $form->addInput($title);
        // 跳转提示标题
        $tip = new Text('tip', null, '外链跳转提示', _t('提示标题文字'), _t('设置跳转提示的标题，用于在跳转页面显示，留空则不显示'));
        $form->addInput($tip);
        // 跳转提示标题
        $tip1 = new Text('tip1', null, '您正在离开本站，前往以下网址：', _t('外链跳转提示副标题'), _t('设置外链跳转提示副标题，用于在跳转页面显示，留空则不显示'));
        $form->addInput($tip1);
        // 跳转警告语
        $tipContent = new Text('tipContent', null, '请注意，我们无法保证外部网站的安全性和内容的真实性，请谨慎访问。', _t('跳转警告语'), _t('设置跳转提示的警告语，用于在跳转页面显示，留空则不显示'));
        $form->addInput($tipContent);
        // 排除网址
        $excludeUrls = new Textarea('excludeUrls', null, '', _t('排除的URL'), _t('一行一个，以下开头的URL将不会被替换，不带http://或者https://，例如：github.com'));
        $form->addInput($excludeUrls);
    }

    /**
     * 个人用户的配置面板
     */
    public static function personalConfig(Form $form) {}

    /**
     * 页脚输出JS脚本
     */
    public static function footer() {
        // 获取插件配置
        $options = Helper::options()->plugin('SafeRedirect');
        // 获取排除的URL列表
        $excludeUrls = array_filter(explode("\n", $options->excludeUrls));
        // 去掉空格
        $excludeUrls = array_map('trim', $excludeUrls);
        // 转化成json
        $excludeUrlsJson = json_encode($excludeUrls);
        // 输出JS脚本
        echo <<<JS
            <script>
                document.addEventListener("DOMContentLoaded", function(){
                    // 排除的URL列表
                    var excludeUrls = {$excludeUrlsJson};
                    // 定义过滤函数
                    const isExternalLink = (url) => {
                        // 如果链接不包含 // 则不是外部链接
                        if (url.indexOf('//') === -1) return false;
                        // 如果链接在排除列表中，不处理
                        for (let i = 0; i < excludeUrls.length; i++) {
                            // 把最左边的//去掉
                            const tmpUrl = url.replace('https://', '').replace('http://', '').replace('//', '');
                            // 检查是否是外部链接
                            if (tmpUrl.indexOf(excludeUrls[i].trim()) === 0) return false;
                        }
                        // 检查是否是外部域名
                        let currentHost = location.host, urlHost;
                        try {
                            urlHost = url.indexOf('//') === 0 ? new URL(location.protocol + url).host : new URL(url).host;
                        } catch(e) {
                            return false;
                        }
                        // 检查域名是否一致
                        return urlHost !== currentHost && urlHost.length > 0;
                    }
                    // base64编码函数
                    const urlSafeBase64 = str => btoa(encodeURIComponent(str).replace(/%([0-9A-F]{2})/g, (_, hex) => {return String.fromCharCode(parseInt(hex, 16))})).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
                    // 使用事件委托监听所有链接点击事件
                    document.addEventListener('click', function(e) {
                        // 查找最近的a元素（处理点击了a内部的元素的情况）
                        const link = e.target.closest('a');
                        // 如果点击的是不是链接，或者已经有了data-safe属性，就直接跳过
                        if (!link || link.hasAttribute('data-safe')) return;
                        // 获取当前链接
                        const href = link.getAttribute('href');
                        // 检查是否是外部链接（根据您的需求调整判断逻辑）
                        if (href && isExternalLink(href)) {
                            // 阻止默认行为
                            e.preventDefault();
                            // 将链接作为参数传递给action.php
                            window.location.href = '/go/' + urlSafeBase64(href);
                        }
                        // 标记链接已处理（避免重复处理）
                        link.setAttribute('data-safe', 'true');
                    });
                });
            </script>
        JS;
    }
}
