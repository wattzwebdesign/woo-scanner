/**
 * WooCommerce Barcode Scanner - POS System JavaScript
 */

jQuery(document).ready(function($) {
    // Cart state
    let cartItems = [];
    let itemCounter = 0;
    let appliedCoupon = null;
    let inputMode = 'scan'; // 'scan' or 'type'
    let keypadValue = '0.00';
    let pendingBacklogData = null; // Store backlog consignor data

    // Auto-focus on scan input
    $('#wbs-pos-scan-input').focus();

    // iOS Chrome keyboard bar workaround
    // When using barcode scanner, prevent keyboard from showing
    const scanInput = document.getElementById('wbs-pos-scan-input');
    if (scanInput) {
        // Detect if using barcode scanner (rapid input) vs manual typing
        let inputSpeed = 0;
        let lastInputTime = 0;

        scanInput.addEventListener('beforeinput', function(e) {
            const now = Date.now();
            inputSpeed = now - lastInputTime;
            lastInputTime = now;

            // If input is very fast (< 50ms between chars), it's likely a barcode scanner
            if (inputSpeed < 50 && inputSpeed > 0) {
                // Keep inputmode="none" to prevent keyboard
                scanInput.setAttribute('inputmode', 'none');
            }
        });

        // When user manually focuses (tap/click), allow keyboard for manual entry
        scanInput.addEventListener('click', function() {
            if (inputMode === 'type') {
                scanInput.setAttribute('inputmode', 'text');
            }
        });
    }

    // ========================================
    // PREVENT ACCIDENTAL REFRESH/NAVIGATION
    // ========================================
    let allowNavigation = false;

    // Intercept keyboard shortcuts for refresh
    $(document).on('keydown', function(e) {
        // Check for F5 or Ctrl+R (Windows/Linux) or Cmd+R (Mac)
        if ((e.key === 'F5') ||
            ((e.ctrlKey || e.metaKey) && e.key === 'r')) {

            if (cartItems.length > 0 && !allowNavigation) {
                e.preventDefault();
                showRefreshWarningModal();
            }
        }
    });

    // Use beforeunload as a fallback for other navigation attempts
    window.addEventListener('beforeunload', function(e) {
        if (cartItems.length > 0 && !allowNavigation) {
            e.preventDefault();
            e.returnValue = '';
            return '';
        }
    });

    function showRefreshWarningModal() {
        const modalHtml = `
            <div id="wbs-pos-refresh-modal" class="wbs-pos-modal">
                <div class="wbs-pos-modal-content">
                    <h3>⚠️ Warning</h3>
                    <p>You have ${cartItems.length} item(s) in your cart. Refreshing will clear all items.</p>
                    <p style="font-weight: 600; margin-top: 15px;">Are you sure you want to refresh?</p>
                    <div class="wbs-pos-modal-buttons">
                        <button type="button" id="wbs-pos-refresh-confirm" class="wbs-pos-modal-btn wbs-pos-modal-btn-primary">Yes, Refresh</button>
                        <button type="button" id="wbs-pos-refresh-cancel" class="wbs-pos-modal-btn wbs-pos-modal-btn-secondary">Cancel</button>
                    </div>
                </div>
            </div>
        `;

        $('body').append(modalHtml);

        // Confirm button - allow refresh
        $('#wbs-pos-refresh-confirm').on('click', function() {
            allowNavigation = true;
            location.reload();
        });

        // Cancel button
        $('#wbs-pos-refresh-cancel').on('click', function() {
            $('#wbs-pos-refresh-modal').remove();
        });

        // Focus cancel button by default
        $('#wbs-pos-refresh-cancel').focus();
    }

    // ========================================
    // MODE TOGGLE
    // ========================================
    $('input[name="wbs-pos-mode"]').on('change', function() {
        inputMode = $(this).val();
        const scanInput = $('#wbs-pos-scan-input');

        if (inputMode === 'scan') {
            scanInput.attr('placeholder', 'Scan product/order');
            scanInput.attr('inputmode', 'none'); // No keyboard for scanning
        } else {
            scanInput.attr('placeholder', 'Type SKU and press Enter');
            scanInput.attr('inputmode', 'text'); // Allow keyboard for typing
        }

        scanInput.focus();
    });

    // ========================================
    // BARCODE SCANNING
    // ========================================
    let scanTimeout;
    $('#wbs-pos-scan-input').on('input', function() {
        clearTimeout(scanTimeout);
        const scanInput = $(this);
        const searchTerm = scanInput.val().trim();

        // Only auto-search in scan mode
        if (inputMode === 'scan' && searchTerm.length >= 3) {
            scanTimeout = setTimeout(function() {
                searchProduct();
            }, 500);
        }
    });

    // Handle Enter key
    $('#wbs-pos-scan-input').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            clearTimeout(scanTimeout);
            searchProduct();
        }
    });

    // Search button click
    $('#wbs-pos-search-btn').on('click', function() {
        clearTimeout(scanTimeout);
        searchProduct();
    });

    function searchProduct() {
        const scanInput = $('#wbs-pos-scan-input');
        const searchTerm = scanInput.val().trim();

        if (!searchTerm) {
            return;
        }

        $.ajax({
            url: wbs_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wbs_search_product',
                search_term: searchTerm,
                nonce: wbs_ajax.nonce,
                scan_context: 'pos'
            },
            success: function(response) {
                if (response.success) {
                    // Check if this is a backlog item scan
                    if (response.data.is_backlog) {
                        // Set pending backlog data and update button
                        pendingBacklogData = response.data;
                        $('#wbs-pos-add-custom').text('Add Backlog Item (Consignor #' + response.data.consignor_number + ')');
                        $('#wbs-pos-add-custom').css({
                            'background-color': '#2271b1',
                            'color': '#ffffff'
                        });
                        // No notification - button change is enough visual feedback
                    } else if (response.data.stock_status === 'outofstock') {
                        // Product is out of stock - show error with order info
                        if (response.data.last_order_info) {
                            showNotification('Out of Stock - Purchased in Order #' + response.data.last_order_info.order_number, 'error');
                        } else {
                            showNotification('Out of Stock - No order found', 'error');
                        }
                    } else {
                        addProductToCart(response.data);
                    }
                    scanInput.val('').focus();
                } else {
                    showNotification('Product not found: ' + searchTerm, 'error');
                    scanInput.val('').focus();
                }
            },
            error: function() {
                showNotification('Error searching for product', 'error');
                scanInput.val('').focus();
            }
        });
    }

    // ========================================
    // CART MANAGEMENT
    // ========================================
    function addProductToCart(product) {
        // Check if product already exists in the cart
        // Convert to string for comparison to handle any type issues
        const newProductId = String(product.id);

        for (let i = 0; i < cartItems.length; i++) {
            const existingProductId = String(cartItems[i].product_id);

            if (existingProductId === newProductId) {
                const displaySku = product.sku || product.old_sku || product.title || 'Unknown';
                showNotification('Item already in cart: ' + displaySku, 'error');
                return false;
            }
        }

        // Add new item to the top
        const item = {
            id: ++itemCounter,
            product_id: product.id,
            sku: product.sku,
            old_sku: product.old_sku || '',
            name: product.title,
            price: parseFloat(product.sale_price || product.regular_price || 0),
            quantity: 1,
            image_url: product.image_url,
            category_ids: product.category_ids || [],
            is_custom: false
        };

        cartItems.unshift(item);
        updateCartDisplay();
        return true;
    }

    function updateCartDisplay() {
        const cartItemsContainer = $('#wbs-pos-cart-items');

        // Store scroll position
        const wasAtTop = cartItemsContainer.scrollTop() === 0;

        cartItemsContainer.empty();

        if (cartItems.length === 0) {
            cartItemsContainer.html(`
                <div class="wbs-pos-cart-empty">
                    <span class="dashicons dashicons-cart"></span>
                    <p>Cart is empty</p>
                    <small>Scan a product to get started</small>
                </div>
            `);
        } else {
            // Build all items first, then insert at once
            const itemsHtml = [];

            cartItems.forEach(item => {
                const total = (item.price * item.quantity).toFixed(2);
                const customClass = item.is_custom ? 'custom-item' : '';
                let imageHtml;
                if (item.image_url) {
                    imageHtml = `<img src="${item.image_url}" alt="${item.name}">`;
                } else if (item.is_custom) {
                    // Use site favicon for custom items
                    const favicon = $('link[rel="icon"]').attr('href') || $('.wbs-pos-logo').attr('src') || '';
                    imageHtml = favicon ? `<img src="${favicon}" alt="Custom Item" style="width: 60px; height: 60px; object-fit: contain;">` : '<span style="font-size: 40px;">💵</span>';
                } else {
                    imageHtml = '<span style="font-size: 40px;">📦</span>';
                }

                // Check discount eligibility
                let discountBadge = '';
                if (appliedCoupon) {
                    const isEligible = isItemEligibleForCoupon(item, appliedCoupon);
                    if (isEligible) {
                        discountBadge = '<span class="wbs-pos-discount-badge active">Discount Applied</span>';
                    } else {
                        discountBadge = '<span class="wbs-pos-discount-badge not-eligible">Discount Not Applied</span>';
                    }

                    // Add toggle button for custom items
                    if (item.is_custom) {
                        discountBadge += ' <button type="button" class="wbs-pos-discount-toggle" data-item-id="' + item.id + '" title="Toggle discount">⇄</button>';
                    }
                }

                itemsHtml.push(`
                    <div class="wbs-pos-cart-item ${customClass}" data-item-id="${item.id}">
                        <div class="wbs-pos-item-image">${imageHtml}</div>
                        <div class="wbs-pos-item-details">
                            <div class="wbs-pos-item-title">${item.name}</div>
                            <div class="wbs-pos-item-sku">SKU: ${item.sku}</div>
                            <div class="wbs-pos-item-price">$${item.price.toFixed(2)}</div>
                            ${discountBadge}
                        </div>
                        <div class="wbs-pos-item-qty">
                            <div class="wbs-pos-qty-display">Qty: ${item.quantity}</div>
                            <div class="wbs-pos-item-subtotal">$${total}</div>
                        </div>
                        <button type="button" class="wbs-pos-remove-btn" data-item-id="${item.id}">×</button>
                    </div>
                `);
            });

            // Insert all items at once (order is already correct from array)
            cartItemsContainer.html(itemsHtml.join(''));
        }

        updateCartTotals();
        updateCartHeader();

        // ALWAYS scroll to top after updating (newest items are first in array)
        const container = cartItemsContainer[0];
        if (container) {
            container.scrollTop = 0;
            // Force again after a moment
            setTimeout(() => { container.scrollTop = 0; }, 10);
            setTimeout(() => { container.scrollTop = 0; }, 50);
            setTimeout(() => { container.scrollTop = 0; }, 100);
        }
    }

    function isItemEligibleForCoupon(item, coupon) {
        // Custom items check their discount_enabled flag
        if (item.is_custom) {
            return item.discount_enabled === true;
        }

        const productId = item.product_id;
        const categoryIds = item.category_ids || [];

        // Check if product is explicitly excluded
        if (coupon.excluded_product_ids && coupon.excluded_product_ids.length > 0) {
            if (coupon.excluded_product_ids.includes(productId)) {
                return false;
            }
        }

        // Check if product category is explicitly excluded
        if (coupon.excluded_product_categories && coupon.excluded_product_categories.length > 0) {
            for (let catId of categoryIds) {
                if (coupon.excluded_product_categories.includes(catId)) {
                    return false;
                }
            }
        }

        // If coupon has specific product IDs, check if this product is included
        if (coupon.product_ids && coupon.product_ids.length > 0) {
            return coupon.product_ids.includes(productId);
        }

        // If coupon has specific categories, check if this product is in one of them
        if (coupon.product_categories && coupon.product_categories.length > 0) {
            for (let catId of categoryIds) {
                if (coupon.product_categories.includes(catId)) {
                    return true;
                }
            }
            return false;
        }

        // If no restrictions, coupon applies to all products
        return true;
    }

    function updateCartHeader() {
        const count = cartItems.length;
        $('#wbs-pos-cart-count').text(count);
    }

    function updateCartTotals() {
        const subtotal = cartItems.reduce((sum, item) => sum + (item.price * item.quantity), 0);
        let discount = 0;
        let total = subtotal;

        // Apply coupon discount if available
        if (appliedCoupon) {
            // Calculate eligible items subtotal
            const eligibleSubtotal = cartItems.reduce((sum, item) => {
                if (isItemEligibleForCoupon(item, appliedCoupon)) {
                    return sum + (item.price * item.quantity);
                }
                return sum;
            }, 0);

            discount = calculateDiscount(eligibleSubtotal, appliedCoupon);
            total = subtotal - discount;

            $('.wbs-pos-total-row.discount').show();
            $('#wbs-pos-discount-amount').text(discount.toFixed(2));
            $('#wbs-pos-discount-code').text(appliedCoupon.code);
        } else {
            $('.wbs-pos-total-row.discount').hide();
        }

        $('#wbs-pos-subtotal').text(subtotal.toFixed(2));
        $('#wbs-pos-total').text(total.toFixed(2));
    }

    function calculateDiscount(eligibleSubtotal, coupon) {
        let discount = 0;

        if (coupon.discount_type === 'percent') {
            discount = (eligibleSubtotal * parseFloat(coupon.amount)) / 100;
        } else if (coupon.discount_type === 'fixed_cart') {
            discount = parseFloat(coupon.amount);
        }

        // Don't let discount exceed eligible subtotal
        discount = Math.min(discount, eligibleSubtotal);

        return discount;
    }

    // Remove item from cart
    $(document).on('click', '.wbs-pos-remove-btn', function() {
        const itemId = parseInt($(this).data('item-id'));
        cartItems = cartItems.filter(item => item.id !== itemId);
        updateCartDisplay();
    });

    // Toggle discount on custom item
    $(document).on('click', '.wbs-pos-discount-toggle', function() {
        const itemId = parseInt($(this).data('item-id'));
        const item = cartItems.find(i => i.id === itemId);

        if (item && item.is_custom) {
            item.discount_enabled = !item.discount_enabled;
            updateCartDisplay();
        }
    });

    // ========================================
    // KEYPAD
    // ========================================
    $('.wbs-pos-keypad-btn').on('click', function() {
        const value = $(this).text();

        if (keypadValue === '0.00') {
            keypadValue = '';
        }

        keypadValue += value;
        updateKeypadDisplay();
    });

    $('#wbs-pos-keypad-clear').on('click', function() {
        keypadValue = '0.00';
        updateKeypadDisplay();
    });

    function updateKeypadDisplay() {
        $('#wbs-pos-keypad-display').text('$' + keypadValue);
    }

    // Add custom item
    $('#wbs-pos-add-custom').on('click', function() {
        const amount = parseFloat(keypadValue);

        if (isNaN(amount) || amount <= 0) {
            showNotification('Please enter a valid amount', 'error');
            return;
        }

        // Check if this is for a pending backlog item
        if (pendingBacklogData) {
            addBacklogItemToCart({
                price: amount,
                consignor_id: pendingBacklogData.consignor_id,
                consignor_number: pendingBacklogData.consignor_number,
                consignor_name: pendingBacklogData.consignor_name,
                commission_rate: pendingBacklogData.commission_rate
            });

            // Clear pending backlog data
            pendingBacklogData = null;

            // Reset keypad
            keypadValue = '0.00';
            updateKeypadDisplay();

            // Reset button
            resetCustomItemButton();

            return;
        }

        // Regular custom item flow
        // If coupon is active, show modal with discount option
        if (appliedCoupon) {
            showCustomItemModal(amount);
        } else {
            // No coupon, just add the item
            addCustomItemToCart(amount, false);
        }
    });

    function showCustomItemModal(amount) {
        const couponInfo = `${appliedCoupon.code} (${appliedCoupon.amount}${appliedCoupon.discount_type === 'percent' ? '%' : ''} off)`;

        const modalHtml = `
            <div id="wbs-pos-custom-item-modal" class="wbs-pos-modal">
                <div class="wbs-pos-modal-content">
                    <h3>Add Custom Item</h3>
                    <p>Add $${amount.toFixed(2)} custom item to cart</p>
                    <div style="margin: 15px 0;">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" id="wbs-pos-custom-discount" checked style="width: 18px; height: 18px; cursor: pointer;">
                            <span style="font-size: 14px; font-weight: 600;">Apply ${couponInfo} discount</span>
                        </label>
                    </div>
                    <div class="wbs-pos-modal-buttons">
                        <button type="button" id="wbs-pos-custom-add" class="wbs-pos-modal-btn wbs-pos-modal-btn-primary">Add Item</button>
                        <button type="button" id="wbs-pos-custom-cancel" class="wbs-pos-modal-btn wbs-pos-modal-btn-secondary">Cancel</button>
                    </div>
                </div>
            </div>
        `;

        $('body').append(modalHtml);

        // Add button
        $('#wbs-pos-custom-add').on('click', function() {
            const discountEnabled = $('#wbs-pos-custom-discount').is(':checked');
            addCustomItemToCart(amount, discountEnabled);
            closeCustomItemModal();
        });

        // Cancel button
        $('#wbs-pos-custom-cancel').on('click', function() {
            closeCustomItemModal();
        });
    }

    function addCustomItemToCart(amount, discountEnabled) {
        const customItem = {
            id: ++itemCounter,
            product_id: 0,
            sku: 'CUSTOM-' + Date.now(),
            name: 'Custom Item',
            price: amount,
            quantity: 1,
            image_url: '',
            category_ids: [],
            is_custom: true,
            discount_enabled: discountEnabled
        };

        cartItems.unshift(customItem); // Add to top
        updateCartDisplay();

        // Reset keypad
        keypadValue = '0.00';
        updateKeypadDisplay();

        // Return focus to scan input
        $('#wbs-pos-scan-input').focus();
    }

    function closeCustomItemModal() {
        $('#wbs-pos-custom-item-modal').remove();
    }

    // ========================================
    // BACKLOG ITEM
    // ========================================
    function resetCustomItemButton() {
        $('#wbs-pos-add-custom').text('Add Custom Item');
        $('#wbs-pos-add-custom').css({
            'background-color': '',
            'color': ''
        });
    }

    function addBacklogItemToCart(backlogItem) {
        const item = {
            id: ++itemCounter,
            product_id: null,
            sku: 'BACKLOG-' + backlogItem.consignor_number,
            name: 'Backlog Item - Consignor #' + backlogItem.consignor_number,
            price: backlogItem.price,
            quantity: 1,
            image_url: '',
            category_ids: [], // Empty = eligible for all coupons
            is_custom: true,
            is_backlog: true,
            discount_enabled: true, // Always enable discounts for backlog items
            consignor_id: backlogItem.consignor_id,
            consignor_number: backlogItem.consignor_number,
            consignor_name: backlogItem.consignor_name,
            commission_rate: backlogItem.commission_rate
        };

        cartItems.unshift(item); // Add to top
        updateCartDisplay();
        // No notification - item appearing in cart is enough feedback

        // Return focus to scan input
        $('#wbs-pos-scan-input').focus();
    }

    // ========================================
    // COUPON
    // ========================================
    $('#wbs-pos-discount-btn').on('click', function() {
        if (cartItems.length === 0) {
            showNotification('Please add items to cart first', 'error');
            return;
        }

        // Show coupon input modal
        showCouponModal();
    });

    function showCouponModal() {
        // Create modal HTML
        const modalHtml = `
            <div id="wbs-pos-coupon-modal" class="wbs-pos-modal">
                <div class="wbs-pos-modal-content">
                    <h3>Apply Discount</h3>
                    <input type="text" id="wbs-pos-coupon-input" placeholder="Enter coupon code" class="wbs-pos-modal-input">
                    <div class="wbs-pos-modal-buttons">
                        <button type="button" id="wbs-pos-coupon-apply" class="wbs-pos-modal-btn wbs-pos-modal-btn-primary">Apply</button>
                        <button type="button" id="wbs-pos-coupon-cancel" class="wbs-pos-modal-btn wbs-pos-modal-btn-secondary">Cancel</button>
                    </div>
                    <div id="wbs-pos-coupon-message" class="wbs-pos-modal-message"></div>
                </div>
            </div>
        `;

        $('body').append(modalHtml);
        $('#wbs-pos-coupon-input').focus();

        // Apply button
        $('#wbs-pos-coupon-apply').on('click', function() {
            applyCoupon();
        });

        // Enter key
        $('#wbs-pos-coupon-input').on('keypress', function(e) {
            if (e.which === 13) {
                applyCoupon();
            }
        });

        // Cancel button
        $('#wbs-pos-coupon-cancel').on('click', function() {
            closeCouponModal();
        });
    }

    function applyCoupon() {
        const couponCode = $('#wbs-pos-coupon-input').val().trim();

        if (!couponCode) {
            $('#wbs-pos-coupon-message').html('<span class="error">Please enter a coupon code</span>');
            return;
        }

        $('#wbs-pos-coupon-apply').prop('disabled', true).text('Validating...');

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
                    updateCartTotals();
                    closeCouponModal();
                    showNotification('Coupon applied successfully!', 'success');
                } else {
                    $('#wbs-pos-coupon-message').html('<span class="error">' + (response.data || 'Invalid coupon code') + '</span>');
                }
            },
            error: function() {
                $('#wbs-pos-coupon-message').html('<span class="error">Error validating coupon</span>');
            },
            complete: function() {
                $('#wbs-pos-coupon-apply').prop('disabled', false).text('Apply');
            }
        });
    }

    function closeCouponModal() {
        $('#wbs-pos-coupon-modal').remove();
    }

    // ========================================
    // CLEAR CART
    // ========================================
    $('#wbs-pos-clear-cart-btn').on('click', function() {
        if (cartItems.length === 0) {
            return;
        }

        showConfirmModal('Are you sure you want to clear the cart?', function() {
            cartItems = [];
            itemCounter = 0;
            appliedCoupon = null;
            keypadValue = '0.00';
            updateCartDisplay();
            updateKeypadDisplay();
            $('#wbs-pos-customer-email').val('');
            showNotification('Cart cleared', 'success');
        });
    });

    // ========================================
    // COMPLETE SALE
    // ========================================
    $('#wbs-pos-complete-sale-btn').on('click', function() {
        if (cartItems.length === 0) {
            showNotification('Please add at least one item to the cart', 'error');
            return;
        }

        const customerEmail = $('#wbs-pos-customer-email').val().trim();
        const $btn = $(this);

        const orderData = {
            items: cartItems,
            customer_email: customerEmail,
            customer_name: '',
            order_status: 'completed',
            order_notes: 'Created via POS',
            coupon_code: appliedCoupon ? appliedCoupon.code : null
        };

        // Add loading state with animated spinner
        $btn.prop('disabled', true)
            .addClass('wbs-pos-btn-loading')
            .html('<span class="wbs-pos-spinner"></span> Processing...');

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
                    const total = $('#wbs-pos-total').text();

                    // Show success modal
                    showSuccessModal(response.data.order_number, total);

                    // Reset cart
                    cartItems = [];
                    itemCounter = 0;
                    appliedCoupon = null;
                    keypadValue = '0.00';
                    updateCartDisplay();
                    updateKeypadDisplay();
                    $('#wbs-pos-customer-email').val('');
                    $('#wbs-pos-scan-input').focus();
                } else {
                    showNotification('Error creating order: ' + response.data, 'error');
                }
            },
            error: function() {
                showNotification('Error creating order. Please try again.', 'error');
            },
            complete: function() {
                $btn.prop('disabled', false)
                    .removeClass('wbs-pos-btn-loading')
                    .html('✓ COMPLETE SALE');
            }
        });
    });


    // ========================================
    // PAGE REFRESH WARNING & CART PERSISTENCE
    // ========================================

    // Save cart to localStorage whenever it changes
    function saveCartToStorage() {
        if (cartItems.length > 0) {
            localStorage.setItem('wbs_pos_cart', JSON.stringify({
                items: cartItems,
                itemCounter: itemCounter,
                timestamp: Date.now()
            }));
        } else {
            localStorage.removeItem('wbs_pos_cart');
        }
    }

    // Load cart from localStorage on page load
    function loadCartFromStorage() {
        const saved = localStorage.getItem('wbs_pos_cart');
        if (saved) {
            try {
                const data = JSON.parse(saved);
                const age = Date.now() - data.timestamp;

                // Only restore if less than 1 hour old
                if (age < 3600000) {
                    // Show custom modal asking if they want to restore
                    showRestoreCartModal(data);
                } else {
                    // Too old, clear it
                    localStorage.removeItem('wbs_pos_cart');
                }
            } catch (e) {
                console.error('Failed to parse saved cart:', e);
                localStorage.removeItem('wbs_pos_cart');
            }
        }
    }

    // Show modal asking to restore previous cart
    function showRestoreCartModal(data) {
        const itemCount = data.items.length;
        const modal = $(`
            <div class="wbs-pos-confirm-modal-overlay">
                <div class="wbs-pos-confirm-modal">
                    <div class="wbs-pos-confirm-icon">🛒</div>
                    <h2 class="wbs-pos-confirm-title">Restore Previous Cart?</h2>
                    <p class="wbs-pos-confirm-message">
                        You have ${itemCount} item${itemCount !== 1 ? 's' : ''} from a previous session.
                        <br>Would you like to restore them?
                    </p>
                    <div class="wbs-pos-confirm-buttons">
                        <button type="button" class="wbs-pos-confirm-btn wbs-pos-confirm-cancel">Start Fresh</button>
                        <button type="button" class="wbs-pos-confirm-btn wbs-pos-confirm-ok">Restore Cart</button>
                    </div>
                </div>
            </div>
        `);

        $('body').append(modal);

        // Restore cart
        modal.find('.wbs-pos-confirm-ok').on('click', function() {
            cartItems = data.items;
            itemCounter = data.itemCounter;
            updateCartDisplay();
            modal.remove();
        });

        // Start fresh
        modal.find('.wbs-pos-confirm-cancel').on('click', function() {
            localStorage.removeItem('wbs_pos_cart');
            modal.remove();
        });

        // Show modal
        setTimeout(function() {
            modal.addClass('show');
        }, 100);
    }

    // Override updateCartDisplay to also save to storage
    const originalUpdateCartDisplay = updateCartDisplay;
    updateCartDisplay = function() {
        originalUpdateCartDisplay();
        saveCartToStorage();
    };

    // Load cart on page load
    $(document).ready(function() {
        loadCartFromStorage();
    });

    // Warn user before leaving/refreshing if cart has items
    window.addEventListener('beforeunload', function(e) {
        if (cartItems.length > 0) {
            saveCartToStorage(); // Save one last time
            // Show browser's native warning
            e.preventDefault();
            e.returnValue = '';
            return '';
        }
    });

    // ========================================
    // MODALS & NOTIFICATIONS
    // ========================================
    function showNotification(message, type) {
        // REBUILT - Create centered modal popup
        const modal = $(`
            <div class="wbs-pos-quick-modal wbs-pos-quick-modal-${type}">
                <div class="wbs-pos-quick-modal-content">
                    <div class="wbs-pos-quick-modal-inner">
                        <div class="wbs-pos-quick-modal-icon">
                            ${type === 'error' ? '⚠️' : '✓'}
                        </div>
                        <div class="wbs-pos-quick-modal-message">${message}</div>
                    </div>
                </div>
            </div>
        `);

        $('body').append(modal);

        // Click anywhere to close
        modal.on('click', function() {
            modal.removeClass('show');
            setTimeout(function() {
                modal.remove();
            }, 400);
        });

        // Fade in with scale animation
        setTimeout(function() {
            modal.addClass('show');
        }, 100);

        // Auto-close after 2 seconds
        setTimeout(function() {
            modal.removeClass('show');
            setTimeout(function() {
                modal.remove();
            }, 400);
        }, 2000);
    }

    function showConfirmModal(message, onConfirm) {
        const modalHtml = `
            <div id="wbs-pos-confirm-modal" class="wbs-pos-modal">
                <div class="wbs-pos-modal-content">
                    <h3>Confirm</h3>
                    <p>${message}</p>
                    <div class="wbs-pos-modal-buttons">
                        <button type="button" id="wbs-pos-confirm-yes" class="wbs-pos-modal-btn wbs-pos-modal-btn-primary">Yes</button>
                        <button type="button" id="wbs-pos-confirm-no" class="wbs-pos-modal-btn wbs-pos-modal-btn-secondary">No</button>
                    </div>
                </div>
            </div>
        `;

        $('body').append(modalHtml);

        $('#wbs-pos-confirm-yes').on('click', function() {
            $('#wbs-pos-confirm-modal').remove();
            onConfirm();
        });

        $('#wbs-pos-confirm-no').on('click', function() {
            $('#wbs-pos-confirm-modal').remove();
        });
    }

    function showSuccessModal(orderNumber, total) {
        const modalHtml = `
            <div id="wbs-pos-success-modal" class="wbs-pos-modal">
                <div class="wbs-pos-modal-content wbs-pos-success-content">
                    <div class="wbs-pos-success-icon">✓</div>
                    <h3>Order Complete!</h3>
                    <p class="wbs-pos-order-number">Order #${orderNumber}</p>
                    <p class="wbs-pos-order-total">Total: $${total}</p>
                    <p class="wbs-pos-order-instruction">Please enter this amount into Square POS</p>
                    <button type="button" id="wbs-pos-success-close" class="wbs-pos-modal-btn wbs-pos-modal-btn-primary">OK</button>
                </div>
            </div>
        `;

        $('body').append(modalHtml);

        $('#wbs-pos-success-close').on('click', function() {
            $('#wbs-pos-success-modal').remove();
        });
    }
});
