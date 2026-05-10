import { FileTree } from './tree.js';

const API_BASE = './api';

const ui = {
    domainInput:     () => document.getElementById('domainInput'),
    clearBtn:        () => document.getElementById('clearBtn'),
    scanBtn:         () => document.getElementById('scanBtn'),
    stopBtn:         () => document.getElementById('stopBtn'),
    progressSection: () => document.getElementById('progressSection'),
    progressBar:     () => document.getElementById('progressBar'),
    progressLog:     () => document.getElementById('progressLog'),
    resultsSection:  () => document.getElementById('resultsSection'),
    errorSection:    () => document.getElementById('errorSection'),
    errorMessage:    () => document.getElementById('errorMessage'),
    targetInfo:      () => document.getElementById('targetInfo'),
    techList:        () => document.getElementById('techList'),
    dnsInfo:         () => document.getElementById('dnsInfo'),
    certList:        () => document.getElementById('certList'),
    statsRow:        () => document.getElementById('statsRow'),
    resultsTitle:    () => document.getElementById('resultsTitle'),
    fileTree:        () => document.getElementById('fileTree'),
    sourcesList:     () => document.getElementById('sourcesList'),
    treeSearch:      () => document.getElementById('treeSearch'),
    filterBtn:       () => document.getElementById('filterInterestingBtn'),
    expandAllBtn:    () => document.getElementById('expandAllBtn'),
    collapseAllBtn:  () => document.getElementById('collapseAllBtn'),
    exportJsonBtn:   () => document.getElementById('exportJsonBtn'),
    exportTextBtn:   () => document.getElementById('exportTextBtn'),
    exportCsvBtn:    () => document.getElementById('exportCsvBtn'),
    exportUrlsBtn:   () => document.getElementById('exportUrlsBtn'),
    exportWgetBtn:   () => document.getElementById('exportWgetBtn'),
    stageEl:         (id) => document.getElementById('stage' + id),
};

const options = {
    robots:     () => document.getElementById('optRobots').checked,
    sitemap:    () => document.getElementById('optSitemap').checked,
    wayback:    () => document.getElementById('optWayback').checked,
    dns:        () => document.getElementById('optDns').checked,
    certs:      () => document.getElementById('optCerts').checked,
    crawl:      () => document.getElementById('optCrawl').checked,
    probeLinks: () => document.getElementById('optProbeLinks').checked,
    scheme:     () => document.getElementById('optScheme').value,
    batch:      () => parseInt(document.getElementById('optBatch').value, 10),
};

let abortController = null;
let scanData        = null;
let fileTreeInst    = null;
let interestingOnly = false;

function sanitizeDomain(raw) {
    return raw.trim().toLowerCase()
        .replace(/^https?:\/\//i, '')
        .replace(/^www\./i, '')
        .split('/')[0].trim();
}

function isValidDomain(domain) {
    return /^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)+$/.test(domain);
}

async function apiFetch(endpoint, params, signal) {
    const url = `${API_BASE}/${endpoint}?${new URLSearchParams(params)}`;
    const res = await fetch(url, { signal });
    if (!res.ok) {
        let msg = `HTTP ${res.status}`;
        try { const j = await res.clone().json(); if (j.error) msg = j.error; } catch {}
        throw new Error(msg);
    }
    return res.json();
}

function setStage(id, state) {
    const el = ui.stageEl(id);
    if (el) el.className = 'stage' + (state ? ' stage--' + state : '');
}

function log(msg, type = '') {
    const el   = ui.progressLog();
    const ts   = new Date().toLocaleTimeString('en', { hour12: false });
    const line = document.createElement('div');
    line.className = 'log-line';
    line.innerHTML = `<span class="log-ts">${ts}</span><span class="log-msg log-msg--${type}">${esc(msg)}</span>`;
    el.appendChild(line);
    el.scrollTop = el.scrollHeight;
}

function setProgress(pct) {
    ui.progressBar().style.width = Math.min(100, pct) + '%';
}

function esc(str) {
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}


function addEntry(data, path, source, probeResult) {
    if (!path) return;
    data.allEntries.push({ path, source, probe: probeResult });
}

