<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['error' => 'PHP fatal: ' . $e['message'], 'results' => [], 'total' => 0, 'done' => true]);
    }
});

$domain = trim($_GET['domain'] ?? '');
if ($domain === '') {
    echo json_encode(['error' => 'Missing domain parameter', 'results' => []]);
    exit;
}

$domain  = strtolower(preg_replace('/^https?:\/\//i', '', $domain));
$domain  = rtrim(explode('/', $domain)[0], '/');
$scheme  = in_array(trim($_GET['scheme'] ?? 'https'), ['http', 'https'], true)
           ? trim($_GET['scheme'] ?? 'https') : 'https';
$batch   = max(1, min((int)($_GET['batch'] ?? 20), 60));
$baseUrl = $scheme . '://' . $domain;

// Queue is passed as JSON from the client between batches
$queue   = json_decode($_GET['queue'] ?? '[]', true);
if (!is_array($queue)) $queue = [];

// Visited set passed from client
$visited = json_decode($_GET['visited'] ?? '[]', true);
if (!is_array($visited)) $visited = [];
$visitedSet = array_flip($visited);

// On first call, seed the queue with the root
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

// Build baseline on first call
$baseline = null;
if (empty($visited)) {
    $baseline = buildBaseline($ch, $baseUrl);
}
$clientBaseline = null;
$rawBaseline = $_GET['baseline'] ?? '';
if ($rawBaseline !== '') {
    $decoded = json_decode(base64_decode($rawBaseline), true);
    if (is_array($decoded)) $clientBaseline = $decoded;
}
$effectiveBaseline = $baseline ?? $clientBaseline;

$results    = [];
$newQueue   = [];
$processed  = 0;

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

    $headerSize = (int)$info['header_size'];
    $rawHeaders = substr($raw ?: '', 0, $headerSize);
    $body       = substr($raw ?: '', $headerSize);
    $headers    = parseHeaders($rawHeaders);
    $contentType = explode(';', $headers['content-type'] ?? '')[0];
    $bodyLen    = strlen($body);
    $bodyHash   = md5(substr($body, 0, 512));

    if (matchesBaseline($effectiveBaseline, $status, $bodyLen, $bodyHash)) continue;

    $contentLength = isset($headers['content-length'])
        ? (int)$headers['content-length']
        : (int)$info['size_download'];

    $result = [
        'path'             => $norm,
        'status'           => $status,
        'contentType'      => $contentType,
        'contentLength'    => $contentLength,
        'server'           => $headers['server'] ?? null,
        'redirect'         => $headers['location'] ?? null,
        'hasDirectoryList' => detectDirList($body),
        'interesting'      => isInteresting($status, $norm),
    ];
    $results[] = $result;

    // Follow redirects within same domain
    if ($status >= 301 && $status <= 308 && isset($headers['location'])) {
        $loc = resolveUrl($headers['location'], $baseUrl, $norm);
        if ($loc !== null && !isset($visitedSet[$loc])) {
            array_unshift($queue, $loc); // prioritize
        }
    }

    // Extract links from HTML/JS/CSS bodies
    if ($status >= 200 && $status < 300) {
        $isHtml = stripos($contentType, 'html') !== false;
        $isJs   = stripos($contentType, 'javascript') !== false;
        $isCss  = stripos($contentType, 'css') !== false;

        if ($isHtml || $isJs || $isCss) {
            $links = extractLinks($body, $baseUrl, $norm);
            foreach ($links as $link) {
                if (!isset($visitedSet[$link])) {
                    $queue[] = $link;
                }
            }
        }
    }
}

curl_close($ch);

// Deduplicate queue before sending back
$queue = array_values(array_unique(array_filter($queue, fn($p) => !isset($visitedSet[$p]))));

$done = empty($queue);

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

// ── Helpers ──────────────────────────────────────────────────────────────────

