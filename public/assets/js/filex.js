import { FileTree } from './tree.js';

const API_BASE = './api';

const ui = {
    domainInput:        () => document.getElementById('domainInput'),
    clearBtn:           () => document.getElementById('clearBtn'),
    scanBtn:            () => document.getElementById('scanBtn'),
    stopBtn:            () => document.getElementById('stopBtn'),
    progressSection:    () => document.getElementById('progressSection'),
    progressBar:        () => document.getElementById('progressBar'),
    progressLog:        () => document.getElementById('progressLog'),
    resultsSection:     () => document.getElementById('resultsSection'),
    errorSection:       () => document.getElementById('errorSection'),
    errorMessage:       () => document.getElementById('errorMessage'),
    targetInfo:         () => document.getElementById('targetInfo'),
    techList:           () => document.getElementById('techList'),
    dnsInfo:            () => document.getElementById('dnsInfo'),
    certList:           () => document.getElementById('certList'),
    statsRow:           () => document.getElementById('statsRow'),
    resultsTitle:       () => document.getElementById('resultsTitle'),
    fileTree:           () => document.getElementById('fileTree'),
    sourcesList:        () => document.getElementById('sourcesList'),
    treeSearch:         () => document.getElementById('treeSearch'),
    filterInterestBtn:  () => document.getElementById('filterInterestingBtn'),
    expandAllBtn:       () => document.getElementById('expandAllBtn'),
    collapseAllBtn:     () => document.getElementById('collapseAllBtn'),
    exportJsonBtn:      () => document.getElementById('exportJsonBtn'),
    exportTextBtn:      () => document.getElementById('exportTextBtn'),
    exportCsvBtn:       () => document.getElementById('exportCsvBtn'),
    stageEl:            (id) => document.getElementById('stage' + id),
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
    return raw
        .trim()
        .toLowerCase()
        .replace(/^https?:\/\//i, '')
        .replace(/^www\./i, '')
        .split('/')[0]
        .trim();
}

function isValidDomain(domain) {
    return /^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)+$/.test(domain);
}

async function apiFetch(endpoint, params, signal) {
    const qs  = new URLSearchParams(params).toString();
    const url = `${API_BASE}/${endpoint}?${qs}`;
    const res = await fetch(url, { signal });
    if (!res.ok) throw new Error(`HTTP ${res.status} from ${endpoint}`);
    return res.json();
}

function setStage(id, state) {
    const el = ui.stageEl(id);
    if (!el) return;
    el.className = 'stage stage--' + state;
}

function log(msg, type = '') {
    const el    = ui.progressLog();
    const ts    = new Date().toLocaleTimeString('en', { hour12: false });
    const line  = document.createElement('div');
    line.className = 'log-line';
    line.innerHTML = `<span class="log-ts">${ts}</span><span class="log-msg log-msg--${type}">${escapeHtml(msg)}</span>`;
    el.appendChild(line);
    el.scrollTop = el.scrollHeight;
}

