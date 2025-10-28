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

        // Register async order completion hook
        add_action('wbs_complete_order_async', array($this, 'complete_order_async'), 10, 2);
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
        
        if (empty($search_term)) {
            wp_send_json_error('Search term is required');
        }
        
        $product = $this->find_product_by_sku($search_term);
        
        if (!$product) {
            wp_send_json_error('Product not found');
        }
        
        $product_data = $this->get_product_data($product);
        wp_send_json_success($product_data);
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
            
            wp_send_json_success('Product updated successfully');
            
        } catch (Exception $e) {
            wp_send_json_error('Error updating product: ' . $e->getMessage());
        }
    }
    
    private function find_product_by_sku($search_term) {
        // First try to find by regular SKU
        $product_id = wc_get_product_id_by_sku($search_term);

        if ($product_id) {
            return wc_get_product($product_id);
        }

        // If not found, try to find by old_sku custom field
        global $wpdb;

        $product_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_old_sku'
            AND meta_value = %s
            LIMIT 1",
            $search_term
        ));

        if ($product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                return $product;
            }
        }

        return false;
    }
    
    private function get_product_data($product) {
        global $wpdb;

        $product_id = $product->get_id();
        $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));

        // Get consignor information
        $consignor_id = get_post_meta($product_id, '_consignor_id', true);
        $consignor_number = '';

        if ($consignor_id) {
            $consignors_table = $wpdb->prefix . 'consignors';
            $consignor = $wpdb->get_row($wpdb->prepare(
                "SELECT consignor_number FROM $consignors_table WHERE id = %d",
                $consignor_id
            ));

            if ($consignor) {
                $consignor_number = $consignor->consignor_number;
            }
        }

        // Get product image
        $image_id = $product->get_image_id();
        $image_url = '';

        if ($image_id) {
            $image_url = wp_get_attachment_image_url($image_id, 'medium');
        }

        // Get old_sku custom field
        $old_sku = get_post_meta($product_id, '_old_sku', true);

        // Get verification status
        $verified = get_post_meta($product_id, '_verified', true);
        if (empty($verified)) {
            $verified = 'not-on-the-floor'; // Default value
        }

        return array(
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
    }

    private function get_product_order_info($product_id) {
        global $wpdb;

        // Query order items to find orders containing this product
        $order_item_id = $wpdb->get_var($wpdb->prepare(
            "SELECT order_items.order_id
            FROM {$wpdb->prefix}woocommerce_order_items as order_items
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
            WHERE order_items.order_item_type = 'line_item'
            AND order_item_meta.meta_key = '_product_id'
            AND order_item_meta.meta_value = %d
            ORDER BY order_items.order_id DESC
            LIMIT 1",
            $product_id
        ));

        if (!$order_item_id) {
            return null;
        }

        $order = wc_get_order($order_item_id);

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
            'order_id' => $order_item_id,
            'order_number' => $order->get_order_number(),
            'order_status' => $order->get_status(),
            'order_date' => $order->get_date_created()->date('Y-m-d H:i:s'),
            'order_edit_url' => admin_url('post.php?post=' . $order_item_id . '&action=edit'),
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
            // OPTIMIZATION: Batch load all products at once
            $product_ids = array();
            foreach ($order_data['items'] as $item) {
                if (empty($item['is_custom']) && !empty($item['product_id'])) {
                    $product_ids[] = intval($item['product_id']);
                }
            }

            // Pre-load all products in one query
            $products = array();
            if (!empty($product_ids)) {
                foreach ($product_ids as $product_id) {
                    $products[$product_id] = wc_get_product($product_id);
                }
            }

            // Create the order
            $order = wc_create_order();

            if (is_wp_error($order)) {
                wp_send_json_error('Failed to create order: ' . $order->get_error_message());
            }

            // Add items to the order (using pre-loaded products)
            foreach ($order_data['items'] as $item) {
                // Check if this is a custom item
                if (!empty($item['is_custom']) && $item['is_custom'] === true) {
                    // Add custom item as a fee
                    $order->add_fee(array(
                        'name' => sanitize_text_field($item['name']),
                        'amount' => floatval($item['price']) * intval($item['quantity']),
                        'taxable' => false,
                        'tax_class' => ''
                    ));
                } else {
                    // Regular product - use pre-loaded product
                    $product_id = intval($item['product_id']);
                    $product = isset($products[$product_id]) ? $products[$product_id] : null;

                    if (!$product) {
                        continue; // Skip invalid products
                    }

                    $order->add_product($product, $item['quantity']);
                }
            }

            // OPTIMIZATION: Only lookup customer if email provided
            if (!empty($order_data['customer_email'])) {
                $email = sanitize_email($order_data['customer_email']);
                // Quick user lookup
                $user = get_user_by('email', $email);

                if ($user) {
                    $order->set_customer_id($user->ID);
                } else {
                    $order->set_billing_email($email);
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

            // IMPORTANT: Apply coupon BEFORE setting status
            // This ensures commission calculations use the post-discount amount
            if (!empty($order_data['coupon_code'])) {
                $coupon_code = sanitize_text_field($order_data['coupon_code']);
                $coupon = new WC_Coupon($coupon_code);

                if ($coupon && $coupon->get_id() && $coupon->is_valid()) {
                    $order->apply_coupon($coupon_code);
                }
            }

            // Calculate totals BEFORE setting status
            // This ensures all discounts are applied before commission hooks fire
            $order->calculate_totals();

            // Save the order in 'pending' status first (fast, minimal hooks)
            $order->set_status('pending');
            $order->save();

            // Get order details for response
            $order_id = $order->get_id();
            $order_number = $order->get_order_number();
            $order_total = $order->get_total();

            // OPTIMIZATION: Schedule status change to 'completed' asynchronously
            // This moves slow plugin hooks (emails, commissions, etc.) to background
            $target_status = !empty($order_data['order_status']) ? sanitize_text_field($order_data['order_status']) : 'completed';

            if ($target_status !== 'pending') {
                // Schedule the status change to happen immediately but asynchronously
                wp_schedule_single_event(time(), 'wbs_complete_order_async', array($order_id, $target_status));

                // Spawn WP Cron immediately (non-blocking)
                spawn_cron();
            }

            // Return success immediately (before slow hooks fire)
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

        $order_info = $this->get_product_order_info($product_id);

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

            wp_send_json_success(array(
                'message' => 'Product marked as On the Floor',
                'verified' => 'on-the-floor'
            ));

        } catch (Exception $e) {
            wp_send_json_error('Error updating verification: ' . $e->getMessage());
        }
    }

    /**
     * Async order completion handler
     * Changes order status from 'pending' to target status (usually 'completed')
     * This runs in background to avoid blocking the POS UI
     */
    public function complete_order_async($order_id, $target_status) {
        try {
            $order = wc_get_order($order_id);

            if (!$order) {
                error_log('WBS Async: Order not found - ' . $order_id);
                return;
            }

            // Change status - this triggers all the slow plugin hooks
            // (emails, commission calculations, inventory sync, etc.)
            $order->set_status($target_status);
            $order->save();

            error_log('WBS Async: Order ' . $order_id . ' status changed to ' . $target_status);

        } catch (Exception $e) {
            error_log('WBS Async Error: ' . $e->getMessage() . ' for order ' . $order_id);
        }
    }
}

new WBS_Ajax();