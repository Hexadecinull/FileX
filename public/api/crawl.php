<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_time_limit(55);

$domain = trim($_GET['domain'] ?? '');
if ($domain === '') {
    echo json_encode(['error' => 'Missing domain parameter']);
    exit;
}

$domain  = strtolower(preg_replace('/^https?:\/\//i', '', $domain));
$domain  = rtrim(explode('/', $domain)[0], '/');
$scheme  = trim($_GET['scheme'] ?? 'https');
$scheme  = in_array($scheme, ['http', 'https'], true) ? $scheme : 'https';
$batch   = min((int) ($_GET['batch'] ?? 50), 100);
$offset  = max((int) ($_GET['offset'] ?? 0), 0);
$custom  = array_filter(array_map('trim', explode("\n", $_GET['paths'] ?? '')));

$wordlist = array_merge(getBuiltinWordlist(), $custom);
$wordlist = array_values(array_unique($wordlist));
$slice    = array_slice($wordlist, $offset, $batch);

$baseUrl = $scheme . '://' . $domain;
$results = probePaths($baseUrl, $slice);

echo json_encode([
    'domain'  => $domain,
    'total'   => count($wordlist),
    'offset'  => $offset,
    'batch'   => $batch,
    'results' => $results,
]);

function probePaths(string $baseUrl, array $paths): array
{
    $multiHandle = curl_multi_init();
    $handles     = [];

    foreach ($paths as $path) {
        $url = $baseUrl . '/' . ltrim($path, '/');
        $ch  = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_NOBODY         => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 FileX/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        curl_multi_add_handle($multiHandle, $ch);
        $handles[$path] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($multiHandle, $running);
        curl_multi_select($multiHandle, 0.05);
    } while ($running > 0);

    $results = [];
    foreach ($handles as $path => $ch) {
        $raw        = curl_multi_getcontent($ch);
        $info       = curl_getinfo($ch);
        $headerSize = (int) $info['header_size'];
        $status     = (int) $info['http_code'];

        if ($status === 0) {
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
            continue;
        }

        $rawHeaders = substr($raw ?: '', 0, $headerSize);
        $body       = substr($raw ?: '', $headerSize);

        $headers     = parseHeaders($rawHeaders);
        $contentType = $headers['content-type'] ?? '';

        $results[] = [
            'path'             => '/' . ltrim($path, '/'),
            'status'           => $status,
            'contentType'      => explode(';', $contentType)[0],
            'contentLength'    => isset($headers['content-length'])
                ? (int) $headers['content-length']
                : (int) $info['size_download'],
            'server'           => $headers['server'] ?? null,
            'redirect'         => $headers['location'] ?? null,
            'hasDirectoryList' => detectDirectoryListing($body),
            'interesting'      => isInteresting($status, $path, $body, $headers),
        ];

        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }

    curl_multi_close($multiHandle);
    return $results;
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

function detectDirectoryListing(string $body): bool
{
    return (bool) preg_match(
        '/(Index of|Directory listing|Parent Directory)/i',
        $body
    );
}

function isInteresting(int $status, string $path, string $body, array $headers): bool
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
    $sensitivePatterns = [
        '.env', 'config', 'backup', 'admin', 'phpmyadmin', 'phpinfo',
        '.git', '.svn', '.htpasswd', 'passwd', 'shadow', 'credentials',
        'secret', 'private', 'key', 'token', 'database',
    ];
    foreach ($sensitivePatterns as $pattern) {
        if (stripos($path, $pattern) !== false) {
            return true;
        }
    }
    return false;
}

