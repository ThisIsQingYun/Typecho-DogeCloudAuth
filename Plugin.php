<?php
/**
 * 多吉云URL鉴权插件
 * 
 * @package DogeCloudAuth
 * @version 1.0.0
 * @description 为多吉云CDN提供URL鉴权功能，防止资源盗链
 * @author DogeCloud Auth Plugin
 * @link https://www.dogecloud.com
 */

require_once __DIR__ . '/ServiceWorker.php';

class DogeCloudAuth_Plugin implements Typecho_Plugin_Interface {

    /**
     * 激活插件
     */
    public static function activate() {
        // 检查必要的PHP扩展
        if (!extension_loaded("hash")) {
            throw new Typecho_Plugin_Exception("插件需要Hash扩展支持，请先安装并启用。");
        }
        
        // 注册钩子
        Typecho_Plugin::factory("index.php")->begin = array("DogeCloudAuth_Plugin", "processPageOutput");
        Typecho_Plugin::factory("admin.php")->begin = array("DogeCloudAuth_Plugin", "processPageOutput");
        
        // 注册Service Worker路由
        Helper::addRoute("dogecloud_auth_sw", "/dogecloud-auth-sw.js", "DogeCloudAuth_ServiceWorker", "execute");
    }

    /**
     * 禁用插件
     */
    public static function deactivate() {
        Helper::removeRoute("dogecloud_auth_sw");
    }

    /**
     * 插件配置
     */
    public static function config(Typecho_Widget_Helper_Form $form) {
        // 域名密钥配置
        $domainKeys = new Typecho_Widget_Helper_Form_Element_Textarea(
            "domainKeys", 
            null, 
            "cdn.example.com:your_secret_key\nstatic.example.com:another_secret_key", 
            _("域名与密钥配置"), 
            _("每行一个域名与密钥的对应关系，格式为：域名:密钥。多吉云的密钥可在控制台的密钥管理中获取。")
        );
        $form->addInput($domainKeys);

        // 链接有效期
        $duration = new Typecho_Widget_Helper_Form_Element_Text(
            "duration", 
            null, 
            "1800", 
            _("链接有效期（秒）"), 
            _("设置生成的鉴权链接的有效时间，单位为秒。默认1800秒（30分钟）。")
        );
        $form->addInput($duration);

        // 需要鉴权的文件扩展名
        $allowedExtensions = new Typecho_Widget_Helper_Form_Element_Text(
            "allowedExtensions", 
            null, 
            ".jpg;.jpeg;.png;.gif;.webp;.css;.js;.mp4;.mp3;.pdf;.zip", 
            _("需要鉴权的文件扩展名"), 
            _("指定需要进行鉴权的文件类型，用分号分隔。例如：.jpg;.png;.css;.js")
        );
        $form->addInput($allowedExtensions);

        // 鉴权参数名
        $authParamName = new Typecho_Widget_Helper_Form_Element_Radio(
            "authParamName", 
            array(
                "auth_key" => _("auth_key (多吉云标准)"),
                "sign" => _("sign (通用标准)")
            ), 
            "auth_key", 
            _("鉴权参数名"), 
            _("选择鉴权签名的参数名，多吉云推荐使用auth_key。")
        );
        $form->addInput($authParamName);


        // 启用Service Worker
        $enableServiceWorker = new Typecho_Widget_Helper_Form_Element_Radio(
            "enableServiceWorker", 
            array(
                "1" => _("启用"),
                "0" => _("禁用")
            ), 
            "1", 
            _("启用Service Worker"), 
            _("启用后可以处理AJAX请求和动态加载的资源。")
        );
        $form->addInput($enableServiceWorker);

        // 帮助说明
        $helpText = new Typecho_Widget_Helper_Form_Element_Text(
            "helpText", 
            null, 
            "多吉云URL鉴权插件使用时间戳+签名的方式对资源进行保护。请确保服务器时间准确，并在多吉云控制台配置相应的鉴权规则。", 
            _("使用说明"), 
            _("更多帮助请参考：<a href=\"https://docs.dogecloud.com/cdn/manual-auth-key\" target=\"_blank\">多吉云官网</a><br>\n            <strong>配置步骤：</strong><br>\n            1. 在多吉云控制台获取域名和密钥<br>\n            2. 在上方配置域名与密钥的对应关系<br>\n            3. 在多吉云控制台配置URL鉴权规则<br>\n            4. 测试鉴权功能是否正常工作")
        );
        $helpText->input->setAttribute("readonly", "readonly");
        $form->addInput($helpText);
    }

