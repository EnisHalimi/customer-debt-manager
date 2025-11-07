<?php
/**
 * Plugin Name: Customer Debt Manager
 * Plugin URI: https://github.com/EnisHalimi/customer-debt-manager
 * Description: Allows customers to order on debt/credit and provides debt tracking for both admin and customers. Compatible with WooCommerce HPOS.
 * Version: 1.0.2
 * Author: Enis Halimi
 * Author URI: https://github.com/EnisHalimi
 * Text Domain: customer-debt-manager
 * Requires at least: 5.0
 * Tested up to: 6.3
 * WC requires at least: 4.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins: woocommerce
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CDM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CDM_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('CDM_VERSION', '1.0.2');

// Declare HPOS compatibility early
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Plugin activation hook
register_activation_hook(__FILE__, 'cdm_activate_plugin');
register_deactivation_hook(__FILE__, 'cdm_deactivate_plugin');

function cdm_activate_plugin() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('Customer Debt Manager requires WooCommerce to be installed and active.', 'customer-debt-manager'));
    }
    
    // Create database tables
    require_once CDM_PLUGIN_PATH . 'includes/class-cdm-database.php';
    $database = new CDM_Database();
    $database->create_tables();
    
    // Set default options
    add_option('cdm_version', CDM_VERSION);
    
    // Create debt page
    $page = get_page_by_path('my-debt');
    if (!$page) {
        $page_data = array(
            'post_title'    => __('My Debt Account', 'customer-debt-manager'),
            'post_name'     => 'my-debt',
            'post_content'  => '[customer_debt_page]',
            'post_status'   => 'publish',
            'post_type'     => 'page',
            'post_author'   => 1,
            'comment_status' => 'closed',
            'ping_status'   => 'closed'
        );
        
        $page_id = wp_insert_post($page_data);
        
        if ($page_id) {
            update_option('cdm_debt_page_id', $page_id);
        }
    } else {
        update_option('cdm_debt_page_id', $page->ID);
    }
    
    // Mark that rewrite rules need to be flushed
    delete_option('cdm_rewrite_rules_flushed');
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

function cdm_deactivate_plugin() {
    // Flush rewrite rules
    flush_rewrite_rules();
}

// Initialize plugin
add_action('plugins_loaded', 'cdm_init_plugin', 20); // Load after other plugins

function cdm_init_plugin() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'cdm_woocommerce_missing_notice');
        return;
    }
    
    // Load and initialize the main plugin class
    new CustomerDebtManager();
}

function cdm_woocommerce_missing_notice() {
    echo '<div class="error"><p><strong>Customer Debt Manager</strong> requires WooCommerce to be installed and active.</p></div>';
}

// Main plugin class
class CustomerDebtManager {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->load_includes();
        $this->init_hooks();
    }
    
    /**
     * Check if HPOS is enabled
     */
    public static function is_hpos_enabled() {
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
            return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        }
        return false;
    }
    
    /**
     * Load required files
     */
    private function load_includes() {
        // Always load database class
        require_once CDM_PLUGIN_PATH . 'includes/class-cdm-database.php';
        
        // Note: Debt gateway disabled - COD orders automatically become debts
        // Only load WooCommerce dependent classes if available
        // if (class_exists('WC_Payment_Gateway')) {
        //     require_once CDM_PLUGIN_PATH . 'includes/class-cdm-debt-gateway.php';
        // }
        
        // Load other classes
        require_once CDM_PLUGIN_PATH . 'includes/class-cdm-admin.php';
        require_once CDM_PLUGIN_PATH . 'includes/class-cdm-frontend.php';
        require_once CDM_PLUGIN_PATH . 'includes/class-cdm-order-handler.php';
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Note: Debt gateway disabled - COD orders automatically become debts
        // add_filter('woocommerce_payment_gateways', array($this, 'add_debt_gateway'));
        
        // Initialize admin functionality
        if (is_admin()) {
            new CDM_Admin();
        }
        
        // Initialize frontend functionality
        new CDM_Frontend();
        
        // Initialize order handler
        new CDM_Order_Handler();
    }
    
    /**
     * Add debt payment gateway to WooCommerce
     */
    public function add_debt_gateway($gateways) {
        if (class_exists('CDM_Debt_Gateway')) {
            $gateways[] = 'CDM_Debt_Gateway';
        }
        return $gateways;
    }
}
