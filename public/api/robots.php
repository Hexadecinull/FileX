<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_time_limit(15);

$domain = trim($_GET['domain'] ?? '');
if ($domain === '') {
    echo json_encode(['error' => 'Missing domain parameter']);
    exit;
}

$domain = strtolower(preg_replace('/^https?:\/\//i', '', $domain));
$domain = rtrim(explode('/', $domain)[0], '/');

$results = [];

foreach (['https', 'http'] as $scheme) {
    $url = $scheme . '://' . $domain . '/robots.txt';
    $res = fetchUrl($url);

    if ($res['status'] >= 200 && $res['status'] < 300 && strlen($res['body']) > 0) {
        $results['url']    = $url;
        $results['status'] = $res['status'];
        $results['raw']    = $res['body'];
        $results['parsed'] = parseRobots($res['body']);
        break;
    }
}

if (empty($results)) {
    $results = ['error' => 'robots.txt not found or unreachable'];
}

echo json_encode($results);

function fetchUrl(string $url): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 FileX/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $body ?: ''];
}

function parseRobots(string $content): array
{
    $lines    = explode("\n", str_replace("\r", '', $content));
    $agents   = [];
    $sitemaps = [];
    $current  = [];

    foreach ($lines as $raw) {
        $line = trim($raw);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $colonPos = strpos($line, ':');
        if ($colonPos === false) {
            continue;
        }

        $directive = strtolower(trim(substr($line, 0, $colonPos)));
        $value     = trim(substr($line, $colonPos + 1));
        $comment   = '';

        $hashPos = strpos($value, '#');
        if ($hashPos !== false) {
            $comment = trim(substr($value, $hashPos + 1));
            $value   = trim(substr($value, 0, $hashPos));
        }

        if ($directive === 'user-agent') {
            $current = [];
            $agents[$value] = &$current;
        } elseif ($directive === 'disallow') {
            $current['disallow'][] = ['path' => $value, 'comment' => $comment];
        } elseif ($directive === 'allow') {
            $current['allow'][] = ['path' => $value, 'comment' => $comment];
        } elseif ($directive === 'sitemap') {
            $sitemaps[] = $value;
        } elseif ($directive === 'crawl-delay') {
            $current['crawlDelay'] = (float) $value;
        } elseif ($directive === 'host') {
            $current['host'] = $value;
        }
    }

    $allPaths = [];
    foreach ($agents as $agent => $rules) {
        foreach ($rules['disallow'] ?? [] as $entry) {
            if ($entry['path'] !== '' && $entry['path'] !== '/') {
                $allPaths[] = $entry['path'];
            }
        }
        foreach ($rules['allow'] ?? [] as $entry) {
            if ($entry['path'] !== '' && $entry['path'] !== '/') {
                $allPaths[] = $entry['path'];
            }
        }
    }

    return [
        'agents'    => $agents,
        'sitemaps'  => array_values(array_unique($sitemaps)),
        'allPaths'  => array_values(array_unique($allPaths)),
    ];
}
