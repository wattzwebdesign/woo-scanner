<?php
if (!defined('ABSPATH')) {
    exit;
}

class WBS_Ajax {
    
    public function __construct() {
        add_action('wp_ajax_wbs_search_product', array($this, 'search_product'));
        add_action('wp_ajax_wbs_update_product', array($this, 'update_product'));
        add_action('wp_ajax_wbs_create_order', array($this, 'create_order'));
        add_action('wp_ajax_wbs_search_customers', array($this, 'search_customers'));
        add_action('wp_ajax_wbs_validate_coupon', array($this, 'validate_coupon'));
        add_action('wp_ajax_wbs_get_product_order', array($this, 'get_product_order'));
        add_action('wp_ajax_wbs_update_verification', array($this, 'update_verification'));
        add_action('wp_ajax_wbs_dismiss_pos_notice', array($this, 'dismiss_pos_notice'));
    }

    public function dismiss_pos_notice() {
        check_ajax_referer('wbs_dismiss_notice', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        update_user_meta(get_current_user_id(), 'wbs_pos_notice_dismissed', true);
        wp_send_json_success();
    }
    
    public function search_product() {
        check_ajax_referer('wbs_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        $search_term = sanitize_text_field($_POST['search_term']);
        $scan_context = isset($_POST['scan_context']) ? sanitize_text_field($_POST['scan_context']) : 'unknown';

        if (empty($search_term)) {
            wp_send_json_error('Search term is required');
        }

        // Check if this is a backlog QR code scan
        if (preg_match('/^BACKLOG:(\d+)$/', $search_term, $matches)) {
            $consignor_id = intval($matches[1]);
            $this->handle_backlog_scan($consignor_id, $scan_context);
            return;
        }

        $product = $this->find_product_by_sku($search_term);

        if (!$product) {
            // Log failed scan
            WBS_Audit_Logger::log_scan(array(
                'search_term' => $search_term,
                'scan_context' => $scan_context,
                'scan_success' => false,
            ));

            wp_send_json_error('Product not found');
        }

        $product_data = $this->get_product_data($product);

        // For POS context, check if product is out of stock and get order info
        if ($scan_context === 'pos' && $product_data['stock_status'] === 'outofstock') {
            $order_info = $this->get_product_order_info($product->get_id());
            if ($order_info) {
                $product_data['last_order_info'] = $order_info;
            }
        }

        // Log successful scan
        WBS_Audit_Logger::log_scan(array(
            'product_id' => $product->get_id(),
            'product_sku' => $product->get_sku(),
            'product_name' => $product->get_name(),
            'search_term' => $search_term,
            'scan_context' => $scan_context,
            'scan_success' => true,
        ));

        wp_send_json_success($product_data);
    }

    private function handle_backlog_scan($consignor_id, $scan_context) {
        global $wpdb;

        // Get consignor details from woo-consign database
        $consignor = $wpdb->get_row($wpdb->prepare(
            "SELECT id, consignor_number, name, commission_rate FROM {$wpdb->prefix}consignors WHERE id = %d",
            $consignor_id
        ));

        if (!$consignor) {
            WBS_Audit_Logger::log_scan(array(
                'search_term' => 'BACKLOG:' . $consignor_id,
                'scan_context' => $scan_context,
                'scan_success' => false,
            ));

            wp_send_json_error('Consignor not found');
        }

        // Log successful backlog scan
        WBS_Audit_Logger::log_scan(array(
            'search_term' => 'BACKLOG:' . $consignor_id,
            'scan_context' => $scan_context,
            'scan_success' => true,
            'product_name' => 'Backlog Item - Consignor #' . $consignor->consignor_number,
        ));

        // Return special response for backlog item
        $response_data = array(
            'is_backlog' => true,
            'consignor_id' => $consignor->id,
            'consignor_number' => $consignor->consignor_number,
            'consignor_name' => $consignor->name,
            'commission_rate' => $consignor->commission_rate
        );

        error_log('Backlog scan response: ' . print_r($response_data, true));

        wp_send_json_success($response_data);
    }
    
    public function update_product() {
        check_ajax_referer('wbs_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }
        
        $product_id = intval($_POST['product_id']);
        $product = wc_get_product($product_id);
        
        if (!$product) {
            wp_send_json_error('Product not found');
        }
        
        try {
            // Update product title
            if (isset($_POST['product_title']) && $_POST['product_title'] !== '') {
                $product_title = sanitize_text_field($_POST['product_title']);
                $product->set_name($product_title);
            }
            
            // Update basic product data (SKU is readonly, so skip it)
            if (isset($_POST['regular_price']) && $_POST['regular_price'] !== '') {
                $regular_price = sanitize_text_field($_POST['regular_price']);
                $product->set_regular_price($regular_price ?: '');
            }
            
            if (isset($_POST['sale_price'])) {
                $sale_price = sanitize_text_field($_POST['sale_price']);
                $product->set_sale_price($sale_price ?: '');
            }
            
            // Handle stock status - set it directly without complex logic
            if (isset($_POST['stock_status']) && $_POST['stock_status'] !== '') {
                $stock_status = sanitize_text_field($_POST['stock_status']);
                if (in_array($stock_status, ['instock', 'outofstock', 'onbackorder'])) {
                    $product->set_stock_status($stock_status);
                }
            }
            
            // Handle quantity separately 
            if (isset($_POST['quantity'])) {
                $quantity = sanitize_text_field($_POST['quantity']);
                if ($quantity !== '' && is_numeric($quantity)) {
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity(intval($quantity));
                } else {
                    $product->set_manage_stock(false);
                }
            }
            
            // Save the product first
            $product->save();
            
            // Update categories
            if (isset($_POST['categories']) && is_array($_POST['categories'])) {
                $categories = array_map('intval', $_POST['categories']);
                wp_set_post_terms($product_id, $categories, 'product_cat');
            }
            
            // Update product status
            if (isset($_POST['product_status'])) {
                $post_data = array(
                    'ID' => $product_id,
                    'post_status' => sanitize_text_field($_POST['product_status'])
                );
                wp_update_post($post_data);
            }
            
            // Clear any caches
            wc_delete_product_transients($product_id);

            // Clear WBS product data cache
            delete_transient('wbs_product_data_' . $product_id);

            // Clear SKU cache if SKU might have changed
            $sku = $product->get_sku();
            if ($sku) {
                delete_transient('wbs_sku_' . md5($sku));
            }

            // Clear old_sku cache
            $old_sku = get_post_meta($product_id, '_old_sku', true);
            if ($old_sku) {
                delete_transient('wbs_sku_' . md5($old_sku));
            }

            wp_send_json_success('Product updated successfully');
            
        } catch (Exception $e) {
            wp_send_json_error('Error updating product: ' . $e->getMessage());
        }
    }
    
    /**
     * Find product by SKU with caching
     *
     * Uses transient caching to avoid repeated database queries for the same SKU.
     * Cache TTL: 5 minutes
     *
     * @param string $search_term SKU or old SKU to search for
     * @return WC_Product|false Product object or false if not found
     */
    private function find_product_by_sku($search_term) {
        // Check transient cache first
        $cache_key = 'wbs_sku_' . md5($search_term);
        $cached_product_id = get_transient($cache_key);

        if ($cached_product_id !== false) {
            // Cache hit - return product or false for "not found" cache
            if ($cached_product_id === 0) {
                return false; // Cached "not found" result
            }
            return wc_get_product($cached_product_id);
        }

        // Cache miss - perform lookup
        // First try to find by regular SKU
        $product_id = wc_get_product_id_by_sku($search_term);

        if (!$product_id) {
            // If not found, try to find by old_sku custom field
            global $wpdb;

            $product_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                WHERE meta_key = '_old_sku'
                AND meta_value = %s
                LIMIT 1",
                $search_term
            ));
        }

        // Cache the result (including "not found" as 0)
        // 5 minute cache TTL
        set_transient($cache_key, $product_id ? (int) $product_id : 0, 5 * MINUTE_IN_SECONDS);

        if ($product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                return $product;
            }
        }

        return false;
    }
    
