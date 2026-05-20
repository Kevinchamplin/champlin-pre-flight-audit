<?php
/**
 * Plugin snapshot module — copy-before-modify safety net for the plugin-update autofix.
 *
 * Snapshots live at wp-content/wp7rc-snapshots/{YYYYMMDD-HHMMSS}-{plugin-slug}/. A
 * deny-all .htaccess + web.config + index.php prevents direct HTTP access.
 *
 * Auto-prune keeps the last WP7RC_SNAPSHOT_KEEP snapshots; older ones are removed
 * after a successful new snapshot.
 *
 * @package WP7ReadinessCheck
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const WP7RC_SNAPSHOT_KEEP = 10;

/**
 * Resolve (and lazily create) the snapshot root directory.
 * Drops protective files on first creation.
 */
function wp7rc_snapshots_dir(): string
{
    $dir = trailingslashit(WP_CONTENT_DIR) . 'wp7rc-snapshots';
    if (!is_dir($dir)) {
        $fs = wp7rc_fs();
        if ($fs) {
            $fs->mkdir($dir, defined('FS_CHMOD_DIR') ? FS_CHMOD_DIR : 0755);
            // Drop deny-all guards across web server flavors.
            $fs->put_contents($dir . '/.htaccess', "Order deny,allow\nDeny from all\n", defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644);
            $fs->put_contents($dir . '/web.config', '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>', defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644);
            $fs->put_contents($dir . '/index.php', "<?php // Silence is golden.\n", defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644);
        }
    }
    return $dir;
}

/**
 * Snapshot a single plugin's directory.
 *
 * @param string $plugin_file e.g. "akismet/akismet.php"
 * @return array{ok:bool,id?:string,path?:string,error?:string}
 */
