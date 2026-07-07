<?php
/**
 * Legacy-slug migration shim.
 *
 * The plugin was renamed from `wp-7-readiness-check` to `champlin-pre-flight-audit`
 * on 2026-05-20. Any user who installed v1.0.6 or earlier before that date has
 * the plugin in `wp-content/plugins/wp-7-readiness-check/` with an active_plugins
 * entry pointing to `wp-7-readiness-check/wp-7-readiness-check.php`. Under
 * WordPress 7.0 that entry orphans and WP deactivates the plugin with
 * "Plugin file does not exist."
 *
 * This shim runs once per site, the first time the renamed plugin loads alongside
 * a detected legacy install. It surfaces an admin notice with a one-click migrate
 * action that:
 *   1. Strips the orphaned basename from active_plugins (and active_sitewide_plugins on multisite)
 *   2. Deletes the legacy plugin folder via WP_Filesystem
 *   3. Sets a flag in options so the notice never re-appears
 *
 * Settings, audit history, and snapshots stored under wp7rc_* options are preserved
 * because the internal WP7RC_* naming was intentionally kept across the rename.
 *
 * @package WP7ReadinessCheck
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const WP7RC_MIGRATION_OPTION = 'wp7rc_legacy_slug_migrated';
const WP7RC_LEGACY_SLUG      = 'wp-7-readiness-check';
const WP7RC_LEGACY_BASENAME  = 'wp-7-readiness-check/wp-7-readiness-check.php';

/**
 * Does the legacy plugin folder still exist on disk?
 */
function wp7rc_legacy_folder_present(): bool
{
    return is_dir(WP_PLUGIN_DIR . '/' . WP7RC_LEGACY_SLUG);
}

/**
 * Is the legacy basename still referenced in any active-plugins option?
 */
function wp7rc_legacy_in_active(): bool
{
    $active = (array) get_option('active_plugins', []);
    if (in_array(WP7RC_LEGACY_BASENAME, $active, true)) {
        return true;
    }
    if (is_multisite()) {
        $network = (array) get_site_option('active_sitewide_plugins', []);
        if (isset($network[WP7RC_LEGACY_BASENAME])) {
            return true;
        }
    }
    return false;
}

add_action('admin_notices', function (): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    // One-time success notice after a legacy migration. The signal is passed via
    // a transient set by the (nonce-verified) migrate action below — not a request
    // parameter — so there is no unauthenticated form data to nonce-check here.
    if (get_transient('wp7rc_legacy_migrated')) {
        delete_transient('wp7rc_legacy_migrated');
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <strong><?php esc_html_e('Champlin Pre-Flight Audit:', 'champlin-pre-flight-audit'); ?></strong>
                <?php esc_html_e('legacy install cleaned up. Your audit history and settings are preserved.', 'champlin-pre-flight-audit'); ?>
            </p>
        </div>
        <?php
        return;
    }

    if (get_option(WP7RC_MIGRATION_OPTION) === '1') {
        return;
    }
    if (!wp7rc_legacy_folder_present() && !wp7rc_legacy_in_active()) {
        return;
    }

    $migrate_url = wp_nonce_url(
        admin_url('tools.php?page=champlin-pre-flight-audit&cpfa_action=migrate_legacy'),
        'wp7rc_migrate_legacy'
    );
    $dismiss_url = wp_nonce_url(
        admin_url('tools.php?page=champlin-pre-flight-audit&cpfa_action=dismiss_legacy_migration'),
        'wp7rc_dismiss_legacy'
    );
    ?>
    <div class="notice notice-warning">
        <p>
            <strong><?php esc_html_e('Champlin Pre-Flight Audit: legacy install detected', 'champlin-pre-flight-audit'); ?></strong>
        </p>
        <p>
            <?php esc_html_e('A pre-rename copy of this plugin was found in wp-content/plugins/wp-7-readiness-check/. Under WordPress 7.0 it will be deactivated with "Plugin file does not exist." Migrate now to clean it up. Settings and audit history will be preserved.', 'champlin-pre-flight-audit'); ?>
        </p>
        <p>
            <a href="<?php echo esc_url($migrate_url); ?>" class="button button-primary">
                <?php esc_html_e('Migrate now', 'champlin-pre-flight-audit'); ?>
            </a>
            <a href="<?php echo esc_url($dismiss_url); ?>" class="button button-link">
                <?php esc_html_e('Dismiss (I will handle it manually)', 'champlin-pre-flight-audit'); ?>
            </a>
        </p>
    </div>
    <?php
});

add_action('admin_init', function (): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    $action = isset($_GET['cpfa_action'])
        ? sanitize_key((string) wp_unslash($_GET['cpfa_action']))
        : '';

    if ($action === 'migrate_legacy') {
        check_admin_referer('wp7rc_migrate_legacy');
        wp7rc_perform_legacy_migration();
        set_transient('wp7rc_legacy_migrated', '1', MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('tools.php?page=champlin-pre-flight-audit'));
        exit;
    }

    if ($action === 'dismiss_legacy_migration') {
        check_admin_referer('wp7rc_dismiss_legacy');
        update_option(WP7RC_MIGRATION_OPTION, '1');
        wp_safe_redirect(admin_url('tools.php?page=champlin-pre-flight-audit'));
        exit;
    }
});

function wp7rc_perform_legacy_migration(): void
{
    $active = (array) get_option('active_plugins', []);
    $cleaned = array_values(array_diff($active, [WP7RC_LEGACY_BASENAME]));
    if ($cleaned !== $active) {
        update_option('active_plugins', $cleaned);
    }

    if (is_multisite()) {
        $network = (array) get_site_option('active_sitewide_plugins', []);
        if (isset($network[WP7RC_LEGACY_BASENAME])) {
            unset($network[WP7RC_LEGACY_BASENAME]);
            update_site_option('active_sitewide_plugins', $network);
        }
    }

    if (wp7rc_legacy_folder_present()) {
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (WP_Filesystem()) {
            global $wp_filesystem;
            if ($wp_filesystem && $wp_filesystem->is_writable(WP_PLUGIN_DIR)) {
                $wp_filesystem->delete(WP_PLUGIN_DIR . '/' . WP7RC_LEGACY_SLUG, true, 'd');
            }
        }
    }

    update_option(WP7RC_MIGRATION_OPTION, '1');
}