async function runScan(domain) {
    abortController = new AbortController();
    const signal = abortController.signal;
    const scheme = options.scheme();

    const data = {
        domain, scheme, root: null,
        robots: null, sitemap: null, wayback: null, dns: null, certs: null,
        crawl: [], probed: [], allEntries: [],
        sources: { robots: 0, sitemap: 0, wayback: 0, crawl: 0, probe: 0, dns: 0 },
    };

    setProgress(2);
    log(`scan started: ${scheme}://${domain}`);

    // robots.txt
    setStage('Robots', 'active');
    if (options.robots()) {
        try {
            const r = await apiFetch('robots.php', { domain }, signal);
            data.robots = r;
            const paths = r.parsed?.allPaths ?? [];
            paths.forEach(p => addEntry(data, p, 'robots', null));
            data.sources.robots = paths.length;
            log(`robots.txt — ${paths.length} paths, ${r.parsed?.sitemaps?.length ?? 0} sitemaps`, 'success');
            setStage('Robots', 'done');
        } catch (e) {
            if (e.name === 'AbortError') throw e;
            log(`robots.txt: ${e.message}`, 'warn');
            setStage('Robots', 'error');
        }
    } else setStage('Robots', 'skipped');

    setProgress(15);

    // sitemap
    setStage('Sitemap', 'active');
    if (options.sitemap()) {
        try {
            const r = await apiFetch('sitemap.php', { domain }, signal);
            data.sitemap = r;
            (r.urls ?? []).forEach(u => {
                try { addEntry(data, new URL(u).pathname, 'sitemap', null); } catch {}
            });
            data.sources.sitemap = r.total ?? 0;
            log(`sitemap — ${r.total ?? 0} URLs from ${r.sources?.length ?? 0} sources`, 'success');
            setStage('Sitemap', 'done');
        } catch (e) {
            if (e.name === 'AbortError') throw e;
            log(`sitemap: ${e.message}`, 'warn');
            setStage('Sitemap', 'error');
        }
    } else setStage('Sitemap', 'skipped');

    setProgress(28);

    // wayback
    setStage('Wayback', 'active');
    if (options.wayback()) {
        try {
            const r = await apiFetch('wayback.php', { domain, limit: 3000 }, signal);
            data.wayback = r;
            (r.paths ?? []).forEach(p => addEntry(data, p, 'wayback', null));
            data.sources.wayback = r.paths?.length ?? 0;
            log(`wayback — ${r.total ?? 0} captures, ${r.paths?.length ?? 0} unique paths`, 'success');
            setStage('Wayback', 'done');
        } catch (e) {
            if (e.name === 'AbortError') throw e;
            log(`wayback: ${e.message}`, 'warn');
            setStage('Wayback', 'error');
        }
    } else setStage('Wayback', 'skipped');

    setProgress(42);

    // dns
    setStage('Dns', 'active');
    if (options.dns()) {
        try {
            const r = await apiFetch('dns.php', { domain }, signal);
            data.dns = r;
            data.sources.dns = r.subdomains?.length ?? 0;
            const wc = r.wildcardDetected ? ' (wildcard DNS — distinct-IP subdomains only)' : '';
            log(`dns — ${r.ipv4?.length ?? 0} IPs, ${r.subdomains?.length ?? 0} subdomains${wc}`, 'success');
            setStage('Dns', 'done');
        } catch (e) {
            if (e.name === 'AbortError') throw e;
            log(`dns: ${e.message}`, 'warn');
            setStage('Dns', 'error');
        }
    } else setStage('Dns', 'skipped');

    setProgress(54);

    // certs
    setStage('Certs', 'active');
    if (options.certs()) {
        try {
            const r = await apiFetch('certs.php', { domain }, signal);
            data.certs = r;
            log(`certs — ${r.total ?? 0} subdomains from ${r.certCount ?? 0} certs`, 'success');
            setStage('Certs', 'done');
        } catch (e) {
            if (e.name === 'AbortError') throw e;
            log(`certs: ${e.message}`, 'warn');
            setStage('Certs', 'error');
        }
    } else setStage('Certs', 'skipped');

    setProgress(60);

    // spider crawl
    setStage('Crawl', 'active');
    if (options.crawl()) {
        const batch = options.batch();
        let queue   = [];
        let visited = [];
        let baselineEncoded = null;
        let failures = 0;
        let done     = false;

        log('spider crawl -- following real links from /');

        while (!done) {
            try {
                const params = {
                    domain, scheme, batch,
                    queue:   JSON.stringify(queue),
                    visited: JSON.stringify(visited),
                };
                if (baselineEncoded) params.baseline = baselineEncoded;

                const r = await apiFetch('crawl.php', params, signal);
                failures = 0;

                if (r.baseline) {
                    data.crawlBaseline = r.baseline;
                    baselineEncoded    = r.baselineEncoded ?? null;
                    const b = r.baseline;
                    log(b.reliable
                        ? `baseline: status=${b.status} body~${b.bodyLen}b`
                        : 'baseline unreliable -- false positives possible',
                        b.reliable ? 'success' : 'warn');
                }

                queue   = r.queue   ?? [];
                visited = r.visited ?? [];
                done    = r.done    ?? true;

                const batchRes = r.results ?? [];
                batchRes.forEach(item => {
                    data.crawl.push(item);
                    addEntry(data, item.path, 'crawl', item);
                });

                const hits = batchRes.filter(x => x.interesting);
                const pct  = Math.min(84, 60 + Math.round(
                    (visited.length / Math.max(visited.length + queue.length, 1)) * 24
                ));
                setProgress(pct);

                if (hits.length > 0) {
                    log(`[${visited.length} visited / ${queue.length} queued] found: ${hits.map(x => x.path + ' [' + x.status + ']').join(', ')}`, 'success');
                } else if (batchRes.length > 0) {
                    log(`[${visited.length} visited / ${queue.length} queued] ${batchRes.length} pages crawled`);
                }

            } catch (e) {
                if (e.name === 'AbortError') throw e;
                failures++;
                log(`crawl error (${failures}/3): ${e.message}`, 'warn');
                if (failures >= 3) {
                    log('crawl aborted after 3 failures', 'error');
                    setStage('Crawl', 'error');
                    break;
                }
                await new Promise(res => window.setTimeout(res, 1500));
            }
        }

        const hits = data.crawl.filter(x => x.interesting);
        log(`crawl done -- ${visited.length} pages visited, ${hits.length} interesting`, hits.length > 0 ? 'success' : '');
        setStage('Crawl', 'done');
        data.sources.crawl = visited.length;
    } else setStage('Crawl', 'skipped');

    setProgress(85);

    // probe links
    setStage('Probe', 'active');
    if (options.probeLinks()) {
        try {
            const probeRoot = await apiFetch('probe.php', { url: `${scheme}://${domain}/` }, signal);
            data.root = probeRoot;
            const targets = [...new Set([...(probeRoot.links ?? []), ...(probeRoot.scripts ?? [])])].slice(0, 30);
            let probeCount = 0;

            for (const url of targets) {
                try {
                    const r = await apiFetch('probe.php', { url }, signal);
                    data.probed.push(r);
                    try { addEntry(data, new URL(url).pathname, 'probe', r); } catch {}
                    probeCount++;
                } catch (e2) {
                    if (e2.name === 'AbortError') throw e2;
                }
            }

            data.sources.probe = probeCount;
            log(`probed ${probeCount} discovered links`, 'success');
            setStage('Probe', 'done');
        } catch (e) {
            if (e.name === 'AbortError') throw e;
            log(`probe: ${e.message}`, 'warn');
            setStage('Probe', 'error');
        }
    } else {
        if (!data.root) {
            try { data.root = await apiFetch('probe.php', { url: `${scheme}://${domain}/` }, signal); } catch {}
        }
        setStage('Probe', 'skipped');
    }

    setProgress(95);
    setStage('Building', 'active');
    log('building file tree...');

    return data;
}