    /**
     * Static cache for consignor lookups within same request
     * @var array
     */
    private static $consignor_cache = array();

    /**
     * Get product data with caching and optimized queries
     *
     * Uses transient caching for full product data and batches meta queries
     * to reduce database load.
     *
     * @param WC_Product $product Product object
     * @return array Product data array
     */
    private function get_product_data($product) {
        $product_id = $product->get_id();

        // Check transient cache first
        $cache_key = 'wbs_product_data_' . $product_id;
        $cached_data = get_transient($cache_key);

        if ($cached_data !== false) {
            return $cached_data;
        }

        global $wpdb;

        // Batch fetch all custom meta in a single query instead of multiple get_post_meta calls
        $custom_meta = $this->get_multiple_post_meta($product_id, array('_consignor_id', '_old_sku', '_verified'));

        $consignor_id = $custom_meta['_consignor_id'] ?? '';
        $old_sku = $custom_meta['_old_sku'] ?? '';
        $verified = $custom_meta['_verified'] ?? 'not-on-the-floor';

        if (empty($verified)) {
            $verified = 'not-on-the-floor'; // Default value
        }

        // Get consignor number with static caching
        $consignor_number = '';
        if ($consignor_id) {
            $consignor_number = $this->get_consignor_number($consignor_id);
        }

        // Get product image URL
        $image_id = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : '';

        // Get categories
        $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));

        $data = array(
            'id' => $product_id,
            'title' => $product->get_name() ?: '',
            'sku' => $product->get_sku() ?: '',
            'old_sku' => $old_sku ?: '',
            'regular_price' => $product->get_regular_price() ?: '',
            'sale_price' => $product->get_sale_price() ?: '',
            'stock_status' => $product->get_stock_status() ?: 'instock',
            'stock_quantity' => $product->get_stock_quantity() ?: 0,
            'manage_stock' => $product->get_manage_stock() ?: false,
            'categories' => $categories ?: array(),
            'category_ids' => $categories ?: array(),
            'status' => get_post_status($product_id) ?: 'publish',
            'consignor_id' => $consignor_id ?: '',
            'consignor_number' => $consignor_number ?: '',
            'image_url' => $image_url ?: '',
            'verified' => $verified
        );

        // Cache the result for 5 minutes
        set_transient($cache_key, $data, 5 * MINUTE_IN_SECONDS);

        return $data;
    }

    /**
     * Batch fetch multiple meta keys in a single query
     *
     * @param int   $post_id Post ID
     * @param array $keys    Array of meta keys to fetch
     * @return array Associative array of meta_key => meta_value
     */
    private function get_multiple_post_meta($post_id, $keys) {
        global $wpdb;

        if (empty($keys)) {
            return array();
        }

        // Build placeholders for the IN clause
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));

        // Merge post_id with keys for prepare
        $query_args = array_merge(array($post_id), $keys);

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
                WHERE post_id = %d AND meta_key IN ($placeholders)",
                $query_args
            ),
            OBJECT_K
        );

        // Build return array with all requested keys
        $meta = array();
        foreach ($keys as $key) {
            $meta[$key] = isset($results[$key]) ? $results[$key]->meta_value : '';
        }

        return $meta;
    }

    /**
     * Get consignor number with static caching
     *
     * Caches consignor lookups within the same request to avoid
     * repeated queries for the same consignor.
     *
     * @param int $consignor_id Consignor ID
     * @return string Consignor number or empty string
     */
    private function get_consignor_number($consignor_id) {
        // Check static cache first
        if (isset(self::$consignor_cache[$consignor_id])) {
            return self::$consignor_cache[$consignor_id];
        }

        global $wpdb;
        $consignors_table = $wpdb->prefix . 'consignors';

        $consignor_number = $wpdb->get_var($wpdb->prepare(
            "SELECT consignor_number FROM $consignors_table WHERE id = %d",
            $consignor_id
        ));

        // Cache the result (even empty results)
        self::$consignor_cache[$consignor_id] = $consignor_number ?: '';

        return self::$consignor_cache[$consignor_id];
    }

    /**
     * Lightweight order lookup for POS stock check
     * Returns just order_number with caching - optimized for performance
     *
     * @param int $product_id Product ID
     * @return array|null Minimal order info or null
     */
    private function get_product_order_info($product_id) {
        // Check transient cache first
        $cache_key = 'wbs_product_order_' . $product_id;
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached === 'none' ? null : $cached;
        }

        global $wpdb;

        // Single optimized query to get order_id and order_number directly
        // Uses HPOS-compatible approach: check both post-based and HPOS orders
        $order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT order_items.order_id
            FROM {$wpdb->prefix}woocommerce_order_items as order_items
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta
                ON order_items.order_item_id = order_item_meta.order_item_id
            WHERE order_items.order_item_type = 'line_item'
            AND order_item_meta.meta_key = '_product_id'
            AND order_item_meta.meta_value = %d
            ORDER BY order_items.order_id DESC
            LIMIT 1",
            $product_id
        ));

        if (!$order_id) {
            // Cache "not found" to avoid repeated queries
            set_transient($cache_key, 'none', 5 * MINUTE_IN_SECONDS);
            return null;
        }

        // Get just the order number - avoid loading full WC_Order object
        $order_number = $order_id; // Default to order_id

        // Check if custom order number exists (some plugins use this)
        $custom_order_number = get_post_meta($order_id, '_order_number', true);
        if ($custom_order_number) {
            $order_number = $custom_order_number;
        }

        $result = array(
            'order_id' => $order_id,
            'order_number' => $order_number
        );

        // Cache for 5 minutes
        set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS);

        return $result;
    }

    /**
     * Full order info lookup (used by admin scanner)
     * Returns complete order details
     *
     * @param int $product_id Product ID
     * @return array|null Full order info or null
     */
    private function get_product_order_info_full($product_id) {
        global $wpdb;

        // Query order items to find orders containing this product
        $order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT order_items.order_id
            FROM {$wpdb->prefix}woocommerce_order_items as order_items
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta
                ON order_items.order_item_id = order_item_meta.order_item_id
            WHERE order_items.order_item_type = 'line_item'
            AND order_item_meta.meta_key = '_product_id'
            AND order_item_meta.meta_value = %d
            ORDER BY order_items.order_id DESC
            LIMIT 1",
            $product_id
        ));

        if (!$order_id) {
            return null;
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            return null;
        }

        // Get order items count
        $item_count = $order->get_item_count();

        // Get order total
        $order_total = $order->get_total();

        // Get payment method
        $payment_method = $order->get_payment_method_title() ?: 'N/A';

        // Get customer email
        $customer_email = $order->get_billing_email();

        // Get customer phone
        $customer_phone = $order->get_billing_phone();

        // Get shipping address
        $shipping_address = '';
        if ($order->has_shipping_address()) {
            $shipping_parts = array_filter(array(
                $order->get_shipping_address_1(),
                $order->get_shipping_city(),
                $order->get_shipping_state(),
                $order->get_shipping_postcode()
            ));
            $shipping_address = implode(', ', $shipping_parts);
        }

        return array(
            'order_id' => $order_id,
            'order_number' => $order->get_order_number(),
            'order_status' => $order->get_status(),
            'order_date' => $order->get_date_created()->date('Y-m-d H:i:s'),
            'order_edit_url' => admin_url('post.php?post=' . $order_id . '&action=edit'),
            'customer_name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) ?: $order->get_billing_email(),
            'customer_email' => $customer_email,
            'customer_phone' => $customer_phone ?: 'N/A',
            'item_count' => $item_count,
            'order_total' => wc_price($order_total),
            'payment_method' => $payment_method,
            'shipping_address' => $shipping_address ?: 'N/A'
        );
    }
    
    public function create_order() {
        check_ajax_referer('wbs_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }
        
        $order_data = $_POST['order_data'];

        if (empty($order_data['items']) || !is_array($order_data['items'])) {
            wp_send_json_error('No items provided for the order');
        }

        try {
            // Create the order
            $order = wc_create_order();
            
            if (is_wp_error($order)) {
                wp_send_json_error('Failed to create order: ' . $order->get_error_message());
            }
            
            // Track backlog items that need metadata added after saving
            $backlog_items = array();

            // Add items to the order
            foreach ($order_data['items'] as $item) {
                // Check for custom item (can be boolean true or string "true")
                if (!empty($item['is_custom']) && ($item['is_custom'] === true || $item['is_custom'] === 'true')) {
                    // Custom item - add as a fee using WC_Order_Item_Fee
                    $fee_amount = floatval($item['price']) * intval($item['quantity']);

                    // Create fee item object
                    $fee = new WC_Order_Item_Fee();
                    $fee->set_name(sanitize_text_field($item['name']));
                    $fee->set_amount($fee_amount);
                    $fee->set_total($fee_amount);
                    $fee->set_tax_status('none');

                    // Add fee to order
                    $order->add_item($fee);

                    // Track backlog metadata to add after saving (can be boolean true or string "true")
                    if (!empty($item['is_backlog']) && ($item['is_backlog'] === true || $item['is_backlog'] === 'true')) {
                        $backlog_items[] = array(
                            'name' => sanitize_text_field($item['name']),
                            'consignor_id' => intval($item['consignor_id']),
                            'consignor_number' => sanitize_text_field($item['consignor_number']),
                            'commission_rate' => floatval($item['commission_rate'])
                        );
                    }
                } else {
                    // Regular product
                    $product = wc_get_product($item['product_id']);

                    if (!$product) {
                        continue; // Skip invalid products
                    }

                    $order->add_product($product, $item['quantity']);
                }
            }
            
            // Set customer information if provided
            if (!empty($order_data['customer_email'])) {
                // Try to find existing user by email
                $user = get_user_by('email', sanitize_email($order_data['customer_email']));
                
                if ($user) {
                    $order->set_customer_id($user->ID);
                } else {
                    // Set as guest order with email
                    $order->set_billing_email(sanitize_email($order_data['customer_email']));
                }
            }
            
            // Set customer name if provided
            if (!empty($order_data['customer_name'])) {
                $name_parts = explode(' ', sanitize_text_field($order_data['customer_name']), 2);
                $first_name = $name_parts[0] ?? '';
                $last_name = $name_parts[1] ?? '';

                $order->set_billing_first_name($first_name);
                $order->set_billing_last_name($last_name);
                $order->set_shipping_first_name($first_name);
                $order->set_shipping_last_name($last_name);
            }

            // Add order notes
            if (!empty($order_data['order_notes'])) {
                $order->add_order_note(sanitize_textarea_field($order_data['order_notes']));
            }

            // CRITICAL: Calculate order total manually from line items and fees
            // DON'T use calculate_totals() as it recalculates from product prices
            $subtotal = 0;
            foreach ($order->get_items() as $item) {
                $subtotal += floatval($item->get_total());
            }
            foreach ($order->get_fees() as $fee) {
                $subtotal += floatval($fee->get_total());
            }

            // Set order total
            $order->set_total($subtotal);

            // IMPORTANT: Apply coupon AFTER setting initial total
            if (!empty($order_data['coupon_code'])) {
                $coupon_code = sanitize_text_field($order_data['coupon_code']);
                $coupon = new WC_Coupon($coupon_code);

                if ($coupon && $coupon->get_id() && $coupon->is_valid()) {
                    $order->apply_coupon($coupon_code);
                    // After applying coupon, recalculate total (coupon discount will be applied)
                    $order->calculate_totals();
                }
            }

            // Save the order in 'pending' status first
            $order->set_status('pending');
            $order->save();

            // Add backlog metadata to fees after saving
            if (!empty($backlog_items)) {
                $fees = $order->get_fees();
                $fee_index = 0;

                foreach ($fees as $fee) {
                    // Match fee to backlog item by name
                    $fee_name = $fee->get_name();

                    foreach ($backlog_items as $backlog) {
                        if ($backlog['name'] === $fee_name) {
                            $fee->add_meta_data('_is_backlog_item', 'yes', true);
                            $fee->add_meta_data('_consignor_id', $backlog['consignor_id'], true);
                            $fee->add_meta_data('_consignor_number', $backlog['consignor_number'], true);
                            $fee->add_meta_data('_commission_rate', $backlog['commission_rate'], true);
                            $fee->save();
                            break;
                        }
                    }
                }
            }

            // Get order details
            $order_id = $order->get_id();
            $order_number = $order->get_order_number();
            $order_total = $order->get_total();

            // Set final status if specified
            $target_status = !empty($order_data['order_status']) ? sanitize_text_field($order_data['order_status']) : 'completed';

            if ($target_status !== 'pending') {
                $order->set_status($target_status);
                $order->save();
            }

            // Link scanned products to this order for audit trail
            $product_ids = array();
            foreach ($order_data['items'] as $item) {
                if (!empty($item['product_id']) && empty($item['is_custom'])) {
                    $product_ids[] = $item['product_id'];
                }
            }

            if (!empty($product_ids)) {
                WBS_Audit_Logger::link_scans_to_order($order->get_id(), $product_ids);
            }

            wp_send_json_success(array(
                'order_id' => $order_id,
                'order_number' => $order_number,
                'total' => $order_total
            ));
            
        } catch (Exception $e) {
            wp_send_json_error('Error creating order: ' . $e->getMessage());
        }
    }
    
    public function search_customers() {
        check_ajax_referer('wbs_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }
        
        $search_term = sanitize_text_field($_POST['search_term']);
        
        if (empty($search_term) || strlen($search_term) < 3) {
            wp_send_json_error('Search term too short');
        }
        
        // Search for users by email
        $users = get_users(array(
            'search' => '*' . $search_term . '*',
            'search_columns' => array('user_email'),
            'number' => 10 // Limit to 10 results
        ));
        
        $customers = array();
        
        foreach ($users as $user) {
            // Get customer data
            $customer = new WC_Customer($user->ID);
            
            $customers[] = array(
                'id' => $user->ID,
                'email' => $user->user_email,
                'first_name' => $customer->get_first_name(),
                'last_name' => $customer->get_last_name(),
                'display_name' => trim($customer->get_first_name() . ' ' . $customer->get_last_name()) ?: $user->display_name
            );
        }
        
        wp_send_json_success($customers);
    }

    public function validate_coupon() {
        check_ajax_referer('wbs_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        $coupon_code = sanitize_text_field($_POST['coupon_code']);

        if (empty($coupon_code)) {
            wp_send_json_error('Coupon code is required');
        }

        // Get coupon by code
        $coupon = new WC_Coupon($coupon_code);

        if (!$coupon || !$coupon->get_id()) {
            wp_send_json_error('Invalid coupon code');
        }

        // Check if coupon is valid
        if (!$coupon->is_valid()) {
            $error_message = 'This coupon is not valid';

            // Get more specific error message
            if ($coupon->get_date_expires() && time() > $coupon->get_date_expires()->getTimestamp()) {
                $error_message = 'This coupon has expired';
            } elseif ($coupon->get_usage_limit() && $coupon->get_usage_count() >= $coupon->get_usage_limit()) {
                $error_message = 'This coupon has reached its usage limit';
            }

            wp_send_json_error($error_message);
        }

        // Get coupon restrictions
        $product_ids = $coupon->get_product_ids();
        $product_categories = $coupon->get_product_categories();
        $excluded_product_ids = $coupon->get_excluded_product_ids();
        $excluded_product_categories = $coupon->get_excluded_product_categories();

        // Return coupon data
        $coupon_data = array(
            'code' => $coupon->get_code(),
            'discount_type' => $coupon->get_discount_type(),
            'amount' => $coupon->get_amount(),
            'description' => $coupon->get_description(),
            'product_ids' => $product_ids,
            'product_categories' => $product_categories,
            'excluded_product_ids' => $excluded_product_ids,
            'excluded_product_categories' => $excluded_product_categories
        );

        wp_send_json_success($coupon_data);
    }

    public function get_product_order() {
        check_ajax_referer('wbs_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        $product_id = intval($_POST['product_id']);

        if (!$product_id) {
            wp_send_json_error('Product ID is required');
        }

        // Use full version for admin scanner (needs all order details)
        $order_info = $this->get_product_order_info_full($product_id);

        if ($order_info) {
            wp_send_json_success($order_info);
        } else {
            wp_send_json_error('No order found for this product');
        }
    }

    public function update_verification() {
        check_ajax_referer('wbs_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        $product_id = intval($_POST['product_id']);

        if (!$product_id) {
            wp_send_json_error('Product ID is required');
        }

        $product = wc_get_product($product_id);

        if (!$product) {
            wp_send_json_error('Product not found');
        }

        // Only allow verification updates for in-stock products
        if ($product->get_stock_status() !== 'instock') {
            wp_send_json_error('Can only verify products that are in stock');
        }

        try {
            // Update verification status to "on-the-floor"
            update_post_meta($product_id, '_verified', 'on-the-floor');

            // Clear WBS product data cache since verified status changed
            delete_transient('wbs_product_data_' . $product_id);

            wp_send_json_success(array(
                'message' => 'Product marked as On the Floor',
                'verified' => 'on-the-floor'
            ));

        } catch (Exception $e) {
            wp_send_json_error('Error updating verification: ' . $e->getMessage());
        }
    }
}

new WBS_Ajax();