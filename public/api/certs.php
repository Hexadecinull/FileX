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

$baseDomain = getBaseDomain($domain);

$crtUrl = 'https://crt.sh/?q=%25.' . urlencode($baseDomain) . '&output=json';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $crtUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 FileX/1.0',
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_ENCODING       => '',
]);
$raw    = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false || $status !== 200) {
    echo json_encode(['error' => 'crt.sh unavailable', 'domain' => $domain]);
    exit;
}

$certs = json_decode($raw, true);
if (!is_array($certs)) {
    echo json_encode(['error' => 'Invalid crt.sh response', 'domain' => $domain]);
    exit;
}

$subdomains = [];
foreach ($certs as $cert) {
    $names = $cert['name_value'] ?? '';
    foreach (explode("\n", $names) as $name) {
        $name = trim(strtolower($name));
        if ($name === '' || str_starts_with($name, '*')) {
            continue;
        }
        if (str_ends_with($name, '.' . $baseDomain) || $name === $baseDomain) {
            $subdomains[$name] = true;
        }
    }
}

$subdomains = array_keys($subdomains);
sort($subdomains);

echo json_encode([
    'domain'        => $domain,
    'baseDomain'    => $baseDomain,
    'total'         => count($subdomains),
    'subdomains'    => $subdomains,
    'certCount'     => count($certs),
]);

function getBaseDomain(string $domain): string
{
    $parts = explode('.', $domain);
    if (count($parts) <= 2) {
        return $domain;
    }
    return implode('.', array_slice($parts, -2));
}
