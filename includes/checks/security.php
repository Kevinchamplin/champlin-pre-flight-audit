<?php
/**
 * Security & compliance checks.
 *
 * @package WP7ReadinessCheck
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function wp7rc_check_security(): array
{
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $out      = [];
    $active   = (array) get_option('active_plugins', []);
    $all      = get_plugins();

    // File-edit lockdown
    if (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT === true) {
        $out[] = wp7rc_result('disallow_file_edit', 'security', 'In-admin file editing', 'pass', 'DISALLOW_FILE_EDIT = true', 'true', 'In-admin file editing is disabled. Best practice.');
    } else {
        $out[] = wp7rc_result(
            'disallow_file_edit',
            'security',
            'In-admin file editing',
            'warn',
            'DISALLOW_FILE_EDIT not set',
            'true',
            'In-admin file editing is enabled. Any Administrator who phishes a session can drop arbitrary PHP via the theme/plugin editors.',
            "Add to wp-config.php: define('DISALLOW_FILE_EDIT', true);",
            '',
            'disallow_file_edit'
        );
    }

    // Admin user count
    $admin_count = 0;
    foreach (get_users(['role' => 'administrator', 'fields' => 'ID']) as $u) {
        $admin_count++;
    }
    if ($admin_count <= 3) {
        $out[] = wp7rc_result('admin_count', 'security', 'Administrator accounts', 'pass', sprintf('%d', $admin_count), '≤ 3', sprintf('%d administrator account%s — within reasonable bounds.', $admin_count, $admin_count === 1 ? '' : 's'));
    } elseif ($admin_count <= 6) {
        $out[] = wp7rc_result('admin_count', 'security', 'Administrator accounts', 'warn', sprintf('%d', $admin_count), '≤ 3', sprintf('%d administrators is more than typical. Each is a potential entry point to the new AI Connectors UI in WP 7.0.', $admin_count), 'Demote any Administrator who does not actively need full admin capabilities to Editor or a custom role.');
    } else {
        $out[] = wp7rc_result('admin_count', 'security', 'Administrator accounts', 'fail', sprintf('%d', $admin_count), '≤ 3', sprintf('%d administrators is a significant access-surface risk.', $admin_count), 'Audit the administrator list. Demote everyone who does not need full admin to Editor or below.');
    }

    // 2FA plugin detection (any of several well-known slugs)
    $tfa_slugs = [
        'two-factor/two-factor.php'            => 'Two-Factor',
        'wp-2fa/wp-2fa.php'                    => 'WP 2FA',
        'duo-wordpress/duo_wordpress.php'      => 'Duo Universal',
        'miniorange-2-factor-authentication/miniorange_2_factor_settings.php' => 'miniOrange 2FA',
        'google-authenticator/google-authenticator.php' => 'Google Authenticator',
    ];
    $tfa_detected = [];
    foreach ($tfa_slugs as $slug => $label) {
        if (in_array($slug, $active, true)) {
            $tfa_detected[] = $label;
        }
    }
    if ($tfa_detected !== []) {
        $out[] = wp7rc_result('two_factor', 'security', 'Two-factor authentication plugin', 'pass', implode(', ', $tfa_detected), 'a 2FA plugin active', 'Two-factor authentication plugin detected.');
    } else {
        $out[] = wp7rc_result('two_factor', 'security', 'Two-factor authentication plugin', 'warn', 'none detected', 'a 2FA plugin active', 'No well-known 2FA plugin is active. Administrator and Super Admin accounts should require 2FA, especially with the new AI Connectors surface in WP 7.0.', 'Install and enforce 2FA for all Administrator accounts. Recommended: the official "Two-Factor" plugin from the WordPress Plugin Directory.', '', 'install_two_factor');
    }

    // Backup plugin detection (any of several well-known slugs)
    $backup_slugs = [
        'updraftplus/updraftplus.php'                                   => 'UpdraftPlus',
        'backupbuddy/backupbuddy.php'                                   => 'BackupBuddy',
        'backwpup/backwpup.php'                                         => 'BackWPup',
        'all-in-one-wp-migration/all-in-one-wp-migration.php'           => 'All-in-One WP Migration',
        'duplicator/duplicator.php'                                     => 'Duplicator',
        'wp-staging/wp-staging.php'                                     => 'WP Staging',
        'wp-database-backup/wp-database-backup.php'                     => 'WP Database Backup',
    ];
    $backup_detected = [];
    foreach ($backup_slugs as $slug => $label) {
        if (in_array($slug, $active, true)) {
            $backup_detected[] = $label;
        }
    }
    if ($backup_detected !== []) {
        $out[] = wp7rc_result('backup_plugin', 'security', 'Backup plugin', 'pass', implode(', ', $backup_detected), 'a backup plugin or host-level snapshots', 'Backup plugin detected — verify your latest backup is recent and a restore has been tested in the last 30 days.');
    } else {
        // No backup plugin — check for managed-host signals before warning.
        $managed_host = wp7rc_detect_managed_host();
        if ($managed_host !== null) {
            $out[] = wp7rc_result(
                'backup_plugin',
                'security',
                'Backup plugin or host-level snapshots',
                'pass',
                $managed_host . ' (host-level snapshots assumed)',
                'a backup plugin or host-level snapshots',
                sprintf('No WordPress backup plugin detected, but this site appears to be hosted on %s, which provides host-level snapshot backups. Verify your snapshot policy in the host control panel — confirm snapshots are running and restore has been tested in the last 30 days.', $managed_host)
            );
        } else {
            $out[] = wp7rc_result('backup_plugin', 'security', 'Backup plugin', 'warn', 'none detected', 'a backup plugin or host-level snapshot', 'No well-known backup plugin is active and no managed-host snapshot system was detected. You may still rely on a backup process the plugin cannot see — verify before the WP 7.0 upgrade.', 'Either confirm a host-level snapshot system is running and tested, or install a backup plugin (UpdraftPlus, BackWPup, BlogVault) and take a snapshot before the upgrade.');
        }
    }

    // AI Connectors policy — only relevant on post-7.0 sites; informational on pre-7.0
    if (wp7rc_is_post_seven()) {
        $out[] = wp7rc_result(
            'ai_connectors_policy',
            'security',
            'AI Connectors policy (WP 7.0+)',
            'info',
            'review required',
            'documented policy',
            'WordPress 7.0 ships the AI Connectors UI at Settings → Connectors. Any administrator can configure an AI provider (OpenAI, Anthropic, Google). For regulated stacks, a written policy must define which providers are permitted.',
            'Draft a policy. If no AI provider is yet approved, restrict the Connectors UI via a capability filter until a Data Processing Addendum is signed.'
        );
    } else {
        $out[] = wp7rc_result(
            'ai_connectors_policy',
            'security',
            'AI Connectors policy (prepare for WP 7.0)',
            'info',
            'pre-upgrade — draft policy now',
            'documented policy ready by upgrade day',
            'WordPress 7.0 introduces an AI Connectors UI at Settings → Connectors. Once you upgrade, any administrator can configure an AI provider. Regulated stacks (SOC 2, HIPAA, FedRAMP-adjacent) should have a written policy ready BEFORE update day.',
            'Draft the policy now. Default: no providers permitted until a Data Processing Addendum is signed for each approved provider.'
        );
    }

    return $out;
}

/**
 * Detect managed-host platforms that provide their own snapshot/backup systems.
 * Returns the platform name (e.g. "Plesk", "WP Engine", "Kinsta") or null.
 */
