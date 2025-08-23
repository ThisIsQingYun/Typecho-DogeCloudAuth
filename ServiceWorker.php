<?php
/**
 * 多吉云URL鉴权 Service Worker 生成器
 * 
 * @package DogeCloudAuth
 * @version 1.0.0
 */

class DogeCloudAuth_ServiceWorker extends Typecho_Widget {
    
    /**
     * 执行Service Worker生成
     */
    public function execute() {
        try {
            // 清理输出缓冲
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            
            // 设置响应头
            header("Content-Type: application/javascript; charset=utf-8");
            header("HTTP/1.1 200 OK");
            header("Service-Worker-Allowed: /");
            header("Cache-Control: no-cache, no-store, must-revalidate");
            header("Pragma: no-cache");
            header("Expires: 0");

            // 获取插件配置
            $options = Helper::options()->plugin("DogeCloudAuth");
            if (!$options) {
                throw new Exception("未找到多吉云鉴权插件配置");
            }

            // 构建配置数组
            $config = array(
                "authParamName" => $options->authParamName ?: "auth_key",
                "duration" => (int) ($options->duration ?: 1800),
                "extensions" => $this->parseExtensions($options->allowedExtensions),
                "domainKeys" => $this->obfuscateDomainKeys($options->domainKeys),
                "resourceSuffixes" => $this->parseResourceSuffixes($options->resourceSuffixes)
            );

            // 生成Service Worker代码
            $jsCode = $this->generateServiceWorkerCode($config);
            echo $jsCode;
            exit;
            
        } catch (Exception $e) {
            header("HTTP/1.1 500 Internal Server Error");
            echo "// Service Worker生成失败: " . $e->getMessage();
            exit;
        }
    }

    /**
     * 解析文件扩展名
     */
    private function parseExtensions($allowedExtensions) {
        $extensions = array_map(function ($ext) {
            return preg_quote(ltrim(trim($ext), "."), "/");
        }, explode(";", $allowedExtensions));
        return array_filter($extensions);
    }