function setProgress(pct) {
    ui.progressBar().style.width = Math.min(100, pct) + '%';
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function formatBytes(n) {
    if (!n || n < 0) return '';
    if (n < 1024) return n + 'B';
    if (n < 1048576) return (n / 1024).toFixed(1) + 'KB';
    return (n / 1048576).toFixed(1) + 'MB';
}

async function runScan(domain) {
    abortController = new AbortController();
    const signal    = abortController.signal;

    const data = {
        domain,
        root:       null,
        robots:     null,
        sitemap:    null,
        wayback:    null,
        dns:        null,
        certs:      null,
        crawl:      [],
        probed:     [],
        allEntries: [],
        sources:    { robots: 0, sitemap: 0, wayback: 0, crawl: 0, probe: 0, dns: 0 },
    };

    const scheme = options.scheme();

    setProgress(2);
    log(`Starting scan: ${domain}`);

    setStage('Robots', 'active');
    if (options.robots()) {
        try {
            const r = await apiFetch('robots.php', { domain }, signal);
            data.robots = r;
            const paths = r.parsed?.allPaths ?? [];
            paths.forEach(p => addEntry(data, p, 'robots', null));
            data.sources.robots = paths.length;
            log(`robots.txt → ${paths.length} paths, ${r.parsed?.sitemaps?.length ?? 0} sitemaps`, 'success');
            setStage('Robots', 'done');
        } catch (e) {
            if (e.name === 'AbortError') throw e;
            log(`robots.txt failed: ${e.message}`, 'warn');
            setStage('Robots', 'error');
        }
    } else {
        setStage('Robots', 'skipped');
    }

    setProgress(15);

    setStage('Sitemap', 'active');
    if (options.sitemap()) {
        try {
            const r = await apiFetch('sitemap.php', { domain }, signal);
            data.sitemap = r;
            (r.urls ?? []).forEach(u => {
                try {
                    const path = new URL(u).pathname;
                    addEntry(data, path, 'sitemap', null);
                } catch {}
            });
            data.sources.sitemap = r.total ?? 0;
            log(`Sitemap → ${r.total ?? 0} URLs from ${r.sources?.length ?? 0} sources`, 'success');
            setStage('Sitemap', 'done');
        } catch (e) {
            if (e.name === 'AbortError') throw e;
            log(`Sitemap failed: ${e.message}`, 'warn');
            setStage('Sitemap', 'error');
        }
    } else {
        setStage('Sitemap', 'skipped');
    }

    setProgress(28);

    setStage('Wayback', 'active');
    if (options.wayback()) {
        try {
            const r = await apiFetch('wayback.php', { domain, limit: 3000 }, signal);
            data.wayback = r;
            (r.paths ?? []).forEach(p => addEntry(data, p, 'wayback', null));
            data.sources.wayback = r.paths?.length ?? 0;
            log(`Wayback Machine → ${r.total ?? 0} captures, ${r.paths?.length ?? 0} unique paths`, 'success');
            setStage('Wayback', 'done');
        } catch (e) {
            if (e.name === 'AbortError') throw e;
            log(`Wayback failed: ${e.message}`, 'warn');
            setStage('Wayback', 'error');
        }
    } else {
        setStage('Wayback', 'skipped');
    }

    setProgress(42);

    setStage('Dns', 'active');
    if (options.dns()) {
        try {
            const r = await apiFetch('dns.php', { domain }, signal);
            data.dns = r;
            (r.subdomains ?? []).forEach(sub => {
                data.sources.dns++;
                addEntry(data, '/', 'dns', null, sub);
            });
            log(`DNS → ${r.ipv4?.length ?? 0} IPs, ${r.subdomains?.length ?? 0} subdomains found`, 'success');
            setStage('Dns', 'done');
        } catch (e) {
            if (e.name === 'AbortError') throw e;
            log(`DNS failed: ${e.message}`, 'warn');
            setStage('Dns', 'error');
        }
    } else {
        setStage('Dns', 'skipped');
    }

    setProgress(54);

    setStage('Certs', 'active');
    if (options.certs()) {
        try {
            const r = await apiFetch('certs.php', { domain }, signal);
            data.certs = r;
            log(`Cert Transparency → ${r.total ?? 0} subdomains from ${r.certCount ?? 0} certs`, 'success');
            setStage('Certs', 'done');
        } catch (e) {
            if (e.name === 'AbortError') throw e;
            log(`Certs failed: ${e.message}`, 'warn');
            setStage('Certs', 'error');
        }
    } else {
        setStage('Certs', 'skipped');
    }

    setProgress(60);

    setStage('Crawl', 'active');
    if (options.crawl()) {
        const batch = options.batch();
        let   offset = 0;
        let   total  = null;

        try {
            do {
                const r = await apiFetch('crawl.php', { domain, scheme, batch, offset }, signal);
                if (total === null) total = r.total;

                (r.results ?? []).forEach(item => {
                    data.crawl.push(item);
                    addEntry(data, item.path, 'crawl', item);
                });

                const done  = offset + batch;
                const pct   = 60 + Math.min(25, Math.round((done / (total || 1)) * 25));
                setProgress(pct);
                log(`Crawl ${Math.min(done, total)}/${total} — found ${data.crawl.filter(x => x.status !== 404 && x.status !== 0).length} hits`);

                offset += batch;
            } while (offset < (total ?? 0));

            const hits = data.crawl.filter(x => x.status > 0 && x.status !== 404);
            log(`Crawl complete — ${hits.length} live paths from ${total} probed`, 'success');
            data.sources.crawl = total;
            setStage('Crawl', 'done');
        } catch (e) {
            if (e.name === 'AbortError') throw e;
            log(`Crawl error: ${e.message}`, 'warn');
            setStage('Crawl', 'error');
        }
    } else {
        setStage('Crawl', 'skipped');
    }

    setProgress(85);

    setStage('Probe', 'active');
    if (options.probeLinks()) {
        try {
            const probeRoot = await apiFetch('probe.php', { url: `${scheme}://${domain}/` }, signal);
            data.root = probeRoot;
            const links   = probeRoot.links ?? [];
            const scripts = probeRoot.scripts ?? [];
            const targets = [...new Set([...links, ...scripts])].slice(0, 30);
            let probeCount = 0;

            for (const url of targets) {
                try {
                    const r = await apiFetch('probe.php', { url }, signal);
                    data.probed.push(r);
                    try {
                        const path = new URL(url).pathname;
                        addEntry(data, path, 'probe', r);
                    } catch {}
                    probeCount++;
                } catch (e2) {
                    if (e2.name === 'AbortError') throw e2;
                }
            }

            data.sources.probe = probeCount;
            log(`Probed ${probeCount} discovered links`, 'success');
            setStage('Probe', 'done');
        } catch (e) {
            if (e.name === 'AbortError') throw e;
            log(`Probe error: ${e.message}`, 'warn');
            setStage('Probe', 'error');
        }
    } else {
        if (!data.root) {
            try {
                data.root = await apiFetch('probe.php', { url: `${scheme}://${domain}/` }, signal);
            } catch {}
        }
        setStage('Probe', 'skipped');
    }

    setProgress(95);
    setStage('Building', 'active');
    log('Building file tree…');

    return data;
}

function addEntry(data, path, source, probeResult, subdomain = null) {
    if (!path || path === '') return;
    data.allEntries.push({
        path,
        source,
        probe:     probeResult,
        subdomain,
    });
}

function buildStats(data) {
    const entries  = data.allEntries;
    const probed   = data.crawl;
    const live     = probed.filter(x => x.status > 0 && x.status !== 404);
    const redir    = probed.filter(x => x.status >= 301 && x.status <= 308);
    const auth     = probed.filter(x => x.status === 401 || x.status === 403);
    const dirs     = probed.filter(x => x.hasDirectoryList);
    const uniquePaths = new Set(entries.map(e => e.path));

    return { total: uniquePaths.size, live: live.length, redir: redir.length, auth: auth.length, dirs: dirs.length };
}

function renderStats(stats, data) {
    const row = ui.statsRow();
    row.innerHTML = '';

    const chips = [
        { label: 'unique paths', value: stats.total,               cls: 'blue' },
        { label: 'live (2xx)',   value: stats.live,                cls: 'green' },
        { label: 'redirects',   value: stats.redir,               cls: '' },
        { label: '401/403',     value: stats.auth,                cls: 'amber' },
        { label: 'open dirs',   value: stats.dirs,                cls: 'amber' },
        { label: 'wayback',     value: data.wayback?.total ?? 0,  cls: '' },
        { label: 'subdomains',  value: (data.certs?.total ?? 0) + (data.dns?.subdomains?.length ?? 0), cls: '' },
    ];

    chips.forEach(({ label, value, cls }) => {
        const chip = document.createElement('div');
        chip.className = 'stat-chip' + (cls ? ' stat-chip--' + cls : '');
        chip.innerHTML = `<span class="stat-chip__label">${escapeHtml(label)}</span><span class="stat-chip__value">${value}</span>`;
        row.appendChild(chip);
    });
}

function renderTargetInfo(data) {
    const root = data.root;
    const el   = ui.targetInfo();
    el.innerHTML = '';

    const rows = [
        ['Domain',      data.domain],
        ['Status',      root?.status ?? '—'],
        ['Server',      root?.server ?? '—'],
        ['Powered By',  root?.poweredBy ?? '—'],
        ['Response',    root?.elapsed ? root.elapsed + 'ms' : '—'],
        ['Final URL',   root?.finalUrl ?? '—'],
        ['Redirects',   root?.redirectCount ?? 0],
    ];

    rows.forEach(([key, val]) => {
        const k = document.createElement('span');
        k.className = 'info-key';
        k.textContent = key;

        const v = document.createElement('span');
        v.className = 'info-val';
        if (key === 'Status') {
            const s = parseInt(val, 10);
            v.className += s >= 200 && s < 300 ? ' info-val--green'
                         : s >= 400            ? ' info-val--red'
                         : ' info-val--amber';
        }
        v.textContent = String(val);

        el.appendChild(k);
        el.appendChild(v);
    });
}

function renderTechList(data) {
    const el  = ui.techList();
    el.innerHTML = '';

    const cms  = [...(data.root?.cms ?? [])];
    const tech = [...(data.root?.tech ?? [])];
    const dns  = [...(data.dns?.tech ?? [])];

    const cmsTags = cms.map(t => ({ text: t, cls: 'cms' }));
    const techTags = tech.map(t => {
        const cls = /nginx|apache|iis|caddy/i.test(t) ? 'server'
                  : /php|python|ruby|node|java|asp/i.test(t) ? 'lang'
                  : /cloudflare|fastly|akamai/i.test(t) ? 'cdn'
                  : 'fw';
        return { text: t, cls };
    });
    const dnsTags = dns.map(t => ({ text: t, cls: '' }));

    [...cmsTags, ...techTags, ...dnsTags].forEach(({ text, cls }) => {
        const tag = document.createElement('span');
        tag.className = 'tech-tag' + (cls ? ' tech-tag--' + cls : '');
        tag.textContent = text;
        el.appendChild(tag);
    });

    if (el.children.length === 0) {
        el.innerHTML = '<span style="color:var(--text-muted);font-size:.75rem">No technologies detected</span>';
    }
}

function renderDnsInfo(data) {
    const dns = data.dns;
    const el  = ui.dnsInfo();
    el.innerHTML = '';

    if (!dns) {
        el.innerHTML = '<span style="color:var(--text-muted);font-size:.75rem">DNS not scanned</span>';
        return;
    }

    const sections = [
        { title: 'IPv4',        items: dns.ipv4 ?? [] },
        { title: 'IPv6',        items: dns.ipv6 ?? [] },
        { title: 'Nameservers', items: dns.nameservers ?? [] },
        { title: 'Subdomains',  items: dns.subdomains ?? [], cls: 'dns-subdomain' },
    ];

    sections.forEach(({ title, items, cls }) => {
        if (items.length === 0) return;
        const sec = document.createElement('div');
        const t   = document.createElement('div');
        t.className = 'dns-section-title';
        t.textContent = title;
        sec.appendChild(t);
        items.slice(0, 20).forEach(item => {
            const d = document.createElement('div');
            d.className = cls || 'dns-record';
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

    if (subs.length === 0) {
        el.innerHTML = '<span style="color:var(--text-muted);font-size:.75rem">No certificate data</span>';
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

    const sources = [
        { name: 'robots.txt',       count: data.sources.robots,  icon: '🤖' },
        { name: 'Sitemap',          count: data.sources.sitemap, icon: '🗺️' },
        { name: 'Wayback Machine',  count: data.sources.wayback, icon: '⏮️' },
        { name: 'Wordlist Crawl',   count: data.sources.crawl,   icon: '🔍' },
        { name: 'Link Probing',     count: data.sources.probe,   icon: '🔗' },
        { name: 'DNS Subdomains',   count: data.sources.dns,     icon: '🌐' },
    ];

    sources.forEach(({ name, count, icon }) => {
        if (!count) return;
        const chip = document.createElement('div');
        chip.className = 'source-chip';
        chip.innerHTML = `<span>${icon}</span><span class="source-chip__name">${escapeHtml(name)}</span><span class="source-chip__count">${count}</span>`;
        el.appendChild(chip);
    });
}

function exportJson(data) {
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    triggerDownload(blob, `filex-${data.domain}-${Date.now()}.json`);
}

function exportCsv(data) {
    const rows = [['path', 'status', 'contentType', 'size', 'source', 'interesting', 'redirect']];
    const seen = new Set();

    data.allEntries.forEach(entry => {
        const p    = entry.path;
        if (seen.has(p)) return;
        seen.add(p);
        const r    = entry.probe;
        rows.push([
            p,
            r?.status ?? '',
            r?.contentType ?? '',
            r?.contentLength ?? '',
            entry.source,
            r?.interesting ? '1' : '',
            r?.redirect ?? '',
        ]);
    });

    const csv  = rows.map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    triggerDownload(blob, `filex-${data.domain}-${Date.now()}.csv`);
}

function triggerDownload(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a   = document.createElement('a');
    a.href    = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

function showError(msg) {
    ui.errorSection().style.display = '';
    ui.errorMessage().textContent   = msg;
}

function hideError() {
    ui.errorSection().style.display = 'none';
}

function resetScanUI() {
    ui.progressSection().style.display = '';
    ui.resultsSection().style.display  = 'none';
    ui.progressLog().innerHTML         = '';
    ui.progressBar().style.width       = '0%';
    hideError();

    ['Robots', 'Sitemap', 'Wayback', 'Dns', 'Certs', 'Crawl', 'Probe', 'Building'].forEach(id => {
        setStage(id, '');
    });
}

async function startScan() {
    const raw    = ui.domainInput().value;
    const domain = sanitizeDomain(raw);

    if (!isValidDomain(domain)) {
        showError(`"${domain}" does not look like a valid domain. Enter just the domain without protocol — e.g. example.com`);
        return;
    }

    hideError();
    resetScanUI();
    interestingOnly = false;
    ui.filterInterestBtn().classList.remove('action-btn--active');

    ui.scanBtn().disabled  = true;
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
        fileTreeInst.render(ui.fileTree(), scanData.domain);

        ui.exportTextBtn().onclick = () => {
            const text = fileTreeInst.toText();
            const blob = new Blob([text], { type: 'text/plain' });
            triggerDownload(blob, `filex-${scanData.domain}-${Date.now()}.txt`);
        };

        ui.resultsTitle().textContent = `Structure: ${domain}`;
        ui.progressSection().style.display = 'none';
        ui.resultsSection().style.display  = '';

        setStage('Building', 'done');
        log(`Done. ${stats.total} unique paths discovered.`, 'success');
        setProgress(100);

    } catch (e) {
        if (e.name === 'AbortError') {
            log('Scan stopped by user.', 'warn');
        } else {
            log(`Fatal error: ${e.message}`, 'error');
            showError(`Scan failed: ${e.message}`);
        }
    } finally {
        ui.scanBtn().disabled  = false;
        ui.scanBtn().classList.remove('scanning');
        ui.stopBtn().style.display = 'none';
        abortController = null;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const input = ui.domainInput();

    input.addEventListener('input', () => {
        ui.clearBtn().style.display = input.value ? '' : 'none';
    });

    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') startScan();
    });

    ui.clearBtn().addEventListener('click', () => {
        input.value = '';
        ui.clearBtn().style.display = 'none';
        input.focus();
    });

    ui.scanBtn().addEventListener('click', startScan);

    ui.stopBtn().addEventListener('click', () => {
        if (abortController) abortController.abort();
    });

    ui.expandAllBtn().addEventListener('click', () => {
        fileTreeInst?.expandAll();
    });

    ui.collapseAllBtn().addEventListener('click', () => {
        fileTreeInst?.collapseAll();
    });

    ui.filterInterestBtn().addEventListener('click', () => {
        interestingOnly = !interestingOnly;
        ui.filterInterestBtn().classList.toggle('action-btn--active', interestingOnly);
        fileTreeInst?.filterInteresting(interestingOnly);
    });

    ui.treeSearch().addEventListener('input', e => {
        fileTreeInst?.search(e.target.value.trim());
    });

    ui.exportJsonBtn().addEventListener('click', () => {
        if (scanData) exportJson(scanData);
    });

    ui.exportCsvBtn().addEventListener('click', () => {
        if (scanData) exportCsv(scanData);
    });

    input.focus();
});
