<?php
/**
 * Theme checks — active theme, compat header, block vs classic.
 *
 * @package WP7ReadinessCheck
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function wp7rc_check_themes(): array
{
    $out   = [];
    $theme = wp_get_theme();

    $name    = (string) $theme->get('Name');
    $version = (string) $theme->get('Version');

    // Active theme info
    $out[] = wp7rc_result('active_theme', 'themes', 'Active theme', 'info', sprintf('%s %s', $name, $version), '', 'Active theme detected.');

    // Tested up to header
    $tested = '';
    $stylesheet = $theme->get_stylesheet_directory() . '/style.css';
    if (is_readable($stylesheet)) {
        $head = @file_get_contents($stylesheet, false, null, 0, 8192);
        if (is_string($head) && preg_match('/Tested up to:\s*([0-9.]+)/i', $head, $m)) {
            $tested = trim($m[1]);
        }
    }
    if ($tested === '') {
        $out[] = wp7rc_result('theme_tested', 'themes', 'Theme compatibility header', 'info', 'no "Tested up to"', 'declares 7.0 compatibility', 'The active theme does not declare a "Tested up to" version. Check the vendor\'s changelog manually.', 'Visit the theme vendor\'s site to confirm 7.0 compatibility.');
    } elseif (version_compare($tested, '7.0', '>=')) {
        $out[] = wp7rc_result('theme_tested', 'themes', 'Theme compatibility header', 'pass', sprintf('tested up to %s', $tested), 'declares 7.0 compatibility', 'Theme declares WordPress 7.0 compatibility.');
    } elseif (version_compare($tested, '6.9', '>=')) {
        // Grading curve relief: within 14 days of a major WP release, themes still on
        // the prior major are info-level (vendor catching up). Outside the window: warn.
        $is_release_week = wp7rc_is_within_major_release_window();
        $status = $is_release_week ? 'info' : 'warn';
        $label  = $is_release_week ? 'Theme compatibility (vendor update expected)' : 'Theme compatibility header';
        $msg    = $is_release_week
            ? sprintf('Theme tested through WP %s but not yet on 7.0. Within the first 14 days of a WordPress major release, themes still on the prior major are considered acceptable risk — the WordPress core team typically ships a theme update within this window.', $tested)
            : sprintf('Theme is tested on %s but not yet on 7.0. Test on staging.', $tested);
        $out[] = wp7rc_result('theme_tested', 'themes', $label, $status, sprintf('tested up to %s', $tested), 'declares 7.0 compatibility', $msg, 'Check vendor for a 7.0-tested release.');
    } else {
        $out[] = wp7rc_result('theme_tested', 'themes', 'Theme compatibility header', 'fail', sprintf('tested up to %s', $tested), 'declares 7.0 compatibility', sprintf('Theme is only tested through WordPress %s.', $tested), 'Plan a theme update or replacement before the WordPress 7.0 upgrade.');
    }

    // Block theme vs classic
    if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
        $out[] = wp7rc_result('block_theme', 'themes', 'Theme architecture', 'pass', 'block theme', 'block theme (preferred)', 'Active theme is a block theme. Best positioned for WordPress 7.0\'s editor improvements.');
    } else {
        $out[] = wp7rc_result(
            'block_theme', 'themes', 'Theme architecture',
            'info', 'classic theme', '',
            'Active theme is a classic (non-block) theme. Fully supported in 7.0 but does not benefit from Site Editor improvements.',
            'No action required for the 7.0 upgrade. A migration to a block theme is a separate strategic decision.'
        );
    }

    // HTML5 script theme support — deprecated in 7.0
    // NOTE: block themes inherit `html5 => [..., 'script']` from WordPress core, not from the theme code.
    // Only flag this if the active theme is a classic theme that's explicitly adding it.
    $is_block_theme = function_exists('wp_is_block_theme') && wp_is_block_theme();
    if ($is_block_theme) {
        $out[] = wp7rc_result('html5_script_support', 'themes', 'HTML5 script theme support', 'pass', 'n/a (block theme)', 'not used by theme code', 'Block themes inherit html5 theme support from WordPress core, not from theme code. The 7.0 deprecation does not apply to your theme.');
    } else {
        $supports = get_theme_support('html5');
        $uses_deprecated = false;
        if (is_array($supports)) {
            foreach ($supports as $support) {
                if (is_array($support) && in_array('script', $support, true)) {
                    $uses_deprecated = true;
                    break;
                }
            }
        }
        if ($uses_deprecated) {
            $out[] = wp7rc_result(
                'html5_script_support', 'themes', 'HTML5 script theme support',
                'fail', "add_theme_support('html5', ['script', ...])", 'not used',
                "The active theme declares add_theme_support('html5', ['script', ...]). This is deprecated in WordPress 7.0 — the script loader no longer honors it.",
                "Remove 'script' from the html5 theme support array in functions.php."
            );
        } else {
            $out[] = wp7rc_result('html5_script_support', 'themes', 'HTML5 script theme support', 'pass', 'not used', 'not used', 'Theme does not use the deprecated HTML5 script theme support.');
        }
    }

    return $out;
}
