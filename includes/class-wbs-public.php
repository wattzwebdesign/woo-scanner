<?php
if (!defined('ABSPATH')) {
    exit;
}

class WBS_Public {

    public function __construct() {
        // Hook into WooCommerce product queries on frontend
        add_action('pre_get_posts', array($this, 'filter_frontend_products'), 20);

        // Hook into Elementor query filters
        add_filter('elementor/query/query_args', array($this, 'filter_elementor_products'), 10, 2);
    }

    /**
     * Filter frontend product queries to show only "On the Floor" products
     * Works with WooCommerce shop, archive, and search pages
     */
    public function filter_frontend_products($query) {
        // Check if filter is enabled
        if (get_option('wbs_frontend_on_floor_filter', '0') !== '1') {
            return;
        }

        // Only run on frontend (not admin)
        if (is_admin()) {
            return;
        }

        // Only filter main query or product queries
        if (!$query->is_main_query() && !isset($query->query_vars['post_type'])) {
            return;
        }

        // Only filter product queries
        $post_type = $query->get('post_type');
        if ($post_type !== 'product' && !$query->is_post_type_archive('product') && !$query->is_tax(get_object_taxonomies('product'))) {
            return;
        }

        // Get existing meta query
        $meta_query = $query->get('meta_query');
        if (empty($meta_query)) {
            $meta_query = array();
        }

        // Add our "On the Floor" filter
        $meta_query[] = array(
            'key' => '_verified',
            'value' => 'on-the-floor',
            'compare' => '='
        );

        $query->set('meta_query', $meta_query);
    }

    /**
     * Filter Elementor product queries to show only "On the Floor" products
     * Specifically handles Elementor loop grids and product widgets
     */
    public function filter_elementor_products($query_args, $widget) {
        // Check if filter is enabled
        if (get_option('wbs_frontend_on_floor_filter', '0') !== '1') {
            return $query_args;
        }

        // Only run on frontend
        if (is_admin()) {
            return $query_args;
        }

        // Check if this is a product query
        if (!isset($query_args['post_type']) || $query_args['post_type'] !== 'product') {
            return $query_args;
        }

        // Get existing meta query
        if (!isset($query_args['meta_query'])) {
            $query_args['meta_query'] = array();
        }

        // Add our "On the Floor" filter
        $query_args['meta_query'][] = array(
            'key' => '_verified',
            'value' => 'on-the-floor',
            'compare' => '='
        );

        return $query_args;
    }
}

new WBS_Public();
