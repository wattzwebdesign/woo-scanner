<?php
/**
 * Manual table creation script
 *
 * If you don't want to deactivate/reactivate the plugin,
 * you can run this script once to create the audit tables.
 *
 * Access via: /wp-content/plugins/woo-scanner/create-audit-tables.php
 * Then delete this file for security.
 */

// Find WordPress
$wp_load_paths = array(
    __DIR__ . '/../../../../wp-load.php',
    __DIR__ . '/../../../wp-load.php',
    __DIR__ . '/../../wp-load.php',
);

foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once($path);
        break;
    }
}

if (!defined('ABSPATH')) {
    die('Could not find WordPress. Please access this via your browser at: your-site.com/wp-content/plugins/woo-scanner/create-audit-tables.php');
}

// Check permissions
if (!current_user_can('manage_options')) {
    die('Unauthorized. Please log in as an administrator.');
}

// Load the audit DB class
require_once(__DIR__ . '/includes/class-wbs-audit-db.php');

echo "<h1>WooCommerce Barcode Scanner - Create Audit Tables</h1>";

// Create tables
WBS_Audit_DB::create_tables();

// Check if successful
global $wpdb;
$scan_table = $wpdb->prefix . 'wbs_scan_audits';
$order_table = $wpdb->prefix . 'wbs_order_scans';

$scan_exists = $wpdb->get_var("SHOW TABLES LIKE '{$scan_table}'");
$order_exists = $wpdb->get_var("SHOW TABLES LIKE '{$order_table}'");

echo "<h2>Results:</h2>";

if ($scan_exists && $order_exists) {
    echo "<p style='color: green; font-size: 18px;'><strong>✓ SUCCESS!</strong> Both tables were created successfully.</p>";
    echo "<ul>";
    echo "<li><strong>{$scan_table}</strong> - ✓ Created</li>";
    echo "<li><strong>{$order_table}</strong> - ✓ Created</li>";
    echo "</ul>";
    echo "<p>You can now delete this file for security: <code>" . __FILE__ . "</code></p>";
    echo "<p><a href='" . admin_url('admin.php?page=wbs-scan-audit') . "'>Go to Scan Audit Page →</a></p>";
} else {
    echo "<p style='color: red; font-size: 18px;'><strong>✗ ERROR!</strong> Tables were not created.</p>";
    echo "<ul>";
    echo "<li><strong>{$scan_table}</strong> - " . ($scan_exists ? '✓ Created' : '✗ Missing') . "</li>";
    echo "<li><strong>{$order_table}</strong> - " . ($order_exists ? '✓ Created' : '✗ Missing') . "</li>";
    echo "</ul>";

    echo "<h3>Debug Information:</h3>";
    echo "<p>Check your WordPress debug.log file for more details.</p>";
    echo "<p>Database user: " . DB_USER . "</p>";
    echo "<p>Database name: " . DB_NAME . "</p>";
    echo "<p>WordPress table prefix: " . $wpdb->prefix . "</p>";

    // Try to get MySQL error
    if ($wpdb->last_error) {
        echo "<p style='color: red;'>MySQL Error: " . $wpdb->last_error . "</p>";
    }
}

echo "<hr>";
echo "<h3>Alternative: Deactivate & Reactivate Plugin</h3>";
echo "<p>You can also create the tables by:</p>";
echo "<ol>";
echo "<li>Go to Plugins page</li>";
echo "<li>Deactivate 'WooCommerce Barcode Scanner'</li>";
echo "<li>Reactivate 'WooCommerce Barcode Scanner'</li>";
echo "</ol>";
