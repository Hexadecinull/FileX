<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_time_limit(30);

$rawUrl = trim($_GET['url'] ?? '');
$domain = trim($_GET['domain'] ?? '');

if ($rawUrl === '') {
    http_response_code(400);
    die('Missing url parameter');
}

if (!filter_var($rawUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    die('Invalid URL');
}

$parsed = parse_url($rawUrl);
$scheme = strtolower($parsed['scheme'] ?? '');
if (!in_array($scheme, ['http', 'https'], true)) {
    http_response_code(400);
    die('Only http/https allowed');
}

// Optional: restrict to the scanned domain to prevent open proxy abuse
if ($domain !== '') {
    $host = strtolower($parsed['host'] ?? '');
    $expected = strtolower($domain);
    if ($host !== $expected && !str_ends_with($host, '.' . $expected)) {
        http_response_code(403);
        die('URL host does not match scanned domain');
    }
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $rawUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 FileX/1.0',
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_ENCODING       => '',
]);

$raw  = curl_exec($ch);
$info = curl_getinfo($ch);
$err  = curl_error($ch);
curl_close($ch);

if ($raw === false || $err) {
    http_response_code(502);
    die('Fetch failed: ' . $err);
}

$headerSize  = (int) $info['header_size'];
$rawHeaders  = substr($raw, 0, $headerSize);
$body        = substr($raw, $headerSize);
$httpStatus  = (int) $info['http_code'];

if ($httpStatus < 200 || $httpStatus >= 300) {
    http_response_code($httpStatus);
    die("Remote returned HTTP $httpStatus");
}

// Parse upstream content-type
$contentType = 'application/octet-stream';
foreach (explode("\r\n", $rawHeaders) as $line) {
    if (stripos($line, 'content-type:') === 0) {
        $contentType = trim(substr($line, 13));
        break;
    }
}

// Derive a filename from the URL path
$urlPath  = $parsed['path'] ?? '/';
$basename = basename($urlPath);
if ($basename === '' || $basename === '/') {
    $basename = 'index';
}
// Strip query string artifacts
$basename = preg_replace('/[?&=].*$/', '', $basename);
// If no extension, try to guess one from content-type
if (!str_contains($basename, '.')) {
    $extMap = [
        'text/html'              => '.html',
        'text/plain'             => '.txt',
        'application/json'       => '.json',
        'application/xml'        => '.xml',
        'text/xml'               => '.xml',
        'text/css'               => '.css',
        'application/javascript' => '.js',
        'text/javascript'        => '.js',
        'image/png'              => '.png',
        'image/jpeg'             => '.jpg',
        'image/gif'              => '.gif',
        'image/svg+xml'          => '.svg',
        'application/pdf'        => '.pdf',
        'application/zip'        => '.zip',
        'application/x-tar'      => '.tar',
    ];
    $baseMime = strtolower(explode(';', $contentType)[0]);
    $ext = $extMap[$baseMime] ?? '';
    $basename .= $ext;
}

// Sanitize filename
$basename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $basename);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $basename . '"');
header('Content-Length: ' . strlen($body));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

echo $body;
