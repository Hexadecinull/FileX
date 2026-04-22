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
        echo json_encode(['error' => 'PHP fatal: ' . $e['message'], 'results' => [], 'total' => 0, 'offset' => 0, 'batch' => 0]);
    }
});

$domain = trim($_GET['domain'] ?? '');
if ($domain === '') {
    echo json_encode(['error' => 'Missing domain parameter', 'results' => []]);
    exit;
}

$domain  = strtolower(preg_replace('/^https?:\/\//i', '', $domain));
$domain  = rtrim(explode('/', $domain)[0], '/');
$scheme  = in_array(trim($_GET['scheme'] ?? 'https'), ['http', 'https'], true) ? trim($_GET['scheme'] ?? 'https') : 'https';
$batch   = max(1, min((int) ($_GET['batch'] ?? 30), 60));
$offset  = max(0, (int) ($_GET['offset'] ?? 0));
$custom  = array_filter(array_map('trim', explode("\n", $_GET['paths'] ?? '')));

$wordlist = array_merge(getWordlist(), $custom);
$wordlist = array_values(array_unique($wordlist));
$slice    = array_slice($wordlist, $offset, $batch);
$baseUrl  = $scheme . '://' . $domain;

$results  = [];
$ch       = curl_init();

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => 7,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 FileX/1.0',
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_ENCODING       => '',
]);

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

    $results[] = [
        'path'             => '/' . ltrim($path, '/'),
        'status'           => $status,
        'contentType'      => $contentType,
        'contentLength'    => isset($headers['content-length'])
            ? (int) $headers['content-length']
            : (int) $info['size_download'],
        'server'           => $headers['server'] ?? null,
        'redirect'         => isset($headers['location'])
            ? (string) $headers['location']
            : null,
        'hasDirectoryList' => detectDirList($body),
        'interesting'      => isInteresting($status, $path, $body),
    ];
}

curl_close($ch);

echo json_encode([
    'domain'  => $domain,
    'total'   => count($wordlist),
    'offset'  => $offset,
    'batch'   => $batch,
    'results' => $results,
]);

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

function isInteresting(int $status, string $path, string $body): bool
{
    if ($status === 200 || $status === 206) {
        return true;
    }
    if ($status >= 301 && $status <= 308) {
        return true;
    }
    if ($status === 401 || $status === 403) {
        return true;
    }
    $sensitive = [
        '.env', 'config', 'backup', 'admin', 'phpmyadmin', 'phpinfo',
        '.git', '.svn', '.htpasswd', 'passwd', 'credentials',
        'secret', 'private', 'key', 'token', 'database', 'shell', 'cmd',
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
        'crossdomain.xml', 'humans.txt', 'security.txt',
        'favicon.ico',

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
        'Dockerfile', 'docker-compose.yml', '.dockerignore',
        'Makefile', 'Gemfile',

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
        'wp-admin/install.php', 'wp-admin/setup-config.php',

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

        'app/', 'application/', 'site/', 'web/',
        'public_html/', 'htdocs/',
    ];
}
