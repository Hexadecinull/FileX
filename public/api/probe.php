<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_time_limit(20);

$rawUrl = trim($_GET['url'] ?? '');
if ($rawUrl === '') {
    echo json_encode(['error' => 'Missing url parameter']);
    exit;
}

if (!filter_var($rawUrl, FILTER_VALIDATE_URL)) {
    echo json_encode(['error' => 'Invalid URL']);
    exit;
}

$parsed = parse_url($rawUrl);
$scheme = strtolower($parsed['scheme'] ?? '');
if (!in_array($scheme, ['http', 'https'], true)) {
    echo json_encode(['error' => 'Only http/https allowed']);
    exit;
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $rawUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 FileX/1.0 (+https://github.com/hexadecinull/filex)',
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_ENCODING       => '',
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
]);

$startMs = (int) round(microtime(true) * 1000);
$raw     = curl_exec($ch);
$elapsed = (int) round(microtime(true) * 1000) - $startMs;

if ($raw === false) {
    $err = curl_error($ch);
    curl_close($ch);
    echo json_encode(['error' => $err ?: 'Curl request failed', 'url' => $rawUrl]);
    exit;
}

$info       = curl_getinfo($ch);
$headerSize = (int) $info['header_size'];
curl_close($ch);

$rawHeaders = substr($raw, 0, $headerSize);
$body       = substr($raw, $headerSize);

$headers = parseHeaders($rawHeaders);

echo json_encode([
    'url'              => $rawUrl,
    'finalUrl'         => $info['url'],
    'status'           => (int) $info['http_code'],
    'elapsed'          => $elapsed,
    'contentType'      => $headers['content-type'] ?? null,
    'contentLength'    => isset($headers['content-length'])
        ? (int) $headers['content-length']
        : (int) $info['size_download'],
    'server'           => $headers['server'] ?? null,
    'poweredBy'        => $headers['x-powered-by'] ?? null,
    'redirectCount'    => (int) $info['redirect_count'],
    'tlsVersion'       => $info['ssl_verify_result'] !== false ? detectTLS($info) : null,
    'headers'          => $headers,
    'hasDirectoryList' => detectDirectoryListing($body),
    'cms'              => detectCMS($body, $headers),
    'tech'             => detectTech($body, $headers),
    'forms'            => extractForms($body),
    'links'            => extractLinks($body, $rawUrl),
    'scripts'          => extractScripts($body, $rawUrl),
    'metaTags'         => extractMetaTags($body),
    'comments'         => extractHTMLComments($body),
    'bodySnippet'      => mb_substr($body, 0, 1024),
]);

function parseHeaders(string $rawHeaders): array
{
    $lines   = explode("\r\n", $rawHeaders);
    $headers = [];
    foreach ($lines as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $key             = strtolower(trim(substr($line, 0, $pos)));
        $headers[$key]   = trim(substr($line, $pos + 1));
    }
    return $headers;
}

function detectDirectoryListing(string $body): bool
{
    return (bool) preg_match(
        '/(<title>[^<]*(Index of|Directory listing)[^<]*<\/title>|<h1>[^<]*(Index of|Directory listing)[^<]*<\/h1>|Parent Directory<\/a>)/i',
        $body
    );
}

function detectCMS(string $body, array $headers): array
{
    $cms = [];

    if (preg_match('/wp-content|wp-includes|wp-json|WordPress/i', $body)) {
        $cms[] = 'WordPress';
    }
    if (preg_match('/Drupal|drupal\.org|sites\/default\/files/i', $body) ||
        isset($headers['x-drupal-cache'])) {
        $cms[] = 'Drupal';
    }
    if (preg_match('/Joomla|\/components\/com_|joomla/i', $body)) {
        $cms[] = 'Joomla';
    }
    if (preg_match('/Magento|mage\/cookies|Mage\.Cookies/i', $body)) {
        $cms[] = 'Magento';
    }
    if (preg_match('/PrestaShop|prestashop/i', $body) ||
        isset($headers['x-prestashop-cache'])) {
        $cms[] = 'PrestaShop';
    }
    if (preg_match('/\/typo3\/|typo3conf|This website is powered by TYPO3/i', $body)) {
        $cms[] = 'TYPO3';
    }
    if (preg_match('/ghost-sdk|content\.ghost\.io|Ghost blog/i', $body)) {
        $cms[] = 'Ghost';
    }
    if (preg_match('/Shopify|shopify\.com\/s\/files|cdn\.shopify/i', $body)) {
        $cms[] = 'Shopify';
    }
    if (preg_match('/squarespace\.com|\/universal\/scripts-compressed\/|Squarespace/i', $body)) {
        $cms[] = 'Squarespace';
    }
    if (preg_match('/wix\.com|X-Wix-Published-Version/i', $body) ||
        isset($headers['x-wix-request-id'])) {
        $cms[] = 'Wix';
    }

    return $cms;
}