    /**
     * 个人配置（暂不使用）
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {
        // 暂不需要个人配置
    }

    /**
     * 处理页面输出
     */
    public static function processPageOutput() {
        $options = Helper::options();
        $pluginConfig = $options->plugin("DogeCloudAuth");
        
        // 检查配置是否完整
        if (!$pluginConfig || !$pluginConfig->domainKeys || !$pluginConfig->duration) {
            return;
        }

        $domainKeys = self::parseDomainKeys($pluginConfig->domainKeys);
        $duration = (int) $pluginConfig->duration;
        $allowedExtensions = $pluginConfig->allowedExtensions;
        $authParamName = $pluginConfig->authParamName ?: "auth_key";
        $enableServiceWorker = $pluginConfig->enableServiceWorker;

        // 解析文件扩展名
        $extensions = self::parseExtensions($allowedExtensions);
        if (empty($extensions)) {
            return;
        }

        // 开始输出缓冲
        ob_start(function ($buffer) use ($domainKeys, $duration, $extensions, $authParamName, $enableServiceWorker) {
            // 处理HTML中的资源链接
            $buffer = self::processHtmlUrls($buffer, $domainKeys, $duration, $extensions, $authParamName);
            
            // 处理CSS中的资源链接
            $buffer = self::processCssUrls($buffer, $domainKeys, $duration, $extensions, $authParamName);
            
            // 注入Service Worker（如果启用）
            if ($enableServiceWorker == "1") {
                $buffer = self::injectServiceWorker($buffer);
            }
            
            return $buffer;
        });
    }

