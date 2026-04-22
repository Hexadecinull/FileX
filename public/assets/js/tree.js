export class FileTree {
    constructor(entries) {
        this.entries   = entries;
        this.treeRoot  = this._buildTree(entries);
        this._nodeEls  = [];
    }

    _buildTree(entries) {
        const root = { name: '/', children: {}, files: [], meta: null };
        const seen = new Map();

        entries.forEach(entry => {
            const { path, source, probe, subdomain } = entry;

            if (subdomain) {
                return;
            }

            const normalized = ('/' + (path || '/').replace(/^\/+/, '')).replace(/\/+/g, '/');

            if (seen.has(normalized)) {
                const existing = seen.get(normalized);
                if (!existing.probe && probe) {
                    existing.probe = probe;
                    if (existing._dirNode) existing._dirNode.meta = existing;
                }
                if (!existing.sources.includes(source)) existing.sources.push(source);
                return;
            }

            const parts    = normalized.split('/').filter(Boolean);
            const isDir    = normalized.endsWith('/') || (parts.length > 0 && !parts[parts.length - 1].includes('.'));
            const fileName = parts.pop() || '';
            let   current  = root;

            parts.forEach(part => {
                if (!current.children[part]) {
                    current.children[part] = { name: part, children: {}, files: [], meta: null };
                }
                current = current.children[part];
            });

            const fileEntry = { name: fileName, source, sources: [source], probe, isDir, _dirNode: null };
            seen.set(normalized, fileEntry);

            if (fileName === '') {
                return;
            }

            if (isDir) {
                if (!current.children[fileName]) {
                    current.children[fileName] = { name: fileName, children: {}, files: [], meta: fileEntry };
                } else if (probe) {
                    current.children[fileName].meta = fileEntry;
                }
                fileEntry._dirNode = current.children[fileName];
            } else {
                current.files.push(fileEntry);
            }
        });

        return root;
    }

    render(container, domain) {
        container.innerHTML = '';
        this._nodeEls       = [];

        if (Object.keys(this.treeRoot.children).length === 0 && this.treeRoot.files.length === 0) {
            const empty = document.createElement('div');
            empty.className   = 'tree-empty';
            empty.textContent = 'No paths discovered yet.';
            container.appendChild(empty);
            return;
        }

        const rootEl = this._renderNode(this.treeRoot, domain || '/', 0, true);
        container.appendChild(rootEl);
    }

    _renderNode(node, displayName, depth, isRoot = false) {
        const wrapper = document.createElement('div');
        wrapper.className = 'tree-node';

        const hasChildren = Object.keys(node.children).length > 0 || node.files.length > 0;

        if (!isRoot) {
            const row = this._buildRow(node.meta || { name: displayName, isDir: true, sources: [] }, depth, hasChildren, displayName);
            wrapper.appendChild(row.el);
            wrapper.dataset.name = displayName;

            if (hasChildren) {
                const childWrap = document.createElement('div');
                childWrap.className = 'tree-children';
                row.toggle.addEventListener('click', e => {
                    e.stopPropagation();
                    const collapsed = childWrap.classList.toggle('tree-children--hidden');
                    row.toggle.textContent = collapsed ? '+' : '−';
                });

                this._populateChildren(childWrap, node, depth + 1);
                wrapper.appendChild(childWrap);
            }
        } else {
            const rootRow = document.createElement('div');
            rootRow.className = 'tree-node-row';
            rootRow.innerHTML = `<span class="tree-icon">🌐</span><span class="tree-name tree-name--dir">${this._esc(displayName)}/</span>`;
            wrapper.appendChild(rootRow);

            const childWrap = document.createElement('div');
            childWrap.className = 'tree-children';
            this._populateChildren(childWrap, node, depth + 1);
            wrapper.appendChild(childWrap);
        }

        this._nodeEls.push(wrapper);
        return wrapper;
    }

    _populateChildren(container, node, depth) {
        const sortedDirs = Object.keys(node.children).sort((a, b) => a.localeCompare(b));
        sortedDirs.forEach(dirName => {
            const child  = node.children[dirName];
            const el     = this._renderNode(child, dirName, depth);
            container.appendChild(el);
        });

        const sortedFiles = [...node.files].sort((a, b) => a.name.localeCompare(b.name));
        sortedFiles.forEach(file => {
            const row = this._buildRow(file, depth, false, file.name);
            container.appendChild(row.el);
            this._nodeEls.push(row.el);
        });
    }

    _buildRow(entry, depth, hasChildren, displayName) {
        const el = document.createElement('div');
        el.className   = 'tree-node-row';
        el.dataset.path = displayName || '';

        const indent = document.createElement('span');
        indent.className = 'tree-indent';
        for (let i = 0; i < depth; i++) {
            const c = document.createElement('span');
            c.className   = 'tree-connector';
            c.textContent = i === depth - 1 ? '├─' : '  ';
            indent.appendChild(c);
        }

        const toggle = document.createElement('span');
        toggle.className   = 'tree-toggle';
        toggle.textContent = hasChildren ? '−' : ' ';
        if (!hasChildren) toggle.style.visibility = 'hidden';

        const icon = document.createElement('span');
        icon.className   = 'tree-icon';
        icon.textContent = entry.isDir || entry.isSubdomain
            ? '📁'
            : this._fileIcon(displayName || entry.name || '');

        const name = document.createElement('span');
        name.className   = 'tree-name' + (entry.isDir ? ' tree-name--dir' : '') + (entry.isSubdomain ? ' tree-name--link' : '');
        name.textContent = displayName || entry.name || '/';

        el.appendChild(indent);
        el.appendChild(toggle);
        el.appendChild(icon);
        el.appendChild(name);

        const meta = document.createElement('span');
        meta.className = 'tree-meta';

        if (entry.probe) {
            const p = entry.probe;

            const status = document.createElement('span');
            const s      = p.status || 0;
            status.className   = 'tree-status status--' + (s || 'unknown');
            status.textContent = s || '?';
            meta.appendChild(status);

            if (p.contentType) {
                const ct = document.createElement('span');
                ct.className   = 'tree-ct';
                ct.textContent = p.contentType.split(';')[0];
                meta.appendChild(ct);
            }

            if (p.contentLength > 0) {
                const sz = document.createElement('span');
                sz.className   = 'tree-size';
                sz.textContent = this._formatBytes(p.contentLength);
                meta.appendChild(sz);
            }

            if (p.hasDirectoryList) {
                const b = document.createElement('span');
                b.className   = 'tree-badge badge--dir';
                b.textContent = 'DIR LIST';
                meta.appendChild(b);
            }

            if (p.interesting) {
                const b = document.createElement('span');
                b.className   = 'tree-badge badge--interesting';
                b.textContent = '⚡';
                meta.appendChild(b);
                el.dataset.interesting = '1';
            }

            if (p.redirect) {
                const b = document.createElement('span');
                b.className   = 'tree-badge badge--redirect';
                b.textContent = '→ ' + p.redirect.replace(/^https?:\/\/[^/]+/, '').slice(0, 30);
                meta.appendChild(b);
            }
        }

        if (entry.sources?.length > 0 || entry.source) {
            const src = (entry.sources || [entry.source]).filter(Boolean)[0];
            if (src) {
                const s = document.createElement('span');
                s.className   = 'tree-source source--' + src;
                s.textContent = src;
                meta.appendChild(s);
            }
        }

        el.appendChild(meta);
        return { el, toggle };
    }

    expandAll() {
        document.querySelectorAll('.tree-children--hidden').forEach(el => {
            el.classList.remove('tree-children--hidden');
            const toggle = el.previousElementSibling?.querySelector?.('.tree-toggle');
            if (toggle) toggle.textContent = '−';
        });
    }

    collapseAll() {
        document.querySelectorAll('.tree-children:not(.tree-children--hidden)').forEach(el => {
            if (el.closest('.tree-node')?.parentElement?.closest('.file-tree')) {
                el.classList.add('tree-children--hidden');
                const toggle = el.previousElementSibling?.querySelector?.('.tree-toggle');
                if (toggle) toggle.textContent = '+';
            }
        });
    }

    filterInteresting(onlyInteresting) {
        document.querySelectorAll('.tree-node-row').forEach(el => {
            if (onlyInteresting) {
                const isInteresting = el.dataset.interesting === '1';
                el.closest('.tree-node')?.classList.toggle('tree-node--hidden', !isInteresting);
            } else {
                el.closest('.tree-node')?.classList.remove('tree-node--hidden');
            }
        });
    }

    search(query) {
        const q = query.toLowerCase();
        document.querySelectorAll('.tree-node-row').forEach(el => {
            const path = (el.dataset.path || '').toLowerCase();
            const match = !q || path.includes(q);
            el.style.display = match ? '' : 'none';
        });
    }

    toText(node = this.treeRoot, prefix = '', isLast = true) {
        let out = '';
        const children  = Object.entries(node.children || {});
        const files     = node.files || [];

        const allItems  = [
            ...children.map(([n, c]) => ({ name: n, isDir: true, node: c })),
            ...files.map(f => ({ name: f.name, isDir: false, probe: f.probe, sources: f.sources })),
        ].sort((a, b) => {
            if (a.isDir !== b.isDir) return a.isDir ? -1 : 1;
            return a.name.localeCompare(b.name);
        });

        allItems.forEach((item, i) => {
            const last    = i === allItems.length - 1;
            const branch  = last ? '└── ' : '├── ';
            const extend  = last ? '    ' : '│   ';
            const status  = item.probe ? ` [${item.probe.status}]` : '';
            const src     = item.sources ? ` (${item.sources.join(',')})` : '';
            out += prefix + branch + item.name + (item.isDir ? '/' : '') + status + src + '\n';
            if (item.isDir) {
                out += this.toText(item.node, prefix + extend, last);
            }
        });

        return out;
    }

    _fileIcon(name) {
        const ext = (name.split('.').pop() || '').toLowerCase();
        const map = {
            php: '🐘', js: '📜', ts: '📜', jsx: '⚛️', tsx: '⚛️',
            html: '🌐', htm: '🌐', css: '🎨', scss: '🎨', sass: '🎨',
            json: '📋', xml: '📋', yaml: '📋', yml: '📋', toml: '📋',
            sql: '🗄️', db: '🗄️', sqlite: '🗄️',
            png: '🖼️', jpg: '🖼️', jpeg: '🖼️', gif: '🖼️', svg: '🖼️', webp: '🖼️', ico: '🖼️',
            pdf: '📄', doc: '📝', docx: '📝', xls: '📊', xlsx: '📊',
            zip: '📦', gz: '📦', tar: '📦', bak: '💾',
            env: '🔑', key: '🔑', pem: '🔑', crt: '🔑',
            log: '📃', txt: '📃', md: '📃',
            sh: '⚙️', bash: '⚙️', py: '🐍', rb: '💎', go: '🔵',
        };
        return map[ext] || '📄';
    }

    _esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    _formatBytes(n) {
        if (!n || n <= 0) return '';
        if (n < 1024) return n + 'B';
        if (n < 1048576) return (n / 1024).toFixed(1) + 'KB';
        return (n / 1048576).toFixed(1) + 'MB';
    }
}