function detectTech(string $body, array $headers): array
{
    $tech = [];

    $phpVersionMatch = null;
    if (preg_match('/^PHP\/(\S+)$/i', $headers['x-powered-by'] ?? '', $m)) {
        $phpVersionMatch = $m[1];
    }
    if (isset($headers['x-powered-by']) && str_contains($headers['x-powered-by'], 'PHP')) {
        $tech[] = 'PHP' . ($phpVersionMatch ? ' ' . $phpVersionMatch : '');
    }
    if (isset($headers['x-powered-by']) && str_contains(strtolower($headers['x-powered-by']), 'asp.net')) {
        $tech[] = 'ASP.NET';
    }
    if (preg_match('/^(nginx)\/?(\S*)/i', $headers['server'] ?? '', $m)) {
        $tech[] = 'nginx' . ($m[2] ? ' ' . $m[2] : '');
    }
    if (preg_match('/^Apache\/?(\S*)/i', $headers['server'] ?? '', $m)) {
        $tech[] = 'Apache' . ($m[1] ? ' ' . $m[1] : '');
    }
    if (isset($headers['cf-ray'])) {
        $tech[] = 'Cloudflare';
    }
    if (preg_match('/react|__REACT__|data-reactroot/i', $body)) {
        $tech[] = 'React';
    }
    if (preg_match('/ng-version|angular\.min\.js|AngularJS/i', $body)) {
        $tech[] = 'Angular';
    }
    if (preg_match('/vue\.js|vue\.min\.js|__vue__/i', $body)) {
        $tech[] = 'Vue.js';
    }
    if (preg_match('/jquery/i', $body)) {
        preg_match('/jquery[.-]?(\d+\.\d+\.\d+)/i', $body, $jm);
        $tech[] = 'jQuery' . (isset($jm[1]) ? ' ' . $jm[1] : '');
    }
    if (preg_match('/bootstrap/i', $body)) {
        $tech[] = 'Bootstrap';
    }
    if (preg_match('/\/__webpack_require__|webpackJsonp/i', $body)) {
        $tech[] = 'Webpack';
    }
    if (preg_match('/next\.js|__NEXT_DATA__|_next\/static/i', $body)) {
        $tech[] = 'Next.js';
    }
    if (preg_match('/Nuxt\.js|__nuxt|nuxt\.config/i', $body)) {
        $tech[] = 'Nuxt.js';
    }
    if (preg_match('/Laravel|laravel_session|XSRF-TOKEN/i', $body) ||
        (isset($headers['set-cookie']) && str_contains($headers['set-cookie'], 'laravel_session'))) {
        $tech[] = 'Laravel';
    }
    if (isset($headers['x-aspnet-version'])) {
        $tech[] = 'ASP.NET ' . $headers['x-aspnet-version'];
    }
    if (preg_match('/gsap|TweenMax|TweenLite/i', $body)) {
        $tech[] = 'GSAP';
    }

    return array_values(array_unique($tech));
}

function extractForms(string $body): array
{
    preg_match_all('/<form[^>]*action=["\']?([^"\'> ]+)["\']?[^>]*>/i', $body, $matches);
    return array_values(array_unique($matches[1]));
}

function extractLinks(string $body, string $baseUrl): array
{
    preg_match_all('/<a[^>]+href=["\']([^"\'#?]+)["\'][^>]*>/i', $body, $matches);
    $base   = parse_url($baseUrl);
    $baseDomain = ($base['scheme'] ?? 'https') . '://' . ($base['host'] ?? '');
    $links  = [];
    foreach ($matches[1] as $href) {
        if (str_starts_with($href, '/')) {
            $links[] = $baseDomain . $href;
        } elseif (str_starts_with($href, 'http')) {
            $links[] = $href;
        }
    }
    return array_slice(array_values(array_unique($links)), 0, 80);
}

function extractScripts(string $body, string $baseUrl): array
{
    preg_match_all('/<script[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $body, $matches);
    $base   = parse_url($baseUrl);
    $baseDomain = ($base['scheme'] ?? 'https') . '://' . ($base['host'] ?? '');
    $scripts = [];
    foreach ($matches[1] as $src) {
        if (str_starts_with($src, '/')) {
            $scripts[] = $baseDomain . $src;
        } elseif (str_starts_with($src, 'http')) {
            $scripts[] = $src;
        }
    }
    return array_values(array_unique($scripts));
}

function extractMetaTags(string $body): array
{
    preg_match_all('/<meta\s+(?:name|property)=["\']([^"\']+)["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/i', $body, $m);
    $tags = [];
    foreach ($m[1] as $i => $name) {
        $tags[$name] = $m[2][$i];
    }
    return $tags;
}

function extractHTMLComments(string $body): array
{
    preg_match_all('/<!--(.*?)-->/s', $body, $matches);
    $comments = [];
    foreach ($matches[1] as $c) {
        $c = trim($c);
        if (strlen($c) > 3 && !str_starts_with($c, '[if') && !str_starts_with($c, '<![')) {
            $comments[] = mb_substr($c, 0, 200);
        }
    }
    return array_slice(array_values(array_unique($comments)), 0, 20);
}

function detectTLS(array $info): ?string
{
    return isset($info['ssl_engines']) ? 'TLS' : null;
}