function buildStats(data) {
    const probed      = data.crawl;
    const live        = probed.filter(x => x.status >= 200 && x.status < 300);
    const redir       = probed.filter(x => x.status >= 301 && x.status <= 308);
    const auth        = probed.filter(x => x.status === 401 || x.status === 403);
    const dirs        = probed.filter(x => x.hasDirectoryList);
    const interesting = probed.filter(x => x.interesting);
    const uniquePaths = new Set(data.allEntries.filter(e => !e.subdomain).map(e => e.path));

    return { total: uniquePaths.size, live: live.length, redir: redir.length, auth: auth.length, dirs: dirs.length, interesting: interesting.length };
}

function renderStats(stats, data) {
    const row = ui.statsRow();
    row.innerHTML = '';
    [
        { label: 'unique paths',   value: stats.total,                                         cls: '' },
        { label: '2xx live',       value: stats.live,                                          cls: 'green' },
        { label: 'interesting',    value: stats.interesting,                                   cls: 'amber' },
        { label: 'redirects',      value: stats.redir,                                         cls: '' },
        { label: '401/403',        value: stats.auth,                                          cls: 'amber' },
        { label: 'open dirs',      value: stats.dirs,                                          cls: 'amber' },
        { label: 'wayback',        value: data.wayback?.total ?? 0,                            cls: '' },
        { label: 'subdomains',     value: (data.certs?.total ?? 0) + (data.dns?.subdomains?.length ?? 0), cls: '' },
    ].forEach(({ label, value, cls }) => {
        const chip = document.createElement('div');
        chip.className = 'stat-chip' + (cls ? ' stat-chip--' + cls : '');
        chip.innerHTML = `<span class="stat-chip__label">${label}</span><span class="stat-chip__value">${value}</span>`;
        row.appendChild(chip);
    });
}

