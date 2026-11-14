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
 * @version 1.0.4
 * @link https://github.com/xa1st/Typecho-Plugin-SafeRedirect
 */

class Plugin implements PluginInterface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     */
    public static function activate()
    {
        // 注册路由 - 使用路径参数
        Helper::addRoute('go-redirect', '/go/[target]', '\TypechoPlugin\SafeRedirect\Action', 'redirect');
        // 注册页脚脚本钩子，用于在底部插入js
        \Typecho\Plugin::factory('Widget_Archive')->footer = [__CLASS__, 'footer'];
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     */
    public static function deactivate()
    {
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
    public static function config(Form $form)
    {
        // 跳转延时设置
        $delay = new Text('delay', null, '5', _t('跳转延时,单位:秒'), _t('设置跳转页面的等待时间，设置为0或留空则不进行延时'));
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
    public static function footer()
    {
        // 获取插件配置
        $options = Helper::options();
        // 通过路由生成中转页地址前缀（基于 index 前缀，自动适配子目录安装与 rewrite 设置）
        $goBase = \Typecho\Router::url('go-redirect', ['target' => ''], $options->index);
        // 获取排除的URL列表：去掉首尾空格与空行，重新排列键名，保证 json 输出为数组而不是对象
        $excludeUrls = array_values(array_filter(array_map('trim', explode("\n", (string) $options->plugin('SafeRedirect')->excludeUrls))));
        // 转化成json
        $excludeUrlsJson = json_encode($excludeUrls);
        $goBaseJson = json_encode($goBase);
        // 输出JS脚本
        echo <<<JS
            <script>
                document.addEventListener("DOMContentLoaded", function(){
                    // 排除的URL列表
                    var excludeUrls = {$excludeUrlsJson};
                    // 中转页地址前缀，由服务端根据站点配置生成，兼容子目录安装
                    var goBaseUrl = {$goBaseJson};
                    // 定义过滤函数
                    const isExternalLink = (url) => {
                        // 如果链接不包含 // 则不是外部链接
                        if (url.indexOf('//') === -1) return false;
                        // 解析链接的域名，解析失败则视为内部链接，不处理
                        let urlHost, urlHostname;
                        try {
                            const parsed = url.indexOf('//') === 0 ? new URL(location.protocol + url) : new URL(url);
                            urlHost = parsed.host;
                            urlHostname = parsed.hostname;
                        } catch(e) {
                            return false;
                        }
                        // 域名为空或与当前站点一致（含端口），不处理
                        if (!urlHostname || urlHost === location.host) return false;
                        // 去掉协议头后的地址，用于路径前缀匹配
                        const tmpUrl = url.replace(/^([a-z][a-z0-9+.-]*:)?\/\//i, '').toLowerCase();
                        // 检查是否在排除列表中
                        for (let i = 0; i < excludeUrls.length; i++) {
                            // 规范化：去掉协议头与首尾斜杠，统一小写
                            const exclude = excludeUrls[i].trim().toLowerCase().replace(/^([a-z][a-z0-9+.-]*:)?\/\//i, '').replace(/^\/+|\/+$/g, '');
                            // 空条目跳过
                            if (!exclude) continue;
                            // 域名完整匹配或子域名匹配，如 github.com 可匹配 www.github.com，但不会误放行 github.com.evil.com
                            if (urlHostname === exclude || urlHostname.endsWith('.' + exclude)) return false;
                            // 路径前缀匹配，如 github.com/xa1st，边界字符必须是 / : ? #，避免 github.com 误匹配 github.com.evil.com
                            if (tmpUrl.indexOf(exclude) === 0 && (tmpUrl.length === exclude.length || '/:?#'.indexOf(tmpUrl.charAt(exclude.length)) !== -1)) return false;
                        }
                        return true;
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
                            // 生成中转页地址
                            const goUrl = goBaseUrl + urlSafeBase64(href);
                            // 替换链接
                            link.setAttribute('href', goUrl);
                            // 将链接作为参数传递给action.php
                            window.location.href = goUrl;
                        }
                        // 标记链接已处理（避免重复处理）
                        link.setAttribute('data-safe', 'true');
                    });
                });
            </script>
        JS;
    }
}
