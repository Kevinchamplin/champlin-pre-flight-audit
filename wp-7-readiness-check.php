<?php
/**
 * Plugin Name:       WP 7 Readiness Check
 * Plugin URI:        https://champlinenterprises.com/wordpress-7-0-readiness-checklist.html
 * Description:       Premium pre-flight (and post-flight) audit for the WordPress 7.0 upgrade. Server, plugins, custom code, headless surfaces, multisite, security — 30+ automated checks with a visual readiness score. Free. Built by Champlin Enterprises.
 * Version:           1.0.6
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Champlin Enterprises
 * Author URI:        https://champlinenterprises.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-7-readiness-check
 *
 * @package WP7ReadinessCheck
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('WP7RC_VERSION', '1.0.6');
define('WP7RC_FILE', __FILE__);
define('WP7RC_DIR', plugin_dir_path(__FILE__));
define('WP7RC_URL', plugin_dir_url(__FILE__));
define('WP7RC_PRINTABLE_URL', 'https://champlinenterprises.com/wordpress-7-0-readiness-checklist.html?utm_source=plugin&utm_medium=admin&utm_campaign=wp7rc');
define('WP7RC_ENGAGEMENT_URL', 'https://champlinenterprises.com/contact?utm_source=plugin&utm_medium=admin&utm_campaign=wp7rc');
define('WP7RC_LANDING_URL',    'https://champlinenterprises.com/wp-7-readiness-plugin.html?utm_source=plugin&utm_medium=admin&utm_campaign=wp7rc');
define('WP7RC_GITHUB_URL',     'https://github.com/Kevinchamplin/wp-7-readiness-check');

require_once WP7RC_DIR . 'includes/helpers.php';
require_once WP7RC_DIR . 'includes/runner.php';
require_once WP7RC_DIR . 'includes/fixes.php';

/**
 * Auto-update from GitHub releases.
 *
 * Uses YahnisElsts/plugin-update-checker (PUC) v5 to poll GitHub releases hourly
 * and surface new versions in wp-admin → Updates with the standard one-click
 * update flow. No telemetry — PUC only fetches the public GitHub release
 * manifest; nothing leaves your server.
 *
 * Each `gh release create vX.Y.Z` with a zip asset propagates to every installed
 * copy within an hour.
 */
if (is_admin() || (defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI)) {
    require_once WP7RC_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
    if (class_exists('\YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
        $wp7rc_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/Kevinchamplin/wp-7-readiness-check/',
            __FILE__,
            'wp-7-readiness-check'
        );
        $vcs_api = $wp7rc_update_checker->getVcsApi();
        if (method_exists($vcs_api, 'enableReleaseAssets')) {
            // Use the release-attached zip asset rather than building from source archive,
            // so the user gets exactly the file shipped with `gh release create`.
            $vcs_api->enableReleaseAssets();
        }
    }
}

if (defined('DOING_AJAX') && DOING_AJAX) {
    require_once WP7RC_DIR . 'includes/ajax.php';
} else {
    add_action('admin_init', static function (): void {
        if (wp_doing_ajax()) {
            require_once WP7RC_DIR . 'includes/ajax.php';
        }
    });
    // Also load ajax.php at admin_init so wp_ajax actions are registered
    add_action('init', static function (): void {
        require_once WP7RC_DIR . 'includes/ajax.php';
    });
}

// Admin menu under Tools
add_action('admin_menu', function (): void {
    add_management_page(
        __('WordPress 7 Readiness', 'wp-7-readiness-check'),
        __('WP 7 Readiness', 'wp-7-readiness-check'),
        'manage_options',
        'wp-7-readiness-check',
        'wp7rc_render_dashboard'
    );
});

// Enqueue assets only on our admin page
add_action('admin_enqueue_scripts', function (string $hook): void {
    if ($hook !== 'tools_page_wp-7-readiness-check') {
        return;
    }
    wp_enqueue_style(
        'wp7rc-admin',
        WP7RC_URL . 'assets/admin.css',
        [],
        WP7RC_VERSION
    );
    wp_enqueue_script(
        'wp7rc-admin',
        WP7RC_URL . 'assets/admin.js',
        [],
        WP7RC_VERSION,
        true
    );
    wp_localize_script('wp7rc-admin', 'wp7rcAjax', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wp7rc_fix'),
    ]);
});

// Add a Settings link on the plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function (array $links): array {
    $audit_link = sprintf(
        '<a href="%s">%s</a>',
        esc_url(admin_url('tools.php?page=wp-7-readiness-check')),
        esc_html__('Run audit', 'wp-7-readiness-check')
    );
    array_unshift($links, $audit_link);
    return $links;
});

// Add a row meta link to the printable checklist
add_filter('plugin_row_meta', function (array $links, string $file): array {
    if ($file !== plugin_basename(__FILE__)) {
        return $links;
    }
    $links[] = sprintf(
        '<a href="%s" target="_blank" rel="noopener">%s</a>',
        esc_url(WP7RC_PRINTABLE_URL),
        esc_html__('Printable 80-point checklist', 'wp-7-readiness-check')
    );
    return $links;
}, 10, 2);

function wp7rc_render_dashboard(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to view this page.', 'wp-7-readiness-check'));
    }
    $results = wp7rc_run_audit();
    require WP7RC_DIR . 'includes/view-dashboard.php';
}
