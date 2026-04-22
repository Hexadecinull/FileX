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

$result = [
    'domain'          => $domain,
    'records'         => [],
    'ipv4'            => [],
    'ipv6'            => [],
    'nameservers'     => [],
    'mx'              => [],
    'txt'             => [],
    'cname'           => null,
    'tech'            => [],
    'subdomains'      => [],
    'wildcardDetected'=> false,
];

$types = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT', 'SOA'];
foreach ($types as $type) {
    $recs = queryDNS($domain, $type);
    if (!empty($recs)) {
        $result['records'][$type] = $recs;
    }
}

foreach ($result['records']['A']    ?? [] as $r) { $result['ipv4'][]        = $r['data']; }
foreach ($result['records']['AAAA'] ?? [] as $r) { $result['ipv6'][]        = $r['data']; }
foreach ($result['records']['NS']   ?? [] as $r) { $result['nameservers'][] = $r['data']; }
foreach ($result['records']['TXT']  ?? [] as $r) { $result['txt'][]         = $r['data']; }
foreach ($result['records']['MX']   ?? [] as $r) {
    $result['mx'][] = ['priority' => $r['data'] ?? 0, 'host' => $r['data'] ?? ''];
}
if (!empty($result['records']['CNAME'])) {
    $result['cname'] = $result['records']['CNAME'][0]['data'] ?? null;
}

$rootIPs = array_unique(array_merge($result['ipv4'], $result['ipv6']));

$wildcardIPs = detectWildcard($domain);
$result['wildcardDetected'] = !empty($wildcardIPs);

$result['subdomains'] = discoverRealSubdomains($domain, $rootIPs, $wildcardIPs);
$result['tech']       = inferTech($result);

echo json_encode($result);

function queryDNS(string $name, string $type): array
{
    $url = 'https://dns.google/resolve?' . http_build_query([
        'name' => $name,
        'type' => $type,
    ]);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 FileX/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code !== 200) return [];
    $json = json_decode($raw, true);
    return $json['Answer'] ?? [];
}

function detectWildcard(string $domain): array
{
    $randLabel = 'filex-nowildcard-' . substr(md5((string) mt_rand()), 0, 8);
    $fqdn      = $randLabel . '.' . $domain;

    $aRecs  = queryDNS($fqdn, 'A');
    $aaRecs = queryDNS($fqdn, 'AAAA');

    $ips = [];
    foreach (array_merge($aRecs, $aaRecs) as $r) {
        $ips[] = $r['data'];
    }
    return array_unique($ips);
}

function discoverRealSubdomains(string $domain, array $rootIPs, array $wildcardIPs): array
{
    $common = [
        'www', 'mail', 'smtp', 'imap', 'pop', 'pop3', 'webmail',
        'ftp', 'sftp',
        'api', 'rest', 'graphql', 'gateway',
        'dev', 'develop', 'development', 'staging', 'stage', 'beta', 'alpha', 'demo', 'sandbox', 'test', 'qa', 'uat',
        'admin', 'cpanel', 'panel', 'dashboard', 'portal',
        'blog', 'shop', 'store', 'app', 'mobile', 'm',
        'cdn', 'static', 'assets', 'media', 'img', 'images',
        'docs', 'doc', 'help', 'support', 'status',
        'git', 'gitlab', 'github', 'bitbucket', 'svn',
        'jenkins', 'ci', 'cd', 'build', 'deploy',
        'vpn', 'remote', 'access',
        'ns1', 'ns2', 'dns', 'dns1', 'dns2',
        'mx', 'mx1', 'mx2', 'mail2', 'maildev',
        'forum', 'community', 'wiki', 'kb',
        'auth', 'login', 'sso', 'id', 'accounts',
        'pay', 'payment', 'checkout', 'billing',
        'metrics', 'monitor', 'logs', 'analytics',
        'internal', 'intranet', 'private',
        'old', 'legacy', 'v1', 'v2',
        'proxy', 'gateway', 'edge', 'lb',
    ];

    $found = [];
    $allExcludes = array_unique(array_merge($rootIPs, $wildcardIPs));

    foreach ($common as $sub) {
        $fqdn   = $sub . '.' . $domain;
        $aRecs  = queryDNS($fqdn, 'A');
        $aaRecs = queryDNS($fqdn, 'AAAA');
        $cRecs  = queryDNS($fqdn, 'CNAME');

        if (empty($aRecs) && empty($aaRecs) && empty($cRecs)) {
            continue;
        }

        $resolvedIPs = [];
        foreach (array_merge($aRecs, $aaRecs) as $r) {
            $resolvedIPs[] = $r['data'];
        }

        if (!empty($wildcardIPs)) {
            $uniqueIPs = array_diff($resolvedIPs, $wildcardIPs);
            if (empty($uniqueIPs) && empty($cRecs)) {
                continue;
            }
            if (empty($uniqueIPs) && !empty($cRecs)) {
                $cname = $cRecs[0]['data'] ?? '';
                if (str_ends_with(rtrim($cname, '.'), '.' . $domain) || $cname === $domain . '.') {
                    continue;
                }
            }
        } else {
            if (!empty($resolvedIPs) && !empty($allExcludes)) {
                $uniqueIPs = array_diff($resolvedIPs, $allExcludes);
                if (empty($uniqueIPs) && empty($cRecs)) {
                    continue;
                }
            }
        }

        $found[] = $fqdn;
    }

    return $found;
}

