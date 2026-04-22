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
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode([
            'error'    => 'PHP fatal: ' . $e['message'],
            'results'  => [],
            'total'    => 0,
            'offset'   => 0,
            'batch'    => 0,
            'baseline' => null,
        ]);
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
$batch   = max(1, min((int) ($_GET['batch'] ?? 20), 60));
$offset  = max(0, (int) ($_GET['offset'] ?? 0));
$custom  = array_filter(array_map('trim', explode("\n", $_GET['paths'] ?? '')));

$wordlist = array_merge(getWordlist(), $custom);
$wordlist = array_values(array_unique($wordlist));
$slice    = array_slice($wordlist, $offset, $batch);
$baseUrl  = $scheme . '://' . $domain;

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

$baseline = null;
if ($offset === 0) {
    $baseline = buildBaseline($ch, $baseUrl);
}

$clientBaseline = null;
$rawBaseline    = $_GET['baseline'] ?? '';
if ($rawBaseline !== '') {
    $decoded = json_decode(base64_decode($rawBaseline), true);
    if (is_array($decoded)) {
        $clientBaseline = $decoded;
    }
}

$effectiveBaseline = $baseline ?? $clientBaseline;

$results = [];

foreach ($slice as $path) {
    $url = $baseUrl . '/' . ltrim($path, '/');
    curl_setopt($ch, CURLOPT_URL, $url);

    $raw  = curl_exec($ch);
    $info = curl_getinfo($ch);

    $status = (int) $info['http_code'];
    if ($status === 0) {
        continue;
    }

    $headerSize  = (int) $info['header_size'];
    $rawHeaders  = substr($raw ?: '', 0, $headerSize);
    $body        = substr($raw ?: '', $headerSize);
    $headers     = parseHeaders($rawHeaders);
    $contentType = explode(';', $headers['content-type'] ?? '')[0];
    $bodyLen     = strlen($body);
    $bodyHash    = md5(substr($body, 0, 512));

    $isBaseline = matchesBaseline($effectiveBaseline, $status, $bodyLen, $bodyHash);

    if ($isBaseline) {
        continue;
    }

    $contentLength = isset($headers['content-length'])
        ? (int) $headers['content-length']
        : (int) $info['size_download'];

    $results[] = [
        'path'             => '/' . ltrim($path, '/'),
        'status'           => $status,
        'contentType'      => $contentType,
        'contentLength'    => $contentLength,
        'server'           => $headers['server'] ?? null,
        'redirect'         => $headers['location'] ?? null,
        'hasDirectoryList' => detectDirList($body),
        'interesting'      => isInteresting($status, $path),
    ];
}

curl_close($ch);

$responseData = [
    'domain'  => $domain,
    'total'   => count($wordlist),
    'offset'  => $offset,
    'batch'   => $batch,
    'results' => $results,
];

if ($baseline !== null) {
    $responseData['baseline']        = $baseline;
    $responseData['baselineEncoded'] = base64_encode(json_encode($baseline));
}

echo json_encode($responseData);

function buildBaseline(CurlHandle $ch, string $baseUrl): array
{
    $probeSignatures = [];

    $randomPaths = [
        'filex-probe-' . substr(md5((string) mt_rand()), 0, 10),
        'filex-probe-' . substr(md5((string) mt_rand()), 0, 10),
        'filex-probe-' . substr(md5((string) mt_rand()), 0, 10),
    ];

    foreach ($randomPaths as $rand) {
        curl_setopt($ch, CURLOPT_URL, $baseUrl . '/' . $rand);
        $raw  = curl_exec($ch);
        $info = curl_getinfo($ch);

        $status = (int) $info['http_code'];
        if ($status === 0) {
            continue;
        }

        $body    = substr($raw ?: '', (int) $info['header_size']);
        $bodyLen = strlen($body);
        $hash    = md5(substr($body, 0, 512));

        $probeSignatures[] = [
            'status'  => $status,
            'bodyLen' => $bodyLen,
            'hash'    => $hash,
        ];
    }

    if (empty($probeSignatures)) {
        return ['status' => 0, 'bodyLen' => 0, 'hash' => '', 'reliable' => false];
    }

    $statusCounts  = array_count_values(array_column($probeSignatures, 'status'));
    $hashCounts    = array_count_values(array_column($probeSignatures, 'hash'));
    $lenVariances  = array_column($probeSignatures, 'bodyLen');

    arsort($statusCounts);
    arsort($hashCounts);

    $dominantStatus = (int) array_key_first($statusCounts);
    $dominantHash   = (string) array_key_first($hashCounts);

    $minLen = min($lenVariances);
    $maxLen = max($lenVariances);
    $lenRange = $maxLen - $minLen;

    $reliable = ($statusCounts[$dominantStatus] >= 2)
             && ($lenRange < 200);

    return [
        'status'     => $dominantStatus,
        'bodyLen'    => (int) round(array_sum($lenVariances) / count($lenVariances)),
        'lenRange'   => $lenRange,
        'hash'       => $dominantHash,
        'reliable'   => $reliable,
        'probeCount' => count($probeSignatures),
    ];
}

function matchesBaseline(?array $baseline, int $status, int $bodyLen, string $hash): bool
{
    if ($baseline === null || !($baseline['reliable'] ?? false)) {
        return false;
    }

    if ($status !== $baseline['status']) {
        return false;
    }

    if ($hash === $baseline['hash']) {
        return true;
    }

    $tolerance = max(50, ($baseline['lenRange'] ?? 0) + 30);
    if (abs($bodyLen - $baseline['bodyLen']) <= $tolerance) {
        return true;
    }

    return false;
}