    /**
     * 混淆域名密钥（简单的安全措施）
     */
    private function obfuscateDomainKeys($domainKeys) {
        $result = array();
        $lines = array_filter(array_map('trim', explode("\n", $domainKeys)));
        
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($domain, $key) = explode(':', $line, 2);
                $domain = trim($domain);
                $key = trim($key);
                
                // 简单的混淆：base64编码后反转
                $obfuscatedKey = strrev(base64_encode($key));
                $result[$domain] = $obfuscatedKey;
            }
        }
        
        return $result;
    }

    /**
     * 解析多吉云资源后缀配置
     */
    private function parseResourceSuffixes($resourceSuffixes) {
        if (empty($resourceSuffixes)) {
            return array();
        }
        
        $suffixes = array();
        $lines = array_filter(array_map('trim', explode("\n", $resourceSuffixes)));
        
        foreach ($lines as $line) {
            if (!empty($line)) {
                // 确保后缀以/开头
                if (!str_starts_with($line, '/')) {
                    $line = '/' . $line;
                }
                $suffixes[] = $line;
            }
        }
        
        return $suffixes;
    }

    /**
     * 生成Service Worker JavaScript代码
     */
    private function generateServiceWorkerCode($config) {
        // 安全地编码配置
        $authParamNameJson = json_encode($config["authParamName"], JSON_UNESCAPED_SLASHES);
        $durationJson = json_encode($config["duration"]);
        $extensionsPattern = implode("|", $config["extensions"]);
        $extensionsRegexJson = json_encode("." . "(" . $extensionsPattern . ")" . "$", JSON_UNESCAPED_SLASHES);
        $domainKeysJson = json_encode($config["domainKeys"], JSON_UNESCAPED_SLASHES);
        $resourceSuffixesJson = json_encode($config["resourceSuffixes"], JSON_UNESCAPED_SLASHES);

        return "// 多吉云URL鉴权 Service Worker\n" .
               "// 版本: 1.0.0\n" .
               "// 自动生成，请勿手动修改\n\n" .
               
               "const DOGECLOUD_AUTH_CONFIG = {\n" .
               "    authParamName: {$authParamNameJson},\n" .
               "    duration: {$durationJson},\n" .
               "    extensionsRegex: new RegExp({$extensionsRegexJson}, 'i'),\n" .
               "    domainKeys: {$domainKeysJson},\n" .
               "    resourceSuffixes: {$resourceSuffixesJson}\n" .
               "};\n\n" .
               
               "// 解混淆密钥\n" .
               "function deobfuscateKey(obfuscatedKey) {\n" .
               "    try {\n" .
               "        return atob(obfuscatedKey.split('').reverse().join(''));\n" .
               "    } catch (e) {\n" .
               "        console.error('密钥解混淆失败:', e);\n" .
               "        return null;\n" .
               "    }\n" .
               "}\n\n" .
               
               "// 生成MD5哈希（简化版）\n" .
               "function md5(str) {\n" .
               "    // 真正的MD5实现\n" .
               "    function rotateLeft(value, amount) {\n" .
               "        return (value << amount) | (value >>> (32 - amount));\n" .
               "    }\n" .
               "    \n" .
               "    function addUnsigned(x, y) {\n" .
               "        const x4 = (x & 0x40000000);\n" .
               "        const y4 = (y & 0x40000000);\n" .
               "        const x8 = (x & 0x80000000);\n" .
               "        const y8 = (y & 0x80000000);\n" .
               "        const result = (x & 0x3FFFFFFF) + (y & 0x3FFFFFFF);\n" .
               "        if (x4 & y4) {\n" .
               "            return (result ^ 0x80000000 ^ x8 ^ y8);\n" .
               "        }\n" .
               "        if (x4 | y4) {\n" .
               "            if (result & 0x40000000) {\n" .
               "                return (result ^ 0xC0000000 ^ x8 ^ y8);\n" .
               "            } else {\n" .
               "                return (result ^ 0x40000000 ^ x8 ^ y8);\n" .
               "            }\n" .
               "        } else {\n" .
               "            return (result ^ x8 ^ y8);\n" .
               "        }\n" .
               "    }\n" .
               "    \n" .
               "    function F(x, y, z) { return (x & y) | ((~x) & z); }\n" .
               "    function G(x, y, z) { return (x & z) | (y & (~z)); }\n" .
               "    function H(x, y, z) { return (x ^ y ^ z); }\n" .
               "    function I(x, y, z) { return (y ^ (x | (~z))); }\n" .
               "    \n" .
               "    function FF(a, b, c, d, x, s, ac) {\n" .
               "        a = addUnsigned(a, addUnsigned(addUnsigned(F(b, c, d), x), ac));\n" .
               "        return addUnsigned(rotateLeft(a, s), b);\n" .
               "    }\n" .
               "    \n" .
               "    function GG(a, b, c, d, x, s, ac) {\n" .
               "        a = addUnsigned(a, addUnsigned(addUnsigned(G(b, c, d), x), ac));\n" .
               "        return addUnsigned(rotateLeft(a, s), b);\n" .
               "    }\n" .
               "    \n" .
               "    function HH(a, b, c, d, x, s, ac) {\n" .
               "        a = addUnsigned(a, addUnsigned(addUnsigned(H(b, c, d), x), ac));\n" .
               "        return addUnsigned(rotateLeft(a, s), b);\n" .
               "    }\n" .
               "    \n" .
               "    function II(a, b, c, d, x, s, ac) {\n" .
               "        a = addUnsigned(a, addUnsigned(addUnsigned(I(b, c, d), x), ac));\n" .
               "        return addUnsigned(rotateLeft(a, s), b);\n" .
               "    }\n" .
               "    \n" .
               "    function convertToWordArray(str) {\n" .
               "        let wordArray = [];\n" .
               "        let messageLength = str.length;\n" .
               "        let numberOfWords = (((messageLength + 8) - ((messageLength + 8) % 64)) / 64 + 1) * 16;\n" .
               "        for (let i = 0; i < numberOfWords; i++) {\n" .
               "            wordArray[i] = 0;\n" .
               "        }\n" .
               "        for (let i = 0; i < messageLength; i++) {\n" .
               "            wordArray[i >> 2] |= str.charCodeAt(i) << ((i % 4) * 8);\n" .
               "        }\n" .
               "        wordArray[messageLength >> 2] |= 0x80 << ((messageLength % 4) * 8);\n" .
               "        wordArray[numberOfWords - 2] = messageLength << 3;\n" .
               "        wordArray[numberOfWords - 1] = messageLength >>> 29;\n" .
               "        return wordArray;\n" .
               "    }\n" .
               "    \n" .
               "    function wordToHex(value) {\n" .
               "        let hex = '';\n" .
               "        for (let i = 0; i <= 3; i++) {\n" .
               "            const byte = (value >>> (i * 8)) & 255;\n" .
               "            hex += ((byte < 16) ? '0' : '') + byte.toString(16);\n" .
               "        }\n" .
               "        return hex;\n" .
               "    }\n" .
               "    \n" .
               "    const x = convertToWordArray(str);\n" .
               "    let a = 0x67452301;\n" .
               "    let b = 0xEFCDAB89;\n" .
               "    let c = 0x98BADCFE;\n" .
               "    let d = 0x10325476;\n" .
               "    \n" .
               "    for (let k = 0; k < x.length; k += 16) {\n" .
               "        const AA = a;\n" .
               "        const BB = b;\n" .
               "        const CC = c;\n" .
               "        const DD = d;\n" .
               "        \n" .
               "        a = FF(a, b, c, d, x[k + 0], 7, 0xD76AA478);\n" .
               "        d = FF(d, a, b, c, x[k + 1], 12, 0xE8C7B756);\n" .
               "        c = FF(c, d, a, b, x[k + 2], 17, 0x242070DB);\n" .
               "        b = FF(b, c, d, a, x[k + 3], 22, 0xC1BDCEEE);\n" .
               "        a = FF(a, b, c, d, x[k + 4], 7, 0xF57C0FAF);\n" .
               "        d = FF(d, a, b, c, x[k + 5], 12, 0x4787C62A);\n" .
               "        c = FF(c, d, a, b, x[k + 6], 17, 0xA8304613);\n" .
               "        b = FF(b, c, d, a, x[k + 7], 22, 0xFD469501);\n" .
               "        a = FF(a, b, c, d, x[k + 8], 7, 0x698098D8);\n" .
               "        d = FF(d, a, b, c, x[k + 9], 12, 0x8B44F7AF);\n" .
               "        c = FF(c, d, a, b, x[k + 10], 17, 0xFFFF5BB1);\n" .
               "        b = FF(b, c, d, a, x[k + 11], 22, 0x895CD7BE);\n" .
               "        a = FF(a, b, c, d, x[k + 12], 7, 0x6B901122);\n" .
               "        d = FF(d, a, b, c, x[k + 13], 12, 0xFD987193);\n" .
               "        c = FF(c, d, a, b, x[k + 14], 17, 0xA679438E);\n" .
               "        b = FF(b, c, d, a, x[k + 15], 22, 0x49B40821);\n" .
               "        \n" .
               "        a = GG(a, b, c, d, x[k + 1], 5, 0xF61E2562);\n" .
               "        d = GG(d, a, b, c, x[k + 6], 9, 0xC040B340);\n" .
               "        c = GG(c, d, a, b, x[k + 11], 14, 0x265E5A51);\n" .
               "        b = GG(b, c, d, a, x[k + 0], 20, 0xE9B6C7AA);\n" .
               "        a = GG(a, b, c, d, x[k + 5], 5, 0xD62F105D);\n" .
               "        d = GG(d, a, b, c, x[k + 10], 9, 0x2441453);\n" .
               "        c = GG(c, d, a, b, x[k + 15], 14, 0xD8A1E681);\n" .
               "        b = GG(b, c, d, a, x[k + 4], 20, 0xE7D3FBC8);\n" .
               "        a = GG(a, b, c, d, x[k + 9], 5, 0x21E1CDE6);\n" .
               "        d = GG(d, a, b, c, x[k + 14], 9, 0xC33707D6);\n" .
               "        c = GG(c, d, a, b, x[k + 3], 14, 0xF4D50D87);\n" .
               "        b = GG(b, c, d, a, x[k + 8], 20, 0x455A14ED);\n" .
               "        a = GG(a, b, c, d, x[k + 13], 5, 0xA9E3E905);\n" .
               "        d = GG(d, a, b, c, x[k + 2], 9, 0xFCEFA3F8);\n" .
               "        c = GG(c, d, a, b, x[k + 7], 14, 0x676F02D9);\n" .
               "        b = GG(b, c, d, a, x[k + 12], 20, 0x8D2A4C8A);\n" .
               "        \n" .
               "        a = HH(a, b, c, d, x[k + 5], 4, 0xFFFA3942);\n" .
               "        d = HH(d, a, b, c, x[k + 8], 11, 0x8771F681);\n" .
               "        c = HH(c, d, a, b, x[k + 11], 16, 0x6D9D6122);\n" .
               "        b = HH(b, c, d, a, x[k + 14], 23, 0xFDE5380C);\n" .
               "        a = HH(a, b, c, d, x[k + 1], 4, 0xA4BEEA44);\n" .
               "        d = HH(d, a, b, c, x[k + 4], 11, 0x4BDECFA9);\n" .
               "        c = HH(c, d, a, b, x[k + 7], 16, 0xF6BB4B60);\n" .
               "        b = HH(b, c, d, a, x[k + 10], 23, 0xBEBFBC70);\n" .
               "        a = HH(a, b, c, d, x[k + 13], 4, 0x289B7EC6);\n" .
               "        d = HH(d, a, b, c, x[k + 0], 11, 0xEAA127FA);\n" .
               "        c = HH(c, d, a, b, x[k + 3], 16, 0xD4EF3085);\n" .
               "        b = HH(b, c, d, a, x[k + 6], 23, 0x4881D05);\n" .
               "        a = HH(a, b, c, d, x[k + 9], 4, 0xD9D4D039);\n" .
               "        d = HH(d, a, b, c, x[k + 12], 11, 0xE6DB99E5);\n" .
               "        c = HH(c, d, a, b, x[k + 15], 16, 0x1FA27CF8);\n" .
               "        b = HH(b, c, d, a, x[k + 2], 23, 0xC4AC5665);\n" .
               "        \n" .
               "        a = II(a, b, c, d, x[k + 0], 6, 0xF4292244);\n" .
               "        d = II(d, a, b, c, x[k + 7], 10, 0x432AFF97);\n" .
               "        c = II(c, d, a, b, x[k + 14], 15, 0xAB9423A7);\n" .
               "        b = II(b, c, d, a, x[k + 5], 21, 0xFC93A039);\n" .
               "        a = II(a, b, c, d, x[k + 12], 6, 0x655B59C3);\n" .
               "        d = II(d, a, b, c, x[k + 3], 10, 0x8F0CCC92);\n" .
               "        c = II(c, d, a, b, x[k + 10], 15, 0xFFEFF47D);\n" .
               "        b = II(b, c, d, a, x[k + 1], 21, 0x85845DD1);\n" .
               "        a = II(a, b, c, d, x[k + 8], 6, 0x6FA87E4F);\n" .
               "        d = II(d, a, b, c, x[k + 15], 10, 0xFE2CE6E0);\n" .
               "        c = II(c, d, a, b, x[k + 6], 15, 0xA3014314);\n" .
               "        b = II(b, c, d, a, x[k + 13], 21, 0x4E0811A1);\n" .
               "        a = II(a, b, c, d, x[k + 4], 6, 0xF7537E82);\n" .
               "        d = II(d, a, b, c, x[k + 11], 10, 0xBD3AF235);\n" .
               "        c = II(c, d, a, b, x[k + 2], 15, 0x2AD7D2BB);\n" .
               "        b = II(b, c, d, a, x[k + 9], 21, 0xEB86D391);\n" .
               "        \n" .
               "        a = addUnsigned(a, AA);\n" .
               "        b = addUnsigned(b, BB);\n" .
               "        c = addUnsigned(c, CC);\n" .
               "        d = addUnsigned(d, DD);\n" .
               "    }\n" .
               "    \n" .
               "    return (wordToHex(a) + wordToHex(b) + wordToHex(c) + wordToHex(d)).toLowerCase();\n" .
               "}\n\n" .
               
               "// 生成随机字符串\n" .
               "function generateRandomString(length = 32) {\n" .
               "    const characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';\n" .
               "    let result = '';\n" .
               "    for (let i = 0; i < length; i++) {\n" .
               "        result += characters.charAt(Math.floor(Math.random() * characters.length));\n" .
               "    }\n" .
               "    return result;\n" .
               "}\n\n" .
               
               "// 提取URL中的资源后缀\n" .
               "function extractSuffixFromUrl(url) {\n" .
               "    try {\n" .
               "        const urlObj = new URL(url);\n" .
               "        const pathname = urlObj.pathname;\n" .
               "        \n" .
               "        // 检查是否包含配置的后缀\n" .
               "        for (const suffix of DOGECLOUD_AUTH_CONFIG.resourceSuffixes) {\n" .
               "            if (pathname.endsWith(suffix)) {\n" .
               "                const basePath = pathname.substring(0, pathname.length - suffix.length);\n" .
               "                return { basePath, suffix };\n" .
               "            }\n" .
               "        }\n" .
               "        \n" .
               "        return { basePath: pathname, suffix: '' };\n" .
               "    } catch (e) {\n" .
               "        return { basePath: url, suffix: '' };\n" .
               "    }\n" .
               "}\n\n" .
               
               "// 生成鉴权URL（多吉云标准格式）\n" .
               "function generateAuthUrl(url, secretKey) {\n" .
               "    try {\n" .
               "        const urlObj = new URL(url);\n" .
               "        const { basePath, suffix } = extractSuffixFromUrl(url);\n" .
               "        const timestamp = Math.floor(Date.now() / 1000) + DOGECLOUD_AUTH_CONFIG.duration;\n" .
               "        \n" .
               "        // 生成随机字符串（1-100位，支持大小写字母和数字）\n" .
               "        const rand = generateRandomString(32);\n" .
               "        \n" .
               "        // 用户ID，默认为0\n" .
               "        const uid = 0;\n" .
               "        \n" .
               "        // 按照多吉云格式计算MD5：md5(basePath-timestamp-rand-uid-key)\n" .
               "        const signString = basePath + '-' + timestamp + '-' + rand + '-' + uid + '-' + secretKey;\n" .
               "        const md5hash = md5(signString);\n" .
               "        \n" .
               "        // 按照多吉云格式组装auth_key：timestamp-rand-uid-md5hash\n" .
               "        const authKey = timestamp + '-' + rand + '-' + uid + '-' + md5hash;\n" .
               "        \n" .
               "        // 重新构建URL，先设置基础路径，再添加鉴权参数，最后添加后缀\n" .
               "        urlObj.pathname = basePath;\n" .
               "        urlObj.searchParams.set(DOGECLOUD_AUTH_CONFIG.authParamName, authKey);\n" .
               "        \n" .
               "        // 如果有后缀，添加到URL末尾\n" .
               "        let finalUrl = urlObj.toString();\n" .
               "        if (suffix) {\n" .
               "            finalUrl += suffix;\n" .
               "        }\n" .
               "        \n" .
               "        return finalUrl;\n" .
               "    } catch (e) {\n" .
               "        console.error('生成鉴权URL失败:', e);\n" .
               "        return url;\n" .
               "    }\n" .
               "}\n\n" .
               
               "// 检查URL是否需要鉴权\n" .
               "function needsAuth(url) {\n" .
               "    try {\n" .
               "        const urlObj = new URL(url);\n" .
               "        \n" .
               "        // 检查文件扩展名\n" .
               "        if (!DOGECLOUD_AUTH_CONFIG.extensionsRegex.test(urlObj.pathname)) {\n" .
               "            return false;\n" .
               "        }\n" .
               "        \n" .
               "        // 检查是否已有鉴权参数\n" .
               "        if (urlObj.searchParams.has(DOGECLOUD_AUTH_CONFIG.authParamName)) {\n" .
               "            return false;\n" .
               "        }\n" .
               "        \n" .
               "        // 检查域名是否在配置中\n" .
               "        return DOGECLOUD_AUTH_CONFIG.domainKeys.hasOwnProperty(urlObj.hostname);\n" .
               "    } catch (e) {\n" .
               "        return false;\n" .
               "    }\n" .
               "}\n\n" .
               
               "// 检查插件是否已禁用\n" .
               "let pluginDisabled = false;\n" .
               "let lastPluginCheck = 0;\n" .
               "const PLUGIN_CHECK_INTERVAL = 30000; // 30秒检查一次\n" .
               "\n" .
               "async function checkPluginStatus() {\n" .
               "    const now = Date.now();\n" .
               "    if (now - lastPluginCheck < PLUGIN_CHECK_INTERVAL) {\n" .
               "        return !pluginDisabled;\n" .
               "    }\n" .
               "    \n" .
               "    try {\n" .
               "        // 尝试访问Service Worker端点来检查插件状态\n" .
               "        const response = await fetch('/action/dogecloud-auth-sw', {\n" .
               "            method: 'HEAD',\n" .
               "            cache: 'no-cache'\n" .
               "        });\n" .
               "        \n" .
               "        pluginDisabled = !response.ok;\n" .
               "        lastPluginCheck = now;\n" .
               "        \n" .
               "        if (pluginDisabled) {\n" .
               "            console.log('检测到插件已禁用，Service Worker将停止处理请求');\n" .
               "        }\n" .
               "        \n" .
               "        return !pluginDisabled;\n" .
               "    } catch (e) {\n" .
               "        // 如果检查失败，假设插件已禁用\n" .
               "        pluginDisabled = true;\n" .
               "        lastPluginCheck = now;\n" .
               "        console.warn('插件状态检查失败，假设插件已禁用:', e);\n" .
               "        return false;\n" .
               "    }\n" .
               "}\n" .
               "\n" .
               "// Service Worker事件监听\n" .
               "self.addEventListener('fetch', function(event) {\n" .
               "    const requestUrl = event.request.url;\n" .
               "    \n" .
               "    // 检查是否需要鉴权\n" .
               "    if (!needsAuth(requestUrl)) {\n" .
               "        return; // 不拦截，使用默认处理\n" .
               "    }\n" .
               "    \n" .
               "    // 检查请求是否来自浏览器的直接导航（避免与服务端处理冲突）\n" .
               "    if (event.request.mode === 'navigate' || event.request.destination === 'document') {\n" .
               "        return; // 不拦截页面导航请求\n" .
               "    }\n" .
               "    \n" .
               "    // 增强的请求处理函数\n" .
               "    async function handleAuthRequest() {\n" .
               "        try {\n" .
               "            // 首先检查插件是否已禁用\n" .
               "            const pluginEnabled = await checkPluginStatus();\n" .
               "            if (!pluginEnabled) {\n" .
               "                // 插件已禁用，直接使用原始请求\n" .
               "                return fetch(event.request);\n" .
               "            }\n" .
               "            \n" .
               "            const urlObj = new URL(requestUrl);\n" .
               "            const hostname = urlObj.hostname;\n" .
               "            const obfuscatedKey = DOGECLOUD_AUTH_CONFIG.domainKeys[hostname];\n" .
               "            \n" .
               "            if (!obfuscatedKey) {\n" .
               "                return fetch(event.request);\n" .
               "            }\n" .
               "            \n" .
               "            const secretKey = deobfuscateKey(obfuscatedKey);\n" .
               "            if (!secretKey) {\n" .
               "                return fetch(event.request);\n" .
               "            }\n" .
               "            \n" .
               "            const authUrl = generateAuthUrl(requestUrl, secretKey);\n" .
               "            \n" .
               "            // 尝试发起请求，带重试机制\n" .
               "            let lastError;\n" .
               "            for (let attempt = 0; attempt < 3; attempt++) {\n" .
               "                try {\n" .
               "                    const response = await fetch(authUrl, {\n" .
               "                        method: event.request.method,\n" .
               "                        headers: event.request.headers,\n" .
               "                        body: event.request.body,\n" .
               "                        mode: event.request.mode,\n" .
               "                        credentials: event.request.credentials,\n" .
               "                        cache: 'no-cache', // 避免缓存过期的鉴权URL\n" .
               "                        redirect: event.request.redirect,\n" .
               "                        referrer: event.request.referrer\n" .
               "                    });\n" .
               "                    \n" .
               "                    // 检查响应状态\n" .
               "                    if (response.ok) {\n" .
               "                        return response;\n" .
               "                    } else if (response.status === 403 || response.status === 401) {\n" .
               "                        // 鉴权失败，可能是过期，重新生成鉴权URL\n" .
               "                        console.warn('鉴权失败，重新生成鉴权URL，尝试次数:', attempt + 1);\n" .
               "                        const newAuthUrl = generateAuthUrl(requestUrl, secretKey);\n" .
               "                        if (newAuthUrl !== authUrl) {\n" .
               "                            authUrl = newAuthUrl;\n" .
               "                            continue;\n" .
               "                        }\n" .
               "                    }\n" .
               "                    \n" .
               "                    return response;\n" .
               "                } catch (error) {\n" .
               "                    lastError = error;\n" .
               "                    console.warn('Service Worker请求失败，尝试次数:', attempt + 1, error);\n" .
               "                    \n" .
               "                    // 如果不是最后一次尝试，等待一段时间后重试\n" .
               "                    if (attempt < 2) {\n" .
               "                        await new Promise(resolve => setTimeout(resolve, 1000 * (attempt + 1)));\n" .
               "                    }\n" .
               "                }\n" .
               "            }\n" .
               "            \n" .
               "            // 所有重试都失败，抛出最后的错误\n" .
               "            throw lastError;\n" .
               "            \n" .
               "        } catch (e) {\n" .
               "            console.error('Service Worker处理请求失败:', e);\n" .
               "            // 发生错误时，使用原始请求\n" .
               "            return fetch(event.request);\n" .
               "        }\n" .
               "    }\n" .
               "    \n" .
               "    // 拦截请求并处理\n" .
               "    event.respondWith(handleAuthRequest());\n" .
               "});\n\n" .
               "\n" .
               "// 与客户端脚本通信\n" .
               "self.addEventListener('message', function(event) {\n" .
               "    if (event.data && event.data.type === 'REFRESH_AUTH') {\n" .
               "        // 客户端请求刷新鉴权，清除可能的缓存\n" .
               "        console.log('收到客户端刷新鉴权请求');\n" .
               "        // 可以在这里添加额外的处理逻辑\n" .
               "    }\n" .
               "});\n\n" .
               
               "// Service Worker安装事件\n" .
               "self.addEventListener('install', function(event) {\n" .
               "    console.log('多吉云鉴权 Service Worker 安装成功');\n" .
               "    event.waitUntil(self.skipWaiting());\n" .
               "});\n\n" .
               
               "// Service Worker激活事件\n" .
               "self.addEventListener('activate', function(event) {\n" .
               "    console.log('多吉云鉴权 Service Worker 激活成功');\n" .
               "    event.waitUntil(self.clients.claim());\n" .
               "});\n\n" .
               
               "// 错误处理\n" .
               "self.addEventListener('error', function(event) {\n" .
               "    console.error('多吉云鉴权 Service Worker 错误:', event.error);\n" .
               "});";
    }
}