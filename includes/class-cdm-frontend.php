<?php
/**
 * Frontend functionality for Customer Debt Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class CDM_Frontend {
    
    private $db;
    
    public function __construct() {
        $this->db = new CDM_Database();
        
        // Removed standalone debt page creation - keeping only My Account integration
        add_action('init', array($this, 'add_endpoint'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        
        // Add debt info to My Account
        add_action('woocommerce_account_dashboard', array($this, 'add_debt_info_to_dashboard'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_debt_menu_item'));
        add_action('woocommerce_account_my-debt_endpoint', array($this, 'debt_account_page'));
        
        // AJAX handlers for frontend
        add_action('wp_ajax_cdm_get_debt_details_frontend', array($this, 'ajax_get_debt_details_frontend'));
        add_action('wp_ajax_nopriv_cdm_get_debt_details_frontend', array($this, 'ajax_get_debt_details_frontend'));
        
        // Shortcodes (keeping balance and history for flexibility)
        add_shortcode('customer_debt_balance', array($this, 'debt_balance_shortcode'));
        add_shortcode('customer_debt_history', array($this, 'debt_history_shortcode'));
        // Removed customer_debt_page shortcode - using only My Account integration
    }
    
    /**
     * Add custom endpoint for WooCommerce My Account
     */
    public function add_endpoint() {
        add_rewrite_endpoint('my-debt', EP_ROOT | EP_PAGES);
        
        // Flush rewrite rules if needed (only once after activation)
        if (!get_option('cdm_rewrite_rules_flushed')) {
            flush_rewrite_rules();
            update_option('cdm_rewrite_rules_flushed', true);
        }
    }
    
    /**
     * Debt page content (used within My Account integration)
     */
    public function render_debt_account_content($atts = array()) {
        if (!is_user_logged_in()) {
            return '<p>' . __('Please log in to view your debt account.', 'customer-debt-manager') . ' <a href="' . wp_login_url(get_permalink()) . '">' . __('Login', 'customer-debt-manager') . '</a></p>';
        }
        
        ob_start();
        $this->render_debt_page_content();
        return ob_get_clean();
    }
    
    /**
     * Render debt page content
     */
    private function render_debt_page_content() {
        $customer_id = get_current_user_id();
        $debt_info = $this->db->get_customer_total_debt($customer_id);
        $debts = $this->db->get_customer_debts($customer_id);
        $payments = $this->db->get_customer_payments($customer_id, 50);
        
        // Get all customer orders (both debt and paid)
        $all_orders = wc_get_orders(array(
            'customer' => $customer_id,
            'limit' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
            'status' => array('completed', 'processing', 'on-hold', 'pending')
        ));
        
        ?>
        <div class="cdm-debt-page" style="max-width: 1200px; margin: 0 auto; padding: 20px;">
            <h1><?php _e('My Orders & Debt Account', 'customer-debt-manager'); ?></h1>
            
            <!-- Debt Summary -->
            <div class="debt-summary-card">
                <h2><?php _e('Account Summary', 'customer-debt-manager'); ?></h2>
                <?php if ($debt_info && $debt_info->total_remaining > 0): ?>
                    <div class="debt-summary-grid">
                        <div class="debt-summary-item">
                            <h3 style="margin: 0; font-size: 24px; color: #fff3cd;"><?php echo wc_price($debt_info->total_remaining); ?></h3>
                            <p style="margin: 5px 0 0 0; opacity: 0.9;"><?php _e('Outstanding Debt', 'customer-debt-manager'); ?></p>
                        </div>
                        <div class="debt-summary-item">
                            <h3 style="margin: 0; font-size: 24px; color: #d4edda;"><?php echo wc_price($debt_info->total_paid); ?></h3>
                            <p style="margin: 5px 0 0 0; opacity: 0.9;"><?php _e('Total Paid on Debt', 'customer-debt-manager'); ?></p>
                        </div>
                        <div class="debt-summary-item">
                            <h3 style="margin: 0; font-size: 24px; color: #e2e3e5;"><?php echo count($all_orders); ?></h3>
                            <p style="margin: 5px 0 0 0; opacity: 0.9;"><?php _e('Total Orders', 'customer-debt-manager'); ?></p>
                        </div>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 20px 0;">
                        <h3 style="margin: 0; color: #d4edda; font-size: 24px;">✓ <?php _e('No outstanding debt!', 'customer-debt-manager'); ?></h3>
                        <p style="margin: 10px 0 0 0; opacity: 0.9;"><?php _e('All your orders have been paid in full.', 'customer-debt-manager'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- All Orders Table (Like WooCommerce My Account Orders) -->
            <div class="woocommerce-orders-table__wrapper">
                <h2><?php _e('All Orders', 'customer-debt-manager'); ?></h2>
                
                <?php if ($all_orders): ?>
                    <table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table" style="width: 100%; border-collapse: collapse; background: white; margin: 20px 0;">
                        <thead>
                            <tr style="background: #f8f9fa;">
                                <th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-number" style="padding: 12px; border: 1px solid #dee2e6; text-align: left;">
                                    <span class="nobr"><?php _e('Order', 'customer-debt-manager'); ?></span>
                                </th>
                                <th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-date" style="padding: 12px; border: 1px solid #dee2e6; text-align: left;">
                                    <span class="nobr"><?php _e('Date', 'customer-debt-manager'); ?></span>
                                </th>
                                <th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-status" style="padding: 12px; border: 1px solid #dee2e6; text-align: left;">
                                    <span class="nobr"><?php _e('Status', 'customer-debt-manager'); ?></span>
                                </th>
                                <th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-total" style="padding: 12px; border: 1px solid #dee2e6; text-align: left;">
                                    <span class="nobr"><?php _e('Total', 'customer-debt-manager'); ?></span>
                                </th>
                                <th class="woocommerce-orders-table__header woocommerce-orders-table__header-payment-status" style="padding: 12px; border: 1px solid #dee2e6; text-align: left;">
                                    <span class="nobr"><?php _e('Payment Status', 'customer-debt-manager'); ?></span>
                                </th>
                                <th class="woocommerce-orders-table__header woocommerce-orders-table__header-debt-balance" style="padding: 12px; border: 1px solid #dee2e6; text-align: left;">
                                    <span class="nobr"><?php _e('Debt Balance', 'customer-debt-manager'); ?></span>
                                </th>
                                <th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-actions" style="padding: 12px; border: 1px solid #dee2e6; text-align: left;">
                                    <span class="nobr"><?php _e('Actions', 'customer-debt-manager'); ?></span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_orders as $order): ?>
                                <?php
                                $order_id = $order->get_id();
                                $is_debt_payment = $order->get_meta('_is_debt_payment') === 'yes';
                                $is_cod_debt = $order->get_meta('_is_cod_debt') === 'yes';
                                $debt_record = null;
                                $debt_balance = 0;
                                $payment_status = 'Paid in Full';
                                $payment_status_color = '#28a745';
                                
                                // Get debt record if this is a debt order (either debt payment or COD)
                                if ($is_debt_payment || $is_cod_debt) {
                                    foreach ($debts as $debt) {
                                        if ($debt->order_id == $order_id) {
                                            $debt_record = $debt;
                                            $debt_balance = $debt->remaining_amount;
                                            break;
                                        }
                                    }
                                    
                                    if ($debt_balance > 0) {
                                        if ($is_cod_debt) {
                                            $payment_status = 'COD - Pending Collection';
                                            $payment_status_color = '#ff6b35';
                                        } else {
                                            $payment_status = 'On Debt';
                                            $payment_status_color = '#dc3545';
                                        }
                                    } else {
                                        $payment_status = 'Debt Paid';
                                        $payment_status_color = '#28a745';
                                    }
                                } else {
                                    // Regular order (COD, etc.)
                                    if ($order->is_paid()) {
                                        $payment_status = 'Paid';
                                        $payment_status_color = '#28a745';
                                    } else {
                                        $payment_status = 'Pending Payment';
                                        $payment_status_color = '#ffc107';
                                    }
                                }
                                ?>
                                <tr class="woocommerce-orders-table__row woocommerce-orders-table__row--status-<?php echo esc_attr($order->get_status()); ?> order">
                                    <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-number" data-title="<?php _e('Order', 'customer-debt-manager'); ?>" style="padding: 12px; border: 1px solid #dee2e6;">
                                        <?php 
                                        $order_view_url = $this->get_order_view_url($order_id, $order);
                                        ?>
                                        
                                        <?php if (!empty($order_view_url)): ?>
                                            <a href="<?php echo esc_url($order_view_url); ?>" style="color: #007cba; text-decoration: none; font-weight: bold;">
                                                #<?php echo $order_id; ?>
                                            </a>
                                        <?php else: ?>
                                            <span style="font-weight: bold;">#<?php echo $order_id; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-date" data-title="<?php _e('Date', 'customer-debt-manager'); ?>" style="padding: 12px; border: 1px solid #dee2e6;">
                                        <time datetime="<?php echo esc_attr($order->get_date_created()->format('c')); ?>">
                                            <?php echo esc_html($order->get_date_created()->format(get_option('date_format'))); ?>
                                        </time>
                                    </td>
                                    <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-status" data-title="<?php _e('Status', 'customer-debt-manager'); ?>" style="padding: 12px; border: 1px solid #dee2e6;">
                                        <span class="order-status" style="padding: 3px 8px; border-radius: 3px; font-size: 12px; background: #e9ecef;">
                                            <?php echo esc_html(wc_get_order_status_name($order->get_status())); ?>
                                        </span>
                                    </td>
                                    <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-total" data-title="<?php _e('Total', 'customer-debt-manager'); ?>" style="padding: 12px; border: 1px solid #dee2e6;">
                                        <span class="woocommerce-Price-amount amount" style="font-weight: bold; font-size: 16px;">
                                            <?php echo $order->get_formatted_order_total(); ?>
                                        </span>
                                        <br><small style="color: #6c757d;"><?php echo $order->get_payment_method_title(); ?></small>
                                    </td>
                                    <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-payment-status" data-title="<?php _e('Payment Status', 'customer-debt-manager'); ?>" style="padding: 12px; border: 1px solid #dee2e6;">
                                        <span style="color: <?php echo $payment_status_color; ?>; font-weight: bold;">
                                            <?php echo $payment_status; ?>
                                        </span>
                                    </td>
                                    <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-debt-balance" data-title="<?php _e('Debt Balance', 'customer-debt-manager'); ?>" style="padding: 12px; border: 1px solid #dee2e6;">
                                        <?php if ($debt_balance > 0): ?>
                                            <span style="color: #dc3545; font-weight: bold;">
                                                <?php echo wc_price($debt_balance); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #6c757d;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-actions" data-title="<?php _e('Actions', 'customer-debt-manager'); ?>" style="padding: 12px; border: 1px solid #dee2e6;">
                                        <?php 
                                        $view_order_url = $this->get_order_view_url($order_id, $order);
                                        ?>
                                        
                                        <?php if (!empty($view_order_url)): ?>
                                            <a href="<?php echo esc_url($view_order_url); ?>" class="woocommerce-button button view" style="background: #007cba; color: white; padding: 6px 12px; text-decoration: none; border-radius: 3px; font-size: 12px; margin: 2px;">
                                                <?php _e('View Order', 'customer-debt-manager'); ?>
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #6c757d; font-size: 12px;"><?php _e('N/A', 'customer-debt-manager'); ?></span>
                                        <?php endif; ?>
                                        
                                        <?php if ($debt_record && $debt_balance > 0): ?>
                                            <button type="button" class="button view-debt-details" data-debt-id="<?php echo $debt_record->id; ?>" style="background: #ffc107; color: #212529; padding: 6px 12px; border: none; border-radius: 3px; font-size: 12px; margin: 2px; cursor: pointer;">
                                                <?php _e('Debt Details', 'customer-debt-manager'); ?>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info" style="background: #d1ecf1; padding: 15px; margin: 20px 0; border-left: 4px solid #bee5eb; color: #0c5460;">
                        <p><?php _e('No orders found.', 'customer-debt-manager'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Debt Payment History -->
            <?php if (!empty($payments)): ?>
                <div class="debt-payments-history" style="margin: 30px 0;">
                    <h2><?php _e('Debt Payment History', 'customer-debt-manager'); ?></h2>
                    <div style="background: white; padding: 20px; border: 1px solid #dee2e6; border-radius: 5px;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f8f9fa;">
                                    <th style="padding: 12px; border: 1px solid #dee2e6; text-align: left;"><?php _e('Date & Time', 'customer-debt-manager'); ?></th>
                                    <th style="padding: 12px; border: 1px solid #dee2e6; text-align: left;"><?php _e('Order', 'customer-debt-manager'); ?></th>
                                    <th style="padding: 12px; border: 1px solid #dee2e6; text-align: left;"><?php _e('Payment Amount', 'customer-debt-manager'); ?></th>
                                    <th style="padding: 12px; border: 1px solid #dee2e6; text-align: left;"><?php _e('Payment Type', 'customer-debt-manager'); ?></th>
                                    <th style="padding: 12px; border: 1px solid #dee2e6; text-align: left;"><?php _e('Note', 'customer-debt-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td style="padding: 12px; border: 1px solid #dee2e6;">
                                            <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($payment->payment_date)); ?>
                                        </td>
                                        <td style="padding: 12px; border: 1px solid #dee2e6;">
                                            <?php if ($payment->order_id): ?>
                                                <a href="<?php echo esc_url(wc_get_account_endpoint_url('view-order', $payment->order_id)); ?>" style="color: #007cba; text-decoration: none;">
                                                    #<?php echo $payment->order_id; ?>
                                                </a>
                                            <?php else: ?>
                                                <span style="color: #6c757d;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 12px; border: 1px solid #dee2e6;">
                                            <span style="color: #28a745; font-weight: bold; font-size: 16px;">
                                                <?php echo wc_price($payment->payment_amount); ?>
                                            </span>
                                        </td>
                                        <td style="padding: 12px; border: 1px solid #dee2e6;">
                                            <span style="padding: 3px 8px; background: #e9ecef; border-radius: 3px; font-size: 12px;">
                                                <?php echo ucfirst(str_replace('_', ' ', $payment->payment_type)); ?>
                                            </span>
                                        </td>
                                        <td style="padding: 12px; border: 1px solid #dee2e6;">
                                            <?php echo esc_html($payment->payment_note ?: '—'); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background: #f8f9fa; font-weight: bold;">
                                    <td colspan="2" style="padding: 12px; border: 1px solid #dee2e6; text-align: right;">
                                        <?php _e('Total Payments Made:', 'customer-debt-manager'); ?>
                                    </td>
                                    <td style="padding: 12px; border: 1px solid #dee2e6;">
                                        <span style="color: #28a745; font-size: 18px;">
                                            <?php 
                                            $total_payments = array_sum(array_column($payments, 'payment_amount'));
                                            echo wc_price($total_payments); 
                                            ?>
                                        </span>
                                    </td>
                                    <td colspan="2" style="padding: 12px; border: 1px solid #dee2e6;"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Debt Details Modal Placeholder -->
            <div id="debt-details-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; max-width: 700px; width: 90%; max-height: 80vh; overflow-y: auto;">
                    <div id="debt-details-content">
                        <p><?php _e('Loading...', 'customer-debt-manager'); ?></p>
                    </div>
                    <p style="text-align: right; margin-top: 20px;">
                        <button type="button" onclick="closeDebtModal()" class="button" style="background: #6c757d; color: white; padding: 10px 20px; border: none; border-radius: 3px; cursor: pointer;">
                            <?php _e('Close', 'customer-debt-manager'); ?>
                        </button>
                    </p>
                </div>
            </div>
        </div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Handle debt details buttons
            document.querySelectorAll('.view-debt-details').forEach(function(button) {
                button.addEventListener('click', function() {
                    var debtId = this.getAttribute('data-debt-id');
                    showDebtDetails(debtId);
                });
            });
            
            // Close modal when clicking outside
            document.getElementById('debt-details-modal').addEventListener('click', function(e) {
                if (e.target === this) {
                    this.style.display = 'none';
                }
            });
        });
        
        function showDebtDetails(debtId) {
            var modal = document.getElementById('debt-details-modal');
            var content = document.getElementById('debt-details-content');
            
            // Show loading state
            content.innerHTML = '<div style="text-align: center; padding: 20px;"><p><?php _e("Loading debt details...", "customer-debt-manager"); ?></p></div>';
            modal.style.display = 'block';
            
            // Make AJAX request
            var xhr = new XMLHttpRequest();
            var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
            
            xhr.open('POST', ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                content.innerHTML = response.data.html;
                            } else {
                                content.innerHTML = '<div style="text-align: center; padding: 20px; color: #d63638;"><h3><?php _e("Error", "customer-debt-manager"); ?></h3><p>' + (response.data.message || '<?php _e("Unable to load debt details. Please try again.", "customer-debt-manager"); ?>') + '</p></div>';
                            }
                        } catch (e) {
                            content.innerHTML = '<div style="text-align: center; padding: 20px; color: #d63638;"><h3><?php _e("Error", "customer-debt-manager"); ?></h3><p><?php _e("Unable to load debt details. Please try again.", "customer-debt-manager"); ?></p></div>';
                        }
                    } else {
                        content.innerHTML = '<div style="text-align: center; padding: 20px; color: #d63638;"><h3><?php _e("Error", "customer-debt-manager"); ?></h3><p><?php _e("Unable to connect to server. Please try again.", "customer-debt-manager"); ?></p></div>';
                    }
                }
            };
            
            xhr.send('action=cdm_get_debt_details_frontend&debt_id=' + debtId);
        }
        
        function closeDebtModal() {
            document.getElementById('debt-details-modal').style.display = 'none';
        }
        </script>
        <?php
    }
    
    /**
     * Get a safe order view URL
     */
    private function get_order_view_url($order_id, $order = null) {
        // Verify user permission
        if (!is_user_logged_in()) {
            return '';
        }
        
        $current_user_id = get_current_user_id();
        
        // If no order object, try to get it
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        
        // If order doesn't exist, is invalid, or is trashed
        if (!$order || !is_a($order, 'WC_Order') || $order->get_status() === 'trash') {
            return '';
        }
        
        // Check ownership - only show URL if current user owns the order
        if ($order->get_customer_id() != $current_user_id) {
            return '';
        }
        
        // Get the my account page URL
        $account_page_url = wc_get_page_permalink('myaccount');
        if (!$account_page_url) {
            return '';
        }
        
        // Build the view order URL manually to ensure it's correct
        return trailingslashit($account_page_url) . 'view-order/' . $order_id . '/';
    }
    
    /**
     * Enqueue frontend scripts
     */
    public function enqueue_frontend_scripts() {
        // Only proceed if we have WordPress functions available
        if (!function_exists('wp_enqueue_script') || !function_exists('admin_url')) {
            return;
        }
        
        // Check if we're on WooCommerce My Account page (where debt integration is active)
        $is_debt_page = false;
        
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('my-debt')) {
            $is_debt_page = true;
        }
        
        if (function_exists('is_account_page') && (is_account_page() || $is_debt_page)) {
            // Enqueue jQuery if not already loaded
            wp_enqueue_script('jquery');
            
            // Load CSS if available (optional, since we use inline styles)
            // Use CDM_PLUGIN_PATH instead of CDM_PLUGIN_DIR for consistency
            if (defined('CDM_PLUGIN_PATH') && defined('CDM_PLUGIN_URL') && file_exists(CDM_PLUGIN_PATH . 'assets/css/frontend.css')) {
                wp_enqueue_style('cdm-frontend', CDM_PLUGIN_URL . 'assets/css/frontend.css', array(), CDM_VERSION);
            }
        }
    }
    
    /**
     * Add debt info to WooCommerce account dashboard
     */
    public function add_debt_info_to_dashboard() {
        if (!is_user_logged_in()) {
            return;
        }
        
        $customer_id = get_current_user_id();
        $debt_info = $this->db->get_customer_total_debt($customer_id);
        
        if ($debt_info && $debt_info->total_remaining > 0) {
            echo '<div class="woocommerce-MyAccount-debt-info" style="background: #fff3cd; padding: 15px; margin: 15px 0; border-left: 4px solid #ffc107; border-radius: 3px;">';
            echo '<h3 style="margin-top: 0;">' . __('Your Debt Account', 'customer-debt-manager') . '</h3>';
            echo '<p><strong>' . __('Outstanding Balance:', 'customer-debt-manager') . '</strong> ' . wc_price($debt_info->total_remaining) . '</p>';
            echo '<p><a href="' . wc_get_account_endpoint_url('my-debt') . '" class="button">' . __('View Details', 'customer-debt-manager') . '</a></p>';
            echo '</div>';
        }
    }
    
    /**
     * Add debt menu item to My Account
     */
    public function add_debt_menu_item($items) {
        // Insert debt item before logout
        $logout = $items['customer-logout'];
        unset($items['customer-logout']);
        
        $items['my-debt'] = __('My Debt', 'customer-debt-manager');
        $items['customer-logout'] = $logout;
        
        return $items;
    }
    
    /**
     * Debt account page content
     */
    public function debt_account_page() {
        echo $this->render_debt_account_content(array());
    }
    
    /**
     * Debt balance shortcode
     */
    public function debt_balance_shortcode($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . __('Please log in to view your debt balance.', 'customer-debt-manager') . '</p>';
        }
        
        $customer_id = get_current_user_id();
        $debt_info = $this->db->get_customer_total_debt($customer_id);
        
        if ($debt_info && $debt_info->total_remaining > 0) {
            return '<div class="debt-balance-widget">' . 
                   '<strong>' . __('Your Outstanding Debt:', 'customer-debt-manager') . '</strong> ' . 
                   wc_price($debt_info->total_remaining) . 
                   '</div>';
        }
        
        return '<div class="debt-balance-widget">' . __('No outstanding debt.', 'customer-debt-manager') . '</div>';
    }
    
    /**
     * Debt history shortcode
     */
    public function debt_history_shortcode($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . __('Please log in to view your debt history.', 'customer-debt-manager') . '</p>';
        }
        
        $atts = shortcode_atts(array(
            'limit' => 10
        ), $atts);
        
        $customer_id = get_current_user_id();
        $debts = $this->db->get_customer_debts($customer_id);
        
        if (empty($debts)) {
            return '<p>' . __('No debt history found.', 'customer-debt-manager') . '</p>';
        }
        
        $output = '<div class="debt-history-widget">';
        $output .= '<table class="debt-history-table">';
        $output .= '<thead><tr><th>' . __('Order', 'customer-debt-manager') . '</th><th>' . __('Amount', 'customer-debt-manager') . '</th><th>' . __('Status', 'customer-debt-manager') . '</th></tr></thead>';
        $output .= '<tbody>';
        
        $count = 0;
        foreach ($debts as $debt) {
            if ($count >= $atts['limit']) break;
            
            $output .= '<tr>';
            $output .= '<td>#' . $debt->order_id . '</td>';
            $output .= '<td>' . wc_price($debt->remaining_amount) . '</td>';
            $output .= '<td>' . ucfirst($debt->status) . '</td>';
            $output .= '</tr>';
            
            $count++;
        }
        
        $output .= '</tbody></table>';
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * AJAX handler for getting debt details on frontend
     */
    public function ajax_get_debt_details_frontend() {
        // Verify user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('Please log in to view debt details.', 'customer-debt-manager')));
        }
        
        $debt_id = intval($_POST['debt_id']);
        $current_user_id = get_current_user_id();
        
        if (!$debt_id) {
            wp_send_json_error(array('message' => __('Invalid debt ID.', 'customer-debt-manager')));
        }
        
        // Get debt details
        $debt = $this->db->get_debt($debt_id);
        
        if (!$debt) {
            wp_send_json_error(array('message' => __('Debt not found.', 'customer-debt-manager')));
        }
        
        // Verify the debt belongs to the current user
        if ($debt->customer_id != $current_user_id) {
            wp_send_json_error(array('message' => __('You are not authorized to view this debt.', 'customer-debt-manager')));
        }
        
        // Get payment history
        $payments = $this->db->get_debt_payments($debt_id);
        
        // Get order details
        $order = wc_get_order($debt->order_id);
        $customer = get_userdata($debt->customer_id);
        
        // Determine debt type
        $debt_type = 'Credit';
        if ($order) {
            $payment_method = $order->get_payment_method();
            if ($payment_method === 'cod') {
                $debt_type = 'Cash on Delivery';
            }
        }
        
        // Build response HTML
        $html = '<div class="debt-details-content">';
        $html .= '<h3>' . __('Debt Details', 'customer-debt-manager') . '</h3>';
        
        // Debt summary
        $html .= '<div class="debt-summary" style="background: #f8f9fa; padding: 15px; margin-bottom: 20px; border-radius: 5px;">';
        $html .= '<h4 style="margin: 0 0 10px 0;">' . __('Order Information', 'customer-debt-manager') . '</h4>';
        $html .= '<p><strong>' . __('Order ID:', 'customer-debt-manager') . '</strong> #' . $debt->order_id . '</p>';
        $html .= '<p><strong>' . __('Debt Type:', 'customer-debt-manager') . '</strong> ' . $debt_type . '</p>';
        $html .= '<p><strong>' . __('Total Debt:', 'customer-debt-manager') . '</strong> ' . wc_price($debt->debt_amount) . '</p>';
        $html .= '<p><strong>' . __('Paid Amount:', 'customer-debt-manager') . '</strong> ' . wc_price($debt->paid_amount) . '</p>';
        $html .= '<p><strong>' . __('Remaining Balance:', 'customer-debt-manager') . '</strong> <span style="color: #d63638; font-weight: bold;">' . wc_price($debt->remaining_amount) . '</span></p>';
        $html .= '<p><strong>' . __('Status:', 'customer-debt-manager') . '</strong> <span style="color: ' . ($debt->status === 'paid' ? '#00a32a' : '#856404') . ';">' . ucfirst($debt->status) . '</span></p>';
        $html .= '<p><strong>' . __('Created:', 'customer-debt-manager') . '</strong> ' . date_i18n(get_option('date_format'), strtotime($debt->created_at)) . '</p>';
        $html .= '</div>';
        
        // Payment history
        if (!empty($payments)) {
            $html .= '<h4>' . __('Payment History', 'customer-debt-manager') . '</h4>';
            $html .= '<table class="debt-payments-table" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">';
            $html .= '<thead>';
            $html .= '<tr style="background: #f1f1f1;">';
            $html .= '<th style="padding: 8px; border: 1px solid #ddd; text-align: left;">' . __('Date', 'customer-debt-manager') . '</th>';
            $html .= '<th style="padding: 8px; border: 1px solid #ddd; text-align: left;">' . __('Amount', 'customer-debt-manager') . '</th>';
            $html .= '<th style="padding: 8px; border: 1px solid #ddd; text-align: left;">' . __('Type', 'customer-debt-manager') . '</th>';
            $html .= '<th style="padding: 8px; border: 1px solid #ddd; text-align: left;">' . __('Note', 'customer-debt-manager') . '</th>';
            $html .= '</tr>';
            $html .= '</thead>';
            $html .= '<tbody>';
            
            foreach ($payments as $payment) {
                $html .= '<tr>';
                $html .= '<td style="padding: 8px; border: 1px solid #ddd;">' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($payment->payment_date)) . '</td>';
                $html .= '<td style="padding: 8px; border: 1px solid #ddd; color: #00a32a; font-weight: bold;">' . wc_price($payment->payment_amount) . '</td>';
                $html .= '<td style="padding: 8px; border: 1px solid #ddd;">' . ucfirst(str_replace('_', ' ', $payment->payment_type)) . '</td>';
                $html .= '<td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($payment->payment_note ?: '—') . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</tbody>';
            $html .= '</table>';
        } else {
            $html .= '<p style="color: #666; font-style: italic;">' . __('No payments have been made on this debt yet.', 'customer-debt-manager') . '</p>';
        }
        
        // Contact information
        $html .= '<div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #007cba; margin-top: 20px;">';
        $html .= '<h4 style="margin: 0 0 10px 0;">' . __('Need Help?', 'customer-debt-manager') . '</h4>';
        $html .= '<p style="margin: 0;">' . __('If you have questions about this debt or need to arrange payment, please contact our customer service team.', 'customer-debt-manager') . '</p>';
        $html .= '</div>';
        
        $html .= '</div>';
        
        wp_send_json_success(array('html' => $html));
    }
    
    /**
     * Debug function to test WooCommerce endpoints
     */
    public function debug_woocommerce_endpoints() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        echo '<div style="background: #f1f1f1; padding: 20px; margin: 20px 0; border-radius: 5px;">';
        echo '<h3>WooCommerce Endpoints Debug</h3>';
        
        // Test account page
        $account_page = wc_get_page_permalink('myaccount');
        echo '<p><strong>Account Page URL:</strong> ' . esc_html($account_page) . '</p>';
        
        // Test view-order endpoint
        $test_order_id = 123; // Test order ID
        $view_order_url = wc_get_account_endpoint_url('view-order', $test_order_id);
        echo '<p><strong>View Order URL (test):</strong> ' . esc_html($view_order_url) . '</p>';
        
        // Test if user is logged in
        echo '<p><strong>User Logged In:</strong> ' . (is_user_logged_in() ? 'Yes' : 'No') . '</p>';
        echo '<p><strong>Current User ID:</strong> ' . get_current_user_id() . '</p>';
        
        // Test rewrite rules
        $rules = get_option('rewrite_rules');
        $has_my_debt = isset($rules['my-debt/?$']);
        echo '<p><strong>My-Debt Endpoint Registered:</strong> ' . ($has_my_debt ? 'Yes' : 'No') . '</p>';
        
        echo '</div>';
    }
}
