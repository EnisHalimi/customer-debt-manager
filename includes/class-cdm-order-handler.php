<?php
/**
 * Order handler for Customer Debt Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class CDM_Order_Handler {
    
    private $db;
    
    public function __construct() {
        $this->db = new CDM_Database();
        
        // Hook into order status changes
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 4);
        
        // Hook into COD orders to create debt records automatically
        add_action('woocommerce_order_status_on-hold', array($this, 'check_and_create_cod_debt'));
        add_action('woocommerce_order_status_processing', array($this, 'check_and_create_cod_debt'));
        add_action('woocommerce_order_status_completed', array($this, 'check_and_create_cod_debt'));
        
        // Hook into original debt payment orders
        add_action('woocommerce_order_status_on-hold', array($this, 'create_debt_record'));
        add_action('woocommerce_order_status_processing', array($this, 'create_debt_record'));
        add_action('woocommerce_order_status_completed', array($this, 'create_debt_record'));
        
        // Add custom order actions
        add_filter('woocommerce_order_actions', array($this, 'add_order_actions'));
        add_action('woocommerce_order_action_create_debt_record', array($this, 'manual_create_debt_record'));
        add_action('woocommerce_order_action_convert_to_debt', array($this, 'convert_order_to_debt'));
        
        // Handle order notes
        add_action('woocommerce_new_order_note', array($this, 'handle_order_note'), 10, 2);
        
        // Email notifications
        add_action('cdm_debt_created', array($this, 'send_debt_created_notification'), 10, 2);
        add_action('cdm_payment_received', array($this, 'send_payment_received_notification'), 10, 3);
    }
    
    /**
     * Handle order status changes
     */
    public function handle_order_status_change($order_id, $from_status, $to_status, $order) {
        // Log status change for debt orders
        if ($order->get_meta('_is_debt_payment') === 'yes') {
            $order->add_order_note(
                sprintf(
                    __('Debt order status changed from %s to %s', 'customer-debt-manager'),
                    $from_status,
                    $to_status
                )
            );
            
            // If order moves to completed or processing, ensure debt record exists
            if (in_array($to_status, array('processing', 'completed'))) {
                $this->create_debt_record($order_id);
            }
            
            // If order is cancelled, handle debt record
            if ($to_status === 'cancelled') {
                $this->handle_cancelled_debt_order($order_id);
            }
        }
    }
    
    /**
     * Create debt record when order is processed/completed
     */
    public function create_debt_record($order_id) {
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("CDM Order Handler: create_debt_record called for order {$order_id}");
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("CDM Order Handler: Order {$order_id} not found");
            }
            return;
        }
        
        $is_debt_payment = $order->get_meta('_is_debt_payment');
        if ($is_debt_payment !== 'yes') {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("CDM Order Handler: Order {$order_id} is not a debt payment (meta: {$is_debt_payment})");
            }
            return;
        }
        
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("CDM Order Handler: Processing debt order {$order_id}");
        }
        
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
        
        $customer_id = $order->get_customer_id();
        $debt_amount = $order->get_total();
        
        // Create debt record
        $debt_id = $this->db->create_debt($customer_id, $order_id, $debt_amount);
        
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
                error_log("CDM: Debt record created - Order: {$order_id}, Debt ID: {$debt_id}, Amount: {$debt_amount}");
            }
        } else {
            // Log error
            $order->add_order_note(__('Failed to create debt record', 'customer-debt-manager'));
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("CDM: Failed to create debt record for order: {$order_id}");
            }
        }
    }
    
    /**
     * Check and create debt record for COD orders automatically
     */
    public function check_and_create_cod_debt($order_id) {
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("CDM Order Handler: check_and_create_cod_debt called for order {$order_id}");
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("CDM Order Handler: Order {$order_id} not found");
            }
            return;
        }
        
        // Check if this is a COD order
        $payment_method = $order->get_payment_method();
        if ($payment_method !== 'cod') {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("CDM Order Handler: Order {$order_id} is not COD (payment method: {$payment_method})");
            }
            return;
        }
        
        // Skip if this is already marked as a debt payment order
        $is_debt_payment = $order->get_meta('_is_debt_payment');
        if ($is_debt_payment === 'yes') {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("CDM Order Handler: Order {$order_id} is already marked as debt payment");
            }
            return;
        }
        
        // Check if debt record already exists
        $existing_debt_id = $order->get_meta('_debt_id');
        if ($existing_debt_id) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("CDM Order Handler: Debt record already exists for order {$order_id} (debt ID: {$existing_debt_id})");
            }
            return;
        }
        
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("CDM Order Handler: Creating debt record for COD order {$order_id}");
        }
        
        $customer_id = $order->get_customer_id();
        $debt_amount = $order->get_total();
        
        // Create debt record
        $debt_id = $this->db->create_debt($customer_id, $order_id, $debt_amount);
        
        if ($debt_id) {
            // Update order meta to mark as COD debt
            $order->update_meta_data('_debt_id', $debt_id);
            $order->update_meta_data('_debt_payment_status', 'active');
            $order->update_meta_data('_is_cod_debt', 'yes'); // Mark as COD debt specifically
            $order->save();
            
            // Add order note
            $order->add_order_note(
                sprintf(
                    __('COD order automatically converted to debt (ID: %d) for amount %s. Payment will be tracked when collected.', 'customer-debt-manager'),
                    $debt_id,
                    wc_price($debt_amount)
                )
            );
            
            // Trigger action for notifications
            do_action('cdm_debt_created', $debt_id, $order);
            
            // Log for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("CDM: COD debt record created - Order: {$order_id}, Debt ID: {$debt_id}, Amount: {$debt_amount}");
            }
        } else {
            // Log error
            $order->add_order_note(__('Failed to create debt record for COD order', 'customer-debt-manager'));
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("CDM: Failed to create debt record for COD order: {$order_id}");
            }
        }
    }
    
    /**
     * Handle cancelled debt orders
     */
    private function handle_cancelled_debt_order($order_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'customer_debts';
        
        // Find debt record
        $debt = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE order_id = %d",
            $order_id
        ));
        
        if ($debt) {
            $order = wc_get_order($order_id);
            
            // If no payments have been made, we can safely remove the debt
            if ($debt->paid_amount == 0) {
                $wpdb->delete($table_name, array('id' => $debt->id));
                $order->add_order_note(__('Debt record removed due to order cancellation', 'customer-debt-manager'));
            } else {
                // If payments have been made, mark as cancelled but keep record
                $wpdb->update(
                    $table_name,
                    array('status' => 'cancelled'),
                    array('id' => $debt->id)
                );
                $order->add_order_note(
                    sprintf(
                        __('Debt record marked as cancelled. Paid amount: %s will need to be refunded manually.', 'customer-debt-manager'),
                        wc_price($debt->paid_amount)
                    )
                );
            }
        }
    }
    
    /**
     * Add custom order actions
     */
    public function add_order_actions($actions) {
        global $theorder;
        
        if (!$theorder) {
            return $actions;
        }
        
        // Add action to create debt record manually
        if ($theorder->get_meta('_is_debt_payment') === 'yes' && !$theorder->get_meta('_debt_id')) {
            $actions['create_debt_record'] = __('Create Debt Record', 'customer-debt-manager');
        }
        
        // Add action to convert regular order to debt
        if ($theorder->get_meta('_is_debt_payment') !== 'yes' && $theorder->get_status() !== 'completed') {
            $actions['convert_to_debt'] = __('Convert to Debt Payment', 'customer-debt-manager');
        }
        
        return $actions;
    }
    
    /**
     * Manually create debt record
     */
    public function manual_create_debt_record($order) {
        $this->create_debt_record($order->get_id());
    }
    
    /**
     * Convert regular order to debt payment
     */
    public function convert_order_to_debt($order) {
        // Update order meta
        $order->update_meta_data('_is_debt_payment', 'yes');
        $order->update_meta_data('_debt_payment_status', 'pending');
        $order->save();
        
        // Add note
        $order->add_order_note(__('Order converted to debt payment by admin', 'customer-debt-manager'));
        
        // Create debt record if order is in appropriate status
        if (in_array($order->get_status(), array('processing', 'completed'))) {
            $this->create_debt_record($order->get_id());
        }
    }
    
    /**
     * Handle order notes (for payment tracking)
     */
    public function handle_order_note($note_id, $order) {
        // This could be used to automatically detect manual payments mentioned in order notes
        // For now, we'll just log it for debt orders
        
        if ($order->get_meta('_is_debt_payment') === 'yes') {
            $note = wc_get_order_note($note_id);
            
            // Log for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("CDM: Note added to debt order {$order->get_id()}: {$note->content}");
            }
        }
    }
    
    /**
     * Send debt created notification
     */
    public function send_debt_created_notification($debt_id, $order) {
        $customer = $order->get_user();
        
        if (!$customer) {
            return;
        }
        
        $debt = $this->db->get_debt_by_id($debt_id);
        
        if (!$debt) {
            return;
        }
        
        $subject = sprintf(
            __('[%s] Your order has been placed on debt', 'customer-debt-manager'),
            get_bloginfo('name')
        );
        
        $message = sprintf(
            __('Dear %s,

Your order #%d has been successfully placed on your debt account.

Order Details:
- Order Number: #%d
- Order Total: %s
- Debt Amount: %s

You can view your debt balance and payment history at: %s

If you have any questions, please contact us.

Thank you!', 'customer-debt-manager'),
            $customer->display_name,
            $order->get_id(),
            $order->get_id(),
            wc_price($order->get_total()),
            wc_price($debt->debt_amount),
            $this->get_debt_page_url()
        );
        
        wp_mail($customer->user_email, $subject, $message);
        
        // Log email sent
        $order->add_order_note(__('Debt creation notification email sent to customer', 'customer-debt-manager'));
    }
    
    /**
     * Send payment received notification
     */
    public function send_payment_received_notification($payment_id, $debt_id, $payment_amount) {
        $debt = $this->db->get_debt_by_id($debt_id);
        
        if (!$debt) {
            return;
        }
        
        $customer = get_user_by('ID', $debt->customer_id);
        $order = wc_get_order($debt->order_id);
        
        if (!$customer || !$order) {
            return;
        }
        
        $subject = sprintf(
            __('[%s] Payment received for your debt', 'customer-debt-manager'),
            get_bloginfo('name')
        );
        
        $message = sprintf(
            __('Dear %s,

We have received a payment for your debt account.

Payment Details:
- Payment Amount: %s
- Order: #%d
- Remaining Debt: %s

You can view your updated debt balance at: %s

Thank you for your payment!', 'customer-debt-manager'),
            $customer->display_name,
            wc_price($payment_amount),
            $order->get_id(),
            wc_price($debt->remaining_amount),
            $this->get_debt_page_url()
        );
        
        wp_mail($customer->user_email, $subject, $message);
        
        // Add order note
        $order->add_order_note(
            sprintf(
                __('Payment notification email sent to customer for payment of %s', 'customer-debt-manager'),
                wc_price($payment_amount)
            )
        );
    }
    
    /**
     * Get debt statistics for dashboard
     */
    public function get_debt_stats() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'customer_debts';
        
        return $wpdb->get_row("
            SELECT 
                COUNT(*) as total_debts,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_debts,
                SUM(debt_amount) as total_debt_amount,
                SUM(paid_amount) as total_paid_amount,
                SUM(remaining_amount) as total_outstanding
            FROM {$table_name}
        ");
    }
    
    /**
     * Cleanup old completed debts (optional)
     */
    public function cleanup_old_debts($days = 365) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'customer_debts';
        
        $wpdb->query($wpdb->prepare("
            DELETE FROM {$table_name} 
            WHERE status = 'paid' 
            AND updated_date < DATE_SUB(NOW(), INTERVAL %d DAY)
        ", $days));
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
}
