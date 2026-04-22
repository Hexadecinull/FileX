<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_time_limit(25);

$domain = trim($_GET['domain'] ?? '');
if ($domain === '') {
    echo json_encode(['error' => 'Missing domain parameter']);
    exit;
}

$domain = strtolower(preg_replace('/^https?:\/\//i', '', $domain));
$domain = rtrim(explode('/', $domain)[0], '/');

$visited = [];
$allUrls = [];
$sources = [];

$candidates = [
    'https://' . $domain . '/sitemap.xml',
    'https://' . $domain . '/sitemap_index.xml',
    'https://' . $domain . '/sitemap-index.xml',
    'https://' . $domain . '/sitemaps/sitemap.xml',
    'https://' . $domain . '/sitemap1.xml',
    'https://' . $domain . '/wp-sitemap.xml',
    'https://' . $domain . '/post-sitemap.xml',
    'https://' . $domain . '/page-sitemap.xml',
    'http://' . $domain . '/sitemap.xml',
];

foreach ($candidates as $candidate) {
    if (count($allUrls) > 5000) {
        break;
    }
    processSitemap($candidate, $visited, $allUrls, $sources, 0);
}

echo json_encode([
    'domain'  => $domain,
    'total'   => count($allUrls),
    'sources' => $sources,
    'urls'    => array_slice($allUrls, 0, 5000),
]);

function processSitemap(string $url, array &$visited, array &$allUrls, array &$sources, int $depth): void
{
    if ($depth > 4 || isset($visited[$url]) || count($allUrls) > 5000) {
        return;
    }
    $visited[$url] = true;

    $res = fetchUrl($url);
    if ($res['status'] < 200 || $res['status'] >= 300 || $res['body'] === '') {
        return;
    }

    $body = $res['body'];
    $sources[] = ['url' => $url, 'status' => $res['status']];

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    if ($xml === false) {
        return;
    }

    $ns = $xml->getNamespaces(true);
    $defaultNs = array_shift($ns) ?? '';

    if ($xml->getName() === 'sitemapindex') {
        foreach ($xml->sitemap as $sitemap) {
            $loc = (string) $sitemap->loc;
            if ($loc !== '') {
                processSitemap($loc, $visited, $allUrls, $sources, $depth + 1);
            }
        }
    } else {
        foreach ($xml->url as $urlNode) {
            $loc = (string) $urlNode->loc;
            if ($loc !== '' && !in_array($loc, $allUrls, true)) {
                $allUrls[] = $loc;
            }
        }
    }
}

function fetchUrl(string $url): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 FileX/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING       => '',
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $body ?: ''];
}