function renderTargetInfo(data) {
    const root = data.root;
    const el   = ui.targetInfo();
    el.innerHTML = '';
    [
        ['domain',     data.domain],
        ['status',     root?.status ?? '—'],
        ['server',     root?.server ?? '—'],
        ['powered by', root?.poweredBy ?? '—'],
        ['response',   root?.elapsed ? root.elapsed + 'ms' : '—'],
        ['final url',  root?.finalUrl ?? '—'],
        ['redirects',  root?.redirectCount ?? 0],
    ].forEach(([key, val]) => {
        const k = document.createElement('span');
        k.className = 'kv-key';
        k.textContent = key;
        const v = document.createElement('span');
        v.className = 'kv-val';
        if (key === 'status') {
            const s = parseInt(val, 10);
            v.className += s >= 200 && s < 300 ? ' kv-val--green' : s >= 400 ? ' kv-val--red' : ' kv-val--amber';
        }
        v.textContent = String(val);
        el.appendChild(k);
        el.appendChild(v);
    });
}

function renderTechList(data) {
    const el = ui.techList();
    el.innerHTML = '';
    const all = [
        ...(data.root?.cms ?? []).map(t => ({ text: t, cls: 'cms' })),
        ...(data.root?.tech ?? []).map(t => ({
            text: t,
            cls: /nginx|apache|iis|caddy/i.test(t) ? 'server'
               : /php|python|ruby|node|java|asp/i.test(t) ? 'lang'
               : /cloudflare|fastly|akamai/i.test(t) ? 'cdn' : 'fw',
        })),
        ...(data.dns?.tech ?? []).map(t => ({ text: t, cls: '' })),
    ];
    if (all.length === 0) {
        el.innerHTML = '<span style="color:var(--t3);font-size:.68rem">none detected</span>';
        return;
    }
    all.forEach(({ text, cls }) => {
        const tag = document.createElement('span');
        tag.className = 'tech-tag' + (cls ? ' tech-tag--' + cls : '');
        tag.textContent = text;
        el.appendChild(tag);
    });
}

function renderDnsInfo(data) {
    const dns = data.dns;
    const el  = ui.dnsInfo();
    el.innerHTML = '';
    if (!dns) {
        el.innerHTML = '<span style="color:var(--t3);font-size:.68rem">not scanned</span>';
        return;
    }
    if (dns.wildcardDetected) {
        const warn = document.createElement('div');
        warn.className = 'dns-warn';
        warn.textContent = 'wildcard DNS detected — *.' + dns.domain + ' resolves. distinct-IP subdomains only.';
        el.appendChild(warn);
    }
    [
        { title: 'ipv4',        items: dns.ipv4 ?? [] },
        { title: 'ipv6',        items: dns.ipv6 ?? [] },
        { title: 'nameservers', items: dns.nameservers ?? [] },
        { title: dns.wildcardDetected ? 'subdomains (distinct ip)' : 'subdomains', items: dns.subdomains ?? [], sub: true },
    ].forEach(({ title, items, sub }) => {
        if (!items.length) return;
        const sec = document.createElement('div');
        const t   = document.createElement('div');
        t.className = 'dns-section-title';
        t.textContent = title;
        sec.appendChild(t);
        items.slice(0, 30).forEach(item => {
            const d = document.createElement('div');
            d.className = sub ? 'dns-subdomain' : 'dns-record';
            d.textContent = item;
            sec.appendChild(d);
        });
        el.appendChild(sec);
    });
}

