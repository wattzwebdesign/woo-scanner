jQuery(document).ready(function($) {
    let scanInput = $('#wbs-scan-input');
    let scanBtn = $('#wbs-scan-btn');
    let scanResult = $('#wbs-scan-result');
    let productEditor = $('#wbs-product-editor');
    let productForm = $('#wbs-product-form');
    
    scanInput.focus();
    
    scanInput.on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            searchProduct();
        }
    });
    
    scanBtn.on('click', function() {
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
                    populateProductForm(response.data);
                    scanResult.html('<div class="notice notice-success"><p>Product found: ' + response.data.title + '</p></div>');
                    productEditor.show();
                } else {
                    showError(response.data);
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
        $('#wbs-product-title-display').text(data.title);
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