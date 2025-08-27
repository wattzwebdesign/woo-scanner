<?php
if (!defined('ABSPATH')) {
    exit;
}

class WBS_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Barcode Scanner',
            'Barcode Scanner',
            'manage_woocommerce',
            'woo-barcode-scanner',
            array($this, 'admin_page'),
            'dashicons-search',
            56
        );
    }
    
    public function admin_page() {
        ?>
        <div class="wrap" id="wbs-main-wrap">
            <div class="wbs-header-controls">
                <h1>Barcode Scanner</h1>
                <div class="wbs-header-buttons">
                    <button type="button" id="wbs-fullscreen-toggle" class="button">
                        <span class="dashicons dashicons-fullscreen-alt"></span>
                        Full Screen
                    </button>
                </div>
            </div>
            
            <div class="wbs-scanner-container" id="wbs-scanner-container">
                <div class="wbs-main-layout">
                    <!-- Left Column - Product Image -->
                    <div class="wbs-image-column">
                        <div class="wbs-product-image-container">
                            <img id="wbs-product-image" src="" alt="Product Image" style="display: none;">
                            <div class="wbs-no-image" id="wbs-no-image">
                                <span class="dashicons dashicons-format-image"></span>
                                <p>No Image</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Column - Product Information -->
                    <div class="wbs-content-column">
                        <!-- Scan Section -->
                        <div class="wbs-scan-section">
                            <div class="wbs-scan-input-group">
                                <input type="text" id="wbs-scan-input" placeholder="Scan barcode or enter SKU..." autocomplete="off">
                                <button type="button" id="wbs-scan-btn" class="button button-primary">Search</button>
                            </div>
                            <div id="wbs-scan-result" class="wbs-found-result"></div>
                        </div>
                        
                        <!-- Product Editor -->
                        <div id="wbs-product-editor" class="wbs-product-editor" style="display: none;">
                            <form id="wbs-product-form">
                                <input type="hidden" id="wbs-product-id" name="product_id">
                                
                                <!-- Product Title & ID -->
                                <div class="wbs-product-header">
                                    <div class="wbs-product-title-section">
                                        <div class="wbs-product-id">#<span id="wbs-product-id-display"></span></div>
                                        <h3 id="wbs-product-title-display" class="wbs-product-title"></h3>
                                    </div>
                                </div>
                                
                                <!-- First Row: SKU, Regular Price, Sale Price, Categories -->
                                <div class="wbs-form-row wbs-row-primary">
                                    <div class="wbs-form-group">
                                        <label for="wbs-sku">SKU</label>
                                        <input type="text" id="wbs-sku" name="sku" class="wbs-input" readonly>
                                    </div>
                                    
                                    <div class="wbs-form-group">
                                        <label for="wbs-regular-price">Regular Price ($)</label>
                                        <input type="number" id="wbs-regular-price" name="regular_price" step="0.01" min="0" class="wbs-input">
                                    </div>
                                    
                                    <div class="wbs-form-group">
                                        <label for="wbs-sale-price">Sale Price ($)</label>
                                        <input type="number" id="wbs-sale-price" name="sale_price" step="0.01" min="0" class="wbs-input">
                                    </div>
                                    
                                    <div class="wbs-form-group wbs-categories-group">
                                        <label for="wbs-categories">Categories</label>
                                        <select id="wbs-categories" name="categories[]" multiple class="wbs-categories-select">
                                            <?php echo $this->get_product_categories_options(); ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <!-- Second Row: Product Status, Stock Status, Quantity, Consignor -->
                                <div class="wbs-form-row wbs-row-secondary">
                                    <div class="wbs-form-group">
                                        <label for="wbs-product-status">Product Status</label>
                                        <select id="wbs-product-status" name="product_status" class="wbs-input">
                                            <option value="publish">Publish</option>
                                            <option value="private">Private</option>
                                            <option value="draft">Draft</option>
                                        </select>
                                    </div>
                                    
                                    <div class="wbs-form-group">
                                        <label for="wbs-stock-status">Stock Status</label>
                                        <div class="wbs-stock-status-wrapper">
                                            <select id="wbs-stock-status" name="stock_status" class="wbs-input wbs-stock-select">
                                                <option value="instock">In Stock</option>
                                                <option value="outofstock">Out of Stock</option>
                                                <option value="onbackorder">On Backorder</option>
                                            </select>
                                            <div class="wbs-stock-indicator" id="wbs-stock-indicator"></div>
                                        </div>
                                    </div>
                                    
                                    <div class="wbs-form-group">
                                        <label for="wbs-quantity">Quantity</label>
                                        <div class="wbs-quantity-controls">
                                            <button type="button" class="wbs-qty-btn wbs-qty-decrease">-</button>
                                            <input type="number" id="wbs-quantity" name="quantity" min="0" class="wbs-quantity-input">
                                            <button type="button" class="wbs-qty-btn wbs-qty-increase">+</button>
                                        </div>
                                    </div>
                                    
                                    <div class="wbs-form-group">
                                        <label for="wbs-consignor">Consignor #</label>
                                        <input type="text" id="wbs-consignor" name="consignor_number" class="wbs-input" readonly>
                                        <input type="hidden" id="wbs-consignor-id" name="consignor_id">
                                    </div>
                                </div>
                                
                                <!-- Action Buttons -->
                                <div class="wbs-form-actions">
                                    <button type="submit" id="wbs-save-product" class="button button-primary">Update Product</button>
                                    <button type="button" id="wbs-clear-form" class="button">Clear & New Search</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function get_product_categories_options() {
        $categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ));
        
        $options = '';
        foreach ($categories as $category) {
            $options .= sprintf('<option value="%d">%s</option>', $category->term_id, esc_html($category->name));
        }
        
        return $options;
    }
}

new WBS_Admin();