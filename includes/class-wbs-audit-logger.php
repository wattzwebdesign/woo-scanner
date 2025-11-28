<?php
/**
 * WooCommerce Barcode Scanner - Audit Logger
 *
 * Handles logging of scan events and order associations
 *
 * @package WooCommerceBarcodeScanner
 * @since 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WBS_Audit_Logger {

    /**
     * Pending log entries to be flushed on shutdown
     * @var array
     */
    private static $pending_logs = array();

    /**
     * Whether the shutdown hook has been registered
     * @var bool
     */
    private static $shutdown_registered = false;

    /**
     * Log a product scan event (async - writes on shutdown)
     *
     * This method queues the log entry to be written after the response is sent,
     * reducing response time for barcode scans.
     *
     * @param array $args {
     *     @type int    $user_id       User ID performing the scan
     *     @type int    $product_id    Product ID (if found)
     *     @type string $product_sku   Product SKU (if found)
     *     @type string $product_name  Product name (if found)
     *     @type string $scan_context  Context: 'main_scanner', 'pos', 'verification', 'create_order'
     *     @type string $search_term   The search term/barcode scanned
     *     @type bool   $scan_success  Whether the scan found a product
     * }
     * @return bool True if queued successfully
     */
    public static function log_scan($args) {
        // Ensure tables exist before queueing
        if (!WBS_Audit_DB::tables_exist()) {
            return false;
        }

        $defaults = array(
            'user_id' => get_current_user_id(),
            'product_id' => null,
            'product_sku' => '',
            'product_name' => '',
            'scan_context' => 'unknown',
            'search_term' => '',
            'scan_success' => true,
        );

        $args = wp_parse_args($args, $defaults);

        // Get user display name now (while user data is available)
        $user = get_userdata($args['user_id']);
        $args['user_display_name'] = $user ? $user->display_name : 'Unknown User';

        // Capture created_at timestamp now
        $args['created_at'] = current_time('mysql');

        // Queue the log entry
        self::$pending_logs[] = $args;

        // Register shutdown hook only once
        if (!self::$shutdown_registered) {
            add_action('shutdown', array(__CLASS__, 'flush_pending_logs'), 0);
            self::$shutdown_registered = true;
        }

        return true;
    }

    /**
     * Flush all pending log entries to the database
     * Called on shutdown after response is sent
     */
    public static function flush_pending_logs() {
        if (empty(self::$pending_logs)) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wbs_scan_audits';

        foreach (self::$pending_logs as $args) {
            $user_id = absint($args['user_id']);
            $product_id = !empty($args['product_id']) ? absint($args['product_id']) : null;
            $product_sku = sanitize_text_field($args['product_sku']);
            $product_name = sanitize_text_field($args['product_name']);
            $scan_context = sanitize_text_field($args['scan_context']);
            $search_term = sanitize_text_field($args['search_term']);
            $scan_success = (int) $args['scan_success'];
            $user_display_name = $args['user_display_name'];
            $created_at = $args['created_at'];

            // Use direct query to handle NULL properly
            if ($product_id === null) {
                $query = $wpdb->prepare(
                    "INSERT INTO {$table}
                    (user_id, user_display_name, product_id, product_sku, product_name, scan_context, search_term, scan_success, created_at)
                    VALUES (%d, %s, NULL, %s, %s, %s, %s, %d, %s)",
                    $user_id,
                    $user_display_name,
                    $product_sku,
                    $product_name,
                    $scan_context,
                    $search_term,
                    $scan_success,
                    $created_at
                );
            } else {
                $query = $wpdb->prepare(
                    "INSERT INTO {$table}
                    (user_id, user_display_name, product_id, product_sku, product_name, scan_context, search_term, scan_success, created_at)
                    VALUES (%d, %s, %d, %s, %s, %s, %s, %d, %s)",
                    $user_id,
                    $user_display_name,
                    $product_id,
                    $product_sku,
                    $product_name,
                    $scan_context,
                    $search_term,
                    $scan_success,
                    $created_at
                );
            }

            $wpdb->query($query);
        }

        // Clear the queue
        self::$pending_logs = array();
    }

    /**
     * Log a scan synchronously (for critical logging that must complete before response)
     *
     * @param array $args Same as log_scan()
     * @return int|false The scan audit ID on success, false on failure
     */
    public static function log_scan_sync($args) {
        global $wpdb;

        // Ensure tables exist
        if (!WBS_Audit_DB::tables_exist()) {
            return false;
        }

        $defaults = array(
            'user_id' => get_current_user_id(),
            'product_id' => null,
            'product_sku' => '',
            'product_name' => '',
            'scan_context' => 'unknown',
            'search_term' => '',
            'scan_success' => true,
        );

        $args = wp_parse_args($args, $defaults);

        // Get user display name
        $user = get_userdata($args['user_id']);
        $user_display_name = $user ? $user->display_name : 'Unknown User';

        // Insert scan audit record
        $table = $wpdb->prefix . 'wbs_scan_audits';

        // Prepare data - use direct query for NULL handling
        $user_id = absint($args['user_id']);
        $product_id = !empty($args['product_id']) ? absint($args['product_id']) : null;
        $product_sku = sanitize_text_field($args['product_sku']);
        $product_name = sanitize_text_field($args['product_name']);
        $scan_context = sanitize_text_field($args['scan_context']);
        $search_term = sanitize_text_field($args['search_term']);
        $scan_success = (int) $args['scan_success'];
        $created_at = current_time('mysql');

        // Use direct query to handle NULL properly
        if ($product_id === null) {
            $query = $wpdb->prepare(
                "INSERT INTO {$table}
                (user_id, user_display_name, product_id, product_sku, product_name, scan_context, search_term, scan_success, created_at)
                VALUES (%d, %s, NULL, %s, %s, %s, %s, %d, %s)",
                $user_id,
                $user_display_name,
                $product_sku,
                $product_name,
                $scan_context,
                $search_term,
                $scan_success,
                $created_at
            );
        } else {
            $query = $wpdb->prepare(
                "INSERT INTO {$table}
                (user_id, user_display_name, product_id, product_sku, product_name, scan_context, search_term, scan_success, created_at)
                VALUES (%d, %s, %d, %s, %s, %s, %s, %d, %s)",
                $user_id,
                $user_display_name,
                $product_id,
                $product_sku,
                $product_name,
                $scan_context,
                $search_term,
                $scan_success,
                $created_at
            );
        }

        $result = $wpdb->query($query);

        if ($result === false) {
            error_log('WBS Audit: Failed to log scan. Error: ' . $wpdb->last_error);
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Link scanned products to an order after checkout
     *
     * @param int   $order_id       WooCommerce order ID
     * @param array $product_ids    Array of product IDs in the order
     * @param int   $user_id        User who created the order
     * @return bool Success status
     */
    public static function link_scans_to_order($order_id, $product_ids, $user_id = null) {
        global $wpdb;

        if (!WBS_Audit_DB::tables_exist()) {
            return false;
        }

        if (empty($product_ids)) {
            return true; // Nothing to link
        }

        $user_id = $user_id ?: get_current_user_id();
        $order_id = absint($order_id);

        $scan_audits_table = $wpdb->prefix . 'wbs_scan_audits';
        $order_scans_table = $wpdb->prefix . 'wbs_order_scans';

        // Find recent scans by this user for these products
        // We'll look for scans in the last 10 minutes to be safe
        $ten_minutes_ago = date('Y-m-d H:i:s', strtotime('-10 minutes'));

        $product_ids_str = implode(',', array_map('absint', $product_ids));

        // Get scan audit IDs for these products
        $scan_ids = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT id, product_id
                FROM {$scan_audits_table}
                WHERE user_id = %d
                AND product_id IN ({$product_ids_str})
                AND created_at >= %s
                ORDER BY created_at DESC",
                $user_id,
                $ten_minutes_ago
            )
        );

        if (empty($scan_ids)) {
            return true; // No recent scans to link
        }

        $created_at = current_time('mysql');

        // Insert order scan links
        foreach ($scan_ids as $scan) {
            $wpdb->insert(
                $order_scans_table,
                array(
                    'order_id' => $order_id,
                    'scan_audit_id' => $scan->id,
                    'product_id' => $scan->product_id,
                    'created_at' => $created_at,
                ),
                array('%d', '%d', '%d', '%s')
            );
        }

        return true;
    }

    /**
     * Retroactively link existing orders to scans
     *
     * This function processes existing WooCommerce orders and links them
     * to scan records based on matching products, user, and time proximity.
     *
     * @param int $days_back How many days back to process (default 90)
     * @return array Statistics about the linking process
     */
    public static function retroactively_link_orders($days_back = 90) {
        global $wpdb;

        if (!WBS_Audit_DB::tables_exist()) {
            return array('error' => 'Audit tables do not exist');
        }

        $scan_audits_table = $wpdb->prefix . 'wbs_scan_audits';
        $order_scans_table = $wpdb->prefix . 'wbs_order_scans';

        $stats = array(
            'orders_processed' => 0,
            'scans_linked' => 0,
            'orders_with_links' => 0,
        );

        // Get orders from the last X days
        $date_from = date('Y-m-d H:i:s', strtotime("-{$days_back} days"));

        $orders = wc_get_orders(array(
            'limit' => -1,
            'date_created' => '>=' . $date_from,
            'return' => 'ids',
        ));

        foreach ($orders as $order_id) {
            $stats['orders_processed']++;
            $order = wc_get_order($order_id);

            if (!$order) {
                continue;
            }

            // Get order date and user
            $order_date = $order->get_date_created();
            if (!$order_date) {
                continue;
            }

            $order_timestamp = $order_date->getTimestamp();
            $user_id = $order->get_customer_id();

            if (!$user_id) {
                // Try to find user by email for guest orders
                $email = $order->get_billing_email();
                if ($email) {
                    $user = get_user_by('email', $email);
                    if ($user) {
                        $user_id = $user->ID;
                    }
                }
            }

            if (!$user_id) {
                continue; // Can't link without a user
            }

            // Get product IDs from order
            $product_ids = array();
            foreach ($order->get_items() as $item) {
                $product_id = $item->get_product_id();
                if ($product_id) {
                    $product_ids[] = $product_id;
                }
            }

            if (empty($product_ids)) {
                continue;
            }

            // Find scans that match:
            // 1. Same user
            // 2. Same products
            // 3. Within 30 minutes BEFORE the order was created
            $time_window_start = date('Y-m-d H:i:s', $order_timestamp - (30 * 60)); // 30 min before
            $time_window_end = date('Y-m-d H:i:s', $order_timestamp);

            $product_ids_str = implode(',', array_map('absint', $product_ids));

            // Get scan audit IDs for these products in the time window
            $scan_ids = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT sa.id, sa.product_id
                    FROM {$scan_audits_table} sa
                    LEFT JOIN {$order_scans_table} os ON sa.id = os.scan_audit_id
                    WHERE sa.user_id = %d
                    AND sa.product_id IN ({$product_ids_str})
                    AND sa.created_at >= %s
                    AND sa.created_at <= %s
                    AND sa.scan_success = 1
                    AND os.id IS NULL
                    ORDER BY sa.created_at DESC",
                    $user_id,
                    $time_window_start,
                    $time_window_end
                )
            );

            if (!empty($scan_ids)) {
                $linked_count = 0;
                $created_at = current_time('mysql');

                foreach ($scan_ids as $scan) {
                    $result = $wpdb->insert(
                        $order_scans_table,
                        array(
                            'order_id' => $order_id,
                            'scan_audit_id' => $scan->id,
                            'product_id' => $scan->product_id,
                            'created_at' => $created_at,
                        ),
                        array('%d', '%d', '%d', '%s')
                    );

                    if ($result !== false) {
                        $linked_count++;
                        $stats['scans_linked']++;
                    }
                }

                if ($linked_count > 0) {
                    $stats['orders_with_links']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Get scan audits with optional filters
     *
     * @param array $args {
     *     @type int    $page          Page number for pagination
     *     @type int    $per_page      Items per page
     *     @type string $date_from     Start date (Y-m-d format)
     *     @type string $date_to       End date (Y-m-d format)
     *     @type int    $user_id       Filter by user ID
     *     @type string $scan_context  Filter by scan context
     *     @type string $search        Search in product name/SKU
     *     @type string $order_by      Order by column
     *     @type string $order         Order direction (ASC/DESC)
     * }
     * @return array Array of scan audit records
     */
    public static function get_scan_audits($args = array()) {
        global $wpdb;

        if (!WBS_Audit_DB::tables_exist()) {
            return array();
        }

        $defaults = array(
            'page' => 1,
            'per_page' => 50,
            'date_from' => '',
            'date_to' => '',
            'user_id' => 0,
            'scan_context' => '',
            'search' => '',
            'order_by' => 'created_at',
            'order' => 'DESC',
        );

        $args = wp_parse_args($args, $defaults);

        $scan_audits_table = $wpdb->prefix . 'wbs_scan_audits';
        $order_scans_table = $wpdb->prefix . 'wbs_order_scans';

        // Build WHERE clause
        $where = array('1=1');
        $where_values = array();

        if (!empty($args['date_from'])) {
            $where[] = 'sa.created_at >= %s';
            $where_values[] = $args['date_from'] . ' 00:00:00';
        }

        if (!empty($args['date_to'])) {
            $where[] = 'sa.created_at <= %s';
            $where_values[] = $args['date_to'] . ' 23:59:59';
        }

        if (!empty($args['user_id'])) {
            $where[] = 'sa.user_id = %d';
            $where_values[] = $args['user_id'];
        }

        if (!empty($args['scan_context'])) {
            $where[] = 'sa.scan_context = %s';
            $where_values[] = $args['scan_context'];
        }

        if (!empty($args['search'])) {
            $where[] = '(sa.product_name LIKE %s OR sa.product_sku LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }

        $where_clause = implode(' AND ', $where);

        // Sanitize order by and order
        $allowed_order_by = array('created_at', 'user_display_name', 'product_name', 'product_sku', 'scan_context');
        $order_by = in_array($args['order_by'], $allowed_order_by) ? $args['order_by'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Calculate offset
        $offset = ($args['page'] - 1) * $args['per_page'];

        // Build query
        $query = "SELECT sa.*,
                  GROUP_CONCAT(DISTINCT os.order_id) as order_ids
                  FROM {$scan_audits_table} sa
                  LEFT JOIN {$order_scans_table} os ON sa.id = os.scan_audit_id
                  WHERE {$where_clause}
                  GROUP BY sa.id
                  ORDER BY sa.{$order_by} {$order}
                  LIMIT %d OFFSET %d";

        $where_values[] = $args['per_page'];
        $where_values[] = $offset;

        if (!empty($where_values)) {
            $query = $wpdb->prepare($query, $where_values);
        }

        return $wpdb->get_results($query);
    }

    /**
     * Get total count of scan audits with filters
     *
     * @param array $args Same filters as get_scan_audits
     * @return int Total count
     */
    public static function get_scan_audits_count($args = array()) {
        global $wpdb;

        if (!WBS_Audit_DB::tables_exist()) {
            return 0;
        }

        $defaults = array(
            'date_from' => '',
            'date_to' => '',
            'user_id' => 0,
            'scan_context' => '',
            'search' => '',
        );

        $args = wp_parse_args($args, $defaults);

        $scan_audits_table = $wpdb->prefix . 'wbs_scan_audits';

        // Build WHERE clause (same as get_scan_audits)
        $where = array('1=1');
        $where_values = array();

        if (!empty($args['date_from'])) {
            $where[] = 'created_at >= %s';
            $where_values[] = $args['date_from'] . ' 00:00:00';
        }

        if (!empty($args['date_to'])) {
            $where[] = 'created_at <= %s';
            $where_values[] = $args['date_to'] . ' 23:59:59';
        }

        if (!empty($args['user_id'])) {
            $where[] = 'user_id = %d';
            $where_values[] = $args['user_id'];
        }

        if (!empty($args['scan_context'])) {
            $where[] = 'scan_context = %s';
            $where_values[] = $args['scan_context'];
        }

        if (!empty($args['search'])) {
            $where[] = '(product_name LIKE %s OR product_sku LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }

        $where_clause = implode(' AND ', $where);

        $query = "SELECT COUNT(*) FROM {$scan_audits_table} WHERE {$where_clause}";

        if (!empty($where_values)) {
            $query = $wpdb->prepare($query, $where_values);
        }

        return (int) $wpdb->get_var($query);
    }
}
