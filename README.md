# WooCommerce Barcode Scanner Plugin

A comprehensive WordPress plugin that allows you to scan barcodes and quickly find and edit WooCommerce products.

## Features

- **Barcode Scanning**: Scan barcodes using any barcode scanner or manually enter SKUs
- **Quick Product Lookup**: Find products by SKU or custom barcode field
- **Real-time Editing**: Edit product information without leaving the scanner interface
- **Stock Management**: Easy quantity controls with +/- buttons
- **Comprehensive Fields**: Edit SKU, prices, stock status, categories, and more

## Installation

1. Upload the `woo-barcode-scanner` folder to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Make sure WooCommerce is installed and activated
4. Navigate to 'Barcode Scanner' in your WordPress admin menu

## Usage

### Setting up Barcodes

1. Edit any WooCommerce product
2. Add a custom field called `_barcode` with your barcode value
3. Or use the built-in SKU field for barcode scanning

### Using the Scanner

1. Go to **Barcode Scanner** in your WordPress admin
2. Use a barcode scanner or manually type the barcode/SKU
3. Press Enter or click "Search"
4. Edit the product information directly
5. Click "Update Product" to save changes

### Supported Fields

- Product Title (read-only)
- SKU
- Regular Price
- Sale Price
- Barcode (custom field)
- Categories
- Product Status (Publish/Private/Draft)
- Stock Status (In Stock/Out of Stock/On Backorder)
- Quantity (with +/- controls)

## Requirements

- WordPress 5.0+
- WooCommerce 5.0+
- PHP 7.4+

## Keyboard Shortcuts

- **Ctrl + /** - Focus on scan input field

## Barcode Scanner Compatibility

This plugin works with any USB barcode scanner that acts as a keyboard input device. Most standard barcode scanners will work out of the box.

## Custom Barcode Field

The plugin uses a custom meta field `_barcode` to store barcode values. You can:

1. Add this field manually to products
2. Import barcode data via CSV
3. Use other plugins to populate barcode values

## Support

For issues and feature requests, please create an issue in the plugin repository.

## License

GPL v2 or later