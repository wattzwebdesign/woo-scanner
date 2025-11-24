<?php
/**
 * Retroactive Order Linking Tool
 *
 * This script links existing orders to scan records retroactively.
 * Run this once after installing the audit system to link old orders.
 *
 * Access via: /wp-content/plugins/woo-scanner/retroactive-link-orders.php
 * Delete this file after running for security.
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
    die('Could not find WordPress. Please access this via your browser.');
}

// Check permissions
if (!current_user_can('manage_options')) {
    die('Unauthorized. Please log in as an administrator.');
}

// Load required classes
require_once(__DIR__ . '/includes/class-wbs-audit-db.php');
require_once(__DIR__ . '/includes/class-wbs-audit-logger.php');

?>
<!DOCTYPE html>
<html>
<head>
    <title>Retroactive Order Linking</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            max-width: 800px;
            margin: 40px auto;
            padding: 20px;
            background: #f0f0f1;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1d2327;
            border-bottom: 2px solid #2271b1;
            padding-bottom: 10px;
        }
        .info-box {
            background: #e7f5ff;
            border-left: 4px solid #2271b1;
            padding: 15px;
            margin: 20px 0;
        }
        .success-box {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin: 20px 0;
        }
        .stats {
            background: #f9f9f9;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .stats table {
            width: 100%;
            border-collapse: collapse;
        }
        .stats td {
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }
        .stats td:first-child {
            font-weight: 600;
            width: 60%;
        }
        button {
            background: #2271b1;
            color: white;
            border: none;
            padding: 12px 24px;
            font-size: 14px;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 10px;
        }
        button:hover {
            background: #135e96;
        }
        button:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Retroactive Order Linking</h1>

        <?php if (isset($_POST['run_linking'])) : ?>
            <?php
            $days_back = isset($_POST['days_back']) ? absint($_POST['days_back']) : 90;
            $start_time = microtime(true);

            $stats = WBS_Audit_Logger::retroactively_link_orders($days_back);

            $end_time = microtime(true);
            $execution_time = round($end_time - $start_time, 2);
            ?>

            <div class="success-box">
                <h2>✓ Linking Complete!</h2>
                <p>Processing finished in <?php echo $execution_time; ?> seconds.</p>
            </div>

            <div class="stats">
                <h3>Results:</h3>
                <table>
                    <?php if (isset($stats['error'])) : ?>
                        <tr>
                            <td colspan="2" style="color: red;">
                                <strong>Error:</strong> <?php echo esc_html($stats['error']); ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <tr>
                            <td>Orders Processed:</td>
                            <td><strong><?php echo number_format($stats['orders_processed']); ?></strong></td>
                        </tr>
                        <tr>
                            <td>Orders with Linked Scans:</td>
                            <td><strong><?php echo number_format($stats['orders_with_links']); ?></strong></td>
                        </tr>
                        <tr>
                            <td>Total Scans Linked:</td>
                            <td><strong><?php echo number_format($stats['scans_linked']); ?></strong></td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>

            <p>
                <a href="<?php echo admin_url('admin.php?page=wbs-scan-audit'); ?>" style="color: #2271b1; text-decoration: none;">
                    → View Scan Audit Page
                </a>
            </p>

            <div class="warning">
                <strong>Security Note:</strong> You can now delete this file:
                <code><?php echo __FILE__; ?></code>
            </div>

        <?php else : ?>

            <div class="info-box">
                <h3>What does this do?</h3>
                <p>This tool will retroactively link existing WooCommerce orders to scan records in the audit log.</p>
                <p><strong>How it works:</strong></p>
                <ul>
                    <li>Looks at orders from the last X days (you choose)</li>
                    <li>Finds scan records that occurred within 30 minutes before each order</li>
                    <li>Links matching products from scans to orders</li>
                    <li>Only links scans that haven't already been linked</li>
                </ul>
            </div>

            <div class="warning">
                <strong>Important:</strong>
                <ul>
                    <li>This only works if you already have scan audit data</li>
                    <li>Safe to run multiple times (won't create duplicates)</li>
                    <li>Can take a while if you have many orders</li>
                    <li>Matches based on user, product, and time proximity</li>
                </ul>
            </div>

            <form method="post">
                <h3>Configuration:</h3>
                <p>
                    <label>
                        <strong>Process orders from the last:</strong><br>
                        <select name="days_back" style="padding: 8px; margin-top: 5px;">
                            <option value="7">7 days</option>
                            <option value="30">30 days</option>
                            <option value="60">60 days</option>
                            <option value="90" selected>90 days</option>
                            <option value="180">180 days (6 months)</option>
                            <option value="365">365 days (1 year)</option>
                        </select>
                    </label>
                </p>

                <button type="submit" name="run_linking" value="1">
                    Start Retroactive Linking
                </button>
            </form>

            <div class="warning" style="margin-top: 30px;">
                <strong>After Running:</strong> Delete this file for security.
            </div>

        <?php endif; ?>
    </div>
</body>
</html>
