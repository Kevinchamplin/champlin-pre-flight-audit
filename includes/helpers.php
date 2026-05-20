<?php
/**
 * Shared helpers — result-building, version comparison, file scanning.
 *
 * @package WP7ReadinessCheck
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Build a normalized check result.
 */
function wp7rc_result(
    string $id,
    string $category,
    string $label,
    string $status,
    string $value = '',
    string $expected = '',
    string $message = '',
    string $remediation = '',
    string $reference = '',
    string $fix_id = ''
): array {
    return [
        'id'          => $id,
        'category'    => $category,
        'label'       => $label,
        'status'      => in_array($status, ['pass', 'warn', 'fail', 'info', 'skip'], true) ? $status : 'info',
        'value'       => $value,
        'expected'    => $expected,
        'message'     => $message,
        'remediation' => $remediation,
        'reference'   => $reference,
        'fix_id'      => $fix_id,
    ];
}

/**
 * Determine whether the site is pre- or post-WP 7.0. Influences framing.
 */
function wp7rc_is_post_seven(): bool
{
    global $wp_version;
    return version_compare((string) $wp_version, '7.0', '>=');
}

/**
 * Lazy-initialize WP_Filesystem and return the global instance.
 * Returns false if filesystem credentials are required and not provided
 * (rare on Plesk and managed hosts where direct access is the default).
 *
 * @return object|false WP_Filesystem_Base subclass or false
 */
function wp7rc_fs()
{
    global $wp_filesystem;
    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if (!$wp_filesystem || !is_object($wp_filesystem)) {
        WP_Filesystem();
    }
    return $wp_filesystem ?? false;
}

/**
 * Are we within the "fresh major release" grading window?
 *
 * WordPress 7.0 shipped 2026-05-20. Vendors typically catch up within 14 days
 * (page builders + ACF stack + Yoast historically bump "Tested up to" within
 * a week of a major). Penalizing them as WARN during this window is
 * unfair — most installs would flunk the audit on launch day no matter how
 * well-prepared they were.
 *
 * Within the window, plugins/themes tested at the immediately-prior major
 * (6.9 when current is 7.0) are downgraded from WARN to INFO.
 */
function wp7rc_is_within_major_release_window(): bool
{
    // Bump this constant when WordPress 7.1 ships.
    $release_date = '2026-05-20';
    $window_days  = 14;
    $today        = (int) strtotime('today');
    $release_ts   = (int) strtotime($release_date);
    if ($release_ts === false || $today === false) {
        return false;
    }
    return $today >= $release_ts && ($today - $release_ts) <= ($window_days * 86400);
}

/**
 * Get the set of finding IDs the user has marked as "accepted known risks."
 * Stored as wp_options['wp7rc_overrides'].
 *
 * @return array<string, array{accepted_by:int,accepted_at:string}>
 */
function wp7rc_get_overrides(): array
{
    $val = get_option('wp7rc_overrides', []);
    return is_array($val) ? $val : [];
}

/**
 * Is this finding ID currently flagged as accepted-risk?
 */
function wp7rc_is_overridden(string $finding_id): bool
{
    $overrides = wp7rc_get_overrides();
    return isset($overrides[$finding_id]);
}

/**
 * Recursively scan files matching a pattern in a directory, with safety caps.
 *
 * @param string   $dir          Directory to scan.
 * @param string[] $extensions   File extensions to include (e.g. ['php', 'json']).
 * @param int      $maxFiles     Hard cap on files scanned.
 * @param int      $maxFileBytes Skip files larger than this (default 1 MB).
 * @return string[] Absolute file paths.
 */
function wp7rc_scan_files(string $dir, array $extensions, int $maxFiles = 2000, int $maxFileBytes = 1048576): array
{
    if (!is_dir($dir) || !is_readable($dir)) {
        return [];
    }

    $skip_dirs = ['vendor', 'node_modules', '.git', '.svn', 'dist', 'build', '.cache', 'tests', 'test', '__tests__'];
    $files     = [];

    try {
        $rii = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                static function (\SplFileInfo $current) use ($skip_dirs): bool {
                    if ($current->isDir() && in_array($current->getFilename(), $skip_dirs, true)) {
                        return false;
                    }
                    return true;
                }
            )
        );
        foreach ($rii as $file) {
            if (count($files) >= $maxFiles) {
                break;
            }
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, $extensions, true)) {
                continue;
            }
            if ($file->getSize() > $maxFileBytes) {
                continue;
            }
            $files[] = $file->getPathname();
        }
    } catch (\Throwable $e) {
        // Permissions or other I/O errors — return what we have so far.
    }

    return $files;
}

/**
 * Search files for a regex pattern. Returns array of [path => [matching_lines]].
 *
 * @param string[] $files Paths to scan.
 * @param string   $pattern PCRE pattern.
 * @param int      $maxMatches Stop after this many matches.
 */
function wp7rc_grep(array $files, string $pattern, int $maxMatches = 50): array
{
    $matches = [];
    foreach ($files as $path) {
        if (count($matches) >= $maxMatches) {
            break;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            continue;
        }
        if (preg_match($pattern, $content)) {
            // Find the line numbers
            $lines = preg_split("/\r\n|\n|\r/", $content);
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $i => $line) {
                if (preg_match($pattern, $line)) {
                    $matches[] = [
                        'path' => $path,
                        'line' => $i + 1,
                        'snippet' => trim(substr($line, 0, 160)),
                    ];
                    if (count($matches) >= $maxMatches) {
                        break 2;
                    }
                }
            }
        }
    }
    return $matches;
}

/**
 * Format a friendly relative path from a WP-root absolute path.
 */
function wp7rc_relpath(string $path): string
{
    $root = trailingslashit(ABSPATH);
    if (strpos($path, $root) === 0) {
        return substr($path, strlen($root));
    }
    return $path;
}

/**
 * Compute a 0-100 readiness score from results.
 * Pass = 1, Warn = 0.5, Fail = 0, Info/Skip/Overridden ignored.
 */
function wp7rc_score(array $results): int
{
    $points = 0.0;
    $total  = 0;
    foreach ($results as $r) {
        // Accepted-as-known-risk findings don't count toward the score.
        if (!empty($r['overridden'])) {
            continue;
        }
        if (in_array($r['status'], ['pass', 'warn', 'fail'], true)) {
            $total++;
            if ($r['status'] === 'pass') {
                $points += 1.0;
            } elseif ($r['status'] === 'warn') {
                $points += 0.5;
            }
        }
    }
    if ($total === 0) {
        return 0;
    }
    return (int) round(($points / $total) * 100);
}

/**
 * Map category slug to a human label + display order.
 */
function wp7rc_categories(): array
{
    return [
        'server'      => ['label' => 'Server & runtime',     'order' => 1],
        'database'    => ['label' => 'Database',             'order' => 2],
        'wordpress'   => ['label' => 'WordPress core',       'order' => 3],
        'plugins'     => ['label' => 'Plugins',              'order' => 4],
        'themes'      => ['label' => 'Themes',               'order' => 5],
        'custom-code' => ['label' => 'Custom code',          'order' => 6],
        'headless'    => ['label' => 'Headless & API',       'order' => 7],
        'multisite'   => ['label' => 'Multisite',            'order' => 8],
        'security'    => ['label' => 'Security & compliance','order' => 9],
    ];
}