function normalizePath(string $path): ?string
{
    // Strip fragment
    $path = preg_replace('/#.*$/', '', $path);
    // Must start with /
    if (!str_starts_with($path, '/')) return null;
    // Remove query string for dedup purposes (keep the path only)
    $path = preg_replace('/\?.*$/', '', $path);
    // Collapse double slashes
    $path = preg_replace('/\/+/', '/', $path);
    // Resolve . and ..
    $parts  = explode('/', $path);
    $stack  = [];
    foreach ($parts as $part) {
        if ($part === '..') { array_pop($stack); }
        elseif ($part !== '.') { $stack[] = $part; }
    }
    $norm = implode('/', $stack);
    if (!str_starts_with($norm, '/')) $norm = '/' . $norm;
    return $norm ?: '/';
}

function resolveUrl(string $href, string $baseUrl, string $currentPath): ?string
{
    if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
        $parsed = parse_url($href);
        $hHost  = strtolower($parsed['host'] ?? '');
        $bHost  = strtolower(parse_url($baseUrl, PHP_URL_HOST) ?? '');
        if ($hHost !== $bHost) return null;
        return normalizePath($parsed['path'] ?? '/');
    }
    if (str_starts_with($href, '//')) return null;
    if (str_starts_with($href, '/')) return normalizePath($href);
    // Relative path
    $base = rtrim(dirname($currentPath), '/') . '/';
    return normalizePath($base . $href);
}

function extractLinks(string $body, string $baseUrl, string $currentPath): array
{
    $links = [];

    // href, src, action, data-src, data-href
    preg_match_all('/(?:href|src|action|data-src|data-href)\s*=\s*["\']([^"\'>\s]+)["\']/', $body, $m1);
    // url() in CSS / JS strings
    preg_match_all('/url\(["\']?([^"\')\s]+)["\']?\)/', $body, $m2);
    // JS import / require / fetch strings (heuristic)
    preg_match_all('/(?:import|require|fetch)\s*\(\s*["\']([^"\']+)["\']/', $body, $m3);
    // JS source maps
    preg_match_all('/sourceMappingURL=([^\s*]+)/', $body, $m4);

    $candidates = array_merge($m1[1], $m2[1], $m3[1], $m4[1]);

    foreach ($candidates as $href) {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, 'data:') || str_starts_with($href, 'mailto:')
            || str_starts_with($href, 'javascript:') || str_starts_with($href, 'tel:')) {
            continue;
        }
        $resolved = resolveUrl($href, $baseUrl, $currentPath);
        if ($resolved !== null) {
            $links[] = $resolved;
        }
    }

    return array_unique($links);
}

function buildBaseline(CurlHandle $ch, string $baseUrl): array
{
    $sigs = [];
    for ($i = 0; $i < 3; $i++) {
        $rand = 'filex-noexist-' . substr(md5((string)mt_rand()), 0, 10);
        curl_setopt($ch, CURLOPT_URL, $baseUrl . '/' . $rand);
        $raw  = curl_exec($ch);
        $info = curl_getinfo($ch);
        $status = (int)$info['http_code'];
        if ($status === 0) continue;
        $body    = substr($raw ?: '', (int)$info['header_size']);
        $bodyLen = strlen($body);
        $sigs[]  = ['status' => $status, 'bodyLen' => $bodyLen, 'hash' => md5(substr($body, 0, 512))];
    }
    if (empty($sigs)) return ['status' => 0, 'bodyLen' => 0, 'hash' => '', 'reliable' => false];

    $statusCounts = array_count_values(array_column($sigs, 'status'));
    $hashCounts   = array_count_values(array_column($sigs, 'hash'));
    $lens         = array_column($sigs, 'bodyLen');
    arsort($statusCounts);
    arsort($hashCounts);

    $domStatus = (int)array_key_first($statusCounts);
    $domHash   = (string)array_key_first($hashCounts);
    $lenRange  = max($lens) - min($lens);

    return [
        'status'     => $domStatus,
        'bodyLen'    => (int)round(array_sum($lens) / count($lens)),
        'lenRange'   => $lenRange,
        'hash'       => $domHash,
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
