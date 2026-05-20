<?php
/**
 * Server & runtime checks — PHP version, opcache, extensions, memory.
 *
 * @package WP7ReadinessCheck
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function wp7rc_check_server(): array
{
    $out = [];

    // PHP version — 7.4 minimum for WP 7.0
    $php = PHP_VERSION;
    if (version_compare($php, '8.3', '>=')) {
        $status  = 'pass';
        $message = sprintf('PHP %s is in the recommended band for WordPress 7.0.', $php);
        $remedy  = '';
    } elseif (version_compare($php, '7.4', '>=')) {
        $status  = 'pass';
        $message = sprintf('PHP %s meets the 7.4 minimum for WordPress 7.0. PHP 8.3+ is recommended for performance.', $php);
        $remedy  = 'Plan a PHP upgrade to 8.3 or 8.4 in your next maintenance window.';
    } else {
        $status  = 'fail';
        $message = sprintf('PHP %s is below the WordPress 7.0 minimum of 7.4. Auto-updates will not advance this site to 7.0.', $php);
        $remedy  = 'Upgrade PHP to 7.4 or higher via your host control panel before the upgrade.';
    }
    $out[] = wp7rc_result('php_version', 'server', 'PHP version', $status, $php, '7.4 minimum (8.3+ recommended)', $message, $remedy);

    // OPcache — detect via extension_loaded() because many hosts (Plesk, CloudLinux,
    // managed WP hosts) lock down opcache_get_status() via disable_functions for security.
    // Using function_exists('opcache_get_status') as the signal produces false positives
    // on hardened servers where OPcache is actually running fine.
    $opcache_loaded = extension_loaded('Zend OPcache');
    $opcache_enabled_ini = (bool) ini_get('opcache.enable');

    if ($opcache_loaded && $opcache_enabled_ini) {
        // Try to read detailed status; fall back to a less-detailed pass if disable_functions hides it.
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        if (is_array($opcache) && !empty($opcache['opcache_enabled'])) {
            $mem = isset($opcache['memory_usage']['used_memory'], $opcache['memory_usage']['free_memory'])
                ? (int) (($opcache['memory_usage']['used_memory'] + $opcache['memory_usage']['free_memory']) / 1048576)
                : 0;
            $out[] = wp7rc_result(
                'opcache', 'server', 'OPcache',
                'pass',
                sprintf('enabled (%d MB)', $mem),
                'extension loaded and enabled',
                'OPcache is enabled. After the upgrade, reset it (USR2 the FPM master or use opcache_reset() via WP-CLI).'
            );
        } else {
            // Extension loaded + ini enabled, but opcache_get_status is hidden by server hardening.
            // This is the COMMON state on Plesk and CloudLinux installs — fully working OPcache.
            $out[] = wp7rc_result(
                'opcache', 'server', 'OPcache',
                'pass',
                'enabled (status hidden)',
                'extension loaded and enabled',
                'OPcache extension is loaded and opcache.enable=1. The opcache_get_status() function is disabled by your server (typical on Plesk / CloudLinux / managed hosts as a security hardening) so detailed memory stats are unavailable, but OPcache itself is fully working.'
            );
        }
    } elseif ($opcache_loaded && !$opcache_enabled_ini) {
        $out[] = wp7rc_result(
            'opcache', 'server', 'OPcache',
            'warn',
            'loaded but disabled',
            'extension loaded and enabled',
            'OPcache extension is loaded but opcache.enable is set to 0 in php.ini. WordPress will recompile every .php file on every request.',
            'Set opcache.enable=1 in your php.ini (or in your Plesk PHP settings panel) and restart PHP-FPM.'
        );
    } else {
        $out[] = wp7rc_result(
            'opcache', 'server', 'OPcache',
            'warn',
            'extension not loaded',
            'extension loaded and enabled',
            'The Zend OPcache extension is not loaded. WordPress recompiles every .php file on every request, which measurably slows page loads.',
            'Install the PHP OPcache extension on your server and enable opcache.enable=1 in php.ini. Restart PHP-FPM.'
        );
    }

    // Memory limit
    $mem_label = (string) ini_get('memory_limit');
    $mem_limit = wp_convert_hr_to_bytes($mem_label);
    if ($mem_label === '-1') {
        $out[] = wp7rc_result('memory_limit', 'server', 'PHP memory_limit', 'pass', 'unlimited (-1)', '256M+', 'memory_limit is unlimited. WordPress will use as much memory as it needs.');
    } elseif ($mem_limit >= 256 * 1048576) {
        $out[] = wp7rc_result('memory_limit', 'server', 'PHP memory_limit', 'pass', $mem_label, '256M+', sprintf('memory_limit is %s.', $mem_label));
    } elseif ($mem_limit >= 128 * 1048576) {
        $out[] = wp7rc_result(
            'memory_limit', 'server', 'PHP memory_limit',
            'warn', $mem_label, '256M+',
            sprintf('memory_limit is %s. WordPress 7.0 + DataViews REST chatter may stress this on larger admin screens.', $mem_label),
            'Bump memory_limit to 256M via php.ini or wp-config.php (define WP_MEMORY_LIMIT).'
        );
    } else {
        $out[] = wp7rc_result(
            'memory_limit', 'server', 'PHP memory_limit',
            'fail', $mem_label, '256M+',
            sprintf('memory_limit of %s is below the recommended floor.', $mem_label),
            'Increase memory_limit to at least 256M before the upgrade.'
        );
    }

    // Max execution time
    $max_exec = (int) ini_get('max_execution_time');
    if ($max_exec === 0 || $max_exec >= 60) {
        $out[] = wp7rc_result('max_execution_time', 'server', 'PHP max_execution_time', 'pass', (string) $max_exec, '60+', sprintf('max_execution_time is %d seconds.', $max_exec));
    } else {
        $out[] = wp7rc_result(
            'max_execution_time', 'server', 'PHP max_execution_time',
            'warn', (string) $max_exec, '60+',
            sprintf('max_execution_time of %d seconds may abort the WordPress upgrade mid-run.', $max_exec),
            'Set max_execution_time to 60+ in php.ini for the upgrade window.'
        );
    }

    // Required PHP extensions
    $required = ['curl', 'mbstring', 'json', 'openssl'];
    $missing  = array_filter($required, static fn($ext) => !extension_loaded($ext));
    if ($missing === []) {
        $out[] = wp7rc_result('php_extensions', 'server', 'PHP extensions', 'pass', implode(', ', $required), 'curl, mbstring, json, openssl', 'All required PHP extensions are loaded.');
    } else {
        $out[] = wp7rc_result(
            'php_extensions', 'server', 'PHP extensions',
            'fail', 'missing: ' . implode(', ', $missing), 'curl, mbstring, json, openssl',
            'One or more required PHP extensions are missing.',
            sprintf('Install the following PHP extensions on the server: %s.', implode(', ', $missing))
        );
    }

    // WordPress cron (system or wp-cron)
    $wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    if ($wp_cron_disabled) {
        $out[] = wp7rc_result(
            'cron', 'server', 'WordPress cron',
            'info', 'system cron (DISABLE_WP_CRON=true)', 'enabled',
            'WP-cron is disabled. You are likely running WP-cron from a system cron job — verify it is firing on schedule.',
            'Run: wp cron event list --due-now — to confirm no events are stuck.'
        );
    } else {
        $out[] = wp7rc_result('cron', 'server', 'WordPress cron', 'pass', 'wp-cron enabled', 'enabled', 'WP-cron is enabled via WordPress (default).');
    }

    return $out;
}
