# FileX API Reference

All endpoints live under `public/api/` and are accessed via same-origin GET requests from the frontend. Every response is `application/json`. Error responses always include an `"error"` key.

---

## Common Patterns

### Domain normalization

All endpoints that accept a `domain` parameter normalize it internally:
- Strip `https://` / `http://` prefix
- Strip `www.` prefix is **not** done automatically — if you want to scan `www.example.com` pass it explicitly
- Strip trailing slashes and any path component

### Timeouts

All cURL handles use `CURLOPT_TIMEOUT=10` and `CURLOPT_CONNECTTIMEOUT=5` unless noted otherwise. `curl_multi` handles in `crawl.php` use 6s/3s to keep batches fast.

### SSL

`CURLOPT_SSL_VERIFYPEER=false` and `CURLOPT_SSL_VERIFYHOST=false` are set to allow probing self-signed or misconfigured targets. Do not change this for general use.

---

## GET /api/probe.php

Fetches a single URL and returns full metadata.

### Parameters

| Param | Required | Description |
|---|---|---|
| `url` | Yes | Full URL including scheme — e.g. `https://example.com/admin/` |

### Response

```json
{
  "url": "https://example.com/admin/",
  "finalUrl": "https://example.com/admin/login",
  "status": 302,
  "elapsed": 218,
  "contentType": "text/html; charset=utf-8",
  "contentLength": 512,
  "server": "Apache/2.4.54",
  "poweredBy": "PHP/8.1.12",
  "redirectCount": 0,
  "tlsVersion": null,
  "headers": {
    "location": "/admin/login",
    "set-cookie": "PHPSESSID=abc123; path=/; HttpOnly"
  },
  "hasDirectoryList": false,
  "cms": ["WordPress"],
  "tech": ["Apache 2.4.54", "PHP 8.1.12", "jQuery 3.6.0"],
  "forms": ["/wp-login.php"],
  "links": ["https://example.com/about/", "https://example.com/contact/"],
  "scripts": ["https://example.com/wp-includes/js/jquery/jquery.min.js"],
  "metaTags": {
    "generator": "WordPress 6.4.2",
    "description": "Just another WordPress site"
  },
  "comments": ["Cache-Buster: v3.2.1"],
  "bodySnippet": "<!DOCTYPE html><html lang=\"en\">…"
}
```

### CMS Detection Signatures

| CMS | Signals |
|---|---|
| WordPress | `wp-content`, `wp-includes`, `wp-json`, `WordPress` in body |
| Drupal | `drupal.org`, `sites/default/files`, `x-drupal-cache` header |
| Joomla | `/components/com_`, `joomla` in body |
| Magento | `mage/cookies`, `Mage.Cookies` |
| PrestaShop | `prestashop`, `x-prestashop-cache` header |
| TYPO3 | `/typo3/`, `typo3conf` |
| Ghost | `ghost-sdk`, `content.ghost.io` |
| Shopify | `shopify.com/s/files`, `cdn.shopify` |
| Squarespace | `squarespace.com`, `universal/scripts-compressed` |
| Wix | `wix.com`, `x-wix-request-id` header |

---

## GET /api/robots.php

### Parameters

| Param | Required | Description |
|---|---|---|
| `domain` | Yes | Domain or subdomain — e.g. `example.com` |

### Response

```json
{
  "url": "https://example.com/robots.txt",
  "status": 200,
  "raw": "User-agent: *\nDisallow: /admin/\nSitemap: https://example.com/sitemap.xml",
  "parsed": {
    "agents": {
      "*": {
        "disallow": [
          { "path": "/admin/", "comment": "" },
          { "path": "/private/", "comment": "internal use" }
        ],
        "allow": [
          { "path": "/admin/public/", "comment": "" }
        ],
        "crawlDelay": 1
      },
      "Googlebot": {
        "disallow": [],
        "allow": []
      }
    },
    "sitemaps": ["https://example.com/sitemap.xml"],
    "allPaths": ["/admin/", "/private/", "/admin/public/"]
  }
}
```

---

## GET /api/sitemap.php

Tries 9 candidate sitemap locations and recursively follows sitemap indexes up to depth 4.

### Parameters

| Param | Required | Description |
|---|---|---|
| `domain` | Yes | Domain — e.g. `example.com` |

### Candidate Locations (in order)

1. `/sitemap.xml`
2. `/sitemap_index.xml`
3. `/sitemap-index.xml`
4. `/sitemaps/sitemap.xml`
5. `/sitemap1.xml`
6. `/wp-sitemap.xml`
7. `/post-sitemap.xml`
8. `/page-sitemap.xml`
9. `http://` fallback of #1

### Response

```json
{
  "domain": "example.com",
  "total": 284,
  "sources": [
    { "url": "https://example.com/sitemap.xml", "status": 200 },
    { "url": "https://example.com/post-sitemap.xml", "status": 200 }
  ],
  "urls": [
    "https://example.com/about/",
    "https://example.com/blog/post-1/",
    "…"
  ]
}
```

---

## GET /api/wayback.php

Proxies the Internet Archive CDX API with `collapse=urlkey` to get deduplicated URL snapshots.

### Parameters

