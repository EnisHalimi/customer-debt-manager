<?php
/**
 * Custom Debt Payment Gateway for WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class CDM_Debt_Gateway extends WC_Payment_Gateway {
    
    /**
     * Maximum debt amount allowed per customer
     * @var float
     */
    public $max_debt_amount;
    
    /**
     * Whether orders require admin approval
     * @var string
     */
    public $require_approval;
    
    public function __construct() {
        $this->id = 'debt_payment';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = __('Pay on Debt', 'customer-debt-manager');
        $this->method_description = __('Allow customers to place orders on debt/credit.', 'customer-debt-manager');
        
        // Load the settings
        $this->init_form_fields();
        $this->init_settings();
        
        // Define user set variables
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->max_debt_amount = $this->get_option('max_debt_amount', 1000);
        $this->require_approval = $this->get_option('require_approval', 'no');
        
        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
    }
    
    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Enable/Disable', 'customer-debt-manager'),
                'type'    => 'checkbox',
                'label'   => __('Enable Debt Payment', 'customer-debt-manager'),
                'default' => 'yes'
            ),
            'title' => array(
                'title'       => __('Title', 'customer-debt-manager'),
                'type'        => 'text',
                'description' => __('This controls the title for the payment method the customer sees during checkout.', 'customer-debt-manager'),
                'default'     => __('Pay on Debt', 'customer-debt-manager'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'customer-debt-manager'),
                'type'        => 'textarea',
                'description' => __('Payment method description that the customer will see on your checkout.', 'customer-debt-manager'),
                'default'     => __('Order now and pay later. Your order will be added to your debt account.', 'customer-debt-manager'),
                'desc_tip'    => true,
            ),
            'max_debt_amount' => array(
                'title'       => __('Maximum Debt Amount', 'customer-debt-manager'),
                'type'        => 'number',
                'description' => __('Maximum total debt amount allowed per customer.', 'customer-debt-manager'),
                'default'     => '1000',
                'desc_tip'    => true,
            ),
            'require_approval' => array(
                'title'   => __('Require Admin Approval', 'customer-debt-manager'),
                'type'    => 'checkbox',
                'label'   => __('Orders placed on debt require admin approval before processing', 'customer-debt-manager'),
                'default' => 'no'
            ),
            'allowed_user_roles' => array(
                'title'       => __('Allowed User Roles', 'customer-debt-manager'),
                'type'        => 'multiselect',
                'description' => __('Select which user roles can use debt payment. Leave empty to allow all registered users.', 'customer-debt-manager'),
                'default'     => array('customer'),
                'options'     => $this->get_user_roles(),
                'desc_tip'    => true,
            ),
        );
    }
    
    /**
     * Get user roles for settings
     */
    private function get_user_roles() {
        if (!function_exists('wp_roles')) {
            return array(
                'customer' => 'Customer',
                'subscriber' => 'Subscriber'
            );
        }
        
        $roles = wp_roles()->get_names();
        return $roles ? $roles : array();
    }
    
    /**
     * Get debt page URL
     */
    private function get_debt_page_url() {
        $page_id = get_option('cdm_debt_page_id');
        if ($page_id) {
            return get_permalink($page_id);
        }
        return home_url('/my-debt/');
    }
    
    /**
     * Check if payment method is available
     */
    public function is_available() {
        if (!$this->enabled || $this->enabled !== 'yes') {
            return false;
        }
        
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return false;
        }
        
        // Check user role permissions
        $allowed_roles = $this->get_option('allowed_user_roles', array());
        if (!empty($allowed_roles)) {
            $current_user = wp_get_current_user();
            $user_roles = $current_user->roles;
            
            if (!array_intersect($user_roles, $allowed_roles)) {
                return false;
            }
        }
        
        // Check customer debt limit
        if (!$this->check_debt_limit()) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Check if customer is within debt limit
     */
    private function check_debt_limit() {
        if (!is_user_logged_in()) {
            return false;
        }
        
        $customer_id = get_current_user_id();
        $db = new CDM_Database();
        $debt_info = $db->get_customer_total_debt($customer_id);
        
        $current_debt = $debt_info ? $debt_info->total_remaining : 0;
        $cart_total = WC()->cart ? WC()->cart->get_total('') : 0;
        
        $max_debt = floatval($this->max_debt_amount);
        
        return ($current_debt + $cart_total) <= $max_debt;
    }
    
    /**
     * Payment form on checkout page
     */
    public function payment_fields() {
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }
        
        // Show current debt information
        if (is_user_logged_in()) {
            $customer_id = get_current_user_id();
            $db = new CDM_Database();
            $debt_info = $db->get_customer_total_debt($customer_id);
            
            if ($debt_info && $debt_info->total_remaining > 0) {
                echo '<div class="debt-info" style="background: #f8f9fa; padding: 10px; margin: 10px 0; border-left: 3px solid #007cba;">';
                echo '<p><strong>' . __('Current Debt:', 'customer-debt-manager') . '</strong> ' . wc_price($debt_info->total_remaining) . '</p>';
                echo '</div>';
            }
            
            // Show debt limit warning if close to limit
            $cart_total = WC()->cart ? WC()->cart->get_total('') : 0;
            $current_debt = $debt_info ? $debt_info->total_remaining : 0;
            $max_debt = floatval($this->max_debt_amount);
            $new_total = $current_debt + $cart_total;
            
            if ($new_total > ($max_debt * 0.8)) {
                echo '<div class="debt-warning" style="background: #fff3cd; padding: 10px; margin: 10px 0; border-left: 3px solid #ffc107;">';
                echo '<p><strong>' . __('Warning:', 'customer-debt-manager') . '</strong> ';
                echo sprintf(__('This order will bring your total debt to %s. Your debt limit is %s.', 'customer-debt-manager'), 
                    wc_price($new_total), wc_price($max_debt));
                echo '</p>';
                echo '</div>';
            }
        }
    }
    
    /**
     * Process the payment
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return array(
                'result'   => 'failure',
                'messages' => __('Order not found.', 'customer-debt-manager')
            );
        }
        
        // Verify debt limit again
        if (!$this->check_debt_limit()) {
            return array(
                'result'   => 'failure',
                'messages' => __('Debt limit exceeded. Please pay your existing debt or contact us.', 'customer-debt-manager')
            );
        }
        
        // Determine order status based on settings
        $order_status = $this->require_approval === 'yes' ? 'on-hold' : 'processing';
        
        // Update order status
        $order->update_status($order_status, __('Order placed on debt. Payment pending.', 'customer-debt-manager'));
        
        // Add order note
        $note = __('Customer chose to pay on debt. ', 'customer-debt-manager');
        if ($this->require_approval === 'yes') {
            $note .= __('Awaiting admin approval.', 'customer-debt-manager');
        }
        $order->add_order_note($note);
        
        // Mark as debt payment
        $order->update_meta_data('_is_debt_payment', 'yes');
        $order->update_meta_data('_debt_payment_status', 'pending');
        $order->save();
        
        // Create debt record immediately
        $this->create_debt_record_immediately($order);
        
        // Reduce stock levels
        wc_reduce_stock_levels($order_id);
        
        // Remove cart
        WC()->cart->empty_cart();
        
        // Return thankyou redirect
        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }
    
    /**
     * Output for the order received page
     */
    public function thankyou_page($order_id) {
        $order = wc_get_order($order_id);
        
        if ($this->require_approval === 'yes') {
            echo '<div class="woocommerce-info">';
            echo '<p>' . __('Your order has been placed successfully and is currently awaiting approval. You will be notified once it has been processed.', 'customer-debt-manager') . '</p>';
            echo '</div>';
        } else {
            echo '<div class="woocommerce-info">';
            echo '<p>' . __('Your order has been placed on debt. The amount will be added to your debt account.', 'customer-debt-manager') . '</p>';
            echo '</div>';
        }
        
        // Show debt information
        if (is_user_logged_in()) {
            $customer_id = get_current_user_id();
            $db = new CDM_Database();
            $debt_info = $db->get_customer_total_debt($customer_id);
            
            if ($debt_info) {
                echo '<div class="debt-summary" style="background: #f8f9fa; padding: 15px; margin: 15px 0; border: 1px solid #dee2e6;">';
                echo '<h4>' . __('Your Debt Summary', 'customer-debt-manager') . '</h4>';
                echo '<p><strong>' . __('Total Debt:', 'customer-debt-manager') . '</strong> ' . wc_price($debt_info->total_remaining) . '</p>';
                echo '<p><a href="' . $this->get_debt_page_url() . '" class="button">' . __('View Debt Details', 'customer-debt-manager') . '</a></p>';
                echo '</div>';
            }
        }
    }
    
    /**
     * Create debt record immediately when order is placed
     */
    private function create_debt_record_immediately($order) {
        $db = new CDM_Database();
        $customer_id = $order->get_customer_id();
        $order_id = $order->get_id();
        $debt_amount = $order->get_total();
        
        // Check if debt record already exists
        global $wpdb;
        $table_name = $wpdb->prefix . 'customer_debts';
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE order_id = %d",
            $order_id
        ));
        
        if ($existing) {
            return; // Debt record already exists
        }
        
        // Create debt record
        $debt_id = $db->create_debt($customer_id, $order_id, $debt_amount);
        
        if ($debt_id) {
            // Update order meta
            $order->update_meta_data('_debt_id', $debt_id);
            $order->update_meta_data('_debt_payment_status', 'active');
            $order->save();
            
            // Add order note
            $order->add_order_note(
                sprintf(
                    __('Debt record created (ID: %d) for amount %s', 'customer-debt-manager'),
                    $debt_id,
                    wc_price($debt_amount)
                )
            );
            
            // Trigger action for notifications
            do_action('cdm_debt_created', $debt_id, $order);
            
            // Log for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("CDM: Debt record created immediately - Order: {$order_id}, Debt ID: {$debt_id}, Amount: {$debt_amount}");
            }
        } else {
            // Log error
            $order->add_order_note(__('Failed to create debt record', 'customer-debt-manager'));
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("CDM: Failed to create debt record for order: {$order_id}");
            }
        }
    }
}
