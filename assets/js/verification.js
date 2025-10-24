jQuery(document).ready(function($) {
    var scanInput = $('#wbs-scan-input');
    var scanBtn = $('#wbs-scan-btn');
    var scanResult = $('#wbs-scan-result');
    var productVerification = $('#wbs-product-verification');
    var searchTimeout;
    var inputMode = 'scan'; // Default to scan mode

    if (scanInput.length === 0) {
        return;
    }

    scanInput.focus();

    // Handle mode toggle
    $('input[name="wbs-input-mode"]').on('change', function() {
        inputMode = $(this).val();

        if (inputMode === 'scan') {
            scanInput.attr('placeholder', 'Scan barcode or enter SKU...');
        } else {
            scanInput.attr('placeholder', 'Type SKU and press Enter or click Search...');
        }

        scanInput.focus();
    });

    // Auto-search while typing with delay for barcode scanners (only in scan mode)
    scanInput.on('input', function() {
        clearTimeout(searchTimeout);
        const searchTerm = $(this).val().trim();

        // Only auto-search in scan mode
        if (inputMode === 'scan' && searchTerm.length >= 3) {
            searchTimeout = setTimeout(function() {
                const finalValue = scanInput.val().trim();
                if (finalValue.length >= 3) {
                    searchProduct();
                }
            }, 500); // 500ms delay to wait for complete typing
        }
    });

    // Keep Enter key functionality for manual entry (works in both modes)
    scanInput.on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            clearTimeout(searchTimeout);
            searchProduct();
        }
    });

    scanBtn.on('click', function() {
        clearTimeout(searchTimeout);
        searchProduct();
    });

    function searchProduct() {
        let searchTerm = scanInput.val().trim();

        if (!searchTerm) {
            showError('Please enter a barcode or SKU');
            scanInput.focus();
            return;
        }

        scanBtn.prop('disabled', true).text('Searching...');

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
                    populateProductDisplay(response.data);

                    let successMessage = '<div class="notice notice-success"><p>Product found: ' + response.data.title + '</p></div>';
                    scanResult.html(successMessage);
                    productVerification.show();

                    // Clear order column first
                    clearOrderColumn();

                    // Auto-mark as verified if product is in stock
                    if (response.data.stock_status === 'instock') {
                        autoMarkAsVerified(response.data.id);
                    } else {
                        // Load order info if out of stock
                        loadProductOrderInfo(response.data.id);
                    }
                } else {
                    showError(response.data);
                    clearOrderColumn();
                }

                // Keep focus on input and clear for next scan
                scanInput.val('').focus().select();
            },
            error: function() {
                showError('Error searching for product');
                scanInput.focus().select();
            },
            complete: function() {
                scanBtn.prop('disabled', false).text('Search');
            }
        });
    }

    function populateProductDisplay(data) {
        $('#wbs-product-id').val(data.id);
        $('#wbs-product-id-display').text(data.id);
        $('#wbs-product-title').text(data.title);
        $('#wbs-sku').text(data.sku);

        // Display price (sale price if available, otherwise regular price)
        let priceDisplay = '';
        if (data.sale_price) {
            priceDisplay = '$' + parseFloat(data.sale_price).toFixed(2);
            if (data.regular_price) {
                priceDisplay += ' <del>$' + parseFloat(data.regular_price).toFixed(2) + '</del>';
            }
        } else if (data.regular_price) {
            priceDisplay = '$' + parseFloat(data.regular_price).toFixed(2);
        } else {
            priceDisplay = 'N/A';
        }
        $('#wbs-price').html(priceDisplay);

        // Display stock status
        let stockStatusText = '';
        switch(data.stock_status) {
            case 'instock':
                stockStatusText = 'In Stock';
                break;
            case 'outofstock':
                stockStatusText = 'Out of Stock';
                break;
            case 'onbackorder':
                stockStatusText = 'On Backorder';
                break;
        }
        $('#wbs-stock-status').text(stockStatusText);

        // Display quantity
        if (data.manage_stock && data.stock_quantity !== null) {
            $('#wbs-quantity').text(data.stock_quantity);
        } else {
            $('#wbs-quantity').text('N/A');
        }

        // Handle product image
        if (data.image_url) {
            $('#wbs-product-image').attr('src', data.image_url).show();
            $('#wbs-no-image').hide();
        } else {
            $('#wbs-product-image').hide();
            $('#wbs-no-image').show();
        }

        // Update stock status indicator
        updateStockStatusIndicator(data.stock_status);

        // Update verification status
        updateVerificationStatus(data.verified, data.stock_status);
    }

    function updateStockStatusIndicator(status) {
        const indicator = $('#wbs-stock-indicator');

        if (indicator.length === 0) {
            return;
        }

        indicator.removeClass('wbs-in-stock wbs-out-of-stock wbs-backorder');

        switch(status) {
            case 'instock':
                indicator.addClass('wbs-in-stock').text('✓ In Stock');
                break;
            case 'outofstock':
                indicator.addClass('wbs-out-of-stock').text('✗ Out of Stock');
                break;
            case 'onbackorder':
                indicator.addClass('wbs-backorder').text('⏳ On Backorder');
                break;
        }
    }

    function updateVerificationStatus(verified, stockStatus) {
        const indicator = $('#wbs-verification-indicator');
        const icon = indicator.find('.wbs-verification-icon');
        const text = indicator.find('.wbs-verification-text');
        const markButton = $('#wbs-mark-verified');

        indicator.removeClass('wbs-verified wbs-not-verified');

        if (verified === 'on-the-floor') {
            indicator.addClass('wbs-verified');
            icon.text('✓');
            text.text('On the Floor');
            markButton.hide();
        } else {
            indicator.addClass('wbs-not-verified');
            icon.text('✗');
            text.text('Not on the Floor');

            // Only show button if product is in stock
            if (stockStatus === 'instock') {
                markButton.show();
            } else {
                markButton.hide();
            }
        }
    }

    $('#wbs-mark-verified').on('click', function() {
        const productId = $('#wbs-product-id').val();

        if (!productId) {
            showError('No product selected');
            return;
        }

        const button = $(this);
        button.prop('disabled', true).text('Updating...');

        $.ajax({
            url: wbs_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wbs_update_verification',
                product_id: productId,
                nonce: wbs_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    showSuccess(response.data.message);
                    updateVerificationStatus('on-the-floor', 'instock');

                    // Auto-clear after 1.5 seconds for quick scanning
                    setTimeout(function() {
                        clearForm();
                    }, 1500);
                } else {
                    showError(response.data);
                    button.prop('disabled', false).text('Mark as On the Floor');
                }
            },
            error: function() {
                showError('Error updating verification');
                button.prop('disabled', false).text('Mark as On the Floor');
            }
        });
    });

    $('#wbs-clear-form').on('click', function() {
        clearForm();
    });

    function clearForm() {
        productVerification.hide();
        scanResult.empty();
        scanInput.val('').focus();
        $('#wbs-product-image').hide();
        $('#wbs-no-image').show();
        $('#wbs-stock-indicator').removeClass('wbs-in-stock wbs-out-of-stock wbs-backorder');
        $('#wbs-verification-indicator').removeClass('wbs-verified wbs-not-verified');
        $('#wbs-mark-verified').prop('disabled', false).text('Mark as On the Floor');
        clearOrderColumn();
    }

    function showError(message) {
        scanResult.html('<div class="notice notice-error"><p>' + message + '</p></div>');
    }

    function showSuccess(message) {
        scanResult.html('<div class="notice notice-success"><p>' + message + '</p></div>');
    }

    function autoMarkAsVerified(productId) {
        $.ajax({
            url: wbs_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wbs_update_verification',
                product_id: productId,
                nonce: wbs_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateVerificationStatus('on-the-floor', 'instock');
                    showSuccess('Product marked as On the Floor');
                    // Don't auto-clear - keep info on screen
                }
            },
            error: function() {
                // Silently fail - just don't update the UI
                console.log('Error auto-marking product as verified');
            }
        });
    }

    function clearOrderColumn() {
        const emptyHtml = `
            <div class="wbs-order-column-empty">
                <span class="dashicons dashicons-cart"></span>
                <p>No order information</p>
                <small>Scan an out-of-stock item to view order details</small>
            </div>
        `;
        $('#wbs-order-column-content').html(emptyHtml);
    }

    function loadProductOrderInfo(productId) {
        // Show loading in order column
        const loadingHtml = '<div class="wbs-order-info-loading">Looking for associated order...</div>';
        $('#wbs-order-column-content').html(loadingHtml);

        $.ajax({
            url: wbs_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wbs_get_product_order',
                product_id: productId,
                nonce: wbs_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Show order info in column
                    $('#wbs-order-column-content').html(buildOrderInfoHtml(response.data));
                } else {
                    // Show empty state if no order found
                    clearOrderColumn();
                }
            },
            error: function() {
                // Show empty state on error
                clearOrderColumn();
            }
        });
    }

    function buildOrderInfoHtml(orderInfo) {
        const statusClass = 'wbs-order-status-' + orderInfo.order_status;
        const statusLabel = orderInfo.order_status.replace(/-/g, ' ').replace(/\b\w/g, l => l.toUpperCase());

        // Format the date and time
        const formattedDateTime = formatOrderDateTime(orderInfo.order_date);

        return `
            <div class="wbs-order-info-box">
                <div class="wbs-order-info-header">
                    <h4>Associated Order</h4>
                    <span class="wbs-order-status ${statusClass}">${statusLabel}</span>
                </div>

                <div class="wbs-order-info-section">
                    <h5 class="wbs-section-title">Order Details</h5>
                    <div class="wbs-order-info-details">
                        <div class="wbs-order-detail">
                            <span class="wbs-detail-label">Order #:</span>
                            <span class="wbs-detail-value">${orderInfo.order_number}</span>
                        </div>
                        <div class="wbs-order-detail">
                            <span class="wbs-detail-label">Date & Time:</span>
                            <span class="wbs-detail-value">${formattedDateTime}</span>
                        </div>
                        <div class="wbs-order-detail">
                            <span class="wbs-detail-label">Items:</span>
                            <span class="wbs-detail-value">${orderInfo.item_count}</span>
                        </div>
                        <div class="wbs-order-detail">
                            <span class="wbs-detail-label">Total:</span>
                            <span class="wbs-detail-value">${orderInfo.order_total}</span>
                        </div>
                        <div class="wbs-order-detail">
                            <span class="wbs-detail-label">Payment Method:</span>
                            <span class="wbs-detail-value">${orderInfo.payment_method}</span>
                        </div>
                    </div>
                </div>

                <div class="wbs-order-info-section">
                    <h5 class="wbs-section-title">Customer Information</h5>
                    <div class="wbs-order-info-details">
                        <div class="wbs-order-detail">
                            <span class="wbs-detail-label">Name:</span>
                            <span class="wbs-detail-value">${orderInfo.customer_name}</span>
                        </div>
                        <div class="wbs-order-detail">
                            <span class="wbs-detail-label">Email:</span>
                            <span class="wbs-detail-value wbs-detail-value-small">${orderInfo.customer_email}</span>
                        </div>
                        <div class="wbs-order-detail">
                            <span class="wbs-detail-label">Phone:</span>
                            <span class="wbs-detail-value">${orderInfo.customer_phone}</span>
                        </div>
                        <div class="wbs-order-detail">
                            <span class="wbs-detail-label">Shipping Address:</span>
                            <span class="wbs-detail-value wbs-detail-value-small">${orderInfo.shipping_address}</span>
                        </div>
                    </div>
                </div>

                <div class="wbs-order-info-actions">
                    <a href="${orderInfo.order_edit_url}" class="button button-primary" target="_blank">
                        View Full Order →
                    </a>
                </div>
            </div>
        `;
    }

    function formatOrderDateTime(dateString) {
        // Parse the date string (format: Y-m-d H:i:s)
        const date = new Date(dateString);

        // Format date as "September 1, 2025"
        const options = { year: 'numeric', month: 'long', day: 'numeric' };
        const formattedDate = date.toLocaleDateString('en-US', options);

        // Format time as "2:00pm"
        let hours = date.getHours();
        const minutes = date.getMinutes();
        const ampm = hours >= 12 ? 'pm' : 'am';
        hours = hours % 12;
        hours = hours ? hours : 12; // the hour '0' should be '12'
        const minutesStr = minutes < 10 ? '0' + minutes : minutes;
        const formattedTime = hours + ':' + minutesStr + ampm;

        return formattedDate + ' at ' + formattedTime;
    }

    scanInput.on('focus', function() {
        $(this).select();
    });

    // Full screen functionality
    $('#wbs-fullscreen-toggle').on('click', function() {
        const element = document.documentElement;
        const button = $(this);

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

    // Update button text when fullscreen changes
    $(document).on('fullscreenchange webkitfullscreenchange mozfullscreenchange MSFullscreenChange', function() {
        const button = $('#wbs-fullscreen-toggle');
        const icon = button.find('.dashicons');

        if (document.fullscreenElement) {
            button.html('<span class="dashicons dashicons-fullscreen-exit-alt"></span> Exit Full Screen');
            $('body').addClass('wbs-fullscreen-active');
        } else {
            button.html('<span class="dashicons dashicons-fullscreen-alt"></span> Full Screen');
            $('body').removeClass('wbs-fullscreen-active');
        }
    });
});
