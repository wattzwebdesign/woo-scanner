<?php
if (!defined('ABSPATH')) {
    exit;
}

class WBS_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Add verification column to products list
        add_filter('manage_edit-product_columns', array($this, 'add_verification_column'));
        add_action('manage_product_posts_custom_column', array($this, 'display_verification_column'), 10, 2);
        add_filter('manage_edit-product_sortable_columns', array($this, 'make_verification_column_sortable'));

        // Add verification filter
        add_action('restrict_manage_posts', array($this, 'add_verification_filter'));
        add_filter('parse_query', array($this, 'filter_by_verification_status'));

        // Add admin notice for POS link
        add_action('admin_notices', array($this, 'pos_link_notice'));
    }

    public function pos_link_notice() {
        // Only show on plugin pages
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'barcode-scanner') === false) {
            return;
        }

        // Show dismissible notice with POS link
        $dismissed = get_user_meta(get_current_user_id(), 'wbs_pos_notice_dismissed', true);
        if ($dismissed) {
            return;
        }

        ?>
        <div class="notice notice-info is-dismissible" id="wbs-pos-notice">
            <p>
                <strong>💡 Tip:</strong> Access the fullscreen POS system at
                <a href="<?php echo esc_url(home_url('/pos')); ?>" target="_blank"><strong><?php echo esc_url(home_url('/pos')); ?></strong></a>
                <br>
                <small>This standalone page works great on iPads and tablets with no WordPress admin interface.</small>
            </p>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#wbs-pos-notice').on('click', '.notice-dismiss', function() {
                $.post(ajaxurl, {
                    action: 'wbs_dismiss_pos_notice',
                    nonce: '<?php echo wp_create_nonce('wbs_dismiss_notice'); ?>'
                });
            });
        });
        </script>
        <?php
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
        
        add_submenu_page(
            'woo-barcode-scanner',
            'Create Order',
            'Create Order',
            'manage_woocommerce',
            'wbs-create-order',
            array($this, 'create_order_page')
        );

        add_submenu_page(
            'woo-barcode-scanner',
            'Verification',
            'Verification',
            'manage_woocommerce',
            'wbs-verification',
            array($this, 'verification_page')
        );

        // Frontend POS link
        add_submenu_page(
            'woo-barcode-scanner',
            'Point of Sale',
            'Point of Sale →',
            'manage_woocommerce',
            'wbs-pos-frontend',
            array($this, 'pos_frontend_redirect')
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
                            <div class="wbs-mode-toggle">
                                <label class="wbs-toggle-label">
                                    <input type="radio" name="wbs-input-mode" value="scan" checked>
                                    <span>Scan Mode</span>
                                </label>
                                <label class="wbs-toggle-label">
                                    <input type="radio" name="wbs-input-mode" value="type">
                                    <span>Type Mode</span>
                                </label>
                            </div>
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
                                        <div class="wbs-form-group">
                                            <label for="wbs-product-title">Product Title</label>
                                            <input type="text" id="wbs-product-title" name="product_title" class="wbs-input wbs-title-input">
                                        </div>
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

                <!-- Order Info Column -->
                <div class="wbs-order-column" id="wbs-order-column">
                    <div class="wbs-order-column-content" id="wbs-order-column-content">
                        <div class="wbs-order-column-empty">
                            <span class="dashicons dashicons-cart"></span>
                            <p>No order information</p>
                            <small>Scan an out-of-stock item to view order details</small>
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
    
    public function create_order_page() {
        ?>
        <div class="wrap" id="wbs-order-wrap">
            <div class="wbs-header-controls">
                <h1>Create Order by Barcode</h1>
                <div class="wbs-header-buttons">
                    <button type="button" id="wbs-create-order-btn" class="button button-primary" disabled>
                        Create Order
                    </button>
                    <button type="button" id="wbs-clear-order-btn" class="button">
                        Clear All
                    </button>
                </div>
            </div>
            
            <div class="wbs-order-container">
                <!-- Barcode Scanning Section -->
                <div class="wbs-scan-section">
                    <div class="wbs-mode-toggle">
                        <label class="wbs-toggle-label">
                            <input type="radio" name="wbs-order-input-mode" value="scan" checked>
                            <span>Scan Mode</span>
                        </label>
                        <label class="wbs-toggle-label">
                            <input type="radio" name="wbs-order-input-mode" value="type">
                            <span>Type Mode</span>
                        </label>
                    </div>
                    <div class="wbs-scan-input-group">
                        <input type="text" id="wbs-order-scan-input" placeholder="Scan barcode or enter SKU..." autocomplete="off">
                        <button type="button" id="wbs-order-scan-btn" class="button button-primary">Add Item</button>
                    </div>
                    <div id="wbs-scan-status" class="wbs-scan-status"></div>

                    <!-- Bulk SKU Paste Section -->
                    <div class="wbs-bulk-section">
                        <div class="wbs-bulk-header">
                            <h4>Or paste multiple SKUs (one per line):</h4>
                        </div>
                        <textarea id="wbs-bulk-sku-input" placeholder="Paste SKUs here, one per line...&#10;Example:&#10;SKU-001&#10;SKU-002&#10;SKU-003" rows="6"></textarea>
                        <div class="wbs-bulk-actions">
                            <button type="button" id="wbs-bulk-add-btn" class="button button-primary">Bulk Add Items</button>
                            <button type="button" id="wbs-bulk-clear-btn" class="button">Clear</button>
                        </div>
                        <div id="wbs-bulk-status" class="wbs-bulk-status"></div>
                    </div>
                </div>
                
                <!-- Order Items Table -->
                <div class="wbs-order-items">
                    <h3>Order Items (<span id="wbs-items-count">0</span>)</h3>
                    <table class="wp-list-table widefat fixed striped" id="wbs-order-items-table">
                        <thead>
                            <tr>
                                <th width="10%">Image</th>
                                <th width="15%">SKU</th>
                                <th width="35%">Product</th>
                                <th width="10%">Qty</th>
                                <th width="15%">Price</th>
                                <th width="15%">Total</th>
                                <th width="10%">Action</th>
                            </tr>
                        </thead>
                        <tbody id="wbs-order-items-tbody">
                            <tr id="wbs-no-items">
                                <td colspan="7" class="wbs-no-items">No items added yet. Start scanning barcodes to add products.</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div class="wbs-order-total">
                        <div class="wbs-order-subtotal">
                            <span>Subtotal:</span>
                            <span>$<span id="wbs-order-subtotal">0.00</span></span>
                        </div>
                        <div class="wbs-order-discount" id="wbs-order-discount-row" style="display: none;">
                            <span>Discount (<span id="wbs-applied-coupon-code"></span>):</span>
                            <span class="wbs-discount-amount">-$<span id="wbs-order-discount">0.00</span></span>
                        </div>
                        <div class="wbs-order-total-row">
                            <strong>Total:</strong>
                            <strong>$<span id="wbs-order-total">0.00</span></strong>
                        </div>
                    </div>
                </div>
                
                <!-- Order Details Section -->
                <div class="wbs-order-details" id="wbs-order-details" style="display: none;">
                    <h3>Order Details</h3>
                    <form id="wbs-order-form">
                        <div class="wbs-order-form-row">
                            <div class="wbs-form-group wbs-customer-email-group">
                                <label for="wbs-customer-email">Customer Email</label>
                                <div class="wbs-autocomplete-wrapper">
                                    <input type="email" id="wbs-customer-email" name="customer_email" class="wbs-input" placeholder="customer@example.com" autocomplete="off">
                                    <div class="wbs-customer-suggestions" id="wbs-customer-suggestions"></div>
                                </div>
                            </div>

                            <div class="wbs-form-group">
                                <label for="wbs-customer-name">Customer Name</label>
                                <input type="text" id="wbs-customer-name" name="customer_name" class="wbs-input" placeholder="John Doe">
                            </div>

                            <div class="wbs-form-group">
                                <label for="wbs-order-status">Order Status</label>
                                <select id="wbs-order-status" name="order_status" class="wbs-input">
                                    <option value="pending">Pending</option>
                                    <option value="processing">Processing</option>
                                    <option value="on-hold">On Hold</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                    <option value="refunded">Refunded</option>
                                    <option value="failed">Failed</option>
                                </select>
                            </div>
                        </div>

                        <div class="wbs-order-form-row">
                            <div class="wbs-form-group">
                                <label for="wbs-coupon-code">Coupon Code</label>
                                <div class="wbs-coupon-wrapper">
                                    <input type="text" id="wbs-coupon-code" name="coupon_code" class="wbs-input" placeholder="Enter coupon code...">
                                    <button type="button" id="wbs-apply-coupon-btn" class="button">Apply Coupon</button>
                                </div>
                                <div id="wbs-coupon-status" class="wbs-coupon-status"></div>
                            </div>
                        </div>
                        
                        <div class="wbs-order-form-row">
                            <div class="wbs-form-group">
                                <label for="wbs-order-notes">Order Notes</label>
                                <textarea id="wbs-order-notes" name="order_notes" class="wbs-input" rows="3" placeholder="Optional order notes..."></textarea>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            let orderItems = [];
            let itemCounter = 0;
            let appliedCoupon = null;
            let orderInputMode = 'scan'; // Default to scan mode

            // Auto-focus on scan input
            $('#wbs-order-scan-input').focus();

            // Handle mode toggle
            $('input[name="wbs-order-input-mode"]').on('change', function() {
                orderInputMode = $(this).val();
                const scanInput = $('#wbs-order-scan-input');

                if (orderInputMode === 'scan') {
                    scanInput.attr('placeholder', 'Scan barcode or enter SKU...');
                } else {
                    scanInput.attr('placeholder', 'Type SKU and press Enter or click Add Item...');
                }

                scanInput.focus();
            });

            // Handle barcode scanning - auto-add after typing stops (only in scan mode)
            let scanTimeout;
            $('#wbs-order-scan-input').on('input', function() {
                clearTimeout(scanTimeout);
                const scanInput = $(this);
                const searchTerm = scanInput.val().trim();

                // Only auto-search in scan mode
                if (orderInputMode === 'scan' && searchTerm.length >= 3) {
                    scanTimeout = setTimeout(function() {
                        addItemToOrder();
                    }, 500); // Wait 500ms after user stops typing
                }
            });

            // Handle Enter key for immediate search (works in both modes)
            $('#wbs-order-scan-input').on('keypress', function(e) {
                if (e.which === 13) { // Enter key
                    e.preventDefault();
                    clearTimeout(scanTimeout); // Cancel the timeout
                    addItemToOrder();
                }
            });
            
            $('#wbs-order-scan-btn').click(function() {
                addItemToOrder();
            });
            
            function addItemToOrder() {
                const scanInput = $('#wbs-order-scan-input');
                const searchTerm = scanInput.val().trim();
                
                if (!searchTerm) {
                    showScanStatus('Please enter a barcode or SKU', 'error');
                    return;
                }
                
                showScanStatus('Searching...', 'loading');
                
                $.ajax({
                    url: wbs_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wbs_search_product',
                        search_term: searchTerm,
                        nonce: wbs_ajax.nonce,
                        scan_context: 'create_order'
                    },
                    success: function(response) {
                        if (response.success) {
                            const wasAdded = addProductToOrder(response.data);
                            scanInput.val('').focus();

                            if (wasAdded) {
                                showScanStatus('Product added successfully!', 'success');
                                setTimeout(() => clearScanStatus(), 2000);
                            }
                            // If not added (duplicate), the error message is already shown by addProductToOrder
                        } else {
                            showScanStatus('Product not found: ' + searchTerm, 'error');
                            scanInput.val('').focus(); // Clear input even when product not found
                        }
                    },
                    error: function() {
                        showScanStatus('Error searching for product', 'error');
                        scanInput.val('').focus(); // Clear input on error
                    }
                });
            }
            
            function addProductToOrder(product) {
                console.log('=== Adding product to order ===');
                console.log('Incoming product:', product);
                console.log('Current order has', orderItems.length, 'items');

                // SIMPLE CHECK: Does this product_id already exist in the order?
                // Convert to string for comparison to handle any type issues
                const newProductId = String(product.id);

                for (let i = 0; i < orderItems.length; i++) {
                    const existingProductId = String(orderItems[i].product_id);
                    console.log(`Checking order item ${i}: ${existingProductId} vs ${newProductId}`);

                    if (existingProductId === newProductId) {
                        console.log('DUPLICATE DETECTED! Product already in order.');
                        showScanStatus('Product already in this order: ' + product.title, 'error');
                        return false;
                    }
                }

                console.log('No duplicate found, adding to order');

                const item = {
                    id: ++itemCounter,
                    product_id: product.id,
                    sku: product.sku,
                    name: product.title,
                    price: parseFloat(product.sale_price || product.regular_price || 0),
                    quantity: 1,
                    image_url: product.image_url
                };

                orderItems.unshift(item); // Add to beginning of array so newest items appear at top
                updateOrderDisplay();
                return true;
            }
            
            function updateOrderDisplay() {
                const tbody = $('#wbs-order-items-tbody');
                tbody.empty();
                
                if (orderItems.length === 0) {
                    tbody.append('<tr id="wbs-no-items"><td colspan="7" class="wbs-no-items">No items added yet. Start scanning barcodes to add products.</td></tr>');
                    $('#wbs-order-details').hide();
                    $('#wbs-create-order-btn').prop('disabled', true);
                } else {
                    orderItems.forEach(item => {
                        const total = (item.price * item.quantity).toFixed(2);
                        const imageHtml = item.image_url ? 
                            `<img src="${item.image_url}" alt="Product" style="width: 50px; height: 50px; object-fit: cover;">` : 
                            '<div class="wbs-no-image-small">No Image</div>';
                            
                        tbody.append(`
                            <tr data-item-id="${item.id}">
                                <td>${imageHtml}</td>
                                <td>${item.sku}</td>
                                <td>${item.name}</td>
                                <td>
                                    <input type="number" class="wbs-qty-input" value="${item.quantity}" min="1" data-item-id="${item.id}">
                                </td>
                                <td>$${item.price.toFixed(2)}</td>
                                <td>$${total}</td>
                                <td>
                                    <button type="button" class="button wbs-remove-item" data-item-id="${item.id}">Remove</button>
                                </td>
                            </tr>
                        `);
                    });
                    
                    $('#wbs-order-details').show();
                    $('#wbs-create-order-btn').prop('disabled', false);
                }
                
                updateOrderSummary();
            }
            
            function updateOrderSummary() {
                const subtotal = orderItems.reduce((sum, item) => sum + (item.price * item.quantity), 0);
                let discount = 0;
                let total = subtotal;

                // Apply coupon discount if available
                if (appliedCoupon) {
                    discount = calculateDiscount(subtotal, appliedCoupon);
                    total = subtotal - discount;

                    $('#wbs-order-discount-row').show();
                    $('#wbs-order-discount').text(discount.toFixed(2));
                    $('#wbs-applied-coupon-code').text(appliedCoupon.code);
                } else {
                    $('#wbs-order-discount-row').hide();
                }

                $('#wbs-order-subtotal').text(subtotal.toFixed(2));
                $('#wbs-order-total').text(total.toFixed(2));
                $('#wbs-items-count').text(orderItems.length);
            }

            function calculateDiscount(subtotal, coupon) {
                let discount = 0;

                if (coupon.discount_type === 'percent') {
                    discount = (subtotal * parseFloat(coupon.amount)) / 100;
                } else if (coupon.discount_type === 'fixed_cart') {
                    discount = parseFloat(coupon.amount);
                }

                // Don't let discount exceed subtotal
                discount = Math.min(discount, subtotal);

                return discount;
            }
            
            // Handle quantity changes
            $(document).on('change', '.wbs-qty-input', function() {
                const itemId = parseInt($(this).data('item-id'));
                const newQty = parseInt($(this).val()) || 1;
                
                const item = orderItems.find(item => item.id === itemId);
                if (item) {
                    item.quantity = Math.max(1, newQty);
                    updateOrderDisplay();
                }
            });
            
            // Handle item removal
            $(document).on('click', '.wbs-remove-item', function() {
                const itemId = parseInt($(this).data('item-id'));
                orderItems = orderItems.filter(item => item.id !== itemId);
                updateOrderDisplay();
            });
            
            // Handle clear all
            $('#wbs-clear-order-btn').click(function() {
                if (confirm('Are you sure you want to clear all items?')) {
                    orderItems = [];
                    itemCounter = 0;
                    appliedCoupon = null;
                    updateOrderDisplay();
                    clearScanStatus();
                    clearCouponStatus();
                    $('#wbs-order-form')[0].reset();
                }
            });

            // Handle coupon application
            $('#wbs-apply-coupon-btn').click(function() {
                const couponCode = $('#wbs-coupon-code').val().trim();

                if (!couponCode) {
                    showCouponStatus('Please enter a coupon code', 'error');
                    return;
                }

                if (orderItems.length === 0) {
                    showCouponStatus('Please add items to the order first', 'error');
                    return;
                }

                $(this).prop('disabled', true).text('Validating...');
                showCouponStatus('Validating coupon...', 'loading');

                $.ajax({
                    url: wbs_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wbs_validate_coupon',
                        coupon_code: couponCode,
                        nonce: wbs_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            appliedCoupon = response.data;
                            updateOrderSummary();
                            showCouponStatus('Coupon applied successfully!', 'success');
                            $('#wbs-apply-coupon-btn').text('Remove').data('action', 'remove');
                            $('#wbs-coupon-code').prop('readonly', true);
                        } else {
                            showCouponStatus(response.data || 'Invalid coupon code', 'error');
                        }
                    },
                    error: function() {
                        showCouponStatus('Error validating coupon', 'error');
                    },
                    complete: function() {
                        $('#wbs-apply-coupon-btn').prop('disabled', false);
                    }
                });
            });

            // Handle coupon removal
            $(document).on('click', '#wbs-apply-coupon-btn[data-action="remove"]', function(e) {
                e.preventDefault();
                appliedCoupon = null;
                updateOrderSummary();
                $('#wbs-coupon-code').val('').prop('readonly', false);
                $('#wbs-apply-coupon-btn').text('Apply Coupon').removeData('action');
                clearCouponStatus();
            });
            
            // Handle order creation
            $('#wbs-create-order-btn').click(function() {
                if (orderItems.length === 0) {
                    alert('Please add at least one item to create an order.');
                    return;
                }
                
                const orderData = {
                    items: orderItems,
                    customer_email: $('#wbs-customer-email').val(),
                    customer_name: $('#wbs-customer-name').val(),
                    order_status: $('#wbs-order-status').val(),
                    order_notes: $('#wbs-order-notes').val(),
                    coupon_code: appliedCoupon ? appliedCoupon.code : null
                };
                
                $(this).prop('disabled', true).text('Creating Order...');
                
                $.ajax({
                    url: wbs_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wbs_create_order',
                        order_data: orderData,
                        nonce: wbs_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Order created successfully! Order ID: ' + response.data.order_id);
                            // Reset form
                            orderItems = [];
                            itemCounter = 0;
                            appliedCoupon = null;
                            updateOrderDisplay();
                            $('#wbs-order-form')[0].reset();
                            clearScanStatus();
                            clearCouponStatus();
                            $('#wbs-apply-coupon-btn').text('Apply Coupon').removeData('action');
                        } else {
                            alert('Error creating order: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('Error creating order. Please try again.');
                    },
                    complete: function() {
                        $('#wbs-create-order-btn').prop('disabled', false).text('Create Order');
                    }
                });
            });
            
            function showScanStatus(message, type) {
                const statusDiv = $('#wbs-scan-status');
                statusDiv.removeClass('success error loading').addClass(type);
                statusDiv.text(message).show();
            }
            
            function clearScanStatus() {
                $('#wbs-scan-status').hide().text('');
            }

            function showCouponStatus(message, type) {
                const statusDiv = $('#wbs-coupon-status');
                statusDiv.removeClass('success error loading').addClass(type);
                statusDiv.text(message).show();
            }

            function clearCouponStatus() {
                $('#wbs-coupon-status').hide().text('');
            }
            
            // Customer email autocomplete
            let customerSearchTimeout;
            $('#wbs-customer-email').on('input', function() {
                clearTimeout(customerSearchTimeout);
                const email = $(this).val().trim();
                const suggestionsDiv = $('#wbs-customer-suggestions');
                
                if (email.length >= 3) {
                    customerSearchTimeout = setTimeout(function() {
                        searchCustomers(email);
                    }, 300);
                } else {
                    suggestionsDiv.hide().empty();
                }
            });
            
            function searchCustomers(searchTerm) {
                $.ajax({
                    url: wbs_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wbs_search_customers',
                        search_term: searchTerm,
                        nonce: wbs_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data.length > 0) {
                            showCustomerSuggestions(response.data);
                        } else {
                            $('#wbs-customer-suggestions').hide().empty();
                        }
                    }
                });
            }
            
            function showCustomerSuggestions(customers) {
                const suggestionsDiv = $('#wbs-customer-suggestions');
                suggestionsDiv.empty();
                
                customers.forEach(customer => {
                    const suggestion = $(`
                        <div class="wbs-customer-suggestion" data-customer-id="${customer.id}" data-email="${customer.email}" data-name="${customer.display_name}">
                            <div class="wbs-customer-email">${customer.email}</div>
                            <div class="wbs-customer-name">${customer.display_name}</div>
                        </div>
                    `);
                    
                    suggestion.on('click', function() {
                        const customerId = $(this).data('customer-id');
                        const customerEmail = $(this).data('email');
                        const customerName = $(this).data('name');
                        
                        $('#wbs-customer-email').val(customerEmail);
                        $('#wbs-customer-name').val(customerName);
                        suggestionsDiv.hide().empty();
                    });
                    
                    suggestionsDiv.append(suggestion);
                });
                
                suggestionsDiv.show();
            }
            
            // Hide suggestions when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.wbs-customer-email-group').length) {
                    $('#wbs-customer-suggestions').hide();
                }
            });

            // Bulk SKU processing
            $('#wbs-bulk-add-btn').click(function() {
                const bulkInput = $('#wbs-bulk-sku-input');
                const skusText = bulkInput.val().trim();

                if (!skusText) {
                    showBulkStatus('Please paste at least one SKU', 'error');
                    return;
                }

                // Split by newlines and filter out empty lines
                const skus = skusText.split('\n')
                    .map(sku => sku.trim())
                    .filter(sku => sku.length > 0);

                if (skus.length === 0) {
                    showBulkStatus('No valid SKUs found', 'error');
                    return;
                }

                processBulkSKUs(skus);
            });

            $('#wbs-bulk-clear-btn').click(function() {
                $('#wbs-bulk-sku-input').val('');
                clearBulkStatus();
            });

            function processBulkSKUs(skus) {
                const totalSKUs = skus.length;
                let processedCount = 0;
                let successCount = 0;
                let failedSKUs = [];

                // Disable the button during processing
                $('#wbs-bulk-add-btn').prop('disabled', true).text('Processing...');

                showBulkStatus(`Processing ${totalSKUs} SKUs...`, 'loading');

                // Process SKUs sequentially to avoid overwhelming the server
                function processNextSKU(index) {
                    if (index >= skus.length) {
                        // All done
                        finishBulkProcessing();
                        return;
                    }

                    const sku = skus[index];

                    $.ajax({
                        url: wbs_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'wbs_search_product',
                            search_term: sku,
                            nonce: wbs_ajax.nonce,
                            scan_context: 'create_order'
                        },
                        success: function(response) {
                            processedCount++;

                            if (response.success) {
                                const wasAdded = addProductToOrder(response.data);
                                if (wasAdded) {
                                    successCount++;
                                } else {
                                    // Product already in order
                                    failedSKUs.push({sku: sku, reason: 'Already in order'});
                                }
                            } else {
                                failedSKUs.push({sku: sku, reason: 'Not found'});
                            }

                            // Update progress
                            showBulkStatus(`Processing ${processedCount}/${totalSKUs} SKUs... (${successCount} added)`, 'loading');

                            // Process next SKU
                            processNextSKU(index + 1);
                        },
                        error: function() {
                            processedCount++;
                            failedSKUs.push({sku: sku, reason: 'Error searching'});

                            // Update progress
                            showBulkStatus(`Processing ${processedCount}/${totalSKUs} SKUs... (${successCount} added)`, 'loading');

                            // Process next SKU
                            processNextSKU(index + 1);
                        }
                    });
                }

                function finishBulkProcessing() {
                    $('#wbs-bulk-add-btn').prop('disabled', false).text('Bulk Add Items');

                    let message = `Completed: ${successCount} of ${totalSKUs} items added`;

                    if (failedSKUs.length > 0) {
                        message += '<br><strong>Failed SKUs:</strong><br>';
                        failedSKUs.forEach(item => {
                            message += `• ${item.sku} - ${item.reason}<br>`;
                        });
                        showBulkStatus(message, 'partial');
                    } else {
                        showBulkStatus(message, 'success');
                    }

                    // Clear the textarea if all were successful
                    if (failedSKUs.length === 0) {
                        $('#wbs-bulk-sku-input').val('');
                    } else {
                        // Keep only failed SKUs in the textarea for retry
                        const failedSKUList = failedSKUs.map(item => item.sku).join('\n');
                        $('#wbs-bulk-sku-input').val(failedSKUList);
                    }
                }

                // Start processing
                processNextSKU(0);
            }

            function showBulkStatus(message, type) {
                const statusDiv = $('#wbs-bulk-status');
                statusDiv.removeClass('success error loading partial').addClass(type);
                statusDiv.html(message).show();
            }

            function clearBulkStatus() {
                $('#wbs-bulk-status').hide().html('');
            }
        });
        </script>
        
        <style>
        .wbs-order-container {
            max-width: 1200px;
        }

        .wbs-scan-section {
            margin-bottom: 30px;
            padding: 20px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .wbs-mode-toggle {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            padding: 10px;
            background: #f5f5f5;
            border-radius: 4px;
        }

        .wbs-toggle-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-weight: 500;
            user-select: none;
        }

        .wbs-toggle-label input[type="radio"] {
            margin-right: 6px;
            cursor: pointer;
        }

        .wbs-toggle-label span {
            color: #666;
        }

        .wbs-toggle-label input[type="radio"]:checked + span {
            color: #2271b1;
            font-weight: 600;
        }
        
        .wbs-scan-input-group {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        #wbs-order-scan-input {
            flex: 1;
            padding: 10px;
            font-size: 16px;
            border: 2px solid #ddd;
            border-radius: 4px;
        }
        
        .wbs-scan-status {
            padding: 8px 12px;
            border-radius: 4px;
            display: none;
        }
        
        .wbs-scan-status.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .wbs-scan-status.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .wbs-scan-status.loading {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .wbs-order-items {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .wbs-no-items {
            text-align: center;
            color: #666;
            font-style: italic;
        }
        
        .wbs-no-image-small {
            width: 50px;
            height: 50px;
            background: #f5f5f5;
            border: 1px solid #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: #666;
        }
        
        .wbs-qty-input {
            width: 60px;
            text-align: center;
        }
        
        .wbs-order-total {
            text-align: right;
            margin-top: 15px;
            font-size: 16px;
            border-top: 2px solid #ddd;
            padding-top: 15px;
        }

        .wbs-order-subtotal,
        .wbs-order-discount {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            color: #666;
        }

        .wbs-order-discount {
            color: #d63638;
        }

        .wbs-order-total-row {
            display: flex;
            justify-content: space-between;
            font-size: 18px;
            padding-top: 10px;
            border-top: 1px solid #ddd;
            margin-top: 10px;
        }

        .wbs-discount-amount {
            color: #d63638;
        }
        
        .wbs-order-details {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 20px;
        }
        
        .wbs-order-form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }
        
        .wbs-order-form-row .wbs-form-group {
            flex: 1;
        }
        
        .wbs-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .wbs-form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        /* Customer autocomplete styles */
        .wbs-autocomplete-wrapper {
            position: relative;
        }
        
        .wbs-customer-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 4px 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }
        
        .wbs-customer-suggestion {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .wbs-customer-suggestion:hover {
            background: #f5f5f5;
        }
        
        .wbs-customer-suggestion:last-child {
            border-bottom: none;
        }
        
        .wbs-customer-email {
            font-weight: 600;
            color: #333;
        }
        
        .wbs-customer-name {
            font-size: 12px;
            color: #666;
            margin-top: 2px;
        }
        
        .wbs-customer-email-group {
            position: relative;
        }

        /* Coupon field styles */
        .wbs-coupon-wrapper {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .wbs-coupon-wrapper input {
            flex: 1;
        }

        .wbs-coupon-status {
            margin-top: 8px;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 13px;
            display: none;
        }

        .wbs-coupon-status.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .wbs-coupon-status.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .wbs-coupon-status.loading {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        /* Bulk SKU Section Styles */
        .wbs-bulk-section {
            margin-top: 25px;
            padding-top: 25px;
            border-top: 2px solid #ddd;
        }

        .wbs-bulk-header h4 {
            margin: 0 0 15px 0;
            color: #333;
            font-size: 14px;
            font-weight: 600;
        }

        #wbs-bulk-sku-input {
            width: 100%;
            padding: 10px;
            font-size: 14px;
            font-family: monospace;
            border: 2px solid #ddd;
            border-radius: 4px;
            resize: vertical;
            min-height: 120px;
        }

        #wbs-bulk-sku-input:focus {
            border-color: #2271b1;
            outline: none;
            box-shadow: 0 0 0 1px #2271b1;
        }

        .wbs-bulk-actions {
            margin-top: 10px;
            display: flex;
            gap: 10px;
        }

        .wbs-bulk-status {
            margin-top: 15px;
            padding: 12px;
            border-radius: 4px;
            display: none;
            font-size: 13px;
            line-height: 1.5;
        }

        .wbs-bulk-status.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .wbs-bulk-status.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .wbs-bulk-status.loading {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .wbs-bulk-status.partial {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }

        .wbs-bulk-status strong {
            display: block;
            margin-top: 8px;
            margin-bottom: 4px;
        }
        </style>
        <?php
    }

    public function verification_page() {
        ?>
        <!-- Verification Notice Modal -->
        <div id="wbs-verification-notice-modal" class="wbs-modal">
            <div class="wbs-modal-content">
                <div class="wbs-modal-header">
                    <span class="wbs-modal-icon">⚠️</span>
                    <h2>Product Verification Scanner</h2>
                </div>
                <div class="wbs-modal-body">
                    <p><strong>Important:</strong> This is NOT the regular barcode scanner.</p>
                    <p>This verification scanner should <strong>only be used when pushing products out from the canvas tote</strong>.</p>
                    <p>For regular product scanning and editing, please use the main Barcode Scanner page.</p>
                </div>
                <div class="wbs-modal-footer">
                    <button type="button" class="button button-primary wbs-modal-close">I Understand</button>
                </div>
            </div>
        </div>

        <div class="wrap" id="wbs-verification-wrap">
            <div class="wbs-header-controls">
                <h1>Product Verification</h1>
                <div class="wbs-header-buttons">
                    <button type="button" id="wbs-fullscreen-toggle" class="button">
                        <span class="dashicons dashicons-fullscreen-alt"></span>
                        Full Screen
                    </button>
                </div>
            </div>

            <!-- Alert Bar -->
            <div class="wbs-verification-alert-bar">
                <div class="wbs-alert-icon">⚠️</div>
                <div class="wbs-alert-content">
                    <strong>VERIFICATION MODE:</strong> This scanner is for verifying products from the canvas tote only.
                    For regular scanning, use <a href="<?php echo admin_url('admin.php?page=woo-barcode-scanner'); ?>">Barcode Scanner</a>.
                </div>
            </div>

            <div class="wbs-scanner-container" id="wbs-verification-container">
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
                            <div class="wbs-mode-toggle">
                                <label class="wbs-toggle-label">
                                    <input type="radio" name="wbs-input-mode" value="scan" checked>
                                    <span>Scan Mode</span>
                                </label>
                                <label class="wbs-toggle-label">
                                    <input type="radio" name="wbs-input-mode" value="type">
                                    <span>Type Mode</span>
                                </label>
                            </div>
                            <div class="wbs-scan-input-group">
                                <input type="text" id="wbs-scan-input" placeholder="Scan barcode or enter SKU..." autocomplete="off">
                                <button type="button" id="wbs-scan-btn" class="button button-primary">Search</button>
                            </div>
                            <div id="wbs-scan-result" class="wbs-found-result"></div>
                        </div>

                        <!-- Product Verification Display -->
                        <div id="wbs-product-verification" class="wbs-product-verification" style="display: none;">
                            <input type="hidden" id="wbs-product-id" name="product_id">

                            <!-- Product Title & ID -->
                            <div class="wbs-product-header">
                                <div class="wbs-product-title-section">
                                    <div class="wbs-product-id">#<span id="wbs-product-id-display"></span></div>
                                    <div class="wbs-product-title-display">
                                        <h3 id="wbs-product-title"></h3>
                                    </div>
                                </div>
                            </div>

                            <!-- Product Details Row -->
                            <div class="wbs-verification-details">
                                <div class="wbs-detail-item">
                                    <label>SKU</label>
                                    <span id="wbs-sku" class="wbs-detail-value"></span>
                                </div>

                                <div class="wbs-detail-item">
                                    <label>Price</label>
                                    <span id="wbs-price" class="wbs-detail-value"></span>
                                </div>

                                <div class="wbs-detail-item">
                                    <label>Stock Status</label>
                                    <div class="wbs-stock-status-display">
                                        <span id="wbs-stock-status" class="wbs-detail-value"></span>
                                        <div class="wbs-stock-indicator" id="wbs-stock-indicator"></div>
                                    </div>
                                </div>

                                <div class="wbs-detail-item">
                                    <label>Quantity</label>
                                    <span id="wbs-quantity" class="wbs-detail-value"></span>
                                </div>
                            </div>

                            <!-- Verification Status Section -->
                            <div class="wbs-verification-status-section">
                                <div class="wbs-verification-status-box">
                                    <h4>Verification Status</h4>
                                    <div class="wbs-verification-indicator" id="wbs-verification-indicator">
                                        <span class="wbs-verification-icon"></span>
                                        <span class="wbs-verification-text"></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="wbs-verification-actions">
                                <button type="button" id="wbs-mark-verified" class="button button-primary" style="display: none;">
                                    Mark as On the Floor
                                </button>
                                <button type="button" id="wbs-clear-form" class="button">Clear & New Search</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Info Column -->
                <div class="wbs-order-column" id="wbs-order-column">
                    <div class="wbs-order-column-content" id="wbs-order-column-content">
                        <div class="wbs-order-column-empty">
                            <span class="dashicons dashicons-cart"></span>
                            <p>No order information</p>
                            <small>Scan an out-of-stock item to view order details</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    // Add verification column to products list
    public function add_verification_column($columns) {
        // Insert verification column after the product name
        $new_columns = array();
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'name') {
                $new_columns['verification_status'] = 'Verification';
            }
        }
        return $new_columns;
    }

    // Display verification status in the column
    public function display_verification_column($column, $post_id) {
        if ($column === 'verification_status') {
            $verified = get_post_meta($post_id, '_verified', true);

            if (empty($verified)) {
                $verified = 'not-on-the-floor'; // Default
            }

            if ($verified === 'on-the-floor') {
                echo '<span style="display: inline-block; padding: 4px 10px; background: #d4edda; color: #155724; border-radius: 3px; font-size: 11px; font-weight: 600;">✓ On the Floor</span>';
            } else {
                echo '<span style="display: inline-block; padding: 4px 10px; background: #f8d7da; color: #721c24; border-radius: 3px; font-size: 11px; font-weight: 600;">✗ Not on the Floor</span>';
            }
        }
    }

    // Make verification column sortable
    public function make_verification_column_sortable($columns) {
        $columns['verification_status'] = 'verification_status';
        return $columns;
    }

    // Add verification filter dropdown
    public function add_verification_filter($post_type) {
        if ($post_type !== 'product') {
            return;
        }

        $current_filter = isset($_GET['verification_status']) ? sanitize_text_field($_GET['verification_status']) : '';

        ?>
        <select name="verification_status" id="verification_status_filter">
            <option value="">All Verification Statuses</option>
            <option value="on-the-floor" <?php selected($current_filter, 'on-the-floor'); ?>>On the Floor</option>
            <option value="not-on-the-floor" <?php selected($current_filter, 'not-on-the-floor'); ?>>Not on the Floor</option>
        </select>
        <?php
    }

    // Filter products by verification status
    public function filter_by_verification_status($query) {
        global $pagenow, $typenow;

        if ($typenow === 'product' && $pagenow === 'edit.php' && isset($_GET['verification_status']) && !empty($_GET['verification_status'])) {
            $verification_status = sanitize_text_field($_GET['verification_status']);

            $meta_query = array(
                'relation' => 'OR',
                array(
                    'key' => '_verified',
                    'value' => $verification_status,
                    'compare' => '='
                )
            );

            // If filtering for "not-on-the-floor", also include products without the meta field
            if ($verification_status === 'not-on-the-floor') {
                $meta_query[] = array(
                    'key' => '_verified',
                    'compare' => 'NOT EXISTS'
                );
            }

            $query->set('meta_query', $meta_query);
        }

        // Handle sorting by verification status
        if (isset($_GET['orderby']) && $_GET['orderby'] === 'verification_status') {
            $query->set('meta_key', '_verified');
            $query->set('orderby', 'meta_value');
        }
    }

    public function pos_frontend_redirect() {
        wp_redirect(home_url('/pos/'));
        exit;
    }

    public function pos_page() {
        $site_logo = get_site_icon_url(50);
        if (!$site_logo) {
            $site_logo = plugins_url('../assets/images/logo.png', __FILE__);
        }
        ?>
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
                            <input type="text" id="wbs-pos-scan-input" placeholder="Scan product/order" autocomplete="off">
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
        <?php
    }
}

new WBS_Admin();