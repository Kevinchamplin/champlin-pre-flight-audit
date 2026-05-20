# Contributing to WP 7 Readiness Check

Pull requests welcome. This file describes what we look for in a good PR.

## Quick start

```bash
git clone https://github.com/Kevinchamplin/wp-7-readiness-check.git
cd wp-7-readiness-check
# Symlink into a local WordPress install for testing
ln -s "$(pwd)" /path/to/wp-content/plugins/wp-7-readiness-check
```

Activate the plugin in your local WordPress, then navigate to **Tools → WP 7 Readiness**.

## What good PRs look like

### New checks

Add a new check function in the appropriate file under `includes/checks/`. Each check module exposes a function named `wp7rc_check_{category}()` that returns an array of result arrays.

Use the `wp7rc_result()` helper for normalized result structure:

```php
$out[] = wp7rc_result(
    'my_check_id',          // unique slug
    'category',             // server | database | wordpress | plugins | themes | custom-code | headless | multisite | security
    'Human-readable label',
    'pass',                 // pass | warn | fail | info | skip
    '8.3.31',               // current value observed
    '7.4 minimum',          // expected value
    'Verbose explanation of the finding.',
    'Concrete remediation hint.',
    '',                     // optional reference URL
    'optional_fix_id'       // optional — only if a corresponding fix module exists
);
```

### New autofixes

Add to the registry in `includes/fixes.php`. Every fix MUST:

1. Be **idempotent** — running it twice does nothing the second time.
2. Be **reversible** — the user has a clear path back.
3. Be **capability-gated** — verify the user has the right capability before acting.
4. Refuse to mutate user code or `wp-config.php` directly. Config-style changes go via mu-plugin files in `wp-content/mu-plugins/`.
5. Return a normalized `['status' => 'success'|'error', 'message' => string]` response.

### Bug fixes

Bug fixes are always welcome. Include:
- A short description of what was broken
- Steps to reproduce
- The fix

## Code style

- Follow WordPress PHP coding standards
- `declare(strict_types=1)` at the top of every PHP file
- Capability + nonce checks at every AJAX endpoint
- No `wp-config.php` edits, ever
- No telemetry, no external data calls except to wordpress.org for plugin install via core API
- All user-facing strings use the `wp-7-readiness-check` text domain (we'll add i18n in a future release)

## Testing

Manual testing is sufficient for now. We'll add PHPUnit + WordPress test infrastructure in a future release. When you submit a PR, describe what you tested:

- WordPress version
- PHP version
- Multisite / single-site
- Active plugin stack (for custom-code scan changes)

## Security issues

Don't open a public PR or issue for security vulnerabilities. See [SECURITY.md](SECURITY.md) for the responsible-disclosure process.
