<?php
/**
 * WooCommerce Barcode Scanner - Audit Database Handler
 *
 * Manages database tables for scan audit tracking
 *
 * @package WooCommerceBarcodeScanner
 * @since 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WBS_Audit_DB {

    /**
     * Database version for migrations
     */
    const DB_VERSION = '1.0';
    const DB_VERSION_OPTION = 'wbs_audit_db_version';

    /**
     * Create audit tables on plugin activation
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        error_log('WBS Audit: Creating audit tables...');

        // Table for individual product scans
        $scan_audits_table = $wpdb->prefix . 'wbs_scan_audits';

        // Table for linking scans to orders
        $order_scans_table = $wpdb->prefix . 'wbs_order_scans';

        $sql = array();

        // Scan audits table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$scan_audits_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            user_display_name VARCHAR(250) NOT NULL,
            product_id BIGINT UNSIGNED,
            product_sku VARCHAR(200),
            product_name VARCHAR(500),
            scan_context VARCHAR(50) NOT NULL,
            search_term VARCHAR(200),
            scan_success TINYINT(1) DEFAULT 1,
            created_at DATETIME NOT NULL,
            INDEX idx_user_id (user_id),
            INDEX idx_product_id (product_id),
            INDEX idx_scan_context (scan_context),
            INDEX idx_created_at (created_at),
            INDEX idx_product_sku (product_sku)
        ) $charset_collate;";

        // Order scans linking table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$order_scans_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            scan_audit_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_order_id (order_id),
            INDEX idx_scan_audit_id (scan_audit_id),
            INDEX idx_product_id (product_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        foreach ($sql as $query) {
            $result = dbDelta($query);
            error_log('WBS Audit dbDelta result: ' . print_r($result, true));
        }

        // Verify tables were created
        $scan_table_check = $wpdb->get_var("SHOW TABLES LIKE '{$scan_audits_table}'");
        $order_table_check = $wpdb->get_var("SHOW TABLES LIKE '{$order_scans_table}'");

        if ($scan_table_check && $order_table_check) {
            error_log('WBS Audit: Tables created successfully!');
        } else {
            error_log('WBS Audit: ERROR - Tables not created. Scan table: ' . ($scan_table_check ? 'OK' : 'MISSING') . ', Order table: ' . ($order_table_check ? 'OK' : 'MISSING'));
        }

        // Update database version
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);

        // Schedule cleanup cron job
        self::schedule_cleanup();
    }

    /**
     * Schedule daily cleanup of old audit data
     */
    public static function schedule_cleanup() {
        if (!wp_next_scheduled('wbs_audit_cleanup')) {
            wp_schedule_event(time(), 'daily', 'wbs_audit_cleanup');
        }
    }

    /**
     * Unschedule cleanup on plugin deactivation
     */
    public static function unschedule_cleanup() {
        $timestamp = wp_next_scheduled('wbs_audit_cleanup');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wbs_audit_cleanup');
        }
    }

    /**
     * Clean up audit data older than 90 days
     */
    public static function cleanup_old_data() {
        global $wpdb;

        $scan_audits_table = $wpdb->prefix . 'wbs_scan_audits';
        $order_scans_table = $wpdb->prefix . 'wbs_order_scans';

        // Delete scans older than 90 days
        $ninety_days_ago = date('Y-m-d H:i:s', strtotime('-90 days'));

        // Delete from order_scans first (due to foreign key)
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$order_scans_table} WHERE created_at < %s",
                $ninety_days_ago
            )
        );

        // Delete from scan_audits
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$scan_audits_table} WHERE created_at < %s",
                $ninety_days_ago
            )
        );
    }

    /**
     * Drop audit tables on plugin uninstall
     */
    public static function drop_tables() {
        global $wpdb;

        $scan_audits_table = $wpdb->prefix . 'wbs_scan_audits';
        $order_scans_table = $wpdb->prefix . 'wbs_order_scans';

        $wpdb->query("DROP TABLE IF EXISTS {$order_scans_table}");
        $wpdb->query("DROP TABLE IF EXISTS {$scan_audits_table}");

        delete_option(self::DB_VERSION_OPTION);
        self::unschedule_cleanup();
    }

    /**
     * Check if tables exist
     */
    public static function tables_exist() {
        global $wpdb;

        $scan_audits_table = $wpdb->prefix . 'wbs_scan_audits';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$scan_audits_table}'");

        return $table_exists === $scan_audits_table;
    }

    /**
     * Add performance indexes for meta queries
     *
     * Creates indexes on postmeta table for _old_sku and _verified lookups
     * which are used heavily in barcode scanning and frontend filtering.
     */
    public static function add_performance_indexes() {
        global $wpdb;

        error_log('WBS: Adding performance indexes...');

        // Check if indexes already exist before creating
        $existing_indexes = $wpdb->get_results(
            "SHOW INDEX FROM {$wpdb->postmeta} WHERE Key_name IN ('wbs_old_sku_lookup', 'wbs_verified_lookup')"
        );

        $existing_index_names = array();
        foreach ($existing_indexes as $index) {
            $existing_index_names[] = $index->Key_name;
        }

        // Index for _old_sku lookups (barcode scanning fallback)
        // This dramatically speeds up the query: SELECT post_id FROM postmeta WHERE meta_key = '_old_sku' AND meta_value = %s
        if (!in_array('wbs_old_sku_lookup', $existing_index_names)) {
            $result = $wpdb->query(
                "CREATE INDEX wbs_old_sku_lookup ON {$wpdb->postmeta} (meta_key(32), meta_value(100))"
            );
            if ($result !== false) {
                error_log('WBS: Created wbs_old_sku_lookup index');
            } else {
                error_log('WBS: Failed to create wbs_old_sku_lookup index: ' . $wpdb->last_error);
            }
        } else {
            error_log('WBS: wbs_old_sku_lookup index already exists');
        }

        // Index for _verified lookups (frontend product filtering)
        // This speeds up the meta_query for 'On the Floor' products
        if (!in_array('wbs_verified_lookup', $existing_index_names)) {
            $result = $wpdb->query(
                "CREATE INDEX wbs_verified_lookup ON {$wpdb->postmeta} (meta_key(32), meta_value(50))"
            );
            if ($result !== false) {
                error_log('WBS: Created wbs_verified_lookup index');
            } else {
                error_log('WBS: Failed to create wbs_verified_lookup index: ' . $wpdb->last_error);
            }
        } else {
            error_log('WBS: wbs_verified_lookup index already exists');
        }

        // Store that we've attempted to add indexes
        update_option('wbs_performance_indexes_added', '1.0');
    }

    /**
     * Remove performance indexes on uninstall
     */
    public static function remove_performance_indexes() {
        global $wpdb;

        // Check and drop indexes if they exist
        $existing_indexes = $wpdb->get_results(
            "SHOW INDEX FROM {$wpdb->postmeta} WHERE Key_name IN ('wbs_old_sku_lookup', 'wbs_verified_lookup')"
        );

        foreach ($existing_indexes as $index) {
            $wpdb->query("DROP INDEX {$index->Key_name} ON {$wpdb->postmeta}");
            error_log("WBS: Dropped index {$index->Key_name}");
        }

        delete_option('wbs_performance_indexes_added');
    }
}
