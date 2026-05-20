# Security Policy

## Supported Versions

| Version | Supported       |
|---------|-----------------|
| 1.0.x   | ✅ Active       |

## Reporting a Vulnerability

If you discover a security vulnerability in WP 7 Readiness Check, please report it privately so we can fix it before public disclosure.

**Email:** hello@kevinchamplin.com (please put `[wp-7-readiness-check security]` in the subject line)

We will acknowledge receipt within 2 business days and aim to release a patch within 14 days for confirmed issues. Critical vulnerabilities (data exposure, privilege escalation, RCE) will be prioritized for same-day patches.

Please **do not** open a public GitHub issue for security vulnerabilities until a fix has been published.

## What's in scope

- The plugin's PHP code in `wp-7-readiness-check.php`, `includes/`, and `assets/`
- The AJAX endpoints registered by the plugin
- The snapshot system at `wp-content/wp7rc-snapshots/`
- The mu-plugins the plugin creates (e.g. `champlin-disallow-file-edit.php`)

## What's out of scope

- Issues in WordPress core itself — report to [WordPress core security](https://wordpress.org/about/security/)
- Issues in third-party plugins the audit detects (Yoast, ACF, etc.) — report to those vendors
- Issues caused by modifying the plugin's own code in your installation
