# Contributing to FileX

Thank you for your interest in contributing to FileX. This document explains how to get involved, what the standards are, and what kinds of contributions are most useful.

---

## Getting Started

1. **Fork** the repository on GitHub
2. **Clone** your fork: `git clone https://github.com/YOUR_USERNAME/filex.git`
3. Create a **feature branch**: `git checkout -b feature/your-feature-name`
4. Make your changes (see guidelines below)
5. Ensure the **lint checks pass** locally before pushing
6. Open a **pull request** against the `main` branch

---

## Local Development

Since this project has no build step, local development is just a web server + PHP:

```bash
cd filex/public
php -S localhost:8080
```

Then open `http://localhost:8080` in your browser. All PHP API calls are same-origin and work immediately.

For PHP linting:

```bash
find public/api -name "*.php" | xargs -I{} php -l {}
```

For JS linting (requires Node.js):

```bash
npm install --no-save eslint@9
npx eslint public/assets/js/
```

---

## Code Standards

### PHP

- PHP 8.0+ syntax only
- `declare(strict_types=1)` at the top of every file
- PSR-12 formatting (enforced by PHP-CS-Fixer in CI)
- No inline comments — code must be self-documenting through naming
- All `curl_exec` calls must check for `false` return
- All external input must be validated before use

### JavaScript

- ES2022 module syntax (`import`/`export`)
- No build tools, no transpilation, no TypeScript
- No inline comments
- `const` by default, `let` only when reassignment is needed
- No `var`
- Strict equality (`===`) throughout

### HTML/CSS

- Single HTML file (`index.html`) — no partials or templates
- CSS custom properties (`var(--*)`) for all colors and spacing
- No inline styles in HTML
- Semantic HTML elements where applicable

---

## What to Contribute

### High-value contributions

- **Wordlist expansion**: Additional paths in `crawl.php::getBuiltinWordlist()`. Focus on: framework-specific paths, cloud provider metadata endpoints, CI/CD artifacts, modern API patterns
- **Technology fingerprints**: New CMS/framework/CDN detections in `probe.php::detectCMS()` and `probe.php::detectTech()`
- **DNS tech inference**: New signals in `dns.php::inferTechFromDNS()` — new email providers, DNS services, domain verification tokens
- **Tree rendering improvements**: Better path deduplication logic in `tree.js::_buildTree()`
- **Export formats**: Additional export types (XLSX, HTML report, Markdown)

### Out of scope

The following will not be merged:

- Exploit or vulnerability scanning capabilities
- Credential brute-forcing (even HTTP Basic)
- Any feature that makes requests not related to passive/low-impact reconnaissance
- Dependencies on npm packages or PHP Composer packages (keep it zero-dependency)
- Features that require a database or persistent server-side storage

---

## Pull Request Requirements

- The PR description must explain **what** the change does and **why**
- All changed PHP files must pass `php -l`
- All changed JS files must pass ESLint with no new errors
- No reduction in existing functionality without discussion
- No new external dependencies (CDN scripts are acceptable in the HTML if they are well-known and have a subresource integrity hash)

---

## Reporting Issues

Open a GitHub issue with:

1. The domain you were scanning (can be redacted if sensitive)
2. Which scan options were enabled
3. The exact error message or unexpected behavior
4. Browser console output if the issue is frontend-side
5. PHP error log output if the issue is API-side

---

## License

By contributing to FileX, you agree that your contributions will be licensed under the **GNU General Public License version 3**.