function wp7rc_snapshot_plugin(string $plugin_file): array
{
    $plugin_dir_name = dirname($plugin_file);
    if ($plugin_dir_name === '.' || $plugin_dir_name === '') {
        return ['ok' => false, 'error' => 'Single-file plugins cannot be snapshotted (no directory).'];
    }
    $src = trailingslashit(WP_PLUGIN_DIR) . $plugin_dir_name;
    if (!is_dir($src)) {
        return ['ok' => false, 'error' => sprintf('Plugin source directory not found: %s', $plugin_dir_name)];
    }

    $root = wp7rc_snapshots_dir();
    $stamp = current_time('Ymd-His');
    $id    = $stamp . '-' . sanitize_file_name($plugin_dir_name);
    $dest  = trailingslashit($root) . $id;

    if (is_dir($dest)) {
        // Highly unlikely collision (same-second snapshot of same plugin), but be safe.
        $id .= '-' . substr(md5(uniqid('', true)), 0, 6);
        $dest = trailingslashit($root) . $id;
    }

    if (!wp7rc_copy_recursive($src, $dest)) {
        wp7rc_rrmdir($dest); // partial cleanup
        return ['ok' => false, 'error' => 'Failed to copy plugin directory.'];
    }

    // Write a manifest sidecar
    $version = '';
    if (function_exists('get_plugin_data')) {
        $data = @get_plugin_data(trailingslashit(WP_PLUGIN_DIR) . $plugin_file, false, false);
        $version = (string) ($data['Version'] ?? '');
    }
    $manifest = [
        'id'              => $id,
        'plugin_file'     => $plugin_file,
        'plugin_dir_name' => $plugin_dir_name,
        'version_at_snap' => $version,
        'created_at'      => current_time('mysql'),
        'created_by'      => get_current_user_id(),
        'byte_size'       => wp7rc_dir_size($dest),
    ];
    $fs = wp7rc_fs();
    if ($fs) {
        $fs->put_contents($dest . '/.wp7rc-manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT), defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644);
    }

    // Prune old snapshots
    wp7rc_prune_snapshots();

    return ['ok' => true, 'id' => $id, 'path' => $dest];
}

/**
 * List all snapshots, newest first.
 *
 * @return array<int, array{id:string,plugin_file:string,plugin_dir_name:string,version_at_snap:string,created_at:string,byte_size:int}>
 */
function wp7rc_list_snapshots(): array
{
    $root = wp7rc_snapshots_dir();
    $out  = [];
    foreach (glob(trailingslashit($root) . '*', GLOB_ONLYDIR) ?: [] as $path) {
        $manifest_path = $path . '/.wp7rc-manifest.json';
        if (!is_readable($manifest_path)) {
            continue;
        }
        $raw = @file_get_contents($manifest_path);
        if (!is_string($raw)) {
            continue;
        }
        $manifest = json_decode($raw, true);
        if (is_array($manifest)) {
            $out[] = $manifest;
        }
    }
    // Newest first
    usort($out, static fn ($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    return $out;
}

/**
 * Restore a snapshot over the live plugin directory.
 *
 * @return array{ok:bool,message:string}
 */
function wp7rc_restore_snapshot(string $snapshot_id): array
{
    $root = wp7rc_snapshots_dir();
    $src  = trailingslashit($root) . $snapshot_id;
    if (!is_dir($src)) {
        return ['ok' => false, 'message' => 'Snapshot not found.'];
    }
    $manifest_path = $src . '/.wp7rc-manifest.json';
    if (!is_readable($manifest_path)) {
        return ['ok' => false, 'message' => 'Snapshot manifest missing.'];
    }
    $manifest = json_decode((string) file_get_contents($manifest_path), true);
    if (!is_array($manifest) || empty($manifest['plugin_dir_name'])) {
        return ['ok' => false, 'message' => 'Snapshot manifest invalid.'];
    }

    $plugin_dir_name = (string) $manifest['plugin_dir_name'];
    $target          = trailingslashit(WP_PLUGIN_DIR) . $plugin_dir_name;

    // Take a recovery snapshot of the CURRENT state before we overwrite it.
    // This way, if the user clicks Restore by mistake, we can re-roll forward.
    if (is_dir($target) && !empty($manifest['plugin_file'])) {
        wp7rc_snapshot_plugin((string) $manifest['plugin_file']);
    }

    // Replace the live plugin dir with the snapshot contents (manifest file excluded).
    if (is_dir($target)) {
        wp7rc_rrmdir($target);
    }
    if (!wp7rc_copy_recursive($src, $target, ['.wp7rc-manifest.json'])) {
        return ['ok' => false, 'message' => 'Restore copy failed.'];
    }

    // WordPress caches plugin data — bust it.
    if (function_exists('wp_clean_plugins_cache')) {
        wp_clean_plugins_cache(true);
    }

    return [
        'ok'      => true,
        'message' => sprintf(
            'Restored %s to version %s (snapshotted %s). A new recovery snapshot of the just-replaced version was taken automatically.',
            $plugin_dir_name,
            (string) ($manifest['version_at_snap'] ?? '?'),
            (string) ($manifest['created_at'] ?? '')
        ),
    ];
}

/**
 * Delete oldest snapshots beyond WP7RC_SNAPSHOT_KEEP.
 */
function wp7rc_prune_snapshots(): void
{
    $all = wp7rc_list_snapshots(); // newest first
    if (count($all) <= WP7RC_SNAPSHOT_KEEP) {
        return;
    }
    $excess = array_slice($all, WP7RC_SNAPSHOT_KEEP);
    $root   = wp7rc_snapshots_dir();
    foreach ($excess as $m) {
        if (!empty($m['id'])) {
            wp7rc_rrmdir(trailingslashit($root) . $m['id']);
        }
    }
}

/* ====================== Internal helpers ====================== */

function wp7rc_copy_recursive(string $src, string $dest, array $exclude = []): bool
{
    if (!is_dir($src)) {
        return false;
    }
    $fs = wp7rc_fs();
    if (!$fs) {
        return false;
    }
    $chmod_dir  = defined('FS_CHMOD_DIR')  ? FS_CHMOD_DIR  : 0755;
    if (!is_dir($dest) && !$fs->mkdir($dest, $chmod_dir)) {
        return false;
    }
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($rii as $item) {
        $rel = substr($item->getPathname(), strlen($src) + 1);
        if (in_array(basename($rel), $exclude, true)) {
            continue;
        }
        $target = trailingslashit($dest) . $rel;
        if ($item->isDir()) {
            if (!is_dir($target) && !$fs->mkdir($target, $chmod_dir)) {
                return false;
            }
        } else {
            if (!$fs->copy($item->getPathname(), $target, true)) {
                return false;
            }
        }
    }
    return true;
}

function wp7rc_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $fs = wp7rc_fs();
    if (!$fs) {
        return;
    }
    // WP_Filesystem's delete() with recursive=true handles the whole tree in one call.
    $fs->delete($dir, true, 'd');
}

function wp7rc_dir_size(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $bytes = 0;
    try {
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($rii as $file) {
            if ($file->isFile()) {
                $bytes += $file->getSize();
            }
        }
    } catch (\Throwable $e) {
        // ignore
    }
    return $bytes;
}

function wp7rc_format_bytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return number_format($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return number_format($bytes / 1048576, 1) . ' MB';
    return number_format($bytes / 1073741824, 2) . ' GB';
}