function inferTech(array $result): array
{
    $tech  = [];
    $ns    = implode(' ', $result['nameservers'] ?? []);
    $txt   = implode(' ', $result['txt'] ?? []);
    $mxStr = implode(' ', array_column($result['mx'] ?? [], 'host'));

    if (preg_match('/cloudflare/i', $ns))     $tech[] = 'Cloudflare DNS';
    if (preg_match('/awsdns/i', $ns))         $tech[] = 'AWS Route 53';
    if (preg_match('/google/i', $ns))         $tech[] = 'Google Cloud DNS';
    if (preg_match('/azure-dns/i', $ns))      $tech[] = 'Azure DNS';
    if (preg_match('/domaincontrol/i', $ns))  $tech[] = 'GoDaddy DNS';
    if (preg_match('/name\.com/i', $ns))      $tech[] = 'Name.com DNS';
    if (preg_match('/porkbun/i', $ns))        $tech[] = 'Porkbun DNS';

    if (preg_match('/google\.com/i', $mxStr))                    $tech[] = 'Google Workspace Mail';
    if (preg_match('/outlook\.com|protection\.outlook/i', $mxStr)) $tech[] = 'Microsoft 365 Mail';
    if (preg_match('/amazonses|amazonaws/i', $mxStr))            $tech[] = 'Amazon SES';
    if (preg_match('/mailgun/i', $mxStr))                        $tech[] = 'Mailgun';
    if (preg_match('/sendgrid/i', $mxStr))                       $tech[] = 'SendGrid';
    if (preg_match('/protonmail/i', $mxStr))                     $tech[] = 'ProtonMail';
    if (preg_match('/fastmail/i', $mxStr))                       $tech[] = 'Fastmail';

    if (preg_match('/v=spf/i', $txt))                            $tech[] = 'SPF';
    if (preg_match('/v=DMARC/i', $txt))                          $tech[] = 'DMARC';
    if (preg_match('/v=DKIM/i', $txt))                           $tech[] = 'DKIM';
    if (preg_match('/google-site-verification/i', $txt))         $tech[] = 'Google Search Console';
    if (preg_match('/facebook-domain-verification/i', $txt))     $tech[] = 'Facebook Domain Verification';
    if (preg_match('/MS=/i', $txt))                              $tech[] = 'Microsoft Domain Verification';
    if (preg_match('/apple-domain-verification/i', $txt))        $tech[] = 'Apple Domain Verification';
    if ($result['wildcardDetected'])                             $tech[] = 'Wildcard DNS (*.' . $result['domain'] . ')';

    return $tech;
}
