<?php
/**
 * Debug script to test audit logging
 * Add this temporarily to check if tables exist and can insert data
 */

// Load WordPress
require_once('../../../wp-load.php');

if (!current_user_can('manage_options')) {
    die('Unauthorized');
}

global $wpdb;

echo "<h2>WooCommerce Barcode Scanner - Audit Debug</h2>";

// Check if classes are loaded
echo "<h3>1. Classes Loaded:</h3>";
echo "WBS_Audit_DB exists: " . (class_exists('WBS_Audit_DB') ? 'YES' : 'NO') . "<br>";
echo "WBS_Audit_Logger exists: " . (class_exists('WBS_Audit_Logger') ? 'YES' : 'NO') . "<br>";

// Check if tables exist
echo "<h3>2. Tables Exist:</h3>";
$scan_audits_table = $wpdb->prefix . 'wbs_scan_audits';
$order_scans_table = $wpdb->prefix . 'wbs_order_scans';

$scan_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$scan_audits_table}'");
$order_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$order_scans_table}'");

echo "Scan audits table ({$scan_audits_table}): " . ($scan_table_exists ? 'EXISTS' : 'MISSING') . "<br>";
echo "Order scans table ({$order_scans_table}): " . ($order_table_exists ? 'EXISTS' : 'MISSING') . "<br>";

if ($scan_table_exists) {
    // Show table structure
    echo "<h3>3. Scan Audits Table Structure:</h3>";
    $columns = $wpdb->get_results("DESCRIBE {$scan_audits_table}");
    echo "<pre>";
    print_r($columns);
    echo "</pre>";

    // Count existing records
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$scan_audits_table}");
    echo "<h3>4. Existing Records: {$count}</h3>";

    if ($count > 0) {
        echo "<h3>5. Sample Records:</h3>";
        $samples = $wpdb->get_results("SELECT * FROM {$scan_audits_table} ORDER BY created_at DESC LIMIT 5");
        echo "<pre>";
        print_r($samples);
        echo "</pre>";
    }

    // Test insert
    echo "<h3>6. Test Insert:</h3>";
    $test_result = $wpdb->insert(
        $scan_audits_table,
        array(
            'user_id' => get_current_user_id(),
            'user_display_name' => wp_get_current_user()->display_name,
            'product_id' => null,
            'product_sku' => 'TEST-SKU',
            'product_name' => 'Test Product',
            'scan_context' => 'test',
            'search_term' => 'TEST',
            'scan_success' => 1,
            'created_at' => current_time('mysql'),
        ),
        array('%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s')
    );

    if ($test_result === false) {
        echo "FAILED: " . $wpdb->last_error . "<br>";
        echo "Last query: " . $wpdb->last_query . "<br>";
    } else {
        echo "SUCCESS: Insert ID = " . $wpdb->insert_id . "<br>";
        // Clean up test record
        $wpdb->delete($scan_audits_table, array('id' => $wpdb->insert_id), array('%d'));
    }
} else {
    echo "<h3>3. Tables Missing - Run Activation</h3>";
    echo "You need to deactivate and reactivate the plugin to create tables.<br>";
    echo "<br><strong>OR run this manually:</strong><br>";
    echo "<pre>";
    echo "require_once(WBS_PLUGIN_PATH . 'includes/class-wbs-audit-db.php');\n";
    echo "WBS_Audit_DB::create_tables();";
    echo "</pre>";
}

// Check for errors in last scan attempt
echo "<h3>7. WordPress Debug Info:</h3>";
echo "WP_DEBUG: " . (defined('WP_DEBUG') && WP_DEBUG ? 'ON' : 'OFF') . "<br>";
echo "Current user ID: " . get_current_user_id() . "<br>";
echo "Current user name: " . wp_get_current_user()->display_name . "<br>";
