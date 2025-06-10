<?php
namespace TypechoPlugin\SafeRedirect;

use Typecho\Widget;
use Typecho\Db;
use Typecho\Plugin\Exception as PluginException;

// 防止直接运行
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class Action extends Widget {
    /**
     * 处理重定向逻辑，验证目标地址并渲染跳转页面或直接跳转
     * 
     * 函数通过请求参数获取base64编码的目标地址，进行格式校验后，根据主题模板配置
     * 决定使用模板渲染跳转页面或执行默认跳转逻辑。包含以下核心流程：
     * 1. 解码并验证目标地址格式
     * 2. 模板文件存在性检查
     * 3. 动态渲染跳转模板或执行默认跳转
     *
     * @return void
     */
    public function redirect() {
        // 解码并获取目标地址，检查是否为空且符合HTTP(S)协议格式
        $target = $this->urlSafeBase64Decode($this->request->get('target'));
        // 验证目标地址
        if (empty($target) || !preg_match('/^((https?:)?\/\/)/i', $target)) throw new  PluginException(_t('未指定跳转地址'), 404);
        // 原本应该再次判定是否为直接跳转，如果直接跳转，则不必用跳转模板
        // 想了想似乎不必，全部让其显示跳转模板即可
        /*$excludeUrls = array_filter(explode("\n", $options->excludeUrls));
        foreach ($excludeUrls as $excludeUrl) {
            if (strpos($target, trim($excludeUrl)) === 0) {
                // 直接跳转
                $this->response->redirect($target );
                return;
            }
        }*/
        // 如果是//开头的话，获取当前网站协议，加在左边
        if (strpos($target, '//') === 0)  {
            // 获取当前网站协议
            $scscheme = $this->request->isSecure() ? 'https' : 'http';
            // 拼接目标地址
            $target =  $scscheme . ':' . $target;
        }
        // 获取主题配置并检测自定义跳转模板是否存在
        $options = Widget::widget('Widget_Options');
        if ($options->theme && is_file($options->themeFile('go.php'))) {
            // 准备模板变量并渲染自定义跳转页面
            $this->_target = $target ;
            $this->_title = _t('正在跳转到外部页面...');
            $this->response->setStatus(200);
            $this->need('go.php');
        } else {
            // 当模板不存在时执行基础跳转逻辑
            $this->_defaultRedirect($target);
        }
    }

     /**
     * 默认的跳转页面
     */
    private function _defaultRedirect($url) {
        // 转义URL
        $url = htmlspecialchars($url);
        // 获取主题配置
        $safeRedirect = Widget::widget('Widget_Options')->plugin('SafeRedirect');
        // 获取跳转提示
        $tip = empty($safeRedirect->tip) ? '' : "<h1>{$safeRedirect->tip}</h1>";
        // 获取安全警告内容
        $tipContent = empty($safeRedirect->tipContent) ? '' : "<p>{$safeRedirect->tipContent}</p>";
        // 获取跳转延时
        $delay = intval($safeRedirect->delay) ?? 5;
        // 标题文字
        $title = $safeRedirect->title ?? '外链跳转';
        // 
        $tip1 = empty($safeRedirect->tip1) ? '' : '<p>您正在离开本站，前往以下网址：</p>';
        // 获取跳转提示内容
        $tipContent1 = empty($safeRedirect->tipContent1) ? '' : "<p>{$safeRedirect->tipContent1}</p>";
        // 输出HTML
        echo <<<HTML
            <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>{$title}</title>
                    <style>body{font-family:Arial,sans-serif;margin:0;padding:0;display:flex;justify-content:center;align-items:center;height:100vh;background-color:#f5f5f5}.container{text-align:center;padding:2rem;background-color:#fff;border-radius:5px;box-shadow:0 2px 10px rgba(0,0,0,.1);max-width:500px;width:90%}h1{color:#333}p{margin:1rem 0;color:#666}.url{word-break:break-all;background-color:#f0f0f0;padding:10px;border-radius:3px;margin:15px 0}.button{display:inline-block;width:50%;padding:10px 20px;color:#fff;text-decoration:none;border-radius:3px;}</style>
                </head>
                <body>
                    <div class="container">
                        {$tip}
                        {$tip1}
                        <div class="url">{$url}</div>
                        {$tipContent}
                        <a id="jump" class="button" href="{$url}" rel="nofollow noopener noreferrer" style="background-color:{$safeRedirect->buttonColor};">立即前往</a>
                        <p id="countdown">页面将在 <span id="timer">{$delay}</span> 秒后自动跳转...</p>
                    </div>
                    <script>
                        var seconds = {$delay};
                        var timer = document.getElementById('timer');
                        var interval = setInterval(() => {
                            seconds--;
                            timer.textContent = seconds;
                            if (seconds <= 0) {
                                clearInterval(interval);
                                window.location.href = "{$url}";
                            }
                        }, 1000);
                    </script>
                </body>
            </html>
        HTML;
    }

    /**
     * 解码URL安全的Base64编码字符串
     * 
     * 该函数将URL安全字符(-和_)替换回标准Base64字符(+和/)，补足缺失的填充符"="，
     * 最终执行标准Base64解码。处理过程包含以下步骤：
     * 1. 替换URL安全字符为标准Base64字符
     * 2. 计算字符串长度并补足缺失的等号填充符
     * 3. 执行标准Base64解码
     *
     * @param string $str 经过URL安全处理的Base64编码字符串
     * @return string|false 返回解码后的二进制数据，失败时返回false
     */
    private function urlSafeBase64Decode($str) {
        // 将URL安全字符(-和_)替换回标准Base64字符(+和/)
        $data = str_replace(['-', '_'], ['+', '/'], $str);
        // 计算需要补充的填充符数量（使总长度成为4的倍数）
        $mod4 = strlen($data) % 4;
        // 补充缺失的等号填充符
        if ($mod4 > 0)  $data .= str_repeat('=', 4 - $mod4);
        // 执行标准Base64解码
        return base64_decode($data);
    }
}