<?php
/**
 * Audit runner — loads every check module and aggregates results.
 *
 * @package WP7ReadinessCheck
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run all available checks. Each check module exposes a function
 * named wp7rc_check_{category}() that returns an array of result arrays.
 *
 * @return array{results: array<int, array>, score: int, summary: array<string,int>, generated_at: string}
 */
function wp7rc_run_audit(): array
{
    $modules = [
        'server'      => 'server.php',
        'database'    => 'database.php',
        'wordpress'   => 'wordpress.php',
        'plugins'     => 'plugins.php',
        'themes'      => 'themes.php',
        'custom-code' => 'custom-code.php',
        'headless'    => 'headless.php',
        'multisite'   => 'multisite.php',
        'security'    => 'security.php',
    ];

    $results = [];

    foreach ($modules as $slug => $file) {
        $path = WP7RC_DIR . 'includes/checks/' . $file;
        if (!is_readable($path)) {
            continue;
        }
        require_once $path;
        $fn = 'wp7rc_check_' . str_replace('-', '_', $slug);
        if (function_exists($fn)) {
            try {
                $module_results = $fn();
                if (is_array($module_results)) {
                    foreach ($module_results as $r) {
                        if (is_array($r) && isset($r['id'], $r['status'])) {
                            $results[] = $r;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $results[] = wp7rc_result(
                    'module_error_' . $slug,
                    $slug,
                    sprintf('Check module: %s', $slug),
                    'warn',
                    'error',
                    'completed without error',
                    sprintf('Module raised an exception: %s', $e->getMessage()),
                    'Re-run the audit. If the error persists, check PHP error log.'
                );
            }
        }
    }

    // Annotate findings with override state so the score + UI can react.
    $overrides = wp7rc_get_overrides();
    foreach ($results as &$r) {
        $r['overridden'] = isset($overrides[$r['id']]);
        if ($r['overridden']) {
            $r['override_meta'] = $overrides[$r['id']];
        }
    }
    unset($r);

    $summary = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0, 'skip' => 0, 'overridden' => 0];
    foreach ($results as $r) {
        $status = $r['status'] ?? 'info';
        if (!empty($r['overridden'])) {
            $summary['overridden']++;
            continue;
        }
        if (isset($summary[$status])) {
            $summary[$status]++;
        }
    }

    return [
        'results'      => $results,
        'score'        => wp7rc_score($results),
        'summary'      => $summary,
        'generated_at' => current_time('mysql'),
    ];
}