    /**
     * 处理HTML中的URL
     */
    private static function processHtmlUrls($buffer, $domainKeys, $duration, $extensions, $authParamName) {
        $extensionPattern = implode("|", array_map(function($ext) {
            return preg_quote(ltrim($ext, "."), "/");
        }, $extensions));
        
        // 匹配HTML属性中的URL（src、href等）
        $pattern = '/((?:src|href|data-src|data-href)\s*=\s*["\'])([^"\'\']+\.(?:' . $extensionPattern . '))(?![^"\'\']*' . preg_quote($authParamName, '/') . '=)(["\'])/i';
        
        return preg_replace_callback($pattern, function ($matches) use ($domainKeys, $duration, $authParamName) {
            $prefix = $matches[1];  // 属性名和引号
            $url = $matches[2];     // URL
            $suffix = $matches[3];  // 结束引号
            
            $originalUrl = $url;
            $parsedUrl = parse_url($url);
            $domain = $parsedUrl['host'] ?? null;
            
            // 处理相对路径
            if (!$domain) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $currentHost = $_SERVER['HTTP_HOST'];
                
                // 处理不同类型的相对路径
                if (strpos($url, '/') === 0) {
                    // 绝对路径（以/开头）
                    $url = $scheme . '://' . $currentHost . $url;
                } else {
                    // 相对路径
                    $url = $scheme . '://' . $currentHost . '/' . ltrim($url, '/');
                }
                $domain = $currentHost;
            }
            
            // 检查是否有对应的密钥
            if (isset($domainKeys[$domain])) {
                $authUrl = self::generateAuthUrl($url, $domainKeys[$domain], $duration, $authParamName);
                return $prefix . $authUrl . $suffix;
            }
            
            return $matches[0];
        }, $buffer);
    }

    /**
     * 处理CSS中的URL
     */
    private static function processCssUrls($buffer, $domainKeys, $duration, $extensions, $authParamName) {
        $extensionPattern = implode("|", array_map(function($ext) {
            return preg_quote(ltrim($ext, "."), "/");
        }, $extensions));
        
        // 匹配CSS中的url()函数和@import规则
        $urlPattern = '/url\s*\(\s*(["\']?)([^"\'\'\)\s]+\.(?:' . $extensionPattern . '))(?![^"\'\'\)\s]*' . preg_quote($authParamName, '/') . '=)(["\']?)\s*\)/i';
        $importPattern = '/@import\s+(["\']?)([^"\'\'\s;]+\.(?:' . $extensionPattern . '))(?![^"\'\'\s;]*' . preg_quote($authParamName, '/') . '=)(["\']?)/i';
        
        // 处理URL的通用函数
        $processUrl = function ($matches, $isImport = false) use ($domainKeys, $duration, $authParamName) {
            $quote1 = $matches[1];  // 开始引号
            $url = $matches[2];     // URL
            $quote2 = $matches[3];  // 结束引号
            
            $originalUrl = $url;
            $parsedUrl = parse_url($url);
            $domain = $parsedUrl['host'] ?? null;
            
            // 处理相对路径
            if (!$domain) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $currentHost = $_SERVER['HTTP_HOST'];
                
                // 处理不同类型的相对路径
                if (strpos($url, '/') === 0) {
                    // 绝对路径（以/开头）
                    $url = $scheme . '://' . $currentHost . $url;
                } else {
                    // 相对路径
                    $url = $scheme . '://' . $currentHost . '/' . ltrim($url, '/');
                }
                $domain = $currentHost;
            }
            
            // 检查是否有对应的密钥
            if (isset($domainKeys[$domain])) {
                $authUrl = self::generateAuthUrl($url, $domainKeys[$domain], $duration, $authParamName);
                if ($isImport) {
                    return "@import " . $quote1 . $authUrl . $quote2;
                } else {
                    return "url(" . $quote1 . $authUrl . $quote2 . ")";
                }
            }
            
            return $matches[0];
        };
        
        // 先处理@import规则
        $buffer = preg_replace_callback($importPattern, function ($matches) use ($processUrl) {
            return $processUrl($matches, true);
        }, $buffer);
        
        // 再处理url()函数
        return preg_replace_callback($urlPattern, function ($matches) use ($processUrl) {
            return $processUrl($matches, false);
        }, $buffer);
    }

    /**
     * 注入Service Worker
     */
    private static function injectServiceWorker($buffer) {
        $swUrl = Helper::options()->siteUrl . "dogecloud-auth-sw.js";
        $version = md5_file(__FILE__);
        
        $swScript = "<script>\n";
        $swScript .= "if ('serviceWorker' in navigator) {\n";
        $swScript .= "    navigator.serviceWorker.register('" . $swUrl . "?v=" . $version . "', { scope: '/' })\n";
        $swScript .= "        .then(function(registration) {\n";
        $swScript .= "            console.log('DogeCloud Auth Service Worker registered successfully');\n";
        $swScript .= "        })\n";
        $swScript .= "        .catch(function(error) {\n";
        $swScript .= "            console.log('DogeCloud Auth Service Worker registration failed');\n";
        $swScript .= "        });\n";
        $swScript .= "}\n";
        $swScript .= "</script>";
        
        return preg_replace("/<\/body>/i", $swScript . "</body>", $buffer);
    }

    /**
     * 生成鉴权URL（多吉云标准格式）
     */
    public static function generateAuthUrl($url, $secretKey, $duration, $authParamName = "auth_key") {
        $parsedUrl = parse_url($url);
        $path = $parsedUrl['path'] ?? '/';
        $timestamp = time() + $duration;
        
        // 生成随机字符串（1-100位，支持大小写字母和数字）
        $rand = self::generateRandomString(32);
        
        // 用户ID，默认为0
        $uid = 0;
        
        // 按照多吉云格式计算MD5：md5(path-timestamp-rand-uid-key)
        $signString = $path . '-' . $timestamp . '-' . $rand . '-' . $uid . '-' . $secretKey;
        $md5hash = md5($signString);
        
        // 按照多吉云格式组装auth_key：timestamp-rand-uid-md5hash
        $authKey = $timestamp . '-' . $rand . '-' . $uid . '-' . $md5hash;
        
        // 构建鉴权参数
        $authParams = array(
            $authParamName => $authKey
        );
        
        // 添加到URL
        $separator = isset($parsedUrl['query']) ? '&' : '?';
        $authQuery = http_build_query($authParams);
        
        return $url . $separator . $authQuery;
    }
    
    /**
     * 生成随机字符串
     */
    private static function generateRandomString($length = 32) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    /**
     * 解析域名密钥配置
     */
    public static function parseDomainKeys($domainKeys) {
        $result = array();
        $lines = array_filter(array_map('trim', explode("\n", $domainKeys)));
        
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($domain, $key) = explode(':', $line, 2);
                $result[trim($domain)] = trim($key);
            }
        }
        
        return $result;
    }

    /**
     * 解析文件扩展名
     */
    public static function parseExtensions($allowedExtensions) {
        return array_filter(array_map('trim', explode(';', $allowedExtensions)));
    }

    /**
     * 验证鉴权参数（用于调试，多吉云格式）
     */
    public static function validateAuth($path, $authKey, $secretKey) {
        // 解析auth_key：timestamp-rand-uid-md5hash
        $parts = explode('-', $authKey);
        if (count($parts) < 4) {
            return false;
        }
        
        $timestamp = $parts[0];
        $rand = $parts[1];
        $uid = $parts[2];
        $md5hash = implode('-', array_slice($parts, 3)); // 处理MD5中可能包含的-
        
        // 检查时间戳是否过期
        if (time() > $timestamp) {
            return false;
        }
        
        // 验证签名：md5(path-timestamp-rand-uid-key)
        $signString = $path . '-' . $timestamp . '-' . $rand . '-' . $uid . '-' . $secretKey;
        $expectedMd5 = md5($signString);
        
        return hash_equals($expectedMd5, $md5hash);
    }
}