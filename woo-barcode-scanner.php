<?php
/**
 * Plugin Name: WooCommerce Barcode Scanner
 * Plugin URI: https://codewattz.com
 * Description: Scan barcodes to quickly find and edit WooCommerce products
 * Version: 1.0.1
 * Author: Code Wattz
 * License: GPL v2 or later
 * Requires at least: 5.0
 * Tested up to: 6.3
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

// Declare HPOS compatibility early
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

if (!class_exists('WooBarcodeScannerPlugin')) {

    class WooBarcodeScannerPlugin {
        
        private static $instance = null;
        
        public static function get_instance() {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        private function __construct() {
            add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
            add_action('init', array($this, 'init'));
        }
        
        public function init() {
            if (!class_exists('WooCommerce')) {
                add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
                return;
            }
            
            $this->define_constants();
            $this->includes();
            $this->init_hooks();
        }
        
        private function define_constants() {
            define('WBS_PLUGIN_URL', plugin_dir_url(__FILE__));
            define('WBS_PLUGIN_PATH', plugin_dir_path(__FILE__));
            define('WBS_VERSION', '1.0.1');
        }
        
        private function includes() {
            require_once WBS_PLUGIN_PATH . 'includes/class-wbs-admin.php';
            require_once WBS_PLUGIN_PATH . 'includes/class-wbs-ajax.php';
        }
        
        private function init_hooks() {
            add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
            add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
            add_action('init', array($this, 'add_rewrite_rules'));
            add_action('template_redirect', array($this, 'handle_barcode_scanner_page'));
            add_shortcode('barcode_scanner', array($this, 'barcode_scanner_shortcode'));
        }
        
        public function declare_hpos_compatibility() {
            if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            }
        }
        
        public function enqueue_scripts() {
            wp_enqueue_script('wbs-scanner', WBS_PLUGIN_URL . 'assets/js/scanner.js', array('jquery'), WBS_VERSION, true);
            wp_enqueue_style('wbs-scanner', WBS_PLUGIN_URL . 'assets/css/scanner.css', array(), WBS_VERSION);
            
            wp_localize_script('wbs-scanner', 'wbs_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wbs_nonce')
            ));
        }
        
        public function admin_enqueue_scripts($hook) {
            if ('toplevel_page_woo-barcode-scanner' !== $hook && 'barcode-scanner_page_wbs-create-order' !== $hook) {
                return;
            }
            
            wp_enqueue_script('wbs-admin', WBS_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), WBS_VERSION, true);
            wp_enqueue_style('wbs-admin', WBS_PLUGIN_URL . 'assets/css/admin.css', array(), WBS_VERSION);
            
            wp_localize_script('wbs-admin', 'wbs_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wbs_nonce')
            ));
        }
        
        public function add_rewrite_rules() {
            add_rewrite_rule('^barcode-scanner/?$', 'index.php?barcode_scanner=1', 'top');
            add_rewrite_tag('%barcode_scanner%', '([^&]+)');
            
            // Add query var filter
            add_filter('query_vars', array($this, 'add_query_vars'));
        }
        
        public function add_query_vars($vars) {
            $vars[] = 'barcode_scanner';
            return $vars;
        }
        
        public function handle_barcode_scanner_page() {
            if (get_query_var('barcode_scanner')) {
                $this->render_frontend_scanner();
                exit;
            }
        }
        
        public function render_frontend_scanner() {
            if (!current_user_can('manage_woocommerce')) {
                wp_redirect(wp_login_url(home_url('/barcode-scanner/')));
                exit;
            }
            
            // Set up proper headers
            status_header(200);
            global $wp_query;
            $wp_query->is_404 = false;
            
            wp_enqueue_script('wbs-admin', WBS_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), WBS_VERSION, true);
            wp_enqueue_style('wbs-admin', WBS_PLUGIN_URL . 'assets/css/admin.css', array(), WBS_VERSION);
            wp_enqueue_style('wbs-frontend', WBS_PLUGIN_URL . 'assets/css/frontend.css', array(), WBS_VERSION);
            
            wp_localize_script('wbs-admin', 'wbs_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wbs_nonce')
            ));
            
            // Set page title
            add_filter('wp_title', function($title) {
                return 'Barcode Scanner | ' . get_bloginfo('name');
            });
            
            add_filter('document_title_parts', function($title) {
                $title['title'] = 'Barcode Scanner';
                return $title;
            });
            
            $admin = new WBS_Admin();
            
            get_header();
            echo '<div class="wbs-frontend-wrapper">';
            echo '<div class="wbs-frontend-container">';
            $admin->admin_page();
            echo '</div>';
            echo '</div>';
            get_footer();
        }
        
        public function barcode_scanner_shortcode($atts) {
            if (!current_user_can('manage_woocommerce')) {
                return '<p>You do not have permission to access this scanner.</p>';
            }
            
            wp_enqueue_script('wbs-admin', WBS_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), WBS_VERSION, true);
            wp_enqueue_style('wbs-admin', WBS_PLUGIN_URL . 'assets/css/admin.css', array(), WBS_VERSION);
            
            wp_localize_script('wbs-admin', 'wbs_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wbs_nonce')
            ));
            
            ob_start();
            $admin = new WBS_Admin();
            $admin->admin_page();
            return ob_get_clean();
        }
        
        public function woocommerce_missing_notice() {
            echo '<div class="notice notice-error"><p><strong>WooCommerce Barcode Scanner</strong> requires WooCommerce to be installed and active.</p></div>';
        }
    }
}

function wbs_init() {
    return WooBarcodeScannerPlugin::get_instance();
}

function wbs_activate() {
    wbs_init();
    flush_rewrite_rules();
}

function wbs_deactivate() {
    flush_rewrite_rules();
}

add_action('plugins_loaded', 'wbs_init');
register_activation_hook(__FILE__, 'wbs_activate');
register_deactivation_hook(__FILE__, 'wbs_deactivate');