function renderCertList(data) {
    const el   = ui.certList();
    el.innerHTML = '';
    const subs = data.certs?.subdomains ?? [];
    if (!subs.length) {
        el.innerHTML = '<span style="color:var(--t3);font-size:.68rem">no certificate data</span>';
        return;
    }
    subs.forEach(sub => {
        const item = document.createElement('div');
        item.className = 'cert-item';
        item.textContent = sub;
        el.appendChild(item);
    });
}

function renderSourcesList(data) {
    const el = ui.sourcesList();
    el.innerHTML = '';
    [
        { name: 'robots.txt',     count: data.sources.robots,  pfx: 'robots' },
        { name: 'sitemap',        count: data.sources.sitemap, pfx: 'sitemap' },
        { name: 'wayback machine',count: data.sources.wayback, pfx: 'wayback' },
        { name: 'spider crawl', count: data.sources.crawl,   pfx: 'crawl' },
        { name: 'link probe',     count: data.sources.probe,   pfx: 'probe' },
        { name: 'dns subdomains', count: data.sources.dns,     pfx: 'dns' },
    ].forEach(({ name, count, pfx }) => {
        if (!count) return;
        const chip = document.createElement('div');
        chip.className = 'source-chip';
        chip.innerHTML = `<span class="source-chip__name source--${pfx}">${name}</span><span class="source-chip__count">${count}</span>`;
        el.appendChild(chip);
    });
}

// ── Exports ─────────────────────────────────────────────────

function triggerDownload(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a   = Object.assign(document.createElement('a'), { href: url, download: filename });
    a.click();
    URL.revokeObjectURL(url);
}

function exportJson(data) {
    triggerDownload(
        new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }),
        `filex-${data.domain}-${Date.now()}.json`
    );
}