function wp7rc_detect_managed_host(): ?string
{
    // Read $_SERVER values once, properly unslashed + sanitized.
    $doc_root        = isset($_SERVER['DOCUMENT_ROOT'])    ? sanitize_text_field(wp_unslash((string) $_SERVER['DOCUMENT_ROOT'])) : '';
    $server_software = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash((string) $_SERVER['SERVER_SOFTWARE'])) : '';

    // Plesk — multiple signals, open_basedir-safe.
    // Order matters: cheap path checks before filesystem reads.
    if (strpos(ABSPATH, '/var/www/vhosts/') === 0) {
        // Plesk's standard vhost root. This works even when open_basedir
        // restricts the PHP process from reading /usr/local/psa/.
        return 'Plesk';
    }
    if ($doc_root !== '' && strpos($doc_root, '/vhosts/') !== false) {
        return 'Plesk';
    }
    if ($server_software !== '' && strpos($server_software, 'Plesk') !== false) {
        return 'Plesk';
    }
    if (@is_dir('/usr/local/psa')) {
        return 'Plesk';
    }
    // WP Engine
    if (defined('WPE_APIKEY') || defined('IS_WPE') || defined('WPE_GOVERNOR_LOADED') || is_dir('/nas/content')) {
        return 'WP Engine';
    }
    // Kinsta
    if (defined('KINSTAMU_VERSION') || defined('KINSTA_CACHE_ZONE')) {
        return 'Kinsta';
    }
    // Pantheon
    if (defined('PANTHEON_ENVIRONMENT') || !empty($_SERVER['PANTHEON_ENVIRONMENT'] ?? '')) {
        return 'Pantheon';
    }
    // Pressable
    if (!empty($_SERVER['PRESSABLE_SITE_NAME'] ?? '') || defined('PRESSABLE_SITE_NAME')) {
        return 'Pressable';
    }
    // SiteGround
    if (defined('SiteGround_Optimizer\VERSION') || defined('SITEGROUND_OPTIMIZER_VERSION')) {
        return 'SiteGround';
    }
    // Flywheel
    if (defined('FLYWHEEL_PLUGIN_DIR') || ($server_software !== '' && strpos($server_software, 'Flywheel') !== false)) {
        return 'Flywheel';
    }
    // GoDaddy Managed WordPress
    if (defined('GD_SYSTEM_PLUGIN_FILE') || class_exists('WPaaS\Plugin')) {
        return 'GoDaddy Managed WordPress';
    }
    // cPanel (has its own backup system)
    if (is_dir('/usr/local/cpanel')) {
        return 'cPanel';
    }
    // DreamHost (DreamPress)
    if (defined('DREAMHOST_PANEL_VERSION') || ($server_software !== '' && strpos($server_software, 'DreamHost') !== false)) {
        return 'DreamHost';
    }

    return null;
}
