 <?php
/**
 * Plugin Name: WooCommerce Barcode Scanner
 * Plugin URI: https://codewattz.com
 * Description: Scan barcodes to quickly find and edit WooCommerce products
 * Version: 1.3.2
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
            define('WBS_VERSION', '1.3.2');
        }
        
        private function includes() {
            require_once WBS_PLUGIN_PATH . 'includes/class-wbs-admin.php';
            require_once WBS_PLUGIN_PATH . 'includes/class-wbs-ajax.php';
            require_once WBS_PLUGIN_PATH . 'includes/class-wbs-audit-db.php';
            require_once WBS_PLUGIN_PATH . 'includes/class-wbs-audit-logger.php';
            require_once WBS_PLUGIN_PATH . 'includes/class-wbs-audit-reports.php';
        }
        
        private function init_hooks() {
            add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
            add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
            add_action('init', array($this, 'add_rewrite_rules'), 10, 0);

            // Use parse_request for earlier interception
            add_action('parse_request', array($this, 'parse_pos_request'), 0);
            add_action('template_redirect', array($this, 'handle_barcode_scanner_page'));
            add_shortcode('barcode_scanner', array($this, 'barcode_scanner_shortcode'));

            // Check if we need to flush rewrite rules
            add_action('init', array($this, 'maybe_flush_rewrite_rules'));

            // Audit cleanup cron job
            add_action('wbs_audit_cleanup', array('WBS_Audit_DB', 'cleanup_old_data'));
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
            if ('toplevel_page_woo-barcode-scanner' !== $hook && 'barcode-scanner_page_wbs-create-order' !== $hook && 'barcode-scanner_page_wbs-verification' !== $hook && 'barcode-scanner_page_wbs-pos' !== $hook) {
                return;
            }

            // Enqueue POS-specific scripts for POS page
            if ('barcode-scanner_page_wbs-pos' === $hook) {
                wp_enqueue_style('wbs-pos', WBS_PLUGIN_URL . 'assets/css/pos.css', array(), WBS_VERSION);
                wp_enqueue_script('wbs-pos', WBS_PLUGIN_URL . 'assets/js/pos.js', array('jquery'), WBS_VERSION, true);

                wp_localize_script('wbs-pos', 'wbs_ajax', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('wbs_nonce')
                ));

                // Add body class for POS page
                add_filter('admin_body_class', function($classes) {
                    return $classes . ' wbs-pos-page';
                });

                return;
            }

            // Enqueue verification-specific scripts for verification page
            if ('barcode-scanner_page_wbs-verification' === $hook) {
                wp_enqueue_style('wbs-admin', WBS_PLUGIN_URL . 'assets/css/admin.css', array(), WBS_VERSION);
                wp_enqueue_style('wbs-verification', WBS_PLUGIN_URL . 'assets/css/verification.css', array(), WBS_VERSION);
                wp_enqueue_script('wbs-verification', WBS_PLUGIN_URL . 'assets/js/verification.js', array('jquery'), WBS_VERSION, true);

                wp_localize_script('wbs-verification', 'wbs_ajax', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('wbs_nonce')
                ));
            } else {
                // Enqueue standard admin scripts for other pages
                wp_enqueue_style('wbs-admin', WBS_PLUGIN_URL . 'assets/css/admin.css', array(), WBS_VERSION);
                wp_enqueue_script('wbs-admin', WBS_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), WBS_VERSION, true);

                wp_localize_script('wbs-admin', 'wbs_ajax', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('wbs_nonce')
                ));
            }
        }
        
        public function add_rewrite_rules() {
            // Register query vars first
            add_filter('query_vars', array($this, 'add_query_vars'));

            // Add rewrite tags
            add_rewrite_tag('%barcode_scanner%', '([^&]+)');
            add_rewrite_tag('%wbs_pos%', '([^&]+)');

            // Add rewrite rules at top priority
            add_rewrite_rule('^barcode-scanner/?$', 'index.php?barcode_scanner=1', 'top');
            add_rewrite_rule('^pos/?$', 'index.php?wbs_pos=1', 'top');
        }

        public function add_query_vars($vars) {
            $vars[] = 'barcode_scanner';
            $vars[] = 'wbs_pos';
            return $vars;
        }

        public function maybe_flush_rewrite_rules() {
            // Check if we need to flush rewrite rules
            $version_option = 'wbs_rewrite_version';
            $current_version = get_option($version_option);

            if ($current_version !== WBS_VERSION) {
                flush_rewrite_rules();
                update_option($version_option, WBS_VERSION);
            }
        }

        private function can_access_pos() {
            // Check if user is logged in
            if (!is_user_logged_in()) {
                return false;
            }

            // Check if user has manage_woocommerce capability (admin)
            if (current_user_can('manage_woocommerce')) {
                return true;
            }

            // Check if user has shop_manager role
            $user = wp_get_current_user();
            if (in_array('shop_manager', (array) $user->roles)) {
                return true;
            }

            return false;
        }

        public function parse_pos_request($wp) {
            // Check if this is a request for /pos
            if (isset($_SERVER['REQUEST_URI'])) {
                $request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
                $home_path = parse_url(home_url(), PHP_URL_PATH);

                // Remove home path if site is in subdirectory
                if ($home_path && $home_path !== '/') {
                    $request_path = str_replace($home_path, '', $request_path);
                }

                // Normalize the path
                $request_path = '/' . trim($request_path, '/');

                if ($request_path === '/pos') {
                    $wp->query_vars['wbs_pos'] = '1';
                }
            }
        }
        
        public function handle_barcode_scanner_page() {
            global $wp_query;

            // Check for barcode scanner page
            if (get_query_var('barcode_scanner')) {
                $this->render_frontend_scanner();
                exit;
            }

            // Check for POS page
            if (get_query_var('wbs_pos')) {
                $this->render_frontend_pos();
                exit;
            }

            // Fallback: Check the request URI directly
            $request_uri = $_SERVER['REQUEST_URI'];
            if (preg_match('#^/pos/?$#', parse_url($request_uri, PHP_URL_PATH))) {
                $this->render_frontend_pos();
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

        public function render_frontend_pos() {
            // Check permissions (admin or shop_manager)
            if (!$this->can_access_pos()) {
                wp_redirect(wp_login_url(home_url('/pos/')));
                exit;
            }

            // Set up proper headers
            status_header(200);
            global $wp_query;
            $wp_query->is_404 = false;

            // Don't show admin bar
            show_admin_bar(false);

            // Get site logo from Site Identity (custom logo)
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $logo_data = wp_get_attachment_image_src($custom_logo_id, 'full');
                $site_logo = $logo_data ? $logo_data[0] : '';
            }

            // Fallback to site icon if no custom logo
            if (empty($site_logo)) {
                $site_logo = get_site_icon_url(200);
            }

            // Final fallback to plugin default
            if (empty($site_logo)) {
                $site_logo = WBS_PLUGIN_URL . 'assets/images/logo.png';
            }

            // Output standalone POS page (no header/footer)
            // Remove all theme actions from wp_head
            remove_all_actions('wp_head');
            remove_all_actions('wp_print_styles');
            remove_all_actions('wp_print_head_scripts');

            // Re-add only essential WordPress hooks
            add_action('wp_head', 'wp_enqueue_scripts', 1);
            add_action('wp_head', 'wp_print_styles', 8);
            add_action('wp_head', 'wp_print_head_scripts', 9);

            ?>
            <!DOCTYPE html>
            <html <?php language_attributes(); ?> class="wbs-pos-standalone">
            <head>
                <meta charset="<?php bloginfo('charset'); ?>">
                <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
                <meta name="apple-mobile-web-app-capable" content="yes">
                <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
                <meta name="mobile-web-app-capable" content="yes">
                <meta name="theme-color" content="#0B2349">
                <title>POS System | <?php bloginfo('name'); ?></title>

                <!-- Load jQuery -->
                <?php wp_print_scripts('jquery'); ?>

                <!-- Load ONLY our POS CSS -->
                <link rel="stylesheet" href="<?php echo WBS_PLUGIN_URL; ?>assets/css/pos.css?ver=<?php echo WBS_VERSION; ?>">

                <!-- Load ONLY our POS JS -->
                <script>
                var wbs_ajax = {
                    ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    nonce: '<?php echo wp_create_nonce('wbs_nonce'); ?>'
                };
                </script>
                <script src="<?php echo WBS_PLUGIN_URL; ?>assets/js/pos.js?ver=<?php echo WBS_VERSION; ?>"></script>
            </head>
            <body class="wbs-pos-standalone">
                <div class="wrap wbs-pos-wrap">
                    <div class="wbs-pos-header">
                        <div class="wbs-pos-header-left">
                            <img src="<?php echo esc_url($site_logo); ?>" alt="Store Logo" class="wbs-pos-logo">
                        </div>
                        <div class="wbs-pos-header-center">
                            <div class="wbs-pos-scan-wrapper">
                                <div class="wbs-pos-mode-toggle">
                                    <label>
                                        <input type="radio" name="wbs-pos-mode" value="scan" checked>
                                        <span>Scan</span>
                                    </label>
                                    <label>
                                        <input type="radio" name="wbs-pos-mode" value="type">
                                        <span>Enter</span>
                                    </label>
                                </div>
                                <div class="wbs-pos-scan-input-group">
                                    <input type="text"
                                           id="wbs-pos-scan-input"
                                           placeholder="Scan product/order"
                                           autocomplete="off"
                                           autocorrect="off"
                                           autocapitalize="off"
                                           spellcheck="false"
                                           data-form-type="other"
                                           data-lpignore="true"
                                           inputmode="none">
                                    <button type="button" id="wbs-pos-search-btn">Search</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="wbs-pos-container">
                        <!-- LEFT PANEL: CART -->
                        <div class="wbs-pos-cart-section">
                            <div class="wbs-pos-cart-header">
                                🛒 Cart Items (<span id="wbs-pos-cart-count">0</span>)
                            </div>

                            <div class="wbs-pos-cart-items" id="wbs-pos-cart-items">
                                <div class="wbs-pos-cart-empty">
                                    <span class="dashicons dashicons-cart"></span>
                                    <p>Cart is empty</p>
                                    <small>Scan a product to get started</small>
                                </div>
                            </div>

                            <!-- TOTALS -->
                            <div class="wbs-pos-cart-totals">
                                <div class="wbs-pos-total-row subtotal">
                                    <span>Subtotal:</span>
                                    <span>$<span id="wbs-pos-subtotal">0.00</span></span>
                                </div>
                                <div class="wbs-pos-total-row discount" style="display: none;">
                                    <span>Discount (<span id="wbs-pos-discount-code"></span>):</span>
                                    <span>−$<span id="wbs-pos-discount-amount">0.00</span></span>
                                </div>
                                <div class="wbs-pos-total-row final">
                                    <span>TOTAL:</span>
                                    <span>$<span id="wbs-pos-total">0.00</span></span>
                                </div>
                            </div>
                        </div>

                        <!-- RIGHT PANEL: ACTIONS -->
                        <div class="wbs-pos-actions-section">
                            <!-- KEYPAD -->
                            <div class="wbs-pos-keypad-panel">
                                <div class="wbs-pos-keypad-display" id="wbs-pos-keypad-display">$0.00</div>
                                <div class="wbs-pos-keypad-grid">
                                    <button type="button" class="wbs-pos-keypad-btn">7</button>
                                    <button type="button" class="wbs-pos-keypad-btn">8</button>
                                    <button type="button" class="wbs-pos-keypad-btn">9</button>
                                    <button type="button" class="wbs-pos-keypad-btn">4</button>
                                    <button type="button" class="wbs-pos-keypad-btn">5</button>
                                    <button type="button" class="wbs-pos-keypad-btn">6</button>
                                    <button type="button" class="wbs-pos-keypad-btn">1</button>
                                    <button type="button" class="wbs-pos-keypad-btn">2</button>
                                    <button type="button" class="wbs-pos-keypad-btn">3</button>
                                    <button type="button" class="wbs-pos-keypad-btn">0</button>
                                    <button type="button" class="wbs-pos-keypad-btn">00</button>
                                    <button type="button" class="wbs-pos-keypad-btn">.</button>
                                </div>
                                <div class="wbs-pos-keypad-actions">
                                    <button type="button" id="wbs-pos-keypad-clear" class="wbs-pos-clear-btn">Clear</button>
                                    <button type="button" id="wbs-pos-add-custom" class="wbs-pos-add-custom-btn">Add Custom Item</button>
                                </div>
                            </div>

                            <!-- QUICK ACTIONS -->
                            <div class="wbs-pos-quick-actions-panel">
                                <input type="email" id="wbs-pos-customer-email" class="wbs-pos-customer-email" placeholder="Customer Email (optional)">
                                <div class="wbs-pos-actions-row">
                                    <button type="button" id="wbs-pos-discount-btn" class="wbs-pos-action-btn wbs-pos-discount-btn">🎟️ Apply Discount</button>
                                    <button type="button" id="wbs-pos-clear-cart-btn" class="wbs-pos-action-btn wbs-pos-clear-cart-btn">🗑️ Clear Cart</button>
                                </div>
                                <button type="button" id="wbs-pos-complete-sale-btn" class="wbs-pos-action-btn wbs-pos-complete-sale-btn">✓ COMPLETE SALE</button>
                            </div>
                        </div>
                    </div>
                </div>
            </body>
            </html>
            <?php
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
    $plugin = wbs_init();
    // Trigger rewrite rules registration
    $plugin->add_rewrite_rules();
    // Flush rewrite rules on activation
    flush_rewrite_rules();
    // Set the version to force flush on next load
    delete_option('wbs_rewrite_version');

    // Create audit database tables
    require_once plugin_dir_path(__FILE__) . 'includes/class-wbs-audit-db.php';
    WBS_Audit_DB::create_tables();
}

function wbs_deactivate() {
    flush_rewrite_rules();
    delete_option('wbs_rewrite_version');

    // Unschedule audit cleanup cron
    require_once plugin_dir_path(__FILE__) . 'includes/class-wbs-audit-db.php';
    WBS_Audit_DB::unschedule_cleanup();
}

add_action('plugins_loaded', 'wbs_init');
register_activation_hook(__FILE__, 'wbs_activate');
register_deactivation_hook(__FILE__, 'wbs_deactivate');