function getBuiltinWordlist(): array
{
    return [
        '.env', '.env.local', '.env.example', '.env.development', '.env.production',
        '.env.backup', '.env.bak', '.env.old', '.env.save', '.env.test',
        '.git/HEAD', '.git/config', '.git/COMMIT_EDITMSG', '.gitignore',
        '.svn/entries', '.svn/wc.db', '.htaccess', '.htpasswd', '.htpasswd.bak',
        '.well-known/security.txt', '.well-known/robots.txt', '.well-known/openid-configuration',
        '.DS_Store', 'Thumbs.db', 'desktop.ini',

        'robots.txt', 'sitemap.xml', 'sitemap_index.xml', 'sitemap.txt',
        'crossdomain.xml', 'clientaccesspolicy.xml', 'humans.txt', 'security.txt',
        'favicon.ico', 'apple-touch-icon.png',

        'index.php', 'index.html', 'index.htm', 'index.asp', 'index.aspx',
        'index.jsp', 'index.cfm', 'index.cgi', 'index.pl', 'index.py',
        'default.php', 'default.html', 'default.asp', 'default.aspx',
        'home.php', 'main.php', 'welcome.php',

        'admin/', 'admin/index.php', 'admin/login.php', 'admin/dashboard.php',
        'administrator/', 'administrator/index.php',
        'wp-admin/', 'wp-login.php', 'wp-config.php', 'wp-config.php.bak',
        'wp-content/', 'wp-includes/', 'wp-json/', 'wp-cron.php',
        'wp-content/debug.log', 'wp-content/uploads/', 'wp-content/plugins/',
        'xmlrpc.php',

        'panel/', 'cpanel/', 'dashboard/', 'control/', 'manage/', 'management/',
        'backend/', 'cms/', 'portal/', 'secure/', 'private/', 'internal/',
        'staff/', 'employee/', 'account/', 'accounts/', 'user/', 'users/',
        'members/', 'member/', 'client/', 'clients/',

        'login', 'login.php', 'login.html', 'login/', 'signin', 'signin.php',
        'signup', 'signup.php', 'register', 'register.php', 'logout', 'auth/',

        'api/', 'api/v1/', 'api/v2/', 'api/v3/', 'api/index.php',
        'api/users', 'api/login', 'api/auth', 'api/status', 'api/health',
        'v1/', 'v2/', 'v3/', 'graphql', 'graphql/', 'rest/', 'rpc/',
        'swagger/', 'swagger.json', 'swagger.yaml', 'openapi.json', 'openapi.yaml',
        'api-docs/', 'api-docs.json',

        'config.php', 'config.js', 'config.json', 'config.xml', 'config.yml',
        'config.yaml', 'config.ini', 'configuration.php', 'settings.php',
        'settings.py', 'local.php', 'database.php', 'db.php',
        'app/config/', 'application.properties', 'application.yml',
        'web.config', 'appsettings.json',

        'backup/', 'backup.sql', 'backup.zip', 'backup.tar.gz', 'backup.gz',
        'db.sql', 'database.sql', 'dump.sql', 'data.sql', 'mysql.sql',
        'site.tar.gz', 'site.zip', 'www.zip', 'html.zip', 'public.zip',
        'files.zip', 'archive.zip', 'export.zip', 'full_backup.zip',

        'package.json', 'composer.json', 'composer.lock', 'yarn.lock',
        'package-lock.json', 'Gemfile', 'Gemfile.lock', 'requirements.txt',
        'Pipfile', 'Pipfile.lock', 'poetry.lock', 'go.mod', 'go.sum',
        'Makefile', 'Dockerfile', 'docker-compose.yml', 'docker-compose.yaml',
        '.dockerignore', 'Vagrantfile',

        'phpinfo.php', 'info.php', 'php.php', 'test.php', 'test.html',
        'phptest.php', 'testphp.php', 'debug.php', 'trace.php',
        'eval.php', 'shell.php', 'cmd.php', 'exec.php', 'webshell.php',

        'logs/', 'log/', 'error.log', 'access.log', 'debug.log', 'app.log',
        'error_log', 'access_log', 'php_error.log', 'laravel.log',
        'storage/logs/', 'var/log/', 'logs/error.log',

        'upload/', 'uploads/', 'uploaded/', 'files/', 'file/', 'media/',
        'images/', 'img/', 'static/', 'assets/', 'public/', 'resources/',
        'storage/', 'data/', 'content/', 'contents/', 'attachments/',

        'js/', 'css/', 'fonts/', 'font/', 'icons/', 'icon/',
        'scripts/', 'style/', 'styles/', 'dist/', 'src/', 'build/',
        'bundle.js', 'app.js', 'main.js', 'vendor.js', 'chunk.js',
        'app.min.js', 'main.min.js', 'bundle.min.js',

        'docs/', 'doc/', 'documentation/', 'help/', 'support/', 'manual/',
        'wiki/', 'readme.md', 'README.txt', 'CHANGELOG.md', 'CHANGELOG.txt',
        'INSTALL.md', 'INSTALL.txt', 'LICENSE', 'LICENSE.txt', 'LICENSE.md',
        'TODO.txt', 'notes.txt',

        'includes/', 'include/', 'lib/', 'libs/', 'library/', 'libraries/',
        'vendor/', 'node_modules/', 'bower_components/', 'modules/', 'plugins/',
        'extensions/', 'addons/', 'components/', 'packages/',
        'class/', 'classes/', 'model/', 'models/', 'view/', 'views/',
        'controller/', 'controllers/', 'helpers/', 'utils/', 'utilities/',

        'dev/', 'develop/', 'development/', 'staging/', 'stage/', 'beta/',
        'alpha/', 'test/', 'tests/', 'testing/', 'qa/', 'uat/', 'demo/',
        'sandbox/', 'local/', 'old/', 'new/', 'tmp/', 'temp/', 'cache/',

        'server-status', 'server-info', 'nginx_status', 'php-fpm/status',
        'health', 'health/', 'healthcheck', 'ping', 'status', 'status/',
        'metrics', 'metrics/', 'monitor/', 'monitoring/',

        'phpmyadmin/', 'phpmyadmin/index.php', 'pma/', 'mysql/', 'adminer.php',
        'adminer/', 'myadmin/', 'db/', 'database/', 'dbadmin/',
        'wp-admin/install.php', 'wp-admin/setup-config.php',

        'webmail/', 'mail/', 'roundcube/', 'horde/', 'squirrelmail/',
        'ftp/', 'sftp/', 'ssl/', 'certs/', 'certificates/',

        '.well-known/acme-challenge/', '.well-known/apple-app-site-association',
        '.well-known/assetlinks.json', '.well-known/dnt-policy.txt',
        '.well-known/change-password',

        'app/', 'application/', 'apps/', 'site/', 'web/', 'www/',
        'public_html/', 'htdocs/', 'html/', 'home/', 'root/',

        'cron.php', 'cron/', 'tasks/', 'jobs/', 'queue/', 'worker.php',
        'cli.php', 'artisan', 'console/', 'bin/',

        'store/', 'shop/', 'cart/', 'checkout/', 'payment/', 'order/',
        'orders/', 'product/', 'products/', 'category/', 'categories/',

        'feed', 'feed/', 'rss', 'rss.xml', 'atom.xml', 'feed.xml',
        'newsletter/', 'subscribe/', 'unsubscribe/',

        'search', 'search.php', 'search/', 'query/', 'results/',
        'contact', 'contact.php', 'contact/', 'about/', 'faq/',

        'install.php', 'install/', 'setup.php', 'setup/', 'update.php',
        'upgrade.php', 'migrate/', 'migration/', 'installer/',

        'token', 'tokens/', 'oauth/', 'oauth2/', 'sso/', 'saml/',
        'verify/', 'confirm/', 'reset/', 'forgot/', 'password/',

        'sitemap-news.xml', 'sitemap-video.xml', 'sitemap-image.xml',
        'sitemap-category.xml', 'sitemap-tags.xml', 'sitemap-users.xml',
        'news/', 'blog/', 'posts/', 'post/', 'articles/', 'article/',
        'events/', 'event/', 'gallery/', 'photo/', 'photos/', 'video/', 'videos/',

        'export/', 'import/', 'report/', 'reports/', 'analytics/', 'stats/',
        'statistics/', 'dashboard/analytics',

        'socket.io/', 'ws/', 'websocket/', 'sse/', 'events',

        'cdn.php', 'proxy.php', 'redirect.php', 'download.php',
        'thumb.php', 'resize.php', 'image.php', 'asset.php',
    ];
}
