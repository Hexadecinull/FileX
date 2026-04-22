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

$types  = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT', 'SOA', 'SRV'];
$result = [
    'domain'     => $domain,
    'records'    => [],
    'ipv4'       => [],
    'ipv6'       => [],
    'nameservers'=> [],
    'mx'         => [],
    'txt'        => [],
    'cname'      => null,
    'tech'       => [],
    'subdomains' => [],
];

foreach ($types as $type) {
    $dnsResult = queryDNS($domain, $type);
    if (!empty($dnsResult)) {
        $result['records'][$type] = $dnsResult;
    }
}

foreach ($result['records']['A'] ?? [] as $rec) {
    $result['ipv4'][] = $rec['data'] ?? $rec;
}
foreach ($result['records']['AAAA'] ?? [] as $rec) {
    $result['ipv6'][] = $rec['data'] ?? $rec;
}
foreach ($result['records']['NS'] ?? [] as $rec) {
    $result['nameservers'][] = $rec['data'] ?? $rec;
}
foreach ($result['records']['MX'] ?? [] as $rec) {
    $result['mx'][] = ['priority' => $rec['priority'] ?? 0, 'host' => $rec['data'] ?? $rec];
}
foreach ($result['records']['TXT'] ?? [] as $rec) {
    $result['txt'][] = $rec['data'] ?? $rec;
}
if (!empty($result['records']['CNAME'])) {
    $result['cname'] = $result['records']['CNAME'][0]['data'] ?? null;
}

$result['tech']       = inferTechFromDNS($result);
$result['subdomains'] = commonSubdomainCheck($domain);

echo json_encode($result);

function queryDNS(string $domain, string $type): array
{
    $url = 'https://dns.google/resolve?' . http_build_query([
        'name' => $domain,
        'type' => $type,
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 FileX/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code !== 200) {
        return [];
    }

    $json = json_decode($raw, true);
    return $json['Answer'] ?? [];
}

function inferTechFromDNS(array $result): array
{
    $tech = [];
    $ns   = implode(' ', $result['nameservers']);
    $txt  = implode(' ', $result['txt']);
    $mx   = array_column($result['mx'], 'host');
    $mxStr = implode(' ', $mx);

    if (preg_match('/cloudflare/i', $ns)) {
        $tech[] = 'Cloudflare DNS';
    }
    if (preg_match('/awsdns/i', $ns)) {
        $tech[] = 'AWS Route 53';
    }
    if (preg_match('/google/i', $ns)) {
        $tech[] = 'Google Cloud DNS';
    }
    if (preg_match('/azure-dns/i', $ns)) {
        $tech[] = 'Azure DNS';
    }
    if (preg_match('/google\.com/i', $mxStr)) {
        $tech[] = 'Google Workspace';
    }
    if (preg_match('/outlook\.com|protection\.outlook\.com/i', $mxStr)) {
        $tech[] = 'Microsoft 365';
    }
    if (preg_match('/amazonses|amazonaws/i', $mxStr)) {
        $tech[] = 'Amazon SES';
    }
    if (preg_match('/mailgun/i', $mxStr)) {
        $tech[] = 'Mailgun';
    }
    if (preg_match('/v=spf/i', $txt)) {
        $tech[] = 'SPF';
    }
    if (preg_match('/v=DMARC/i', $txt)) {
        $tech[] = 'DMARC';
    }
    if (preg_match('/google-site-verification/i', $txt)) {
        $tech[] = 'Google Search Console';
    }
    if (preg_match('/facebook-domain-verification/i', $txt)) {
        $tech[] = 'Facebook Domain Verification';
    }

    return $tech;
}

function commonSubdomainCheck(string $domain): array
{
    $common = ['www', 'mail', 'ftp', 'api', 'dev', 'staging', 'test', 'admin',
               'blog', 'shop', 'store', 'app', 'cdn', 'static', 'media',
               'assets', 'img', 'docs', 'help', 'support', 'forum', 'git',
               'gitlab', 'jenkins', 'ci', 'vpn', 'm', 'mobile', 'panel',
               'cpanel', 'webmail', 'smtp', 'pop', 'imap', 'ns1', 'ns2'];

    $found = [];
    foreach ($common as $sub) {
        $fqdn    = $sub . '.' . $domain;
        $records = @dns_get_record($fqdn, DNS_A | DNS_AAAA | DNS_CNAME);
        if (!empty($records)) {
            $found[] = $fqdn;
        }
    }

    return $found;
}
