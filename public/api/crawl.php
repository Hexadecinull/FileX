<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['error' => 'PHP fatal: ' . $e['message'], 'results' => [], 'done' => true]);
    }
});

$domain = trim($_GET['domain'] ?? '');
if ($domain === '') { echo json_encode(['error' => 'Missing domain', 'results' => []]); exit; }

$domain  = strtolower(preg_replace('/^https?:\/\//i', '', $domain));
$domain  = rtrim(explode('/', $domain)[0], '/');
$scheme  = in_array(trim($_GET['scheme'] ?? 'https'), ['http', 'https'], true) ? trim($_GET['scheme'] ?? 'https') : 'https';
$batch   = max(1, min((int)($_GET['batch'] ?? 20), 60));
$baseUrl = $scheme . '://' . $domain;

$queue      = json_decode($_GET['queue']   ?? '[]', true) ?: [];
$visited    = json_decode($_GET['visited'] ?? '[]', true) ?: [];
$visitedSet = array_flip($visited);

// On first request with empty state, seed with root
if (empty($queue) && empty($visited)) {
    $queue = ['/'];
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 FileX/1.0',
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_ENCODING       => '',
]);

// Build baseline on first request
$baseline = null;
if (empty($visited)) {
    $baseline = buildBaseline($ch, $baseUrl);
}
$effectiveBaseline = $baseline;
$rawBaseline = $_GET['baseline'] ?? '';
if ($rawBaseline !== '' && $baseline === null) {
    $decoded = json_decode(base64_decode($rawBaseline), true);
    if (is_array($decoded)) $effectiveBaseline = $decoded;
}

$results   = [];
$processed = 0;

while (!empty($queue) && $processed < $batch) {
    $path = array_shift($queue);
    $norm = normalizePath($path);
    if ($norm === null || isset($visitedSet[$norm])) continue;

    $visitedSet[$norm] = true;
    $visited[]         = $norm;
    $processed++;

    $url = $baseUrl . $norm;
    curl_setopt($ch, CURLOPT_URL, $url);

    $raw  = curl_exec($ch);
    $info = curl_getinfo($ch);

    $status = (int)$info['http_code'];
    if ($status === 0) continue;

    $headerSize  = (int)$info['header_size'];
    $rawHeaders  = substr($raw ?: '', 0, $headerSize);
    $body        = substr($raw ?: '', $headerSize);
    $headers     = parseHeaders($rawHeaders);
    $contentType = explode(';', $headers['content-type'] ?? '')[0];
    $bodyLen     = strlen($body);
    $bodyHash    = md5(substr($body, 0, 512));

    if (matchesBaseline($effectiveBaseline, $status, $bodyLen, $bodyHash)) continue;

    $contentLength = isset($headers['content-length'])
        ? (int)$headers['content-length']
        : (int)$info['size_download'];

    $results[] = [
        'path'             => $norm,
        'status'           => $status,
        'contentType'      => $contentType,
        'contentLength'    => $contentLength,
        'server'           => $headers['server'] ?? null,
        'redirect'         => $headers['location'] ?? null,
        'hasDirectoryList' => detectDirList($body),
        'interesting'      => isInteresting($status, $norm),
    ];

    // Follow redirect within same domain
    if ($status >= 301 && $status <= 308 && !empty($headers['location'])) {
        $loc = resolveUrl($headers['location'], $domain, $norm);
        if ($loc !== null && !isset($visitedSet[$loc])) {
            array_unshift($queue, $loc);
        }
    }

    // Extract links from HTML/CSS — using wget's exact attribute list
    if ($status >= 200 && $status < 300) {
        $mime = strtolower(explode(';', $contentType)[0]);
        if (strpos($mime, 'html') !== false || strpos($mime, 'xhtml') !== false) {
            $links = extractLinksFromHtml($body, $domain, $norm);
            foreach ($links as $link) {
                if (!isset($visitedSet[$link])) $queue[] = $link;
            }
        } elseif (strpos($mime, 'css') !== false) {
            $links = extractLinksFromCss($body, $domain, $norm);
            foreach ($links as $link) {
                if (!isset($visitedSet[$link])) $queue[] = $link;
            }
        }
    }
}

curl_close($ch);

$queue = array_values(array_unique(array_filter($queue, fn($p) => !isset($visitedSet[$p]))));
$done  = empty($queue);

$response = [
    'domain'  => $domain,
    'results' => $results,
    'total'   => count($visited),
    'done'    => $done,
    'queue'   => $queue,
    'visited' => $visited,
];

if ($baseline !== null) {
    $response['baseline']        = $baseline;
    $response['baselineEncoded'] = base64_encode(json_encode($baseline));
}

echo json_encode($response);