| Param | Required | Default | Description |
|---|---|---|---|
| `domain` | Yes | — | Target domain |
| `limit` | No | 3000 | Max CDX records (capped at 5000) |
| `from` | No | — | Start timestamp `YYYYMMDDhhmmss` |
| `to` | No | — | End timestamp `YYYYMMDDhhmmss` |

### Response

```json
{
  "domain": "example.com",
  "total": 1842,
  "paths": ["/", "/old-about/", "/deleted-blog/private/", "…"],
  "mimeStats": {
    "text/html": 1200,
    "application/javascript": 380,
    "text/css": 142,
    "image/jpeg": 90,
    "application/json": 30
  },
  "urls": [
    {
      "url": "https://example.com/deleted-admin/",
      "path": "/deleted-admin/",
      "status": "200",
      "mime": "text/html",
      "ts": "20180914083021",
      "size": 4096
    }
  ]
}
```

---

## GET /api/dns.php

Queries Google DoH for all common record types and probes 30 common subdomains.

### Parameters

| Param | Required | Description |
|---|---|---|
| `domain` | Yes | Domain or subdomain |

### Queried Record Types

`A`, `AAAA`, `CNAME`, `MX`, `NS`, `TXT`, `SOA`, `SRV`

### Probed Subdomains

`www`, `mail`, `ftp`, `api`, `dev`, `staging`, `test`, `admin`, `blog`, `shop`, `store`, `app`, `cdn`, `static`, `media`, `assets`, `img`, `docs`, `help`, `support`, `forum`, `git`, `gitlab`, `jenkins`, `ci`, `vpn`, `m`, `mobile`, `panel`, `cpanel`, `webmail`, `smtp`, `pop`, `imap`, `ns1`, `ns2`

### Response

```json
{
  "domain": "example.com",
  "records": {
    "A":  [{ "name": "example.com.", "type": 1, "TTL": 3600, "data": "93.184.216.34" }],
    "NS": [{ "data": "a.iana-servers.net." }, { "data": "b.iana-servers.net." }],
    "TXT": [{ "data": "v=spf1 -all" }, { "data": "v=DMARC1; p=reject" }]
  },
  "ipv4": ["93.184.216.34"],
  "ipv6": [],
  "nameservers": ["a.iana-servers.net.", "b.iana-servers.net."],
  "mx": [],
  "txt": ["v=spf1 -all", "v=DMARC1; p=reject"],
  "cname": null,
  "tech": ["SPF", "DMARC"],
  "subdomains": ["www.example.com"]
}
```

---

## GET /api/certs.php

Queries `crt.sh` for all TLS certificates ever issued to the domain's base domain.

### Parameters

| Param | Required | Description |
|---|---|---|
| `domain` | Yes | Domain — base domain is automatically extracted |

### Base Domain Extraction

`api.staging.example.com` → queries `crt.sh` for `%.example.com`

### Response

```json
{
  "domain": "api.staging.example.com",
  "baseDomain": "example.com",
  "total": 12,
  "subdomains": [
    "api.example.com",
    "dev.example.com",
    "example.com",
    "staging.example.com",
    "test.example.com",
    "www.example.com"
  ],
  "certCount": 87
}
```

---

## GET /api/crawl.php

Probes a batch of wordlist paths in parallel using `curl_multi`.

### Parameters

| Param | Required | Default | Description |
|---|---|---|---|
| `domain` | Yes | — | Target domain |
| `scheme` | No | `https` | `http` or `https` |
| `batch` | No | `50` | Paths per call (max 100) |
| `offset` | No | `0` | Start offset into wordlist |
| `paths` | No | — | Newline-separated custom paths to prepend |

### Response

```json
{
  "domain": "example.com",
  "total": 352,
  "offset": 0,
  "batch": 50,
  "results": [
    {
      "path": "/.env",
      "status": 403,
      "contentType": "text/html",
      "contentLength": 0,
      "server": "nginx/1.24.0",
      "redirect": null,
      "hasDirectoryList": false,
      "interesting": true
    },
    {
      "path": "/admin/",
      "status": 302,
      "contentType": "text/html",
      "contentLength": 280,
      "server": "nginx/1.24.0",
      "redirect": "/admin/login",
      "hasDirectoryList": false,
      "interesting": true
    }
  ]
}
```

### Interesting Path Criteria

A result is flagged `interesting: true` if any of the following apply:

- Status is 2xx or 206
- Status is 3xx (redirect)
- Status is 401 or 403
- Path name contains: `.env`, `config`, `backup`, `admin`, `phpmyadmin`, `phpinfo`, `.git`, `.svn`, `.htpasswd`, `passwd`, `shadow`, `credentials`, `secret`, `private`, `key`, `token`, `database`

---

## Error Responses

All endpoints return HTTP 200 with a JSON error object on failure (to simplify frontend handling):

```json
{ "error": "Human-readable message", "url": "…" }
```

Common error messages:
- `Missing url parameter` / `Missing domain parameter`
- `Invalid URL`
- `Only http/https allowed`
- `Curl request failed: <curl_error>`
- `robots.txt not found or unreachable`
- `Wayback CDX unavailable`
- `crt.sh unavailable`
