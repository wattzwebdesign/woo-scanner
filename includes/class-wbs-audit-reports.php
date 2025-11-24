<?php
/**
 * WooCommerce Barcode Scanner - Audit Reports
 *
 * Handles the Scan Audit admin page and reporting interface
 *
 * @package WooCommerceBarcodeScanner
 * @since 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WBS_Audit_Reports {

    /**
     * Initialize the audit reports page
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_admin_page'), 20);
    }

    /**
     * Add Scan Audit submenu page
     */
    public static function add_admin_page() {
        add_submenu_page(
            'woo-barcode-scanner',
            'Scan Audit',
            'Scan Audit',
            'manage_woocommerce',
            'wbs-scan-audit',
            array(__CLASS__, 'render_audit_page')
        );
    }

    /**
     * Render the audit reports page
     */
    public static function render_audit_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        // Get filter values
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        $user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        $scan_context = isset($_GET['scan_context']) ? sanitize_text_field($_GET['scan_context']) : '';
        $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        $current_page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        $per_page = 50;

        // Build filter args
        $filter_args = array(
            'page' => $current_page,
            'per_page' => $per_page,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'user_id' => $user_id,
            'scan_context' => $scan_context,
            'search' => $search,
        );

        // Get scan audits
        $scan_audits = WBS_Audit_Logger::get_scan_audits($filter_args);
        $total_items = WBS_Audit_Logger::get_scan_audits_count($filter_args);
        $total_pages = ceil($total_items / $per_page);

        // Get all users who have performed scans for the filter dropdown
        global $wpdb;
        $scan_audits_table = $wpdb->prefix . 'wbs_scan_audits';
        $users = array();

        if (WBS_Audit_DB::tables_exist()) {
            $users = $wpdb->get_results(
                "SELECT DISTINCT user_id, user_display_name
                FROM {$scan_audits_table}
                ORDER BY user_display_name ASC"
            );
        }

        ?>
        <div class="wrap">
            <h1>Scan Audit</h1>
            <p>Track every product scan and see which products were scanned into orders.</p>

            <!-- Filters -->
            <div class="wbs-audit-filters" style="background: #fff; padding: 15px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <form method="get" action="">
                    <input type="hidden" name="page" value="wbs-scan-audit">

                    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                        <!-- Date Range -->
                        <div>
                            <label for="date_from" style="display: block; margin-bottom: 5px; font-weight: 600;">From Date</label>
                            <input type="date" id="date_from" name="date_from" value="<?php echo esc_attr($date_from); ?>" style="padding: 5px;">
                        </div>

                        <div>
                            <label for="date_to" style="display: block; margin-bottom: 5px; font-weight: 600;">To Date</label>
                            <input type="date" id="date_to" name="date_to" value="<?php echo esc_attr($date_to); ?>" style="padding: 5px;">
                        </div>

                        <!-- User Filter -->
                        <div>
                            <label for="user_id" style="display: block; margin-bottom: 5px; font-weight: 600;">User</label>
                            <select id="user_id" name="user_id" style="padding: 5px;">
                                <option value="">All Users</option>
                                <?php foreach ($users as $user) : ?>
                                    <option value="<?php echo esc_attr($user->user_id); ?>" <?php selected($user_id, $user->user_id); ?>>
                                        <?php echo esc_html($user->user_display_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Scan Context Filter -->
                        <div>
                            <label for="scan_context" style="display: block; margin-bottom: 5px; font-weight: 600;">Scan Location</label>
                            <select id="scan_context" name="scan_context" style="padding: 5px;">
                                <option value="">All Locations</option>
                                <option value="main_scanner" <?php selected($scan_context, 'main_scanner'); ?>>Barcode Scanner</option>
                                <option value="pos" <?php selected($scan_context, 'pos'); ?>>Point of Sale</option>
                                <option value="verification" <?php selected($scan_context, 'verification'); ?>>Verification</option>
                                <option value="create_order" <?php selected($scan_context, 'create_order'); ?>>Create Order</option>
                            </select>
                        </div>

                        <!-- Product Search -->
                        <div>
                            <label for="search" style="display: block; margin-bottom: 5px; font-weight: 600;">Search Product</label>
                            <input type="text" id="search" name="search" value="<?php echo esc_attr($search); ?>" placeholder="SKU or name..." style="padding: 5px; width: 200px;">
                        </div>

                        <!-- Buttons -->
                        <div style="display: flex; gap: 5px;">
                            <button type="submit" class="button button-primary">Filter</button>
                            <a href="?page=wbs-scan-audit" class="button">Reset</a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Results Summary -->
            <div style="margin: 15px 0;">
                <strong>Total Scans: <?php echo number_format($total_items); ?></strong>
                <?php if ($current_page > 1 || $total_pages > 1) : ?>
                    | Showing page <?php echo $current_page; ?> of <?php echo $total_pages; ?>
                <?php endif; ?>
            </div>

            <!-- Audit Table -->
            <?php if (empty($scan_audits)) : ?>
                <div class="notice notice-info" style="padding: 15px;">
                    <p>No scan records found. <?php echo !WBS_Audit_DB::tables_exist() ? 'The audit tables may not be set up yet. Try deactivating and reactivating the plugin.' : ''; ?></p>
                </div>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 140px;">Date/Time</th>
                            <th style="width: 120px;">User</th>
                            <th style="width: 100px;">SKU</th>
                            <th>Product Name</th>
                            <th style="width: 120px;">Scan Location</th>
                            <th style="width: 100px;">Order ID</th>
                            <th style="width: 80px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scan_audits as $audit) : ?>
                            <tr>
                                <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($audit->created_at))); ?></td>
                                <td><?php echo esc_html($audit->user_display_name); ?></td>
                                <td>
                                    <?php if ($audit->product_id) : ?>
                                        <a href="<?php echo esc_url(admin_url('post.php?post=' . $audit->product_id . '&action=edit')); ?>" target="_blank">
                                            <?php echo esc_html($audit->product_sku ?: 'N/A'); ?>
                                        </a>
                                    <?php else : ?>
                                        <?php echo esc_html($audit->search_term ?: 'N/A'); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($audit->product_name ?: 'Not found'); ?></td>
                                <td>
                                    <?php
                                    $context_labels = array(
                                        'main_scanner' => 'Barcode Scanner',
                                        'pos' => 'Point of Sale',
                                        'verification' => 'Verification',
                                        'create_order' => 'Create Order',
                                    );
                                    echo esc_html($context_labels[$audit->scan_context] ?? $audit->scan_context);
                                    ?>
                                </td>
                                <td>
                                    <?php if (!empty($audit->order_ids)) : ?>
                                        <?php
                                        $order_ids = explode(',', $audit->order_ids);
                                        foreach ($order_ids as $order_id) :
                                        ?>
                                            <a href="<?php echo esc_url(admin_url('post.php?post=' . $order_id . '&action=edit')); ?>" target="_blank" style="display: block;">
                                                #<?php echo esc_html($order_id); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <span style="color: #999;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($audit->scan_success) : ?>
                                        <span style="color: #46b450;">✓ Found</span>
                                    <?php else : ?>
                                        <span style="color: #dc3232;">✗ Failed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ($total_pages > 1) : ?>
                    <div class="tablenav" style="margin: 20px 0;">
                        <div class="tablenav-pages">
                            <?php
                            $base_url = add_query_arg(array(
                                'page' => 'wbs-scan-audit',
                                'date_from' => $date_from,
                                'date_to' => $date_to,
                                'user_id' => $user_id,
                                'scan_context' => $scan_context,
                                'search' => $search,
                            ), admin_url('admin.php'));

                            echo paginate_links(array(
                                'base' => add_query_arg('paged', '%#%', $base_url),
                                'format' => '',
                                'prev_text' => '&laquo; Previous',
                                'next_text' => 'Next &raquo;',
                                'total' => $total_pages,
                                'current' => $current_page,
                            ));
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <style>
            .wbs-audit-filters label {
                font-size: 12px;
            }
            .wbs-audit-filters input,
            .wbs-audit-filters select {
                font-size: 13px;
            }
        </style>
        <?php
    }
}

// Initialize the audit reports
WBS_Audit_Reports::init();
