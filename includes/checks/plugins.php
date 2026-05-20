<?php
/**
 * Plugin checks — inventory, compatibility headers, known-risky slugs, updates available.
 *
 * @package WP7ReadinessCheck
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function wp7rc_check_plugins(): array
{
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (!function_exists('get_plugin_updates')) {
        require_once ABSPATH . 'wp-admin/includes/update.php';
    }

    $all_plugins    = get_plugins();
    $active_plugins = (array) get_option('active_plugins', []);
    if (is_multisite()) {
        $network_active = array_keys((array) get_site_option('active_sitewide_plugins', []));
        $active_plugins = array_unique(array_merge($active_plugins, $network_active));
    }
    $plugin_updates = function_exists('get_plugin_updates') ? get_plugin_updates() : [];

    $out = [];

    // 1. Active plugin count + summary
    $active_count   = count($active_plugins);
    $inactive_count = count($all_plugins) - $active_count;
    $out[] = wp7rc_result(
        'plugin_inventory',
        'plugins',
        'Plugin inventory',
        'info',
        sprintf('%d active, %d inactive', $active_count, $inactive_count),
        '',
        sprintf('Detected %d active plugin%s and %d inactive. Each active plugin is a potential compatibility surface for the upgrade.', $active_count, $active_count === 1 ? '' : 's', $inactive_count),
        $inactive_count > 0 ? 'Inactive plugins are still attack surface. Delete any you do not use.' : ''
    );

    // 2. Pending plugin updates
    if (!empty($plugin_updates)) {
        $update_list = array_map(static fn($p) => $p->Name ?? 'Unknown', $plugin_updates);
        $out[] = wp7rc_result(
            'plugin_updates',
            'plugins',
            'Pending plugin updates',
            'warn',
            sprintf('%d pending', count($plugin_updates)),
            '0 pending before upgrade',
            sprintf('There are %d plugin updates available. Run these BEFORE the WordPress core upgrade so the most current versions are tested against 7.0.', count($plugin_updates)),
            sprintf('Update these plugins from Dashboard → Updates: %s', implode(', ', array_slice($update_list, 0, 8)) . (count($update_list) > 8 ? '…' : '')),
            '',
            'update_all_plugins'
        );
    } else {
        $out[] = wp7rc_result('plugin_updates', 'plugins', 'Pending plugin updates', 'pass', '0 pending', '0 pending before upgrade', 'All plugins are at their latest stable versions.');
    }

    // 3. Compatibility audit per active plugin (Tested up to header)
    $compat_fails = [];
    $compat_warn  = [];
    foreach ($active_plugins as $plugin_file) {
        if (!isset($all_plugins[$plugin_file])) {
            continue;
        }
        $data = $all_plugins[$plugin_file];
        $name = $data['Name'] ?? $plugin_file;
        $tested_up_to = '';

        // Read raw plugin header for the "Tested up to" field (not in default get_plugin_data())
        $full_path = WP_PLUGIN_DIR . '/' . $plugin_file;
        if (is_readable($full_path)) {
            $head = @file_get_contents($full_path, false, null, 0, 8192);
            if (is_string($head) && preg_match('/Tested up to:\s*([0-9.]+)/i', $head, $m)) {
                $tested_up_to = trim($m[1]);
            }
        }

        if ($tested_up_to === '') {
            continue; // unknown — don't penalize
        }
        if (version_compare($tested_up_to, '7.0', '>=')) {
            // Pass — no individual result row to avoid noise
            continue;
        } elseif (version_compare($tested_up_to, '6.9', '>=')) {
            $compat_warn[] = sprintf('%s (tested up to %s)', $name, $tested_up_to);
        } else {
            $compat_fails[] = sprintf('%s (tested up to %s)', $name, $tested_up_to);
        }
    }

    if ($compat_fails !== []) {
        $out[] = wp7rc_result(
            'plugin_compat_old',
            'plugins',
            'Plugin compatibility (stale)',
            'fail',
            sprintf('%d plugin%s tested below WP 6.9', count($compat_fails), count($compat_fails) === 1 ? '' : 's'),
            'All active plugins tested against WP 7.0',
            sprintf('Plugins tested only against older WordPress versions: %s', implode(', ', array_slice($compat_fails, 0, 10)) . (count($compat_fails) > 10 ? '…' : '')),
            'Check each vendor changelog. If a 7.0-tested release is available, update. If the plugin is unmaintained, plan replacement or removal.'
        );
    }
    if ($compat_warn !== []) {
        // Grading curve relief: during the first 14 days after a WordPress major release,
        // plugins tested at the immediately-prior major (6.9 when current is 7.0) are
        // downgraded from WARN to INFO. Vendors typically catch up within this window.
        $is_release_week = wp7rc_is_within_major_release_window();
        $compat_status = $is_release_week ? 'info' : 'warn';
        $compat_label = $is_release_week
            ? 'Plugin compatibility (vendor update expected)'
            : 'Plugin compatibility (untested on 7.0)';
        $compat_message_suffix = $is_release_week
            ? ' Within the first 14 days of a WordPress major release, plugins still on the prior major are considered acceptable risk — vendors typically ship their compatibility update within this window.'
            : '';
        $out[] = wp7rc_result(
            'plugin_compat_warn',
            'plugins',
            $compat_label,
            $compat_status,
            sprintf('%d plugin%s tested on 6.9, not yet on 7.0', count($compat_warn), count($compat_warn) === 1 ? '' : 's'),
            'All active plugins tested against WP 7.0',
            sprintf('Plugins tested through WP 6.9 but not yet on 7.0: %s.%s', implode(', ', array_slice($compat_warn, 0, 10)) . (count($compat_warn) > 10 ? '…' : ''), $compat_message_suffix),
            'Test these on staging against 7.0 before production rollout. Many plugins ship their 7.0-tested release in the 24-72h window after launch.'
        );
    }
    if ($compat_fails === [] && $compat_warn === []) {
        $out[] = wp7rc_result('plugin_compat_ok', 'plugins', 'Plugin compatibility headers', 'pass', 'all current', 'All active plugins tested against WP 7.0', 'All active plugins with a declared "Tested up to" header are 7.0-tested.');
    }

    // 4. Known-risky plugin slugs (page builders, admin customizers)
    $risky_slugs = [
        'elementor/elementor.php'                            => 'Elementor',
        'beaver-builder-lite-version/fl-builder.php'         => 'Beaver Builder',
        'divi/divi.php'                                      => 'Divi',
        'bricks/bricks.php'                                  => 'Bricks',
        'breakdance/breakdance.php'                          => 'Breakdance',
        'oxygen/functions.php'                               => 'Oxygen Builder',
        'admin-columns-pro/admin-columns-pro.php'            => 'Admin Columns Pro',
        'codepress-admin-columns/codepress-admin-columns.php'=> 'Admin Columns',
        'wp-list-table-extender/wp-list-table-extender.php'  => 'WP List Table Extender',
    ];
    $found_risky = [];
    foreach ($risky_slugs as $slug => $label) {
        if (in_array($slug, $active_plugins, true)) {
            $found_risky[] = $label;
        }
    }
    if ($found_risky !== []) {
        $out[] = wp7rc_result(
            'plugin_risky',
            'plugins',
            'High-risk plugin categories detected',
            'warn',
            implode(', ', $found_risky),
            '',
            'These plugins customize wp-admin or hook the classic WP_List_Table surface that DataViews replaces in WordPress 7.0. Test these on staging against 7.0 before production rollout.',
            'Open each plugin\'s changelog for an explicit "WordPress 7.0 / DataViews compatibility" statement. Hold the upgrade until all are confirmed.'
        );
    }

    return $out;
}
