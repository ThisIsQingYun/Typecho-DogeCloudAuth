<?php
/**
 * 多吉云URL鉴权插件
 * 
 * @package DogeCloudAuth
 * @version 1.1.0
 * @description 为多吉云CDN提供URL鉴权功能，防止资源盗链
 * @author QingYun
 * @link https://github.com/ThisIsQingYun/Typecho-DogeCloudAuth
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
        // 移除注册的钩子
        Typecho_Plugin::factory("index.php")->begin = null;
        Typecho_Plugin::factory("admin.php")->begin = null;
        
        // 移除Service Worker路由
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
        
        // 检查插件是否启用
        if (!$pluginConfig || !isset($options->plugins['activated']['DogeCloudAuth'])) {
            return;
        }
        
        // 检查配置是否完整
        if (!$pluginConfig->domainKeys || !$pluginConfig->duration) {
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
     * 注入Service Worker和自动刷新脚本
     */
    private static function injectServiceWorker($buffer) {
        $swUrl = Helper::options()->siteUrl . "dogecloud-auth-sw.js";
        $version = md5_file(__FILE__);
        
        // 获取插件配置
        $options = Helper::options()->plugin("DogeCloudAuth");
        $authParamName = $options->authParamName ?: "auth_key";
        $duration = (int) ($options->duration ?: 1800);
        $domainKeys = self::parseDomainKeys($options->domainKeys);
        $extensions = self::parseExtensions($options->allowedExtensions);
        
        // 生成配置的JSON
        $configJson = json_encode(array(
            'authParamName' => $authParamName,
            'duration' => $duration,
            'domainKeys' => $domainKeys,
            'extensions' => $extensions
        ), JSON_UNESCAPED_SLASHES);
        
        $swScript = "<script>\n";
        
        // 注入配置
        $swScript .= "window.DogeCloudAuthConfig = " . $configJson . ";\n\n";
        
        // 添加自动刷新功能
        $swScript .= self::getAutoRefreshScript();
        
        // Service Worker注册
        $swScript .= "if ('serviceWorker' in navigator) {\n";
        $swScript .= "    navigator.serviceWorker.register('" . $swUrl . "?v=" . $version . "', { scope: '/' })\n";
        $swScript .= "        .then(function(registration) {\n";
        $swScript .= "            console.log('DogeCloud Auth Service Worker registered successfully');\n";
        $swScript .= "            // 启动自动刷新检查\n";
        $swScript .= "            if (window.DogeCloudAuth) {\n";
        $swScript .= "                window.DogeCloudAuth.startAutoRefresh();\n";
        $swScript .= "            }\n";
        $swScript .= "        })\n";
        $swScript .= "        .catch(function(error) {\n";
        $swScript .= "            console.log('DogeCloud Auth Service Worker registration failed');\n";
        $swScript .= "        });\n";
        $swScript .= "} else if (window.DogeCloudAuth) {\n";
        $swScript .= "    // 即使没有Service Worker也启动自动刷新\n";
        $swScript .= "    window.DogeCloudAuth.startAutoRefresh();\n";
        $swScript .= "}\n";
        $swScript .= "</script>";
        
        return preg_replace("/</body>/i", $swScript . "</body>", $buffer);
    }

    /**
     * 获取自动刷新脚本
     */
    private static function getAutoRefreshScript() {
        return "
// 多吉云鉴权自动刷新功能
window.DogeCloudAuth = {
    refreshInterval: null,
    checkInterval: 60000, // 每分钟检查一次
    
    // 启动自动刷新
    startAutoRefresh: function() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
        }
        
        this.refreshInterval = setInterval(() => {
            this.checkAndRefreshUrls();
        }, this.checkInterval);
        
        console.log('DogeCloud Auth 自动刷新已启动');
    },
    
    // 停止自动刷新
    stopAutoRefresh: function() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }
    },
    
    // 检查并刷新URL
    checkAndRefreshUrls: function() {
        const config = window.DogeCloudAuthConfig;
        if (!config) return;
        
        // 检查图片元素
        const images = document.querySelectorAll('img');
        images.forEach(img => {
            if (this.needsRefresh(img.src, config)) {
                const newUrl = this.generateAuthUrl(img.src, config);
                if (newUrl && newUrl !== img.src) {
                    img.src = newUrl;
                    console.log('刷新图片URL:', newUrl);
                }
            }
        });
        
        // 检查背景图片
        const elementsWithBg = document.querySelectorAll('*');
        elementsWithBg.forEach(el => {
            const style = window.getComputedStyle(el);
            const bgImage = style.backgroundImage;
            if (bgImage && bgImage !== 'none') {
                const urlMatch = bgImage.match(/url\([\"']?([^\"'\)]+)[\"']?\)/);
                if (urlMatch && urlMatch[1]) {
                    const url = urlMatch[1];
                    if (this.needsRefresh(url, config)) {
                        const newUrl = this.generateAuthUrl(url, config);
                        if (newUrl && newUrl !== url) {
                            el.style.backgroundImage = 'url(' + newUrl + ')';
                            console.log('刷新背景图片URL:', newUrl);
                        }
                    }
                }
            }
        });
        
        // 检查CSS中的资源
        this.refreshCssUrls(config);
    },
    
    // 检查URL是否需要刷新
    needsRefresh: function(url, config) {
        try {
            const urlObj = new URL(url, window.location.href);
            
            // 检查是否有鉴权参数
            const authParam = urlObj.searchParams.get(config.authParamName);
            if (!authParam) return false;
            
            // 检查文件扩展名
            const hasValidExt = config.extensions.some(ext => {
                return urlObj.pathname.toLowerCase().endsWith('.' + ext.toLowerCase());
            });
            if (!hasValidExt) return false;
            
            // 检查域名
            if (!config.domainKeys[urlObj.hostname]) return false;
            
            // 解析时间戳
            const parts = authParam.split('-');
            if (parts.length < 4) return false;
            
            const timestamp = parseInt(parts[0]);
            const currentTime = Math.floor(Date.now() / 1000);
            
            // 如果还有5分钟就过期，则刷新
            return (timestamp - currentTime) < 300;
        } catch (e) {
            return false;
        }
    },
    
    // 生成新的鉴权URL
    generateAuthUrl: function(url, config) {
        try {
            const urlObj = new URL(url, window.location.href);
            const hostname = urlObj.hostname;
            const secretKey = config.domainKeys[hostname];
            
            if (!secretKey) return null;
            
            const path = urlObj.pathname;
            const timestamp = Math.floor(Date.now() / 1000) + config.duration;
            const rand = this.generateRandomString(32);
            const uid = 0;
            
            // 计算MD5签名
            const signString = path + '-' + timestamp + '-' + rand + '-' + uid + '-' + secretKey;
            const md5hash = this.md5(signString);
            
            // 组装auth_key
            const authKey = timestamp + '-' + rand + '-' + uid + '-' + md5hash;
            
            // 移除旧的鉴权参数并添加新的
            urlObj.searchParams.delete(config.authParamName);
            urlObj.searchParams.set(config.authParamName, authKey);
            
            return urlObj.toString();
        } catch (e) {
            console.error('生成鉴权URL失败:', e);
            return null;
        }
    },
    
    // 刷新CSS中的URL
    refreshCssUrls: function(config) {
        const styleSheets = document.styleSheets;
        for (let i = 0; i < styleSheets.length; i++) {
            try {
                const sheet = styleSheets[i];
                if (sheet.href && this.needsRefresh(sheet.href, config)) {
                    const newHref = this.generateAuthUrl(sheet.href, config);
                    if (newHref && newHref !== sheet.href) {
                        // 重新加载样式表
                        const newLink = document.createElement('link');
                        newLink.rel = 'stylesheet';
                        newLink.href = newHref;
                        document.head.appendChild(newLink);
                        
                        // 移除旧的样式表
                        if (sheet.ownerNode) {
                            sheet.ownerNode.remove();
                        }
                        console.log('刷新CSS文件:', newHref);
                    }
                }
            } catch (e) {
                // 跨域CSS无法访问，忽略
            }
        }
    },
    
    // 生成随机字符串
    generateRandomString: function(length) {
        const chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        let result = '';
        for (let i = 0; i < length; i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
    },
    
    // MD5哈希函数（简化版）
    md5: function(str) {
        // 使用简化的MD5实现
        function rotateLeft(value, amount) {
            return (value << amount) | (value >>> (32 - amount));
        }
        
        function addUnsigned(x, y) {
            const x4 = (x & 0x40000000);
            const y4 = (y & 0x40000000);
            const x8 = (x & 0x80000000);
            const y8 = (y & 0x80000000);
            const result = (x & 0x3FFFFFFF) + (y & 0x3FFFFFFF);
            if (x4 & y4) {
                return (result ^ 0x80000000 ^ x8 ^ y8);
            }
            if (x4 | y4) {
                if (result & 0x40000000) {
                    return (result ^ 0xC0000000 ^ x8 ^ y8);
                } else {
                    return (result ^ 0x40000000 ^ x8 ^ y8);
                }
            } else {
                return (result ^ x8 ^ y8);
            }
        }
        
        function F(x, y, z) { return (x & y) | ((~x) & z); }
        function G(x, y, z) { return (x & z) | (y & (~z)); }
        function H(x, y, z) { return (x ^ y ^ z); }
        function I(x, y, z) { return (y ^ (x | (~z))); }
        
        function FF(a, b, c, d, x, s, ac) {
            a = addUnsigned(a, addUnsigned(addUnsigned(F(b, c, d), x), ac));
            return addUnsigned(rotateLeft(a, s), b);
        }
        
        function GG(a, b, c, d, x, s, ac) {
            a = addUnsigned(a, addUnsigned(addUnsigned(G(b, c, d), x), ac));
            return addUnsigned(rotateLeft(a, s), b);
        }
        
        function HH(a, b, c, d, x, s, ac) {
            a = addUnsigned(a, addUnsigned(addUnsigned(H(b, c, d), x), ac));
            return addUnsigned(rotateLeft(a, s), b);
        }
        
        function II(a, b, c, d, x, s, ac) {
            a = addUnsigned(a, addUnsigned(addUnsigned(I(b, c, d), x), ac));
            return addUnsigned(rotateLeft(a, s), b);
        }
        
        function convertToWordArray(str) {
            let wordArray = [];
            let messageLength = str.length;
            let numberOfWords = (((messageLength + 8) - ((messageLength + 8) % 64)) / 64 + 1) * 16;
            for (let i = 0; i < numberOfWords; i++) {
                wordArray[i] = 0;
            }
            for (let i = 0; i < messageLength; i++) {
                wordArray[i >> 2] |= str.charCodeAt(i) << ((i % 4) * 8);
            }
            wordArray[messageLength >> 2] |= 0x80 << ((messageLength % 4) * 8);
            wordArray[numberOfWords - 2] = messageLength << 3;
            wordArray[numberOfWords - 1] = messageLength >>> 29;
            return wordArray;
        }
        
        function wordToHex(value) {
            let hex = '';
            for (let i = 0; i <= 3; i++) {
                const byte = (value >>> (i * 8)) & 255;
                hex += ((byte < 16) ? '0' : '') + byte.toString(16);
            }
            return hex;
        }
        
        const x = convertToWordArray(str);
        let a = 0x67452301;
        let b = 0xEFCDAB89;
        let c = 0x98BADCFE;
        let d = 0x10325476;
        
        for (let k = 0; k < x.length; k += 16) {
            const AA = a, BB = b, CC = c, DD = d;
            
            a = FF(a, b, c, d, x[k + 0], 7, 0xD76AA478);
            d = FF(d, a, b, c, x[k + 1], 12, 0xE8C7B756);
            c = FF(c, d, a, b, x[k + 2], 17, 0x242070DB);
            b = FF(b, c, d, a, x[k + 3], 22, 0xC1BDCEEE);
            a = FF(a, b, c, d, x[k + 4], 7, 0xF57C0FAF);
            d = FF(d, a, b, c, x[k + 5], 12, 0x4787C62A);
            c = FF(c, d, a, b, x[k + 6], 17, 0xA8304613);
            b = FF(b, c, d, a, x[k + 7], 22, 0xFD469501);
            a = FF(a, b, c, d, x[k + 8], 7, 0x698098D8);
            d = FF(d, a, b, c, x[k + 9], 12, 0x8B44F7AF);
            c = FF(c, d, a, b, x[k + 10], 17, 0xFFFF5BB1);
            b = FF(b, c, d, a, x[k + 11], 22, 0x895CD7BE);
            a = FF(a, b, c, d, x[k + 12], 7, 0x6B901122);
            d = FF(d, a, b, c, x[k + 13], 12, 0xFD987193);
            c = FF(c, d, a, b, x[k + 14], 17, 0xA679438E);
            b = FF(b, c, d, a, x[k + 15], 22, 0x49B40821);
            
            a = GG(a, b, c, d, x[k + 1], 5, 0xF61E2562);
            d = GG(d, a, b, c, x[k + 6], 9, 0xC040B340);
            c = GG(c, d, a, b, x[k + 11], 14, 0x265E5A51);
            b = GG(b, c, d, a, x[k + 0], 20, 0xE9B6C7AA);
            a = GG(a, b, c, d, x[k + 5], 5, 0xD62F105D);
            d = GG(d, a, b, c, x[k + 10], 9, 0x2441453);
            c = GG(c, d, a, b, x[k + 15], 14, 0xD8A1E681);
            b = GG(b, c, d, a, x[k + 4], 20, 0xE7D3FBC8);
            a = GG(a, b, c, d, x[k + 9], 5, 0x21E1CDE6);
            d = GG(d, a, b, c, x[k + 14], 9, 0xC33707D6);
            c = GG(c, d, a, b, x[k + 3], 14, 0xF4D50D87);
            b = GG(b, c, d, a, x[k + 8], 20, 0x455A14ED);
            a = GG(a, b, c, d, x[k + 13], 5, 0xA9E3E905);
            d = GG(d, a, b, c, x[k + 2], 9, 0xFCEFA3F8);
            c = GG(c, d, a, b, x[k + 7], 14, 0x676F02D9);
            b = GG(b, c, d, a, x[k + 12], 20, 0x8D2A4C8A);
            
            a = HH(a, b, c, d, x[k + 5], 4, 0xFFFA3942);
            d = HH(d, a, b, c, x[k + 8], 11, 0x8771F681);
            c = HH(c, d, a, b, x[k + 11], 16, 0x6D9D6122);
            b = HH(b, c, d, a, x[k + 14], 23, 0xFDE5380C);
            a = HH(a, b, c, d, x[k + 1], 4, 0xA4BEEA44);
            d = HH(d, a, b, c, x[k + 4], 11, 0x4BDECFA9);
            c = HH(c, d, a, b, x[k + 7], 16, 0xF6BB4B60);
            b = HH(b, c, d, a, x[k + 10], 23, 0xBEBFBC70);
            a = HH(a, b, c, d, x[k + 13], 4, 0x289B7EC6);
            d = HH(d, a, b, c, x[k + 0], 11, 0xEAA127FA);
            c = HH(c, d, a, b, x[k + 3], 16, 0xD4EF3085);
            b = HH(b, c, d, a, x[k + 6], 23, 0x4881D05);
            a = HH(a, b, c, d, x[k + 9], 4, 0xD9D4D039);
            d = HH(d, a, b, c, x[k + 12], 11, 0xE6DB99E5);
            c = HH(c, d, a, b, x[k + 15], 16, 0x1FA27CF8);
            b = HH(b, c, d, a, x[k + 2], 23, 0xC4AC5665);
            
            a = II(a, b, c, d, x[k + 0], 6, 0xF4292244);
            d = II(d, a, b, c, x[k + 7], 10, 0x432AFF97);
            c = II(c, d, a, b, x[k + 14], 15, 0xAB9423A7);
            b = II(b, c, d, a, x[k + 5], 21, 0xFC93A039);
            a = II(a, b, c, d, x[k + 12], 6, 0x655B59C3);
            d = II(d, a, b, c, x[k + 3], 10, 0x8F0CCC92);
            c = II(c, d, a, b, x[k + 10], 15, 0xFFEFF47D);
            b = II(b, c, d, a, x[k + 1], 21, 0x85845DD1);
            a = II(a, b, c, d, x[k + 8], 6, 0x6FA87E4F);
            d = II(d, a, b, c, x[k + 15], 10, 0xFE2CE6E0);
            c = II(c, d, a, b, x[k + 6], 15, 0xA3014314);
            b = II(b, c, d, a, x[k + 13], 21, 0x4E0811A1);
            a = II(a, b, c, d, x[k + 4], 6, 0xF7537E82);
            d = II(d, a, b, c, x[k + 11], 10, 0xBD3AF235);
            c = II(c, d, a, b, x[k + 2], 15, 0x2AD7D2BB);
            b = II(b, c, d, a, x[k + 9], 21, 0xEB86D391);
            
            a = addUnsigned(a, AA);
            b = addUnsigned(b, BB);
            c = addUnsigned(c, CC);
            d = addUnsigned(d, DD);
        }
        
        return (wordToHex(a) + wordToHex(b) + wordToHex(c) + wordToHex(d)).toLowerCase();
    }
};

// 页面加载完成后添加错误重试机制
document.addEventListener('DOMContentLoaded', function() {
    // 为所有图片添加错误重试
    const images = document.querySelectorAll('img');
    images.forEach(img => {
        img.addEventListener('error', function() {
            const config = window.DogeCloudAuthConfig;
            if (config && this.src.includes(config.authParamName)) {
                console.log('图片加载失败，尝试刷新鉴权参数:', this.src);
                const newUrl = window.DogeCloudAuth.generateAuthUrl(this.src, config);
                if (newUrl && newUrl !== this.src) {
                    this.src = newUrl;
                }
            }
        });
    });
    
    // 监听新添加的图片
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            mutation.addedNodes.forEach(function(node) {
                if (node.nodeType === 1) { // Element node
                    const imgs = node.tagName === 'IMG' ? [node] : node.querySelectorAll('img');
                    imgs.forEach(img => {
                        img.addEventListener('error', function() {
                            const config = window.DogeCloudAuthConfig;
                            if (config && this.src.includes(config.authParamName)) {
                                console.log('新图片加载失败，尝试刷新鉴权参数:', this.src);
                                const newUrl = window.DogeCloudAuth.generateAuthUrl(this.src, config);
                                if (newUrl && newUrl !== this.src) {
                                    this.src = newUrl;
                                }
                            }
                        });
                    });
                }
            });
        });
    });
    
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
});
";
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