function exportCsv(data) {
    const rows = [['path', 'status', 'contentType', 'size', 'source', 'interesting', 'redirect']];
    const seen = new Set();
    data.allEntries.forEach(entry => {
        if (seen.has(entry.path)) return;
        seen.add(entry.path);
        const r = entry.probe;
        rows.push([entry.path, r?.status ?? '', r?.contentType ?? '', r?.contentLength ?? '', entry.source, r?.interesting ? '1' : '', r?.redirect ?? '']);
    });
    triggerDownload(
        new Blob([rows.map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n')], { type: 'text/csv' }),
        `filex-${data.domain}-${Date.now()}.csv`
    );
}

function exportUrlList(data) {
    const baseUrl = `${data.scheme || 'https'}://${data.domain}`;
    const urls = data.crawl
        .filter(x => x.status >= 200 && x.status < 300)
        .map(x => baseUrl + x.path);

    // also include wayback/sitemap/robots paths that were actually found
    data.allEntries.forEach(e => {
        if (e.probe?.status >= 200 && e.probe?.status < 300) {
            const full = baseUrl + e.path;
            if (!urls.includes(full)) urls.push(full);
        }
    });

    const content = [...new Set(urls)].join('\n');
    triggerDownload(
        new Blob([content], { type: 'text/plain' }),
        `filex-urls-${data.domain}.txt`
    );
}

function exportWgetScript(data) {
    const baseUrl = `${data.scheme || 'https'}://${data.domain}`;
    const hits = data.crawl.filter(x => x.status >= 200 && x.status < 300);

    let sh = `#!/bin/bash\n`;
    sh += `# FileX wget script — ${data.domain}\n`;
    sh += `# Generated: ${new Date().toISOString()}\n`;
    sh += `# Usage: chmod +x this-file.sh && ./this-file.sh\n\n`;
    sh += `set -e\n`;
    sh += `DIR="filex-${data.domain}"\n`;
    sh += `mkdir -p "$DIR"\n`;
    sh += `echo "Downloading ${hits.length} files from ${data.domain}..."\n\n`;

    hits.forEach(item => {
        const url      = baseUrl + item.path;
        const relPath  = item.path.replace(/^\//, '').replace(/\//g, '_') || 'index';
        const filename = relPath || item.path.split('/').pop() || 'download';
        sh += `wget -q --no-check-certificate -O "$DIR/${filename}" "${url}" && echo "  OK  ${item.path}" || echo "  ERR ${item.path}"\n`;
    });

    sh += `\necho "Done. Files saved to $DIR/"\n`;

    triggerDownload(
        new Blob([sh], { type: 'text/x-shellscript' }),
        `filex-wget-${data.domain}.sh`
    );
}

// ── UI state ─────────────────────────────────────────────────

function showError(msg) {
    ui.errorSection().style.display = '';
    ui.errorMessage().textContent   = msg;
}
function hideError() { ui.errorSection().style.display = 'none'; }

function resetScanUI() {
    ui.progressSection().style.display = '';
    ui.resultsSection().style.display  = 'none';
    ui.progressLog().innerHTML         = '';
    ui.progressBar().style.width       = '0%';
    hideError();
    ['Robots','Sitemap','Wayback','Dns','Certs','Crawl','Probe','Building'].forEach(id => setStage(id, ''));
}

async function startScan() {
    const domain = sanitizeDomain(ui.domainInput().value);
    if (!isValidDomain(domain)) {
        showError(`"${domain}" — not a valid domain. Enter just the hostname, e.g. example.com`);
        return;
    }

    hideError();
    resetScanUI();
    interestingOnly = false;
    ui.filterBtn().classList.remove('active');
    ui.scanBtn().disabled = true;
    ui.scanBtn().classList.add('scanning');
    ui.stopBtn().style.display = '';

    try {
        scanData = await runScan(domain);

        setStage('Building', 'active');
        const stats = buildStats(scanData);
        renderStats(stats, scanData);
        renderTargetInfo(scanData);
        renderTechList(scanData);
        renderDnsInfo(scanData);
        renderCertList(scanData);
        renderSourcesList(scanData);

        fileTreeInst = new FileTree(scanData.allEntries);
        fileTreeInst.render(ui.fileTree(), scanData.domain, scanData.scheme || options.scheme());

        ui.resultsTitle().textContent  = domain;
        ui.progressSection().style.display = 'none';
        ui.resultsSection().style.display  = '';
        setStage('Building', 'done');
        log(`done — ${stats.total} unique paths`, 'success');
        setProgress(100);

    } catch (e) {
        if (e.name === 'AbortError') {
            log('stopped by user.', 'warn');
        } else {
            log(`fatal: ${e.message}`, 'error');
            showError(`Scan failed: ${e.message}`);
        }
    } finally {
        ui.scanBtn().disabled = false;
        ui.scanBtn().classList.remove('scanning');
        ui.stopBtn().style.display = 'none';
        abortController = null;
    }
}

// ── Boot ─────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    const input = ui.domainInput();

    input.addEventListener('input', () => {
        ui.clearBtn().style.display = input.value ? '' : 'none';
    });
    input.addEventListener('keydown', e => { if (e.key === 'Enter') startScan(); });
    ui.clearBtn().addEventListener('click', () => { input.value = ''; ui.clearBtn().style.display = 'none'; input.focus(); });
    ui.scanBtn().addEventListener('click', startScan);
    ui.stopBtn().addEventListener('click', () => { if (abortController) abortController.abort(); });

    ui.expandAllBtn().addEventListener('click', () => fileTreeInst?.expandAll());
    ui.collapseAllBtn().addEventListener('click', () => fileTreeInst?.collapseAll());

    ui.filterBtn().addEventListener('click', () => {
        interestingOnly = !interestingOnly;
        ui.filterBtn().classList.toggle('active', interestingOnly);
        fileTreeInst?.filterInteresting(interestingOnly);
    });

    ui.treeSearch().addEventListener('input', e => fileTreeInst?.search(e.target.value.trim()));

    ui.exportJsonBtn().addEventListener('click', () => { if (scanData) exportJson(scanData); });
    ui.exportCsvBtn().addEventListener('click',  () => { if (scanData) exportCsv(scanData); });
    ui.exportUrlsBtn().addEventListener('click', () => { if (scanData) exportUrlList(scanData); });
    ui.exportWgetBtn().addEventListener('click', () => { if (scanData) exportWgetScript(scanData); });
    ui.exportTextBtn().addEventListener('click', () => {
        if (scanData && fileTreeInst) {
            triggerDownload(
                new Blob([fileTreeInst.toText()], { type: 'text/plain' }),
                `filex-tree-${scanData.domain}.txt`
            );
        }
    });

    input.focus();
});
