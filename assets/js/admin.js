jQuery(document).ready(function($) {
    var scanInput = $('#wbs-scan-input');
    var scanBtn = $('#wbs-scan-btn');
    var scanResult = $('#wbs-scan-result');
    var productEditor = $('#wbs-product-editor');
    var productForm = $('#wbs-product-form');
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
            }, 500); // 500ms delay to wait for complete typing (same as Create Order)
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
                nonce: wbs_ajax.nonce,
                scan_context: 'main_scanner'
            },
            success: function(response) {
                if (response.success) {
                    populateProductForm(response.data);

                    // Build success message
                    let successMessage = '<div class="notice notice-success"><p>Product found: ' + response.data.title + '</p></div>';

                    scanResult.html(successMessage);
                    productEditor.show();

                    // Clear order column first
                    clearOrderColumn();

                    // Asynchronously load order information if product is out of stock
                    if (response.data.stock_status === 'outofstock') {
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
    
    function populateProductForm(data) {
        $('#wbs-product-id').val(data.id);
        $('#wbs-product-id-display').text(data.id);
        $('#wbs-product-title').val(data.title);
        $('#wbs-sku').val(data.sku);
        $('#wbs-regular-price').val(data.regular_price);
        $('#wbs-sale-price').val(data.sale_price);
        $('#wbs-stock-status').val(data.stock_status);
        $('#wbs-product-status').val(data.status);
        $('#wbs-consignor').val(data.consignor_number);
        $('#wbs-consignor-id').val(data.consignor_id);
        
        if (data.manage_stock && data.stock_quantity !== null) {
            $('#wbs-quantity').val(data.stock_quantity);
        } else {
            $('#wbs-quantity').val('');
        }
        
        $('#wbs-categories').val(data.categories);
        
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
    }
    
    $('.wbs-qty-decrease').on('click', function() {
        let qtyInput = $('#wbs-quantity');
        let currentQty = parseInt(qtyInput.val()) || 0;
        if (currentQty > 0) {
            qtyInput.val(currentQty - 1);
        }
    });
    
    $('.wbs-qty-increase').on('click', function() {
        let qtyInput = $('#wbs-quantity');
        let currentQty = parseInt(qtyInput.val()) || 0;
        qtyInput.val(currentQty + 1);
    });
    
    productForm.on('submit', function(e) {
        e.preventDefault();
        updateProduct();
    });
    
    function updateProduct() {
        let formData = productForm.serialize();
        formData += '&action=wbs_update_product&nonce=' + wbs_ajax.nonce;
        
        $('#wbs-save-product').prop('disabled', true).text('Updating...');
        
        $.ajax({
            url: wbs_ajax.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    showSuccess('Product updated successfully!');
                    // Refresh the product data to show updated values
                    refreshCurrentProduct();
                } else {
                    showError(response.data);
                }
            },
            error: function() {
                showError('Error updating product');
            },
            complete: function() {
                $('#wbs-save-product').prop('disabled', false).text('Update Product');
            }
        });
    }
    
    $('#wbs-clear-form').on('click', function() {
        productEditor.hide();
        productForm[0].reset();
        scanResult.empty();
        scanInput.val('').focus();
        $('#wbs-product-image').hide();
        $('#wbs-no-image').show();
        $('#wbs-stock-indicator').removeClass('wbs-in-stock wbs-out-of-stock wbs-backorder');
        clearOrderColumn();
    });
    
    $(document).on('change', '#wbs-stock-status', function() {
        updateStockStatusIndicator($(this).val());
    });
    
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

    function showError(message) {
        scanResult.html('<div class="notice notice-error"><p>' + message + '</p></div>');
    }

    function showSuccess(message) {
        scanResult.html('<div class="notice notice-success"><p>' + message + '</p></div>');
    }
    
    function refreshCurrentProduct() {
        const currentProductId = $('#wbs-product-id').val();
        if (currentProductId) {
            // Re-search the current product to get updated data
            $.ajax({
                url: wbs_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wbs_search_product',
                    search_term: $('#wbs-sku').val(),
                    nonce: wbs_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        populateProductForm(response.data);
                    }
                }
            });
        }
    }
    
    scanInput.on('focus', function() {
        $(this).select();
    });
    
    // Full screen functionality
    $('#wbs-fullscreen-toggle').on('click', function() {
        const element = document.documentElement; // Use entire document
        const button = $(this);
        
        if (document.fullscreenElement) {
            // Exit full screen
            document.exitFullscreen();
        } else {
            // Enter full screen
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