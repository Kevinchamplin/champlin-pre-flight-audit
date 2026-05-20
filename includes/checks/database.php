<?php
/**
 * Database checks — MySQL/MariaDB version, InnoDB.
 *
 * @package WP7ReadinessCheck
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function wp7rc_check_database(): array
{
    global $wpdb;
    $out = [];

    // Direct DB queries are intentional and uncacheable: this is a one-shot audit
    // that introspects the live database engine + storage-engine capabilities.
    // Caching defeats the entire point — we want fresh state every audit run.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

    $version_raw = (string) $wpdb->get_var('SELECT VERSION()');
    $is_maria    = stripos($version_raw, 'mariadb') !== false;
    // Some versions return "5.5.5-10.6.x-MariaDB" — strip the prefix
    $numeric = preg_replace('/^5\.5\.5-/', '', $version_raw);
    $numeric = (string) preg_replace('/-.*$/', '', $numeric);

    if ($is_maria) {
        if (version_compare($numeric, '10.6', '>=')) {
            $out[] = wp7rc_result('db_version', 'database', 'MariaDB version', 'pass', $version_raw, 'MariaDB 10.6+', 'MariaDB version meets the recommended minimum.');
        } elseif (version_compare($numeric, '10.4', '>=')) {
            $out[] = wp7rc_result('db_version', 'database', 'MariaDB version', 'warn', $version_raw, 'MariaDB 10.6+', 'MariaDB version is supported but below the recommendation for WordPress 7.0.', 'Plan a MariaDB upgrade to 10.6 or 10.11 LTS in your next maintenance window.');
        } else {
            $out[] = wp7rc_result('db_version', 'database', 'MariaDB version', 'fail', $version_raw, 'MariaDB 10.6+', 'MariaDB version is below recommended minimums for WordPress 7.0.', 'Upgrade MariaDB to at least 10.6 before the WordPress upgrade.');
        }
    } else {
        // Assume MySQL
        if (version_compare($numeric, '8.0', '>=')) {
            $out[] = wp7rc_result('db_version', 'database', 'MySQL version', 'pass', $version_raw, 'MySQL 8.0+', 'MySQL version meets the recommended minimum.');
        } elseif (version_compare($numeric, '5.7', '>=')) {
            $out[] = wp7rc_result('db_version', 'database', 'MySQL version', 'warn', $version_raw, 'MySQL 8.0+', 'MySQL 5.7 still works but is below the recommendation for WordPress 7.0.', 'Plan a MySQL upgrade to 8.0+ in your next maintenance window.');
        } else {
            $out[] = wp7rc_result('db_version', 'database', 'MySQL version', 'fail', $version_raw, 'MySQL 8.0+', 'MySQL version is below the recommended minimum.', 'Upgrade MySQL to at least 5.7 (8.0+ preferred) before the WordPress upgrade.');
        }
    }

    // InnoDB engine available
    $engines = $wpdb->get_results('SHOW ENGINES', ARRAY_A);
    $innodb_ok = false;
    if (is_array($engines)) {
        foreach ($engines as $row) {
            if (isset($row['Engine'], $row['Support'])
                && strtolower($row['Engine']) === 'innodb'
                && in_array(strtoupper($row['Support']), ['DEFAULT', 'YES'], true)
            ) {
                $innodb_ok = true;
                break;
            }
        }
    }
    if ($innodb_ok) {
        $out[] = wp7rc_result('innodb', 'database', 'InnoDB storage engine', 'pass', 'available', 'InnoDB enabled', 'InnoDB is available — required for transactional integrity during the schema update.');
    } else {
        $out[] = wp7rc_result('innodb', 'database', 'InnoDB storage engine', 'fail', 'unavailable', 'InnoDB enabled', 'InnoDB is not available; WordPress 7.0 RTC schema and large-table operations may fail.', 'Contact your host to enable InnoDB.');
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching

    return $out;
}