// ─────────────────────────────────────────────────────────────────────────────
// Link extraction — modelled directly on wget2's html_url.c attr list
// ─────────────────────────────────────────────────────────────────────────────

/**
 * wget2 html_url.c extracts URLs from these attributes (verbatim from source):
 *   action, archive, background, code, codebase, cite, classid,
 *   data, formaction, href, icon, lowsrc, longdesc, manifest,
 *   profile, poster, src, srcset, usemap
 * Plus inline style="..." and <style> block url() values.
 * Plus <base href> which changes the resolution base.
 */
function extractLinksFromHtml(string $html, string $domain, string $currentPath): array
{
    $links = [];

    // Detect <base href="..."> — affects resolution of all relative URLs
    $base = $currentPath;
    if (preg_match('/<base[^>]+href\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $html, $bm)) {
        $resolved = resolveUrl($bm[1], $domain, $currentPath);
        if ($resolved !== null) $base = $resolved;
    }

    // Check <meta name="robots" content="nofollow"> — wget respects this
    if (preg_match('/<meta[^>]+name\s*=\s*["\']robots["\'][^>]+content\s*=\s*["\'][^"\']*nofollow[^"\']*["\'][^>]*>/i', $html)) {
        return [];
    }

    // wget's exact attribute list from html_url.c attrs[]
    $urlAttrs = 'action|archive|background|code|codebase|cite|classid|data|formaction|href|icon|lowsrc|longdesc|manifest|profile|poster|src|usemap';

    // Single-URL attributes
    preg_match_all('/(?:' . $urlAttrs . ')\s*=\s*["\']([^"\'>\s][^"\']*)["\'](?=[^>]*>)/i', $html, $m1);
    foreach ($m1[1] as $href) {
        $r = resolveUrl(trim($href), $domain, $base);
        if ($r !== null) $links[] = $r;
    }

    // srcset — comma-separated list of "url descriptor" pairs (wget handles this specially)
    preg_match_all('/srcset\s*=\s*["\']([^"\']+)["\'](?=[^>]*>)/i', $html, $sm);
    foreach ($sm[1] as $srcset) {
        foreach (parseSrcset($srcset) as $href) {
            $r = resolveUrl($href, $domain, $base);
            if ($r !== null) $links[] = $r;
        }
    }

    // Inline style="...url(...)..." attributes
    preg_match_all('/style\s*=\s*["\']([^"\']+)["\'](?=[^>]*>)/i', $html, $styles);
    foreach ($styles[1] as $style) {
        foreach (extractLinksFromCss($style, $domain, $base) as $r) {
            $links[] = $r;
        }
    }

    // <style> blocks
    preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $html, $styleBlocks);
    foreach ($styleBlocks[1] as $css) {
        foreach (extractLinksFromCss($css, $domain, $base) as $r) {
            $links[] = $r;
        }
    }

    // JS: import '...', fetch('...'), require('...'), sourceMappingURL=
    preg_match_all('/(?:import|require|fetch)\s*\(\s*["\']([^"\']+)["\']\s*\)/i', $html, $js);
    foreach ($js[1] as $href) {
        $r = resolveUrl($href, $domain, $base);
        if ($r !== null) $links[] = $r;
    }
    preg_match_all('/sourceMappingURL=([^\s*\'"]+)/', $html, $maps);
    foreach ($maps[1] as $href) {
        $r = resolveUrl($href, $domain, $base);
        if ($r !== null) $links[] = $r;
    }

    return array_unique($links);
}

/**
 * CSS url() extraction — mirrors wget2's css_url.c
 */
function extractLinksFromCss(string $css, string $domain, string $base): array
{
    $links = [];
    // url("..."), url('...'), url(...)
    preg_match_all('/url\(\s*["\']?([^"\')\s]+)["\']?\s*\)/i', $css, $m);
    foreach ($m[1] as $href) {
        if (str_starts_with($href, 'data:')) continue;
        $r = resolveUrl($href, $domain, $base);
        if ($r !== null) $links[] = $r;
    }
    // @import "..." / @import '...'
    preg_match_all('/@import\s+["\']([^"\']+)["\']/i', $css, $imp);
    foreach ($imp[1] as $href) {
        $r = resolveUrl($href, $domain, $base);
        if ($r !== null) $links[] = $r;
    }
    return array_unique($links);
}

/**
 * Parse srcset attribute value — wget2 handles this as a special case in html_url.c
 * Format: "url1 descriptor, url2 descriptor, ..."
 */
function parseSrcset(string $srcset): array
{
    $urls = [];
    $parts = explode(',', $srcset);
    foreach ($parts as $part) {
        $tokens = preg_split('/\s+/', trim($part));
        if (!empty($tokens[0]) && !str_starts_with($tokens[0], 'data:')) {
            $urls[] = $tokens[0];
        }
    }
    return $urls;
}

