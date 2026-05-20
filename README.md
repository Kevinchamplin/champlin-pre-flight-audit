# WP 7 Readiness Check

> **A free WordPress plugin that audits your site for the WordPress 7.0 upgrade in 5 seconds.** 30+ automated checks. Visual readiness score. Four one-click autofixes. Plugin-directory snapshots before any update.

[![License: GPL v2](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://github.com/Kevinchamplin/wp-7-readiness-check/releases/latest)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://www.php.net)
[![Download](https://img.shields.io/badge/download-47%20KB-22c55e.svg)](https://champlinenterprises.com/wp-7-readiness-check.zip)

**[→ Download the plugin (champlinenterprises.com)](https://champlinenterprises.com/wp-7-readiness-check.zip)** · **[Landing page](https://champlinenterprises.com/wp-7-readiness-plugin.html)** · **[Printable 80-point checklist](https://champlinenterprises.com/wordpress-7-0-readiness-checklist.html)**

---

![WP 7 Readiness Check dashboard — visual readiness score, summary pills, action buttons](https://champlinenterprises.com/wp-7-readiness-plugin-dashboard.png)

---

## Why this exists

WordPress 7.0 ships **May 20, 2026** with DataViews replacing the classic `WP_List_Table` on Posts / Pages / Media, a new AI Client + Connectors UI in core, Block API v3 enforcement of an iframed editor, and a higher PHP floor. For most teams, the upgrade is a click. For enterprise teams running revenue-bearing WordPress behind WAFs, SSO, headless front ends, or compliance review, it is a project — and one that quietly punishes teams that treat it as a click.

This plugin runs the technical pre-flight audit automatically so your engineering team can focus on the human-judgment items.

## What it checks (30+ checks across 9 categories)

| Category | What we verify |
|---|---|
| **Server & runtime** | PHP version (7.4 min, 8.3+ recommended), OPcache enabled, memory_limit, max_execution_time, required PHP extensions, WP-Cron status |
| **Database** | MySQL 8.0+ or MariaDB 10.6+, InnoDB engine availability |
| **WordPress core** | Version, WP_DEBUG state, major auto-update setting, HTTPS site URL |
| **Plugins** | Inventory, pending updates, per-plugin "Tested up to" headers, known-risky page-builder slugs (Elementor, Divi, Beaver Builder, Bricks, Breakdance, Oxygen, Admin Columns Pro) |
| **Themes** | Active theme version, compatibility header, block vs classic theme, deprecated HTML5 script theme support |
| **Custom code** | Static scan of theme + custom plugins + mu-plugins for: `WP_List_Table`, `manage_posts_columns`, classic `add_meta_box`, Block API v2, Interactivity API `effect()`, deprecated HTML5 script support, DataViews `groupByField` |
| **Headless & API** | WPGraphQL detection, REST API base, custom REST route inventory |
| **Multisite** | Network mode, site count, super admin count, WordPress 7.0 spam-flag behavior change |
| **Security** | `DISALLOW_FILE_EDIT` state, administrator count, two-factor auth plugin detection, backup plugin detection, AI Connectors policy reminder |

## Four one-click autofixes

Each autofix is **idempotent, reversible, nonce-protected, and capability-gated**. We refuse to mutate your custom code or edit `wp-config.php` directly — config changes are made via mu-plugin files so they can be undone by deleting one file.

1. **Disable major auto-updates during the WordPress 7.0 launch window.** Single option flip. Reversible from Dashboard → Updates.
2. **Lock down in-admin file editing.** Drops a 5-line mu-plugin defining `DISALLOW_FILE_EDIT`. Reversible by deleting the mu-plugin file.
3. **Update all plugins to current stable.** Uses WordPress core's own `Plugin_Upgrader::bulk_upgrade()`. Each plugin directory is automatically snapshotted before the update.
4. **Install + activate the official Two-Factor plugin** from WordPress.org.

## Plugin-directory snapshot safety net

The feature most "autofix" tools skip. Before any plugin update runs, the existing plugin directory is copied to `wp-content/wp7rc-snapshots/` with deny-all `.htaccess` / `web.config` / `index.php` guards (one for each major web-server flavor — Apache, IIS, nginx fallback). If a new plugin version breaks your site, restore the previous version with one click from the dashboard. Auto-prunes to the last 10 snapshots. **Refuses to apply the plugin-update fix if the snapshot fails** — fail-loud over fail-silent.

## Installation

1. Download [`wp-7-readiness-check.zip`](https://champlinenterprises.com/wp-7-readiness-check.zip) (47 KB) or [the latest GitHub release](https://github.com/Kevinchamplin/wp-7-readiness-check/releases/latest).
2. In WordPress admin, **Plugins → Add New → Upload Plugin** → choose the zip → **Install Now** → **Activate**.
3. Navigate to **Tools → WP 7 Readiness**. Audit runs automatically.

Works on WordPress 6.0+ (pre-flight audit mode) and WordPress 7.0+ (post-flight verification mode). Requires PHP 7.4+.

## Screenshots

| Dashboard hero | Findings detail |
|---|---|
| ![Hero](https://champlinenterprises.com/wp-7-readiness-plugin-dashboard.png) | ![Findings](https://champlinenterprises.com/wp-7-readiness-plugin-findings.png) |

## Frequently asked questions

**Does this work before AND after WordPress 7.0 is installed?**
Yes. On pre-7.0 sites the plugin operates as a pre-flight audit. On post-7.0 sites it operates as a post-flight verification, checking that the upgrade landed cleanly.

**Will the plugin modify my site without my consent?**
No. The audit itself is strictly read-only. The four autofixes only run on explicit click and are each idempotent and reversible.

**How long does the audit take?**
Typically under 5 seconds on most sites. The custom-code static scan is capped at 1,500 files to keep large sites responsive.

**Does it cover everything in the 80-point manual checklist?**
No — it covers the 30+ items that can be automated. The remaining 50 are human-judgment items (has the rollback been tested? has the AI Connectors policy been signed off? has the on-call rota been confirmed?) that no plugin can verify. The dashboard links to the [printable 80-point checklist](https://champlinenterprises.com/wordpress-7-0-readiness-checklist.html) for the full audit.

**Does it touch `wp-config.php`?**
Never. All `wp-config`-style settings are applied via mu-plugin files dropped into `wp-content/mu-plugins/`. Safer than editing `wp-config.php` (no risk of breaking the bootstrap), fully reversible (delete the mu-plugin to undo).

**Is the plugin really free?**
Yes. GPL-2.0 licensed, no license keys, no account signup, no telemetry. Source code is here on GitHub.

## Related resources

- **[Printable 80-point readiness checklist](https://champlinenterprises.com/wordpress-7-0-readiness-checklist.html)** — the manual companion to this plugin. Pre-flight audit + rollout sequence + rollback runbook + 4-signature sign-off page.
- **[WordPress 7.0 migration services](https://champlinenterprises.com/blog/wordpress-7-0-migration-services)** — Champlin Enterprises engagements for teams that would rather have an engineer run the full audit + remediation + rollout as a fixed-scope project.
- **[WordPress 7.0 readiness checklist (engineer's POV)](https://kevinchamplin.com/blog/wordpress-7-0-readiness-checklist)** — the technical deep-dive blog post on what changes in 7.0 and how to prep.

## Contributing

Pull requests are welcome. For larger changes, please open an issue first so we can discuss what you'd like to change. Sensible additions:

- New checks for additional plugin compatibility surfaces
- Additional autofixes (with the same idempotent + reversible safety guarantees)
- WP.org Plugin Directory translations
- Bug fixes (always welcome)

## Security

Found a security issue? See [SECURITY.md](SECURITY.md) for the responsible-disclosure process.

## License

GPL v2 or later. See [LICENSE](LICENSE).

## Author

Built by **[Champlin Enterprises](https://champlinenterprises.com)** — premium WordPress + applied-AI engineering. Want this audit + remediation + rollout run for your team as a fixed-scope engagement? [Apply here](https://champlinenterprises.com/contact).
