<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_time_limit(20);

$domain = trim($_GET['domain'] ?? '');
if ($domain === '') {
    echo json_encode(['error' => 'Missing domain parameter']);
    exit;
}

$domain = strtolower(preg_replace('/^https?:\/\//i', '', $domain));
$domain = rtrim(explode('/', $domain)[0], '/');

$limit  = min((int) ($_GET['limit'] ?? 3000), 5000);
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');

$cdxParams = http_build_query(array_filter([
    'url'        => $domain . '/*',
    'output'     => 'json',
    'fl'         => 'original,statuscode,mimetype,timestamp,length',
    'collapse'   => 'urlkey',
    'limit'      => $limit,
    'filter'     => '!statuscode:0',
    'from'       => $from,
    'to'         => $to,
]));

$cdxUrl = 'https://web.archive.org/cdx/search/cdx?' . $cdxParams;

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $cdxUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 FileX/1.0',
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_ENCODING       => '',
]);
$raw    = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false || $status < 200 || $status >= 300) {
    echo json_encode(['error' => 'Wayback CDX unavailable', 'status' => $status]);
    exit;
}

$lines = array_filter(explode("\n", trim($raw)));
$rows  = [];
foreach ($lines as $line) {
    $decoded = json_decode($line, true);
    if (is_array($decoded)) {
        $rows[] = $decoded;
    }
}

if (empty($rows)) {
    echo json_encode(['domain' => $domain, 'total' => 0, 'urls' => [], 'paths' => []]);
    exit;
}

$fields = array_shift($rows);
$fieldMap = array_flip($fields);

$urls      = [];
$pathSet   = [];
$mimeStats = [];

foreach ($rows as $row) {
    $url      = $row[$fieldMap['original']] ?? '';
    $status   = $row[$fieldMap['statuscode']] ?? '';
    $mime     = $row[$fieldMap['mimetype']] ?? '';
    $ts       = $row[$fieldMap['timestamp']] ?? '';
    $length   = $row[$fieldMap['length']] ?? '';

    if ($url === '') {
        continue;
    }

    $parsedPath = parse_url($url, PHP_URL_PATH) ?: '/';
    $pathSet[$parsedPath] = true;

    $baseMime = explode(';', $mime)[0];
    $mimeStats[$baseMime] = ($mimeStats[$baseMime] ?? 0) + 1;

    $urls[] = [
        'url'    => $url,
        'path'   => $parsedPath,
        'status' => $status,
        'mime'   => $baseMime,
        'ts'     => $ts,
        'size'   => $length === '-' ? null : (int) $length,
    ];
}

arsort($mimeStats);

echo json_encode([
    'domain'    => $domain,
    'total'     => count($urls),
    'paths'     => array_keys($pathSet),
    'mimeStats' => $mimeStats,
    'urls'      => $urls,
]);