/**
 * Resolve a URL relative to the current domain/path.
 * Returns a normalized path (string starting with /) if it stays on the same domain,
 * null if it goes to a different domain or is unusable.
 */
function resolveUrl(string $href, string $domain, string $currentPath): ?string
{
    $href = trim($href);
    if ($href === '' || $href === '#' || str_starts_with($href, '#')) return null;
    if (str_starts_with($href, 'data:') || str_starts_with($href, 'mailto:')
        || str_starts_with($href, 'javascript:') || str_starts_with($href, 'tel:')
        || str_starts_with($href, 'blob:')) return null;

    if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
        $parsed = parse_url($href);
        if (strtolower($parsed['host'] ?? '') !== $domain) return null;
        return normalizePath(($parsed['path'] ?? '/') ?: '/');
    }

    if (str_starts_with($href, '//')) {
        // Protocol-relative — check host
        $parsed = parse_url('https:' . $href);
        if (strtolower($parsed['host'] ?? '') !== $domain) return null;
        return normalizePath(($parsed['path'] ?? '/') ?: '/');
    }

    if (str_starts_with($href, '/')) {
        return normalizePath($href);
    }

    // Relative path — resolve against current directory, not current file
    $dir = rtrim(dirname($currentPath), '/') . '/';
    return normalizePath($dir . $href);
}

function normalizePath(string $path): ?string
{
    // Strip fragment and query
    $path = preg_replace('/#.*$/', '', $path);
    $path = preg_replace('/\?.*$/', '', $path);
    if ($path === '' || !str_starts_with($path, '/')) return null;

    // Resolve . and .. segments
    $parts = explode('/', $path);
    $stack = [];
    foreach ($parts as $p) {
        if ($p === '..') { array_pop($stack); }
        elseif ($p !== '.') { $stack[] = $p; }
    }
    $norm = implode('/', $stack);
    if (!str_starts_with($norm, '/')) $norm = '/' . $norm;
    return $norm ?: '/';
}

function buildBaseline(CurlHandle $ch, string $baseUrl): array
{
    $sigs = [];
    for ($i = 0; $i < 3; $i++) {
        $rand = 'filex-noexist-' . substr(md5((string)mt_rand()), 0, 10);
        curl_setopt($ch, CURLOPT_URL, $baseUrl . '/' . $rand);
        $raw    = curl_exec($ch);
        $info   = curl_getinfo($ch);
        $status = (int)$info['http_code'];
        if ($status === 0) continue;
        $body   = substr($raw ?: '', (int)$info['header_size']);
        $sigs[] = ['status' => $status, 'bodyLen' => strlen($body), 'hash' => md5(substr($body, 0, 512))];
    }
    if (empty($sigs)) return ['status' => 0, 'bodyLen' => 0, 'hash' => '', 'reliable' => false];

    $statusCounts = array_count_values(array_column($sigs, 'status'));
    $hashCounts   = array_count_values(array_column($sigs, 'hash'));
    $lens         = array_column($sigs, 'bodyLen');
    arsort($statusCounts); arsort($hashCounts);
    $domStatus = (int)array_key_first($statusCounts);
    $lenRange  = max($lens) - min($lens);

    return [
        'status'     => $domStatus,
        'bodyLen'    => (int)round(array_sum($lens) / count($lens)),
        'lenRange'   => $lenRange,
        'hash'       => (string)array_key_first($hashCounts),
        'reliable'   => ($statusCounts[$domStatus] >= 2) && ($lenRange < 200),
        'probeCount' => count($sigs),
    ];
}

function matchesBaseline(?array $b, int $status, int $bodyLen, string $hash): bool
{
    if ($b === null || !($b['reliable'] ?? false)) return false;
    if ($status !== $b['status']) return false;
    if ($hash === $b['hash']) return true;
    return abs($bodyLen - $b['bodyLen']) <= max(50, ($b['lenRange'] ?? 0) + 30);
}

function parseHeaders(string $raw): array
{
    $headers = [];
    foreach (explode("\r\n", $raw) as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) continue;
        $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
    }
    return $headers;
}

function detectDirList(string $body): bool
{
    return (bool)preg_match('/(Index of|Directory listing|Parent Directory)/i', $body);
}

function isInteresting(int $status, string $path): bool
{
    if ($status === 200 || $status === 206) return true;
    if ($status >= 301 && $status <= 308) return true;
    if ($status === 401) return true;
    foreach (['.env', '.git', '.svn', '.htpasswd', 'phpinfo', 'backup', 'dump.sql', 'db.sql', '.pem', '.key', 'id_rsa', 'credentials', 'webshell', 'shell.php'] as $p) {
        if (stripos($path, $p) !== false) return true;
    }
    return false;
}
