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

    // Auto-focus on scan input
    $('#wbs-pos-scan-input').focus();

    // ========================================
    // MODE TOGGLE
    // ========================================
    $('input[name="wbs-pos-mode"]').on('change', function() {
        inputMode = $(this).val();
        const scanInput = $('#wbs-pos-scan-input');

        if (inputMode === 'scan') {
            scanInput.attr('placeholder', 'Scan product/order');
        } else {
            scanInput.attr('placeholder', 'Type SKU and press Enter');
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
                nonce: wbs_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    addProductToCart(response.data);
                    scanInput.val('').focus();
                } else {
                    alert('Product not found: ' + searchTerm);
                    scanInput.val('').focus();
                }
            },
            error: function() {
                alert('Error searching for product');
                scanInput.val('').focus();
            }
        });
    }

    // ========================================
    // CART MANAGEMENT
    // ========================================
    function addProductToCart(product) {
        // Check if product already exists
        let existingItem = cartItems.find(item => item.product_id === product.id);

        if (existingItem) {
            // Increase quantity
            existingItem.quantity += 1;
            // Move existing item to top
            cartItems = cartItems.filter(item => item.product_id !== product.id);
            cartItems.unshift(existingItem);
        } else {
            // Add new item to the top
            const item = {
                id: ++itemCounter,
                product_id: product.id,
                sku: product.sku,
                name: product.title,
                price: parseFloat(product.sale_price || product.regular_price || 0),
                quantity: 1,
                image_url: product.image_url,
                category_ids: product.category_ids || [],
                is_custom: false
            };

            cartItems.unshift(item);
        }

        updateCartDisplay();
    }

    function updateCartDisplay() {
        const cartItemsContainer = $('#wbs-pos-cart-items');
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
                if (appliedCoupon && !item.is_custom) {
                    const isEligible = isItemEligibleForCoupon(item, appliedCoupon);
                    if (isEligible) {
                        discountBadge = '<span class="wbs-pos-discount-badge active">Discount Applied</span>';
                    } else {
                        discountBadge = '<span class="wbs-pos-discount-badge not-eligible">Discount Not Applied</span>';
                    }
                }

                cartItemsContainer.append(`
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
        }

        updateCartTotals();
        updateCartHeader();
    }

    function isItemEligibleForCoupon(item, coupon) {
        // Custom items are never eligible for coupons
        if (item.is_custom) {
            return false;
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
            alert('Please enter a valid amount');
            return;
        }

        const customItem = {
            id: ++itemCounter,
            product_id: 0,
            sku: 'CUSTOM-' + Date.now(),
            name: 'Custom Item',
            price: amount,
            quantity: 1,
            image_url: '',
            is_custom: true
        };

        cartItems.unshift(customItem); // Add to top
        updateCartDisplay();

        // Reset keypad
        keypadValue = '0.00';
        updateKeypadDisplay();
    });

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

        const orderData = {
            items: cartItems,
            customer_email: customerEmail,
            customer_name: '',
            order_status: 'completed',
            order_notes: 'Created via POS',
            coupon_code: appliedCoupon ? appliedCoupon.code : null
        };

        $(this).prop('disabled', true).text('Processing...');

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
                $('#wbs-pos-complete-sale-btn').prop('disabled', false).text('✓ COMPLETE SALE');
            }
        });
    });

    // ========================================
    // FULLSCREEN
    // ========================================
    $('#wbs-pos-fullscreen-toggle').on('click', function() {
        const element = document.documentElement;

        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else {
            if (element.requestFullscreen) {
                element.requestFullscreen();
            } else if (element.webkitRequestFullscreen) {
                element.webkitRequestFullscreen();
            } else if (element.mozRequestFullScreen) {
                element.mozRequestFullScreen();
            } else if (element.msRequestFullscreen) {
                element.msRequestFullscreen();
            }
        }
    });

    // Update fullscreen button text
    $(document).on('fullscreenchange webkitfullscreenchange mozfullscreenchange MSFullscreenChange', function() {
        const button = $('#wbs-pos-fullscreen-toggle');

        if (document.fullscreenElement) {
            button.text('Exit Fullscreen');
            $('body').addClass('wbs-pos-fullscreen');
        } else {
            button.text('⛶ Full Screen');
            $('body').removeClass('wbs-pos-fullscreen');
        }
    });

    // ========================================
    // MODALS & NOTIFICATIONS
    // ========================================
    function showNotification(message, type) {
        const notification = $(`
            <div class="wbs-pos-notification wbs-pos-notification-${type}">
                ${message}
            </div>
        `);

        $('body').append(notification);

        setTimeout(function() {
            notification.addClass('show');
        }, 10);

        setTimeout(function() {
            notification.removeClass('show');
            setTimeout(function() {
                notification.remove();
            }, 300);
        }, 3000);
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