function parseHeaders(string $raw): array
{
    $headers = [];
    foreach (explode("\r\n", $raw) as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $key           = strtolower(trim(substr($line, 0, $pos)));
        $headers[$key] = trim(substr($line, $pos + 1));
    }
    return $headers;
}

function detectDirList(string $body): bool
{
    return (bool) preg_match('/(Index of|Directory listing|Parent Directory)/i', $body);
}

function isInteresting(int $status, string $path): bool
{
    if ($status === 200 || $status === 206) {
        return true;
    }
    if ($status >= 301 && $status <= 308) {
        return true;
    }
    if ($status === 401) {
        return true;
    }
    $sensitive = [
        '.env', '.git', '.svn', '.htpasswd', 'phpinfo', 'phptest',
        'backup', 'dump.sql', 'db.sql', 'database.sql',
        'shell', 'cmd', 'eval', 'webshell',
        'credentials', '.pem', '.key', 'id_rsa',
    ];
    foreach ($sensitive as $p) {
        if (stripos($path, $p) !== false) {
            return true;
        }
    }
    return false;
}

function getWordlist(): array
{
    return [
        '.env', '.env.local', '.env.example', '.env.development', '.env.production',
        '.env.backup', '.env.bak', '.env.old',
        '.git/HEAD', '.git/config', '.gitignore',
        '.svn/entries', '.htaccess', '.htpasswd',
        '.well-known/security.txt', '.well-known/openid-configuration',
        '.well-known/apple-app-site-association', '.well-known/assetlinks.json',
        '.DS_Store',

        'robots.txt', 'sitemap.xml', 'sitemap_index.xml',
        'crossdomain.xml', 'humans.txt', 'security.txt', 'favicon.ico',

        'index.php', 'index.html', 'index.htm', 'index.asp', 'index.aspx',
        'index.jsp', 'default.php', 'default.html', 'home.php',

        'admin/', 'admin/index.php', 'admin/login.php', 'admin/dashboard.php',
        'administrator/', 'administrator/index.php',
        'wp-admin/', 'wp-login.php', 'wp-config.php', 'wp-config.php.bak',
        'wp-content/', 'wp-includes/', 'wp-json/', 'wp-cron.php',
        'wp-content/debug.log', 'wp-content/uploads/',
        'xmlrpc.php',

        'panel/', 'cpanel/', 'dashboard/', 'backend/', 'cms/', 'portal/',
        'secure/', 'private/', 'internal/', 'staff/', 'admin2/',

        'login', 'login.php', 'login.html', 'login/',
        'signin', 'signin.php', 'signup', 'signup.php',
        'register', 'register.php', 'logout', 'auth/',

        'api/', 'api/v1/', 'api/v2/', 'api/v3/', 'api/index.php',
        'api/users', 'api/login', 'api/auth', 'api/status', 'api/health',
        'v1/', 'v2/', 'v3/', 'graphql', 'graphql/',
        'swagger/', 'swagger.json', 'openapi.json', 'api-docs/',

        'config.php', 'config.js', 'config.json', 'config.xml', 'config.yml',
        'configuration.php', 'settings.php', 'settings.py',
        'database.php', 'db.php', 'web.config', 'appsettings.json',
        'app/config/', 'application.yml',

        'backup/', 'backup.sql', 'backup.zip', 'backup.tar.gz',
        'db.sql', 'database.sql', 'dump.sql',
        'site.tar.gz', 'site.zip', 'www.zip', 'files.zip',

        'package.json', 'composer.json', 'requirements.txt',
        'Dockerfile', 'docker-compose.yml', '.dockerignore', 'Makefile', 'Gemfile',

        'phpinfo.php', 'info.php', 'php.php', 'test.php', 'debug.php',
        'test.html', 'phptest.php',

        'logs/', 'log/', 'error.log', 'access.log', 'debug.log',
        'storage/logs/', 'laravel.log',

        'upload/', 'uploads/', 'files/', 'media/',
        'images/', 'img/', 'static/', 'assets/', 'storage/', 'data/',

        'js/', 'css/', 'fonts/', 'dist/', 'src/', 'build/',
        'bundle.js', 'app.js', 'main.js', 'vendor.js',

        'docs/', 'doc/', 'documentation/', 'help/', 'wiki/',
        'readme.md', 'README.txt', 'CHANGELOG.md',

        'includes/', 'lib/', 'vendor/', 'modules/', 'plugins/',
        'components/', 'classes/', 'models/', 'controllers/',

        'dev/', 'staging/', 'test/', 'tmp/', 'temp/', 'cache/',

        'server-status', 'nginx_status', 'health', 'health/',
        'healthcheck', 'ping', 'status', 'metrics',

        'phpmyadmin/', 'pma/', 'adminer.php', 'myadmin/',

        'store/', 'shop/', 'cart/', 'checkout/', 'payment/',
        'order/', 'products/', 'category/',

        'feed', 'rss', 'rss.xml', 'atom.xml',
        'search', 'search.php', 'contact', 'contact.php', 'about/',

        'install.php', 'install/', 'setup.php', 'upgrade.php',
        'oauth/', 'oauth2/', 'sso/', 'verify/', 'reset/', 'forgot/',

        'news/', 'blog/', 'posts/', 'articles/',
        'gallery/', 'photos/', 'videos/',

        'export/', 'report/', 'reports/', 'analytics/',

        'sitemap-news.xml', 'sitemap-video.xml', 'sitemap-image.xml',

        'cron.php', 'tasks/', 'jobs/', 'queue/', 'artisan',

        'webmail/', 'mail/', 'roundcube/',
        'app/', 'application/', 'site/', 'web/', 'public_html/', 'htdocs/',
    ];
}
