# [FileX] — Domain Structure Reconstructor

> **GPL-3.0** · PHP + Vanilla JS · Deploy-ready for InfinityFree

FileX is a powerful web tool that reconstructs the internal file and directory structure of any domain or subdomain by aggregating data from multiple passive and active sources — robots.txt, sitemaps, the Wayback Machine CDX API, DNS, certificate transparency logs, and a multi-threaded wordlist crawler. Results are rendered as an interactive, filterable, exportable file tree with technology fingerprinting, subdomain discovery, and security-relevant path flagging.

---

## Table of Contents

- [Features](#features)
- [Architecture](#architecture)
- [How It Works](#how-it-works)
  - [Stage 1 — robots.txt](#stage-1--robotstxt)
  - [Stage 2 — Sitemap Discovery](#stage-2--sitemap-discovery)
  - [Stage 3 — Wayback Machine CDX](#stage-3--wayback-machine-cdx)
  - [Stage 4 — DNS + Subdomain Enumeration](#stage-4--dns--subdomain-enumeration)
  - [Stage 5 — Certificate Transparency](#stage-5--certificate-transparency)
  - [Stage 6 — Wordlist Crawl](#stage-6--wordlist-crawl)
  - [Stage 7 — Link Probing (optional)](#stage-7--link-probing-optional)
  - [Stage 8 — Tree Building](#stage-8--tree-building)
- [Directory Layout](#directory-layout)
- [API Endpoints](#api-endpoints)
  - [probe.php](#probephp)
  - [robots.php](#robotsphp)
  - [sitemap.php](#sitemapphp)
  - [wayback.php](#waybackphp)
  - [dns.php](#dnsphp)
  - [certs.php](#certsphp)
  - [crawl.php](#crawlphp)
- [Deployment](#deployment)
  - [Prerequisites](#prerequisites)
  - [InfinityFree Setup](#infinityfree-setup)
  - [GitHub Secrets Required](#github-secrets-required)
  - [Manual FTP Deploy](#manual-ftp-deploy)
- [CI/CD Pipelines](#cicd-pipelines)
  - [lint.yml](#lintyml)
  - [deploy.yml](#deployyml)
- [Configuration & Options](#configuration--options)
- [Exporting Results](#exporting-results)
- [Security & Ethics](#security--ethics)
- [Why PHP for the Backend?](#why-php-for-the-backend)
- [Known Limitations](#known-limitations)
- [Contributing](#contributing)
- [License](#license)

---

## Features

| Feature | Detail |
|---|---|
| **robots.txt parser** | Full directive parsing — Disallow, Allow, Sitemap, Crawl-delay, Host — per user-agent with comment extraction |
| **Sitemap crawler** | Recursive sitemap index traversal across unlimited nesting levels, up to 5,000 URLs |
| **Wayback Machine CDX** | Up to 5,000 collapsed URL snapshots with MIME stats and path deduplication |
| **DNS recon** | A/AAAA/CNAME/MX/NS/TXT/SOA/SRV via Google DoH, with 30 common subdomain probes |
| **Certificate transparency** | crt.sh wildcard query → all known subdomains from historical TLS certificates |
| **Wordlist crawl** | 350+ path wordlist probed in parallel batches via PHP curl_multi, configurable batch size |
| **Link probing** | Optionally probe every `<a href>` and `<script src>` discovered on the root page |
| **Technology fingerprinting** | CMS (WordPress, Drupal, Joomla, Shopify…), server (nginx, Apache), framework (React, Vue, Next.js, Laravel…), CDN (Cloudflare), mail (GSuite, M365) |
| **HTML comment extraction** | Surfaces developer comments that may leak paths or credentials |
| **Interactive file tree** | Collapsible, searchable, filterable tree with status badges, content types, sizes, redirect targets |
| **⚡ Interesting filter** | One-click to show only security-relevant paths (open dirs, 401/403, .env, config, backup…) |
| **Export** | JSON (full scan data), CSV (path/status/type/size/source), plaintext tree |
| **Stat dashboard** | Live chips: unique paths, live 2xx, redirects, 401/403, open directories, Wayback captures, subdomains |
| **Abort support** | Cancel mid-scan at any time with the STOP button |
| **Source attribution** | Every path tagged with its discovery source (robots, sitemap, wayback, crawl, probe, dns) |

---

## Architecture

```
Browser (HTML + CSS + JS ES Modules)
    │
    │  fetch() calls — same origin, no CORS
    ▼
PHP API endpoints (public/api/*.php)
    │
    ├── curl / curl_multi  ──► Target domain
    ├── curl               ──► web.archive.org CDX API
    ├── curl               ──► dns.google/resolve (DoH)
    └── curl               ──► crt.sh
```

The frontend is a single-page app built with ES Modules — no build step, no bundler, no framework. The PHP layer exists entirely to proxy outbound HTTP requests that would be blocked by CORS in a browser context.

---

## How It Works

### Stage 1 — robots.txt

`robots.php` fetches `https://{domain}/robots.txt` (falling back to HTTP) and parses every directive. All Disallow and Allow paths across every user-agent block are extracted and surfaced as known paths. Sitemap URLs referenced in robots.txt seed Stage 2.

### Stage 2 — Sitemap Discovery

`sitemap.php` tries 9 common sitemap locations (`/sitemap.xml`, `/wp-sitemap.xml`, etc.) and recursively follows `<sitemapindex>` references up to 4 levels deep, collecting `<loc>` entries up to a 5,000-URL cap.

### Stage 3 — Wayback Machine CDX

`wayback.php` queries the Internet Archive's CDX Search API with `collapse=urlkey` to get one representative snapshot per unique URL. It extracts paths, MIME types, status codes, and timestamps — surfacing URLs that existed historically but may have been deleted.

### Stage 4 — DNS + Subdomain Enumeration

`dns.php` queries Google's DoH API (`dns.google/resolve`) for A, AAAA, CNAME, MX, NS, TXT, SOA, and SRV records. It then probes 30 common subdomain prefixes (`www`, `mail`, `api`, `dev`, `staging`, `admin`, etc.) using PHP's `dns_get_record()`. Technology inference from nameservers, MX hosts, and TXT records surfaces email providers, DNS providers, and domain verification tokens.

### Stage 5 — Certificate Transparency

`certs.php` queries `crt.sh` with a wildcard search for the base domain (`%.example.com`). This reveals all subdomains that have ever had a TLS certificate issued — including internal, staging, and legacy ones that DNS no longer resolves.

### Stage 6 — Wordlist Crawl

`crawl.php` contains a built-in wordlist of 350+ paths covering every common web application pattern — CMS paths, API endpoints, configuration files, backup archives, admin panels, framework artifacts, development leftovers, log files, and security-sensitive filenames. Paths are probed in parallel using PHP's `curl_multi_*` API. The frontend paginates through the wordlist in configurable batches (25/50/100), streaming results in real time. Only paths with a non-zero, non-404 status are highlighted as live.

### Stage 7 — Link Probing (optional)

When enabled, `probe.php` is called on the root URL to extract all `<a href>` and `<script src>` values. Each discovered URL is then individually probed to retrieve its status, content type, size, and response headers.

### Stage 8 — Tree Building

The frontend deduplicates all collected path entries across all sources, builds a nested directory tree structure, and renders it as an interactive DOM tree. Each node carries its probe result (if available), source attribution badge, and semantic badges (DIR LIST, ⚡ interesting, redirect target).

---

## Directory Layout

```
filex/
├── .github/
│   └── workflows/
│       ├── lint.yml          # PHP syntax, ESLint, HTML validation
│       └── deploy.yml        # FTP deploy to InfinityFree on push to main
├── public/                   # Entire contents deployed to /htdocs/
│   ├── .htaccess             # Security headers, compression, cache
│   ├── index.html            # Single-page app shell
│   ├── api/
│   │   ├── .htaccess         # API-specific headers, PHP config
│   │   ├── probe.php         # General URL probe + fingerprinting
│   │   ├── robots.php        # robots.txt fetch + parser
│   │   ├── sitemap.php       # Recursive sitemap crawler
│   │   ├── wayback.php       # Wayback Machine CDX proxy
│   │   ├── dns.php           # DNS recon via Google DoH
│   │   ├── certs.php         # Certificate transparency via crt.sh
│   │   └── crawl.php         # Parallel wordlist path prober
│   └── assets/
│       ├── css/
│       │   └── filex.css     # Full stylesheet (CSS variables, dark theme)
│       └── js/
│           ├── filex.js      # Main orchestrator (ES module)
│           └── tree.js       # FileTree class — build, render, export
├── docs/
│   └── api.md                # API endpoint reference
├── .gitignore
├── .gitattributes
├── LICENSE                   # GPL-3.0
└── README.md
```

---

## API Endpoints

All endpoints accept `GET` requests and return `application/json`. They are designed for same-origin use only (the `.htaccess` sets broad CORS headers for development convenience, which you may want to tighten for production).

### probe.php

**Query params:** `url` (full URL including scheme)

Fetches the target URL and returns a rich metadata object.

```json
{
  "url": "https://example.com/",
  "finalUrl": "https://example.com/",
  "status": 200,
  "elapsed": 312,
  "contentType": "text/html; charset=UTF-8",
  "contentLength": 14832,
  "server": "nginx/1.24.0",
  "poweredBy": "PHP/8.2.0",
  "redirectCount": 0,
  "headers": { "…": "…" },
  "hasDirectoryList": false,
  "cms": ["WordPress"],
  "tech": ["nginx 1.24.0", "PHP 8.2.0", "jQuery 3.6.4"],
  "forms": ["/wp-login.php"],
  "links": ["https://example.com/about/", "…"],
  "scripts": ["https://example.com/wp-includes/js/jquery/jquery.min.js"],
  "metaTags": { "generator": "WordPress 6.4" },
  "comments": ["Build: 2024-01-15"],
  "bodySnippet": "<!DOCTYPE html>…"
}
```

### robots.php

**Query params:** `domain`

```json
{
  "url": "https://example.com/robots.txt",
  "status": 200,
  "raw": "User-agent: *\nDisallow: /admin/\n…",
  "parsed": {
    "agents": {
      "*": {
        "disallow": [{ "path": "/admin/", "comment": "" }],
        "allow": []
      }
    },
    "sitemaps": ["https://example.com/sitemap.xml"],
    "allPaths": ["/admin/", "/private/", "…"]
  }
}
```

### sitemap.php

**Query params:** `domain`

```json
{
  "domain": "example.com",
  "total": 142,
  "sources": [
    { "url": "https://example.com/sitemap.xml", "status": 200 }
  ],
  "urls": ["https://example.com/about/", "…"]
}
```

### wayback.php

**Query params:** `domain`, `limit` (max 5000), `from` (timestamp), `to` (timestamp)

```json
{
  "domain": "example.com",
  "total": 2841,
  "paths": ["/", "/old-page/", "/deleted-admin/", "…"],
  "mimeStats": { "text/html": 2100, "application/javascript": 400, "…": "…" },
  "urls": [
    { "url": "https://example.com/old-page/", "path": "/old-page/", "status": "200", "mime": "text/html", "ts": "20190401120000", "size": 8192 }
  ]
}
```

### dns.php

**Query params:** `domain`

```json
{
  "domain": "example.com",
  "ipv4": ["93.184.216.34"],
  "ipv6": ["2606:2800:220:1:248:1893:25c8:1946"],
  "nameservers": ["a.iana-servers.net.", "b.iana-servers.net."],
  "mx": [{ "priority": 10, "host": "mail.example.com." }],
  "txt": ["v=spf1 -all"],
  "cname": null,
  "tech": ["SPF", "DMARC"],
  "subdomains": ["www.example.com", "mail.example.com"]
}
```

### certs.php

**Query params:** `domain`

```json
{
  "domain": "example.com",
  "baseDomain": "example.com",
  "total": 7,
  "subdomains": ["api.example.com", "dev.example.com", "staging.example.com", "…"],
  "certCount": 34
}
```

### crawl.php

**Query params:** `domain`, `scheme` (http/https), `batch`, `offset`, `paths` (newline-separated custom paths)

```json
{
  "domain": "example.com",
  "total": 350,
  "offset": 0,
  "batch": 50,
  "results": [
    {
      "path": "/.env",
      "status": 403,
      "contentType": "text/html",
      "contentLength": 0,
      "server": "nginx",
      "redirect": null,
      "hasDirectoryList": false,
      "interesting": true
    }
  ]
}
```

---

## Deployment

### Prerequisites

- A free [InfinityFree](https://infinityfree.net) account
- A GitHub repository (fork or clone of this one)
- PHP 8.0+ with `curl` and `libxml` enabled (InfinityFree provides both)

### InfinityFree Setup

1. Create a hosting account and note your **FTP hostname**, **FTP username**, and **FTP password** from the control panel.
2. The deploy target is `/htdocs/` — the `public/` directory contents go directly here.
3. Ensure no existing `index.html` conflicts.
4. InfinityFree's PHP has `curl` and `allow_url_fopen` enabled by default.

### GitHub Secrets Required

Navigate to your repo → **Settings → Secrets and variables → Actions → New repository secret** and add:

| Secret name | Value |
|---|---|
| `FTP_HOST` | e.g. `ftpupload.net` |
| `FTP_USERNAME` | e.g. `epiz_12345678` |
| `FTP_PASSWORD` | Your FTP password |

### Manual FTP Deploy

If you prefer a one-time manual deploy with `lftp`:

```bash
lftp -e "mirror -R ./public/ /htdocs/ --delete; bye" \
     -u "$FTP_USER,$FTP_PASS" ftp://$FTP_HOST
```

Or use [FileZilla](https://filezilla-project.org/) to drag `public/` contents into `/htdocs/`.

---

## CI/CD Pipelines

### lint.yml

Triggers on every push to `main`/`develop` and every pull request to `main`.

| Job | What it does |
|---|---|
| `lint-php` | `php -l` syntax check on every `.php` file + PHP-CS-Fixer dry run against PSR-12 |
| `lint-js` | ESLint 9 with ES2022 module rules across `public/assets/js/` |
| `lint-html` | `html-validate` on `index.html` |

### deploy.yml

Triggers on push to `main` and manual `workflow_dispatch`.

1. Runs a pre-deploy PHP syntax check as a gate
2. Uses `SamKirkland/FTP-Deploy-Action` to mirror `public/` → `/htdocs/`
3. Excludes `.git*`, `node_modules`, `*.bak`, `*.log`
4. Reports success or failure in the workflow log

---

## Configuration & Options

All options are toggled in the UI before scanning. They persist per-scan but not across page reloads.

| Option | Default | Effect |
|---|---|---|
| robots.txt | ✅ | Fetch and parse `/robots.txt` |
| Sitemap | ✅ | Recursively crawl all sitemaps |
| Wayback Machine | ✅ | Query CDX API for historical paths |
| DNS + Subdomains | ✅ | Full DNS recon + 30 subdomain probes |
| Cert Transparency | ✅ | Query crt.sh for subdomain history |
| Wordlist Crawl | ✅ | Probe 350+ paths in parallel batches |
| Probe Discovered Links | ❌ | Individually probe `<a>` + `<script>` URLs from root page |
| Scheme | HTTPS | Use HTTP or HTTPS for crawl requests |
| Batch size | 50 | Paths per crawl API call (25/50/100) |

---

## Exporting Results

| Format | Contents |
|---|---|
| **JSON** | Full scan data object — all entries, probe results, robots, sitemap, wayback, dns, certs, source counts |
| **Tree (TXT)** | ASCII art directory tree identical to `tree(1)` output, with `[status]` and `(source)` annotations |
| **CSV** | One row per unique path — columns: `path, status, contentType, size, source, interesting, redirect` |

---

## Security & Ethics

FileX is a **passive and semi-passive** reconnaissance tool. It:

- **Does not** send exploit payloads of any kind
- **Does not** brute-force authentication
- **Does not** attempt to read file contents beyond HTTP response metadata
- Uses only publicly available data sources (robots.txt, sitemaps, Wayback Machine, DNS, crt.sh)
- The wordlist crawl sends standard HTTP GET requests — identical to what a browser or search engine crawler would do

**You are solely responsible for ensuring you have authorization to scan any domain you target.** Scanning domains you do not own or do not have explicit written permission to test may violate computer fraud laws in your jurisdiction. The authors and contributors of FileX accept no liability for misuse.

The tool deliberately omits:
- Authenticated brute-force (HTTP 401 credential testing)
- Vulnerability scanning or exploit probing
- Automated rate-exhaustion or DoS-capable patterns

---

## Why PHP for the Backend?

The frontend makes HTTP requests to arbitrary external domains, which browsers block via CORS unless the target server explicitly allows it — which most do not. A thin PHP proxy running on the same origin as the frontend sidesteps this entirely. InfinityFree provides free PHP hosting with cURL, making it a zero-cost deployment target. The PHP layer is intentionally minimal: it validates input, proxies requests with a custom UA, and returns JSON.

---

## Known Limitations

- **InfinityFree `max_execution_time`**: InfinityFree caps PHP execution at approximately 30–60 seconds. Large Wayback CDX responses or slow crawl batches may hit this limit. Reduce batch size or limit source counts if you observe timeouts.
- **InfinityFree cURL restrictions**: Some shared hosts block outbound cURL to certain ranges. If Wayback or crt.sh calls fail, this may be the cause.
- **No JavaScript execution**: `probe.php` fetches raw HTML only — it cannot render JavaScript-heavy SPAs. Paths loaded via client-side routing will not be discovered unless they appear in other sources.
- **Wayback CDX rate limiting**: The Internet Archive imposes soft rate limits on CDX queries. Repeated rapid scans of the same domain may return throttled or empty responses.
- **crt.sh availability**: crt.sh is a free community service and occasionally experiences downtime.
- **robots.txt scope**: robots.txt only discloses paths the site operator chose to list. It is not a complete inventory.

---

## Contributing

Contributions are welcome. Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/your-feature`)
3. Ensure `lint.yml` passes (`php -l`, ESLint, html-validate)
4. Submit a pull request against `main` with a clear description

Ideas for contribution:
- Additional CMS/tech fingerprints in `probe.php`
- Expanded wordlist entries in `crawl.php`
- JavaScript `<link rel="preload">` / `<link rel="stylesheet">` path extraction
- `.well-known/` endpoint enumeration
- Wappalyzer-style comprehensive tech detection
- Dark/light theme toggle
- Scan history stored in `localStorage`

---

## License

FileX is free software: you can redistribute it and/or modify it under the terms of the **GNU General Public License version 3** as published by the Free Software Foundation.

See [LICENSE](LICENSE) for the full license text, or visit [https://www.gnu.org/licenses/gpl-3.0.en.html](https://www.gnu.org/licenses/gpl-3.0.en.html).

```
Copyright (C) FileX Contributors
This program comes with ABSOLUTELY NO WARRANTY.
This is free software, and you are welcome to redistribute it
under certain conditions; see LICENSE for details.
```
