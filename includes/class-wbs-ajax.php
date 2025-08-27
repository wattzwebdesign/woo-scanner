<?php
if (!defined('ABSPATH')) {
    exit;
}

class WBS_Ajax {
    
    public function __construct() {
        add_action('wp_ajax_wbs_search_product', array($this, 'search_product'));
        add_action('wp_ajax_wbs_update_product', array($this, 'update_product'));
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
        $product_id = wc_get_product_id_by_sku($search_term);
        
        if ($product_id) {
            return wc_get_product($product_id);
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
        
        return array(
            'id' => $product_id,
            'title' => $product->get_name() ?: '',
            'sku' => $product->get_sku() ?: '',
            'regular_price' => $product->get_regular_price() ?: '',
            'sale_price' => $product->get_sale_price() ?: '',
            'stock_status' => $product->get_stock_status() ?: 'instock',
            'stock_quantity' => $product->get_stock_quantity() ?: 0,
            'manage_stock' => $product->get_manage_stock() ?: false,
            'categories' => $categories ?: array(),
            'status' => get_post_status($product_id) ?: 'publish',
            'consignor_id' => $consignor_id ?: '',
            'consignor_number' => $consignor_number ?: '',
            'image_url' => $image_url ?: ''
        );
    }
}

new WBS_Ajax();