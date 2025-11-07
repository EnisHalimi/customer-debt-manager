<?php
/**
 * Admin functionality for Customer Debt Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class CDM_Admin {
    
    private $db;
    
    public function __construct() {
        $this->db = new CDM_Database();
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_cdm_add_payment', array($this, 'ajax_add_payment'));
        add_action('wp_ajax_cdm_get_debt_details', array($this, 'ajax_get_debt_details'));
        add_action('wp_ajax_cdm_get_debt_payments', array($this, 'ajax_get_debt_payments'));
        add_action('wp_ajax_cdm_manual_debt_adjustment', array($this, 'ajax_manual_debt_adjustment'));
        add_action('wp_ajax_cdm_get_customer_debt_details', array($this, 'ajax_get_customer_debt_details'));
        add_action('wp_ajax_cdm_get_customer_debt_summary', array($this, 'ajax_get_customer_debt_summary'));
        add_action('wp_ajax_cdm_cleanup_debt_data', array($this, 'ajax_cleanup_debt_data'));
        
        // Add debt info to order details
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_debt_info_in_order'));
        add_action('add_meta_boxes', array($this, 'add_debt_meta_box'));
        
        // HPOS compatibility - add meta box for HPOS orders
        add_action('add_meta_boxes_woocommerce_page_wc-orders', array($this, 'add_debt_meta_box'));
        add_action('add_meta_boxes_shop_order', array($this, 'add_debt_meta_box'));
    }
    
    /**
     * Get order edit URL (HPOS compatible)
     */
    private function get_order_edit_url($order_id) {
        if (class_exists('CustomerDebtManager') && CustomerDebtManager::is_hpos_enabled()) {
            return admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id);
        } else {
            return admin_url('post.php?post=' . $order_id . '&action=edit');
        }
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Primary menu
        add_menu_page(
            __('Customer Debts', 'customer-debt-manager'),
            __('Customer Debts', 'customer-debt-manager'),
            'manage_woocommerce',
            'customer-debts',
            array($this, 'admin_page'),
            'dashicons-money-alt',
            56
        );
        
        // Also add as WooCommerce submenu as fallback
        if (class_exists('WooCommerce')) {
            add_submenu_page(
                'woocommerce',
                __('Customer Debts', 'customer-debt-manager'),
                __('Customer Debts', 'customer-debt-manager'),
                'manage_woocommerce',
                'customer-debts-alt',
                array($this, 'admin_page')
            );
        }
    }
    
    /**
     * Admin page content
     */
    public function admin_page() {
        // Handle tab selection - default to "by_customer"
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'by_customer';
        
        // Handle search and sorting parameters
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : ($current_tab === 'by_customer' ? 'customer_name' : 'created_date');
        $order = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'asc' : 'desc';
        $status_filter = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : 'all';
        $type_filter = isset($_GET['type_filter']) ? sanitize_text_field($_GET['type_filter']) : 'all';
        
        // Ensure status_filter is valid - fallback to 'all' if invalid
        $valid_status_filters = array('all', 'active', 'paid', 'no_debt');
        if (!in_array($status_filter, $valid_status_filters)) {
            $status_filter = 'all';
        }
        
        // Get data based on current tab
        if ($current_tab === 'by_customer') {
            $customer_debts = $this->db->get_all_customers_with_debt_info($search, $orderby, $order, $status_filter);
            
            // Ensure we have an array even if the function returns null/false
            if (!is_array($customer_debts)) {
                $customer_debts = array();
            }
            
            $debts = array(); // Empty for summary calculations
            
            // Fallback if no customers found and not using 'all' filter
            if (empty($customer_debts) && $status_filter !== 'all') {
                $customer_debts = $this->db->get_all_customers_with_debt_info($search, $orderby, $order, 'all');
                if (!is_array($customer_debts)) {
                    $customer_debts = array();
                }
            }
        } else {
            $debts = $this->get_filtered_debts($search, $orderby, $order, $status_filter, $type_filter);
            $customer_debts = array();
        }
        
        ?>
        <div class="wrap cdm-debts-admin-page">
            <h1><?php _e('Customer Debt Management', 'customer-debt-manager'); ?>

            </h1>
            
            <!-- Navigation Tabs -->
            <div class="nav-tab-wrapper">
                <a href="<?php echo admin_url('admin.php?page=customer-debts&tab=by_customer'); ?>" 
                   class="nav-tab <?php echo $current_tab === 'by_customer' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('By Customer', 'customer-debt-manager'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=customer-debts&tab=all_debts'); ?>" 
                   class="nav-tab <?php echo $current_tab === 'all_debts' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('All Debts', 'customer-debt-manager'); ?>
                </a>
            </div>
            
            <form method="get" action="">
                <input type="hidden" name="page" value="customer-debts">
                <input type="hidden" name="tab" value="<?php echo esc_attr($current_tab); ?>">
                <?php if (!empty($_GET['orderby'])): ?>
                    <input type="hidden" name="orderby" value="<?php echo esc_attr($_GET['orderby']); ?>">
                <?php endif; ?>
                <?php if (!empty($_GET['order'])): ?>
                    <input type="hidden" name="order" value="<?php echo esc_attr($_GET['order']); ?>">
                <?php endif; ?>
            
            <!-- Summary Cards -->
            <div class="cdm-admin-summary" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
                <?php
                $total_outstanding = 0;
                $total_paid = 0;
                $active_debts = 0;
                $total_customers = 0;
                
                if ($current_tab === 'by_customer') {
                    // Calculate totals from customer debt data
                    foreach ($customer_debts as $customer_debt) {
                        $total_outstanding += $customer_debt->total_remaining_amount;
                        $total_paid += $customer_debt->total_paid_amount;
                        if ($customer_debt->total_remaining_amount > 0) $active_debts++;
                        $total_customers++;
                    }
                } else {
                    // Calculate totals from individual debt records
                    foreach ($debts as $debt) {
                        $total_outstanding += $debt->remaining_amount;
                        $total_paid += $debt->paid_amount;
                        if ($debt->status === 'active') $active_debts++;
                    }
                }
                ?>
                
                <div class="cdm-summary-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <h3 style="margin: 0 0 10px 0; color: #d63638;"><?php echo wc_price($total_outstanding); ?></h3>
                    <p style="margin: 0; color: #646970;"><?php _e('Total Outstanding', 'customer-debt-manager'); ?></p>
                </div>
                
                <div class="cdm-summary-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <h3 style="margin: 0 0 10px 0; color: #00a32a;"><?php echo wc_price($total_paid); ?></h3>
                    <p style="margin: 0; color: #646970;"><?php _e('Total Paid', 'customer-debt-manager'); ?></p>
                </div>
                
                <div class="cdm-summary-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <h3 style="margin: 0 0 10px 0; color: #0073aa;"><?php echo $active_debts; ?></h3>
                    <p style="margin: 0; color: #646970;">
                        <?php 
                        if ($current_tab === 'by_customer') {
                            _e('Customers with Debt', 'customer-debt-manager');
                        } else {
                            _e('Active Debts', 'customer-debt-manager');
                        }
                        ?>
                    </p>
                </div>
                
                <div class="cdm-summary-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <h3 style="margin: 0 0 10px 0; color: #646970;">
                        <?php 
                        if ($current_tab === 'by_customer') {
                            echo $total_customers;
                        } else {
                            echo count($debts);
                        }
                        ?>
                    </h3>
                    <p style="margin: 0; color: #646970;">
                        <?php 
                        if ($current_tab === 'by_customer') {
                            _e('Total Customers', 'customer-debt-manager');
                        } else {
                            _e('Total Debts', 'customer-debt-manager');
                        }
                        ?>
                    </p>
                </div>
            </div>
            
            <!-- Search and Filters -->
            <div class="tablenav top">
                <div class="alignleft actions">
                    <!-- Status Filter -->
                    <select name="status_filter" id="status_filter">
                        <option value="all" <?php selected($status_filter, 'all'); ?>><?php _e('All Customers', 'customer-debt-manager'); ?></option>
                        <option value="active" <?php selected($status_filter, 'active'); ?>><?php _e('With Outstanding Debt', 'customer-debt-manager'); ?></option>
                        <option value="paid" <?php selected($status_filter, 'paid'); ?>><?php _e('Fully Paid', 'customer-debt-manager'); ?></option>
                        <?php if ($current_tab === 'by_customer'): ?>
                        <option value="no_debt" <?php selected($status_filter, 'no_debt'); ?>><?php _e('No Debt History', 'customer-debt-manager'); ?></option>
                        <?php endif; ?>
                    </select>
                    
                    <!-- Type Filter - Only show for All Debts tab -->
                    <?php if ($current_tab === 'all_debts'): ?>
                    <select name="type_filter" id="type_filter">
                        <option value="all" <?php selected($type_filter, 'all'); ?>><?php _e('All Types', 'customer-debt-manager'); ?></option>
                        <option value="cod" <?php selected($type_filter, 'cod'); ?>><?php _e('COD Only', 'customer-debt-manager'); ?></option>
                        <option value="credit" <?php selected($type_filter, 'credit'); ?>><?php _e('Credit Only', 'customer-debt-manager'); ?></option>
                    </select>
                    <?php endif; ?>
                    
                    <input type="submit" class="button" value="<?php _e('Filter', 'customer-debt-manager'); ?>" id="filter-submit">
                </div>
                
                <div class="alignright">
                    <div class="search-box">
                        <input type="search" name="s" id="debt-search-input" value="<?php echo esc_attr($search); ?>" 
                               placeholder="<?php 
                               if ($current_tab === 'by_customer') {
                                   _e('Search customers...', 'customer-debt-manager');
                               } else {
                                   _e('Search debts...', 'customer-debt-manager');
                               }
                               ?>">
                        <input type="submit" class="button" value="<?php _e('Search', 'customer-debt-manager'); ?>" id="search-submit">
                    </div>
                </div>
                
                <br class="clear">
            </div>
            
            <?php if ($current_tab === 'by_customer'): ?>
                <!-- Customer Debts View -->
                <?php $this->render_customer_debts_view($customer_debts, $search, $orderby, $order, $status_filter); ?>
            <?php else: ?>
                <!-- Individual Debts Table -->
                <?php $this->render_all_debts_view($debts, $search, $orderby, $order, $status_filter, $type_filter); ?>
            <?php endif; ?>
        </form>
        </div>
        
        <!-- Payment Modal -->
        <div id="cdm-payment-modal" class="cdm-modal">
            <div class="cdm-modal-content">
                <h3><?php _e('Add Manual Payment', 'customer-debt-manager'); ?></h3>
                
                <form id="cdm-payment-form" method="post">
                    <input type="hidden" name="debt_id" id="modal-debt-id" value="">
                    <input type="hidden" name="action" value="cdm_add_payment">
                    <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('cdm_add_payment'); ?>">
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Customer:', 'customer-debt-manager'); ?></th>
                            <td><span id="modal-customer-name" style="font-weight: bold;"></span></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Remaining Debt:', 'customer-debt-manager'); ?></th>
                            <td><span id="modal-remaining-amount" style="font-weight: bold; color: #d63638;"></span></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="payment_amount"><?php _e('Payment Amount:', 'customer-debt-manager'); ?></label></th>
                            <td>
                                <input type="number" name="payment_amount" id="payment_amount" min="0.01" step="0.01" required class="regular-text">
                                <p class="description"><?php _e('Enter the amount received from customer', 'customer-debt-manager'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="payment_type"><?php _e('Payment Type:', 'customer-debt-manager'); ?></label></th>
                            <td>
                                <select name="payment_type" id="payment_type" class="regular-text">
                                    <option value="cash"><?php _e('Cash', 'customer-debt-manager'); ?></option>
                                    <option value="bank_transfer"><?php _e('Bank Transfer', 'customer-debt-manager'); ?></option>
                                    <option value="credit_card"><?php _e('Credit Card', 'customer-debt-manager'); ?></option>
                                    <option value="check"><?php _e('Check', 'customer-debt-manager'); ?></option>
                                    <option value="other"><?php _e('Other', 'customer-debt-manager'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="payment_note"><?php _e('Payment Note:', 'customer-debt-manager'); ?></label></th>
                            <td>
                                <textarea name="payment_note" id="payment_note" rows="3" class="large-text" placeholder="<?php _e('Optional note about this payment...', 'customer-debt-manager'); ?>"></textarea>
                            </td>
                        </tr>
                    </table>
                    
                    <div class="cdm-modal-actions">
                        <button type="button" class="button" onclick="closeCdmPaymentModal()">
                            <?php _e('Cancel', 'customer-debt-manager'); ?>
                        </button>
                        <button type="submit" class="button button-primary">
                            <?php _e('Add Payment', 'customer-debt-manager'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Payments History Modal -->
        <div id="cdm-payments-modal" class="cdm-modal">
            <div class="cdm-modal-content" style="min-width: 600px; max-width: 800px;">
                <h3><?php _e('Payment History', 'customer-debt-manager'); ?></h3>
                <div id="payments-history-content">
                    <?php _e('Loading...', 'customer-debt-manager'); ?>
                </div>
                <div class="cdm-modal-actions">
                    <button type="button" class="button" onclick="closeCdmPaymentsModal()">
                        <?php _e('Close', 'customer-debt-manager'); ?>
                    </button>
                </div>
            </div>
        </div>
        
        <script>
        var currencySymbol = '<?php echo esc_js(get_woocommerce_currency_symbol()); ?>';
        
        // Helper function for payment modal notifications (outside jQuery context)
        function showPaymentModalNotification(message, type) {
            jQuery(document).ready(function($) {
                type = type || 'error';
                var noticeClass = 'notice notice-' + type;
                if (type === 'error') {
                    noticeClass += ' notice-alt';
                }
                
                var notification = $('<div class="' + noticeClass + ' is-dismissible cdm-notification"><p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>');
                
                // Remove existing modal notifications
                $('#cdm-payment-modal .cdm-notification').remove();
                
                // Add notification at the top of modal content
                $('#cdm-payment-modal .cdm-modal-content h3').after(notification);
                
                // Modal styling
                notification.css({
                    'margin': '15px 0',
                    'position': 'relative'
                });
                
                // Auto-dismiss after 5 seconds for success messages
                if (type === 'success') {
                    setTimeout(function() {
                        notification.fadeOut();
                    }, 5000);
                }
                
                // Handle dismiss button
                notification.find('.notice-dismiss').on('click', function() {
                    notification.fadeOut();
                });
            });
        }

        function openCdmPaymentModal(debtId, customerName, remainingAmount) {
            // Clear any existing modal notifications
            document.querySelectorAll('#cdm-payment-modal .cdm-notification').forEach(function(notification) {
                notification.remove();
            });
            document.getElementById('modal-debt-id').value = debtId;
            document.getElementById('modal-customer-name').textContent = customerName;
            document.getElementById('modal-remaining-amount').textContent = currencySymbol + remainingAmount;
            document.getElementById('payment_amount').value = '';
            document.getElementById('payment_amount').max = remainingAmount;
            document.getElementById('payment_type').value = 'cash';
            document.getElementById('payment_note').value = '';
            document.getElementById('cdm-payment-modal').style.display = 'block';
        }
        
        function closeCdmPaymentModal() {
            // Clear modal notifications
            document.querySelectorAll('#cdm-payment-modal .cdm-notification').forEach(function(notification) {
                notification.remove();
            });
            document.getElementById('cdm-payment-modal').style.display = 'none';
            document.getElementById('cdm-payment-form').reset();
        }
        
        function openCdmPaymentsModal(debtId) {
            document.getElementById('cdm-payments-modal').style.display = 'block';
            
            // Load payment history via AJAX
            var xhr = new XMLHttpRequest();
            var ajaxUrl = typeof cdm_ajax !== 'undefined' ? cdm_ajax.ajax_url : ajaxurl;
            xhr.open('POST', ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    document.getElementById('payments-history-content').innerHTML = xhr.responseText;
                }
            };
            xhr.send('action=cdm_get_debt_payments&debt_id=' + debtId + '&nonce=<?php echo wp_create_nonce('cdm_get_payments'); ?>');
        }
        
        function closeCdmPaymentsModal() {
            document.getElementById('cdm-payments-modal').style.display = 'none';
        }
        
        // Handle AJAX form submission for payment
        function submitPaymentForm(form) {
            var formData = new FormData(form);
            var xhr = new XMLHttpRequest();
            var ajaxUrl = typeof cdm_ajax !== 'undefined' ? cdm_ajax.ajax_url : ajaxurl;
            
            xhr.open('POST', ajaxUrl, true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            showPaymentModalNotification(response.data.message, 'success');
                            setTimeout(function() {
                                closeCdmPaymentModal();
                                location.reload();
                            }, 2000); // Brief delay to show success message
                        } else {
                            showPaymentModalNotification(response.data.message || 'An error occurred while processing the payment.', 'error');
                        }
                    } catch (e) {
                        showPaymentModalNotification('An error occurred while processing the payment.', 'error');
                    }
                }
            };
            
            xhr.send(formData);
        }
        
        // Bind event listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Payment form submission
            var paymentForm = document.getElementById('cdm-payment-form');
            if (paymentForm) {
                paymentForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var amount = parseFloat(document.getElementById('payment_amount').value);
                    if (!amount || amount <= 0) {
                        showPaymentModalNotification('Please enter a valid payment amount.', 'error');
                        return false;
                    }
                    submitPaymentForm(this);
                });
            }
        });
            
            // Payment buttons
            document.querySelectorAll('.cdm-add-payment-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    openCdmPaymentModal(
                        this.dataset.debtId,
                        this.dataset.customerName,
                        this.dataset.remaining
                    );
                });
            });
            
            document.querySelectorAll('.cdm-view-payments-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    openCdmPaymentsModal(this.dataset.debtId);
                });
            });
            
            // Close modal when clicking outside
            document.getElementById('cdm-payment-modal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeCdmPaymentModal();
                }
            });
            
            document.getElementById('cdm-payments-modal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeCdmPaymentsModal();
                }
            });
            
            // Auto-submit filter form when dropdowns change
            document.querySelectorAll('.cdm-filter-select').forEach(function(select) {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });
            
            // Search form submission handlers
            var searchForm = document.getElementById('debt-search-form');
            if (searchForm) {
                var searchSubmit = document.getElementById('debt-search-submit');
                var searchClear = document.getElementById('debt-search-clear');
                
                if (searchSubmit) {
                    searchSubmit.addEventListener('click', function(e) {
                        e.preventDefault();
                        searchForm.submit();
                    });
                }
                
                if (searchClear) {
                    searchClear.addEventListener('click', function(e) {
                        e.preventDefault();
                        document.getElementById('customer_search').value = '';
                        document.getElementById('status_filter').value = '';
                        document.getElementById('type_filter').value = '';
                        searchForm.submit();
                    });
                }
            }
        </script>
        
        <script type="text/javascript">
        // Localized strings for JavaScript
        var cdmStrings = {
            manualDebtAdjustment: '<?php echo esc_js(__('Manual Debt Adjustment', 'customer-debt-manager')); ?>',
            addDebtToCustomer: '<?php echo esc_js(__('Add Debt to Customer', 'customer-debt-manager')); ?>',
            adjustmentAmount: '<?php echo esc_js(__('Adjustment Amount', 'customer-debt-manager')); ?>',
            debtAmount: '<?php echo esc_js(__('Debt Amount', 'customer-debt-manager')); ?>',
            adjustmentDescription: '<?php echo esc_js(__('Select what you would like to do and enter the amount you want to apply.', 'customer-debt-manager')); ?>',
            addDebtDescription: '<?php echo esc_js(__('Enter the amount you want to add as new debt.', 'customer-debt-manager')); ?>',
            reduceDebtDescription: '<?php echo esc_js(__('Choose \'Reduce debt\' to record a payment against the customer\'s balance.', 'customer-debt-manager')); ?>',
            increaseOnlyDescription: '<?php echo esc_js(__('This customer has no outstanding balance yet, so only increases are available.', 'customer-debt-manager')); ?>',
            directionRequired: '<?php echo esc_js(__('Please select whether to increase or reduce the debt.', 'customer-debt-manager')); ?>',
            amountPositive: '<?php echo esc_js(__('Please enter an amount greater than zero.', 'customer-debt-manager')); ?>',
            decreaseNotAllowed: '<?php echo esc_js(__('This customer has no outstanding debt to reduce. Please select \'Increase debt\' instead to add new debt.', 'customer-debt-manager')); ?>',
            overReductionWarning: '<?php echo esc_js(__('The amount entered is larger than the customer\'s outstanding balance. Continue?', 'customer-debt-manager')); ?>',
            noDebtWarning: '<?php echo esc_js(__('This customer currently has no recorded debt. Do you still want to continue?', 'customer-debt-manager')); ?>',
            refreshingBalance: '<?php echo esc_js(__('Fetching latest balance...', 'customer-debt-manager')); ?>',
            noRecordedBalance: '<?php echo esc_js(__('No recorded balance', 'customer-debt-manager')); ?>',
            balanceRefreshError: '<?php echo esc_js(__('Unable to load the latest balance. The last known value is shown.', 'customer-debt-manager')); ?>'
        };
        
        jQuery(document).ready(function($) {
            var currencySymbol = (typeof cdm_ajax !== 'undefined' && cdm_ajax.currency_symbol) ? cdm_ajax.currency_symbol : '';
            var currentSummaryRequest = null;

            // Custom notification system
            function showNotification(message, type, container) {
                type = type || 'error';
                container = container || 'page';
                
                var noticeClass = 'notice notice-' + type;
                if (type === 'error') {
                    noticeClass += ' notice-alt';
                }
                
                var notification = $('<div class="' + noticeClass + ' is-dismissible cdm-notification"><p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>');
                
                if (container === 'modal') {
                    // Show in modal
                    var activeModal = $('.cdm-modal:visible');
                    if (activeModal.length) {
                        // Remove existing modal notifications
                        activeModal.find('.cdm-notification').remove();
                        
                        // Add notification at the top of modal content
                        activeModal.find('.cdm-modal-content h3').after(notification);
                        
                        // Different styling for modal notifications
                        notification.css({
                            'margin': '15px 0',
                            'position': 'relative'
                        });
                    } else {
                        // Fallback to page notification if no modal is visible
                        container = 'page';
                    }
                }
                
                if (container === 'page') {
                    // Show at page level
                    $('.cdm-notification').remove();
                    $('.wrap h1').after(notification);
                    
                    // Scroll to top to show notification
                    $('html, body').animate({scrollTop: 0}, 500);
                }
                
                // Auto-dismiss after 5 seconds for success messages
                if (type === 'success') {
                    setTimeout(function() {
                        notification.fadeOut();
                    }, 5000);
                }
                
                // Handle dismiss button
                notification.find('.notice-dismiss').on('click', function() {
                    notification.fadeOut();
                });
            }

            function parseMoneyValue(rawValue) {
                if (rawValue === undefined || rawValue === null) {
                    return 0;
                }

                var normalized = String(rawValue).trim();
                if (!normalized) {
                    return 0;
                }

                normalized = normalized.replace(/\s+/g, '');

                // Handle decimals that use comma separators
                if (/,[0-9]{1,}/.test(normalized) && normalized.indexOf('.') === -1) {
                    normalized = normalized.replace(/\./g, '');
                    normalized = normalized.replace(/,/g, '.');
                } else {
                    normalized = normalized.replace(/,/g, '');
                }

                normalized = normalized.replace(/[^0-9.\-]/g, '');
                var parsed = parseFloat(normalized);
                return isNaN(parsed) ? 0 : parsed;
            }

            function formatCurrency(amount) {
                var numeric = parseFloat(amount);
                if (isNaN(numeric)) {
                    return cdmStrings.noRecordedBalance;
                }

                numeric = Math.round((numeric + Number.EPSILON) * 100) / 100;
                if (currencySymbol) {
                    return currencySymbol + numeric.toFixed(2);
                }
                return numeric.toFixed(2);
            }

            function applyManualAdjustState(state, options) {
                options = options || {};

                var previousDirection = $('input[name="adjustment_direction"]:checked').val();
                var explicitDirection = options.forceDirection ? options.forceDirection : null;

                var currentDebtValue = typeof state.currentDebt !== 'undefined' ? parseFloat(state.currentDebt) : 0;
                if (isNaN(currentDebtValue)) {
                    currentDebtValue = 0;
                }

                var hasDebt = state.hasDebt === true || state.hasDebt === 'true' || state.hasDebt === 1 || state.hasDebt === '1' || currentDebtValue > 0;

                $('#cdm-manual-adjust-form').data('has-debt', !!hasDebt);
                $('#cdm-manual-adjust-form').data('current-debt', parseFloat(currentDebtValue));

                var balanceDisplay = state.displayBalance;
                if (balanceDisplay === undefined || balanceDisplay === null) {
                    if (currentDebtValue > 0) {
                        balanceDisplay = formatCurrency(currentDebtValue);
                    } else if (state.rawBalance) {
                        balanceDisplay = state.rawBalance;
                    } else {
                        balanceDisplay = cdmStrings.noRecordedBalance;
                    }
                }

                $('#adjust-current-debt').text(balanceDisplay);

                $('#adjustment-type-description').text(hasDebt ? cdmStrings.reduceDebtDescription : cdmStrings.increaseOnlyDescription);

                $('#adjustment-direction-increase').prop('disabled', false);
                $('#adjustment-direction-decrease').prop('disabled', false);

                $('#adjustment-direction-decrease-label').toggleClass('cdm-option-disabled', !hasDebt && currentDebtValue <= 0);

                if (explicitDirection) {
                    $('input[name="adjustment_direction"]').prop('checked', false);
                    $('input[name="adjustment_direction"][value="' + explicitDirection + '"]').prop('checked', true);
                } else if (!options.preserveDirection) {
                    var defaultDirection = hasDebt ? 'decrease' : 'increase';
                    $('input[name="adjustment_direction"]').prop('checked', false);
                    $('input[name="adjustment_direction"][value="' + defaultDirection + '"]').prop('checked', true);
                } else {
                    if (previousDirection && $('input[name="adjustment_direction"][value="' + previousDirection + '"]').length) {
                        $('input[name="adjustment_direction"]').prop('checked', false);
                        $('input[name="adjustment_direction"][value="' + previousDirection + '"]').prop('checked', true);
                    }
                }
            }

            function refreshManualAdjustBalance(customerId) {
                if (!cdm_ajax || !cdm_ajax.nonces || !cdm_ajax.nonces.get_customer_summary) {
                    return;
                }

                if (currentSummaryRequest && currentSummaryRequest.readyState !== 4) {
                    currentSummaryRequest.abort();
                }

                $('#adjust-current-debt').text(cdmStrings.refreshingBalance);

                currentSummaryRequest = $.ajax({
                    url: cdm_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'cdm_get_customer_debt_summary',
                        customer_id: customerId,
                        nonce: cdm_ajax.nonces.get_customer_summary
                    },
                    success: function(response) {
                        if (response && response.success && response.data) {
                            var remaining = parseFloat(response.data.total_remaining_amount);
                            if (isNaN(remaining)) {
                                remaining = 0;
                            }

                            applyManualAdjustState({
                                hasDebt: !!(response.data.has_active_debt || remaining > 0),
                                currentDebt: remaining,
                                displayBalance: response.data.formatted_balance || formatCurrency(remaining)
                            }, { preserveDirection: true });

                            if (response.data.has_active_debt) {
                                $('#current-balance-row').show();
                                $('#adjust-modal-title').text(cdmStrings.manualDebtAdjustment);
                            } else {
                                if (remaining > 0) {
                                    $('#current-balance-row').show();
                                } else {
                                    $('#current-balance-row').hide();
                                }
                                $('#adjust-modal-title').text(cdmStrings.addDebtToCustomer);
                            }
                        } else if (response && response.data && response.data.message) {
                            $('#adjust-current-debt').text(response.data.message);
                        } else {
                            $('#adjust-current-debt').text(cdmStrings.balanceRefreshError);
                        }
                    },
                    error: function() {
                        $('#adjust-current-debt').text(cdmStrings.balanceRefreshError);
                    },
                    complete: function() {
                        currentSummaryRequest = null;
                    }
                });
            }
            
            // Manual debt adjustment functionality
            $(document).on('click', '.cdm-manual-adjust-btn', function() {
                var customerId = $(this).data('customer-id');
                var customerName = $(this).data('customer-name');
                var currentDebt = $(this).data('current-debt');
                var hasDebtHint = $(this).data('has-debt');

                openCdmManualAdjustModal(customerId, customerName, currentDebt, hasDebtHint);
            });
            
            // Customer details functionality
            $(document).on('click', '.cdm-view-customer-details-btn', function() {
                var customerId = $(this).data('customer-id');
                var customerName = $(this).data('customer-name');
                
                openCdmCustomerDetailsModal(customerId, customerName);
            });
            
            function submitManualAdjustment() {
                var customerId = $('#adjust-customer-id').val();
                var adjustmentAmountField = $('#adjustment-amount');
                var adjustmentAmount = adjustmentAmountField.val();
                var adjustmentReason = $('#adjustment-reason').val();
                var adjustmentDirection = $('input[name="adjustment_direction"]:checked').val();
                
                // Get form data with fallback to current balance display
                var rawHasDebt = $('#cdm-manual-adjust-form').data('has-debt');
                var hasDebt = rawHasDebt === true || rawHasDebt === 'true' || rawHasDebt === 1 || rawHasDebt === '1';
                var rawCurrentDebt = $('#cdm-manual-adjust-form').data('current-debt');
                var currentDebtNumeric = parseFloat(rawCurrentDebt);
                if (isNaN(currentDebtNumeric)) {
                    currentDebtNumeric = 0;
                }
                
                // If form data is missing, try to get it from the displayed balance
                if ((rawHasDebt === undefined || rawCurrentDebt === undefined) && $('#adjust-current-debt').length) {
                    var displayedBalance = $('#adjust-current-debt').text();
                    if (displayedBalance && displayedBalance !== cdmStrings.refreshingBalance && displayedBalance !== cdmStrings.noRecordedBalance) {
                        // Try to parse the displayed balance to get debt amount
                        var balanceAmount = parseMoneyValue(displayedBalance);
                        if (balanceAmount > 0) {
                            currentDebtNumeric = balanceAmount;
                            hasDebt = true;
                        }
                    }
                }
                
                var hasCollectibleDebt = hasDebt || currentDebtNumeric > 0;

                if (!customerId) {
                    showNotification('Customer ID is missing', 'error', 'modal');
                    return false;
                }

                if (!adjustmentDirection) {
                    showNotification(cdmStrings.directionRequired, 'error', 'modal');
                    return false;
                }

                var amountValue = parseMoneyValue(adjustmentAmount);
                if (!amountValue || amountValue <= 0) {
                    showNotification(cdmStrings.amountPositive, 'error', 'modal');
                    return false;
                }

                if (!hasCollectibleDebt && adjustmentDirection === 'decrease') {
                    showNotification(cdmStrings.decreaseNotAllowed, 'error', 'modal');
                    return false;
                }

                if (adjustmentDirection === 'decrease' && currentDebtNumeric > 0 && amountValue > currentDebtNumeric) {
                    if (!confirm(cdmStrings.overReductionWarning)) {
                        return false;
                    }
                }

                if (!adjustmentReason || !adjustmentReason.trim()) {
                    showNotification('Please provide a reason for the adjustment', 'error', 'modal');
                    return false;
                }

                if (typeof cdm_ajax === 'undefined') {
                    console.error('CDM: Configuration error - cdm_ajax not loaded');
                    return false;
                }

                var payload = {
                    action: 'cdm_manual_debt_adjustment',
                    customer_id: customerId,
                    adjustment_amount: amountValue,
                    adjustment_reason: adjustmentReason,
                    adjustment_direction: adjustmentDirection,
                    nonce: cdm_ajax.nonces.manual_adjustment
                };

                $.ajax({
                    url: cdm_ajax.ajax_url,
                    type: 'POST',
                    data: payload,
                    beforeSend: function() {
                        $('#cdm-submit-adjustment').prop('disabled', true).text('Processing...');
                    },
                    success: function(response) {
                        if (response.success) {
                            showNotification(response.data.message, 'success', 'page');
                            closeCdmManualAdjustModal();
                            location.reload();
                        } else {
                            showNotification('Error: ' + (response.data.message || 'Unknown error occurred'), 'error', 'modal');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('CDM: Network error occurred:', error);
                    },
                    complete: function() {
                        $('#cdm-submit-adjustment').prop('disabled', false).text('Apply Adjustment');
                    }
                });

                return false;
            }

            // Manual adjustment form submission
            $(document).on('submit', '#cdm-manual-adjust-form', function(e) {
                e.preventDefault();
                e.stopPropagation();
                submitManualAdjustment();
                return false;
            });

            // Handle primary button click
            $(document).on('click', '#cdm-submit-adjustment', function(e) {
                e.preventDefault();
                submitManualAdjustment();
                return false;
            });
            
            // Manual debt adjustment modal functions
            window.openCdmManualAdjustModal = function(customerId, customerName, currentDebt, hasDebtHint) {
                // Clear any existing modal notifications
                $('#cdm-manual-adjust-modal .cdm-notification').remove();
                
                var currentDebtNumeric = parseMoneyValue(currentDebt);
                var inferredHasDebt = typeof hasDebtHint !== 'undefined' ? Boolean(parseInt(hasDebtHint, 10)) : (currentDebtNumeric > 0);

                $('#adjust-customer-id').val(customerId);
                $('#adjust-customer-name').text(customerName);

                $('#adjust-modal-title').text(inferredHasDebt ? cdmStrings.manualDebtAdjustment : cdmStrings.addDebtToCustomer);
                $('#current-balance-row')[inferredHasDebt ? 'show' : 'hide']();

                applyManualAdjustState({
                    hasDebt: inferredHasDebt,
                    currentDebt: currentDebtNumeric,
                    displayBalance: inferredHasDebt && currentDebtNumeric > 0 ? formatCurrency(currentDebtNumeric) : cdmStrings.noRecordedBalance,
                    rawBalance: currentDebt
                }, { forceDirection: inferredHasDebt ? 'decrease' : 'increase' });

                $('#adjustment-amount').val('');
                $('#adjustment-reason').val('');
                $('#cdm-manual-adjust-modal').show();

                refreshManualAdjustBalance(customerId);
            };
            
            window.closeCdmManualAdjustModal = function() {
                // Clear modal notifications
                $('#cdm-manual-adjust-modal .cdm-notification').remove();
                $('#cdm-manual-adjust-modal').hide();
                $('#cdm-manual-adjust-form').removeData('has-debt');
                $('#cdm-manual-adjust-form').removeData('current-debt');

                if (currentSummaryRequest && currentSummaryRequest.readyState !== 4) {
                    currentSummaryRequest.abort();
                    currentSummaryRequest = null;
                }
            };
            
            // Customer details modal functions
            window.openCdmCustomerDetailsModal = function(customerId, customerName) {
                $('#cdm-customer-details-content').html('<div class="cdm-loading">Loading...</div>');
                $('#cdm-customer-details-modal').show();
                
                $.ajax({
                    url: cdm_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'cdm_get_customer_debt_details',
                        customer_id: customerId,
                        nonce: cdm_ajax.nonces.get_customer_details
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#cdm-customer-details-content').html(response.data.content);
                        } else {
                            $('#cdm-customer-details-content').html('<p>Error: ' + (response.data.message || 'Failed to load customer details') + '</p>');
                        }
                    },
                    error: function() {
                        $('#cdm-customer-details-content').html('<p>Network error occurred</p>');
                    }
                });
            };
            
            window.closeCdmCustomerDetailsModal = function() {
                $('#cdm-customer-details-modal').hide();
            };
        
            // Close modals when clicking outside
            $(document).on('click', '.cdm-modal', function(e) {
                if (e.target === this) {
                    $(this).hide();
                }
            });

            // Cleanup data button handler
            $('#cdm-cleanup-data-btn').on('click', function() {
                $(this).prop('disabled', true).text('Cleaning up...');
                
                $.ajax({
                    url: cdm_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'cdm_cleanup_debt_data',
                        nonce: cdm_ajax.nonces.cleanup_data
                    },
                    success: function(response) {
                        if (response.success) {
                            console.log('Cleanup completed:', response.data.message);
                            location.reload(); // Refresh to show clean data
                        } else {
                            console.error('Cleanup failed:', response.data.message || 'Unknown error');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Cleanup network error:', error);
                    },
                    complete: function() {
                        $('#cdm-cleanup-data-btn').prop('disabled', false).text('Cleanup Data');
                    }
                });
            });
        });
        </script>
        
        <style>
        /* Custom notification styling */
        .cdm-notification {
            margin: 20px 0 20px 0;
            border-left: 4px solid;
            box-shadow: 0 1px 1px 0 rgba(0, 0, 0, 0.1);
            background: #fff;
            padding: 1px 12px;
        }
        
        .cdm-notification.notice-success {
            border-left-color: #00a32a;
        }
        
        .cdm-notification.notice-error {
            border-left-color: #d63638;
        }
        
        .cdm-notification.notice-warning {
            border-left-color: #dba617;
        }
        
        .cdm-notification.notice-info {
            border-left-color: #72aee6;
        }
        
        .cdm-notification p {
            margin: 0.5em 0;
            padding: 2px;
        }
        
        .cdm-notification .notice-dismiss {
            float: right;
            padding: 9px;
            text-decoration: none;
            position: absolute;
            right: 1px;
            top: 0;
            outline: none;
            border: none;
            background: none;
            color: #787c82;
            cursor: pointer;
        }
        
        /* WordPress Admin Table Styling for Debt Manager */
        .cdm-debts-table {
            margin: 20px 0;
        }
        
        .cdm-debts-table h2 {
            margin-bottom: 10px;
        }
        
        /* Sortable columns */
        .wp-list-table th.sortable a,
        .wp-list-table th.sorted a {
            display: block;
            padding: 8px;
            text-decoration: none;
            color: inherit;
        }
        
        .wp-list-table th.sortable a:hover,
        .wp-list-table th.sorted a:hover {
            color: #0073aa;
        }
        
        /* Search and filter form */
        .tablenav {
            margin: 6px 0 4px 0;
            padding: 0;
            height: 30px;
            overflow: hidden;
        }
        
        .tablenav .alignleft {
            float: left;
        }
        
        .tablenav .alignright {
            float: right;
        }
        
        .tablenav .actions {
            padding: 2px 8px 0 0;
        }
        
        .tablenav .actions select {
            margin-right: 6px;
        }
        
        .search-box {
            float: right;
            margin: 0;
        }
        
        .search-box input[type="search"] {
            margin: 0 4px 0 0;
            width: 280px;
        }
        
        /* Debt Type Badges */
        .cdm-debt-type {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .cdm-debt-type-cod {
            background: #ff6b35;
            color: white;
        }
        
        .cdm-debt-type-credit {
            background: #0073aa;
            color: white;
        }
        
        .cdm-debt-type-unknown {
            background: #6c757d;
            color: white;
        }
        
        /* Debt Status Badges */
        .cdm-debt-status {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .cdm-debt-status-active {
            background: #fff3cd;
            color: #856404;
        }
        
        .cdm-debt-status-paid {
            background: #d4edda;
            color: #155724;
        }
        
        /* Amount Styling */
        .cdm-paid-amount {
            color: #00a32a;
        }
        
        .cdm-amount-outstanding {
            color: #d63638;
        }
        
        .cdm-amount-paid {
            color: #00a32a;
        }
        
        /* Row Actions Styling */
        .row-actions {
            margin-top: 5px;
        }
        
        .row-actions .sep {
            color: #ddd;
        }
        
        /* Summary Cards */
        .cdm-admin-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .cdm-summary-card {
            background: #fff;
            padding: 20px;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            box-shadow: 0 1px 1px rgba(0,0,0,0.04);
        }
        
        .cdm-summary-card h3 {
            margin: 0 0 10px 0;
            font-size: 24px;
        }
        
        .cdm-summary-card p {
            margin: 0;
            color: #646970;
        }
        
        /* Modal Styling */
        .cdm-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999999;
        }
        
        .cdm-modal-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 30px;
            border-radius: 5px;
            min-width: 400px;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .cdm-modal h3 {
            margin: 0 0 20px 0;
        }
        
        .cdm-modal .form-table {
            margin: 0;
        }
        
        .cdm-modal-actions {
            margin-top: 20px;
            text-align: right;
        }
        
        .cdm-modal-actions .button {
            margin-left: 10px;
        }
        
        /* Navigation Tabs */
        .nav-tab-wrapper {
            margin-bottom: 20px;
        }
        
        /* Customer debt table styling */
        .column-debt-count {
            width: 80px;
        }
        
        .column-total-debt,
        .column-paid,
        .column-remaining {
            width: 100px;
            text-align: right;
        }
        
        .column-latest-debt {
            width: 100px;
        }
        
        .column-actions {
            width: 180px;
        }
        
        /* Manual adjustment modal */
        #cdm-manual-adjust-modal .form-table th {
            width: 150px;
        }
        
        #adjustment-amount {
            width: 100px;
        }
        
        #adjustment-reason {
            width: 100%;
            max-width: 400px;
        }

        .cdm-adjustment-type {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
        }

        .cdm-adjustment-type label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }

        .cdm-adjustment-type label.cdm-option-disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .cdm-adjustment-type input[type="radio"] {
            margin: 0;
        }
        
        /* Customer details modal */
        #cdm-customer-details-modal .cdm-modal-content {
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .cdm-customer-details h4 {
            margin-top: 20px;
            margin-bottom: 10px;
            border-bottom: 1px solid #ccd0d4;
            padding-bottom: 5px;
        }
        
        .cdm-customer-details h4:first-child {
            margin-top: 0;
        }
        
        .cdm-loading {
            text-align: center;
            padding: 20px;
            color: #666;
        }
        
        /* Button spacing for customer actions */
        .row-actions .sep {
            color: #ddd;
        }
        
        /* Debt type badges for customer view */
        .cdm-debt-type {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            color: white;
        }
        
        .cdm-debt-type-cod {
            background-color: #ff6b35;
        }
        
        .cdm-debt-type-credit {
            background-color: #0073aa;
        }
        
        .cdm-debt-type-unknown {
            background-color: #6c757d;
        }
        
        /* Status indicators */
        .cdm-amount-outstanding {
            color: #d63638;
            font-weight: bold;
        }
        
        .cdm-amount-paid {
            color: #00a32a;
            font-weight: bold;
        }
        
        .cdm-paid-amount {
            color: #00a32a;
        }
        
        .cdm-debt-status {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            text-transform: capitalize;
        }
        
        .cdm-debt-status-active {
            background-color: #d63638;
            color: white;
        }
        
        .cdm-debt-status-paid {
            background-color: #00a32a;
            color: white;
        }
        </style>
        <?php
    }
    
    /**
     * Render the customer debts view (grouped by customer)
     */
    private function render_customer_debts_view($customer_debts, $search, $orderby, $order, $status_filter) {
        ?>
        <div class="cdm-debts-table">
            <h2><?php _e('All Customers', 'customer-debt-manager'); ?></h2>
            
            <?php if (empty($customer_debts)): ?>
                <div class="notice notice-info">
                    <p><?php _e('No customers found.', 'customer-debt-manager'); ?></p>
                    <!-- Debug info for admin -->
                    <?php if (current_user_can('manage_options')): ?>
                        <p><small>
                            Debug: Customer debts array is empty. 
                            Current filter: <?php echo esc_html($status_filter); ?>. 
                            <a href="<?php echo admin_url('admin.php?page=customer-debts&tab=by_customer&status_filter=all'); ?>">Try showing all customers</a>
                            | <a href="<?php echo admin_url('admin.php?page=customer-debts&tab=by_customer&status_filter=active'); ?>">Show only active debts</a>
                            | <a href="<?php echo admin_url('admin.php?page=customer-debts&tab=by_customer&status_filter=paid'); ?>">Show only paid debts</a>
                        </small></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col" class="manage-column column-customer sortable <?php echo $orderby === 'customer_name' ? 'sorted' : ''; ?> <?php echo $orderby === 'customer_name' ? $order : ''; ?>">
                                <a href="<?php echo $this->get_sort_url('customer_name', $orderby, $order, true); ?>">
                                    <span><?php _e('Customer', 'customer-debt-manager'); ?></span>
                                    <span class="sorting-indicator"></span>
                                </a>
                            </th>
                            <th scope="col" class="manage-column column-debt-count sortable <?php echo $orderby === 'debt_count' ? 'sorted' : ''; ?> <?php echo $orderby === 'debt_count' ? $order : ''; ?>">
                                <a href="<?php echo $this->get_sort_url('debt_count', $orderby, $order, true); ?>">
                                    <span><?php _e('Orders', 'customer-debt-manager'); ?></span>
                                    <span class="sorting-indicator"></span>
                                </a>
                            </th>
                            <th scope="col" class="manage-column column-total-debt sortable <?php echo $orderby === 'total_remaining_amount' ? 'sorted' : ''; ?> <?php echo $orderby === 'total_remaining_amount' ? $order : ''; ?>">
                                <a href="<?php echo $this->get_sort_url('total_remaining_amount', $orderby, $order, true); ?>">
                                    <span><?php _e('Current Debt', 'customer-debt-manager'); ?></span>
                                    <span class="sorting-indicator"></span>
                                </a>
                            </th>
                            <th scope="col" class="manage-column column-paid sortable <?php echo $orderby === 'total_paid_amount' ? 'sorted' : ''; ?> <?php echo $orderby === 'total_paid_amount' ? $order : ''; ?>">
                                <a href="<?php echo $this->get_sort_url('total_paid_amount', $orderby, $order, true); ?>">
                                    <span><?php _e('Paid', 'customer-debt-manager'); ?></span>
                                    <span class="sorting-indicator"></span>
                                </a>
                            </th>
                            <th scope="col" class="manage-column column-remaining sortable <?php echo $orderby === 'total_remaining_amount' ? 'sorted' : ''; ?> <?php echo $orderby === 'total_remaining_amount' ? $order : ''; ?>">
                                <a href="<?php echo $this->get_sort_url('total_remaining_amount', $orderby, $order, true); ?>">
                                    <span><?php _e('Remaining', 'customer-debt-manager'); ?></span>
                                    <span class="sorting-indicator"></span>
                                </a>
                            </th>
                            <th scope="col" class="manage-column column-latest-debt"><?php _e('Latest Order', 'customer-debt-manager'); ?></th>
                            <th scope="col" class="manage-column column-actions"><?php _e('Actions', 'customer-debt-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="the-list">
                        <?php foreach ($customer_debts as $customer_debt): ?>
                            <?php $customer = get_userdata($customer_debt->customer_id); ?>
                            <tr>
                                <td class="customer column-customer">
                                    <?php if ($customer): ?>
                                        <strong><?php echo esc_html($customer->display_name); ?></strong><br>
                                        <span class="description"><?php echo esc_html($customer->user_email); ?></span>
                                    <?php else: ?>
                                        <em class="description"><?php _e('Customer not found', 'customer-debt-manager'); ?></em>
                                    <?php endif; ?>
                                </td>
                                <td class="debt-count column-debt-count">
                                    <strong><?php echo $customer_debt->debt_count; ?></strong>
                                    <?php if ($customer_debt->debt_count > 1): ?>
                                        <span class="description"><?php _e('orders', 'customer-debt-manager'); ?></span>
                                    <?php else: ?>
                                        <span class="description"><?php _e('order', 'customer-debt-manager'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="total-debt column-total-debt">
                                    <strong><?php echo wc_price($customer_debt->total_remaining_amount); ?></strong>
                                </td>
                                <td class="paid column-paid">
                                    <strong class="cdm-paid-amount"><?php echo wc_price($customer_debt->total_paid_amount); ?></strong>
                                </td>
                                <td class="remaining column-remaining">
                                    <strong class="<?php echo $customer_debt->total_remaining_amount > 0 ? 'cdm-amount-outstanding' : 'cdm-amount-paid'; ?>">
                                        <?php echo wc_price($customer_debt->total_remaining_amount); ?>
                                    </strong>
                                </td>
                                <td class="latest-debt column-latest-debt">
                                    <?php if ($customer_debt->latest_debt_date): ?>
                                        <abbr title="<?php echo esc_attr($customer_debt->latest_debt_date); ?>">
                                            <?php echo date_i18n(get_option('date_format'), strtotime($customer_debt->latest_debt_date)); ?>
                                        </abbr>
                                    <?php else: ?>
                                        <span class="description"><?php _e('No debt history', 'customer-debt-manager'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions column-actions">
                                    <div class="row-actions">
                                        <span class="manual-adjust">
                                            <button type="button" class="button <?php echo $customer_debt->debt_count > 0 ? 'button-primary' : 'button-secondary'; ?> cdm-manual-adjust-btn" 
                                                    data-customer-id="<?php echo $customer_debt->customer_id; ?>" 
                                                    data-customer-name="<?php echo $customer ? esc_attr($customer->display_name) : 'Unknown'; ?>"
                                                    data-current-debt="<?php echo esc_attr($customer_debt->total_remaining_amount); ?>"
                                                    data-has-debt="<?php echo ($customer_debt->total_remaining_amount > 0) ? '1' : '0'; ?>">
                                                <?php 
                                                if ($customer_debt->debt_count > 0) {
                                                    _e('Adjust Debt', 'customer-debt-manager');
                                                } else {
                                                    _e('Add Debt', 'customer-debt-manager');
                                                }
                                                ?>
                                            </button>
                                        </span>
                                        <?php if ($customer_debt->debt_count > 0): ?>
                                            <span class="sep"> | </span>
                                            <span class="view-details">
                                                <button type="button" class="button cdm-view-customer-details-btn" 
                                                        data-customer-id="<?php echo $customer_debt->customer_id; ?>"
                                                        data-customer-name="<?php echo $customer ? esc_attr($customer->display_name) : 'Unknown'; ?>">
                                                    <?php _e('View Details', 'customer-debt-manager'); ?>
                                                </button>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <!-- Manual Debt Adjustment Modal -->
        <div id="cdm-manual-adjust-modal" class="cdm-modal">
            <div class="cdm-modal-content">
                <h3 id="adjust-modal-title"><?php _e('Manual Debt Adjustment', 'customer-debt-manager'); ?></h3>
                
                <form id="cdm-manual-adjust-form" onsubmit="return false;">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Customer', 'customer-debt-manager'); ?></th>
                            <td><strong><span id="adjust-customer-name"></span></strong></td>
                        </tr>
                        <tr id="current-balance-row">
                            <th scope="row"><?php _e('Current Balance', 'customer-debt-manager'); ?></th>
                            <td><strong><span id="adjust-current-debt"></span></strong></td>
                        </tr>
                        <tr id="adjustment-type-row">
                            <th scope="row"><?php _e('Action', 'customer-debt-manager'); ?></th>
                            <td class="cdm-adjustment-type">
                                <label id="adjustment-direction-increase-label">
                                    <input type="radio" name="adjustment_direction" id="adjustment-direction-increase" value="increase" checked>
                                    <?php _e('Increase debt', 'customer-debt-manager'); ?>
                                </label>
                                <label id="adjustment-direction-decrease-label">
                                    <input type="radio" name="adjustment_direction" id="adjustment-direction-decrease" value="decrease">
                                    <?php _e('Reduce debt / record payment', 'customer-debt-manager'); ?>
                                </label>
                                <p class="description" id="adjustment-type-description">
                                    <?php _e('Select whether to increase or reduce the outstanding balance.', 'customer-debt-manager'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="adjustment-amount" id="adjustment-amount-label"><?php _e('Adjustment Amount', 'customer-debt-manager'); ?></label></th>
                            <td>
                                <input type="number" id="adjustment-amount" name="adjustment_amount" step="0.01" min="0.01" required>
                                <p class="description" id="adjustment-description">
                                    <?php _e('Enter the amount you want to apply.', 'customer-debt-manager'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="adjustment-reason"><?php _e('Reason', 'customer-debt-manager'); ?></label></th>
                            <td>
                                <textarea id="adjustment-reason" name="adjustment_reason" rows="3" cols="50" required 
                                         placeholder="<?php _e('Please provide a reason for this adjustment...', 'customer-debt-manager'); ?>"></textarea>
                            </td>
                        </tr>
                    </table>
                    
                    <div class="cdm-modal-actions">
                        <input type="hidden" id="adjust-customer-id" name="customer_id">
                        <button type="button" class="button" onclick="closeCdmManualAdjustModal()"><?php _e('Cancel', 'customer-debt-manager'); ?></button>
                        <button type="button" id="cdm-submit-adjustment" class="button button-primary"><?php _e('Apply Adjustment', 'customer-debt-manager'); ?></button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Customer Details Modal -->
        <div id="cdm-customer-details-modal" class="cdm-modal">
            <div class="cdm-modal-content">
                <h3><?php _e('Customer Debt Details', 'customer-debt-manager'); ?></h3>
                <div id="cdm-customer-details-content">
                    <div class="cdm-loading"><?php _e('Loading...', 'customer-debt-manager'); ?></div>
                </div>
                <div class="cdm-modal-actions">
                    <button type="button" class="button" onclick="closeCdmCustomerDetailsModal()"><?php _e('Close', 'customer-debt-manager'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render the all debts view (individual debt records)
     */
    private function render_all_debts_view($debts, $search, $orderby, $order, $status_filter, $type_filter) {
        ?>
        <div class="cdm-debts-table">
            <h2><?php _e('All Customer Debts', 'customer-debt-manager'); ?></h2>
            
            <?php if (empty($debts)): ?>
                <div class="notice notice-info">
                    <p><?php _e('No individual debt records found.', 'customer-debt-manager'); ?></p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Debt ID', 'customer-debt-manager'); ?></th>
                            <th><?php _e('Customer', 'customer-debt-manager'); ?></th>
                            <th><?php _e('Order', 'customer-debt-manager'); ?></th>
                            <th><?php _e('Total Debt', 'customer-debt-manager'); ?></th>
                            <th><?php _e('Paid', 'customer-debt-manager'); ?></th>
                            <th><?php _e('Remaining', 'customer-debt-manager'); ?></th>
                            <th><?php _e('Status', 'customer-debt-manager'); ?></th>
                            <th><?php _e('Created', 'customer-debt-manager'); ?></th>
                            <th><?php _e('Actions', 'customer-debt-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($debts as $debt): ?>
                            <?php 
                            $customer = get_userdata($debt->customer_id);
                            $order = wc_get_order($debt->order_id);
                            ?>
                            <tr>
                                <td><strong>#<?php echo $debt->id; ?></strong></td>
                                <td>
                                    <?php if ($customer): ?>
                                        <strong><?php echo esc_html($customer->display_name); ?></strong><br>
                                        <span class="description"><?php echo esc_html($customer->user_email); ?></span>
                                    <?php else: ?>
                                        <em><?php _e('Customer not found', 'customer-debt-manager'); ?></em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($debt->order_id == 0): ?>
                                        <em><?php _e('Manual Adjustment', 'customer-debt-manager'); ?></em>
                                    <?php elseif ($order): ?>
                                        <a href="<?php echo $this->get_order_edit_url($debt->order_id); ?>" target="_blank">
                                            #<?php echo $debt->order_id; ?>
                                        </a>
                                    <?php else: ?>
                                        #<?php echo $debt->order_id; ?> <em>(<?php _e('Not found', 'customer-debt-manager'); ?>)</em>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo wc_price($debt->debt_amount); ?></td>
                                <td><?php echo wc_price($debt->paid_amount); ?></td>
                                <td class="<?php echo $debt->remaining_amount > 0 ? 'cdm-amount-outstanding' : 'cdm-amount-paid'; ?>">
                                    <?php echo wc_price($debt->remaining_amount); ?>
                                </td>
                                <td>
                                    <span class="cdm-debt-status cdm-debt-status-<?php echo $debt->status; ?>">
                                        <?php echo ucfirst($debt->status); ?>
                                    </span>
                                </td>
                                <td><?php echo date_i18n(get_option('date_format'), strtotime($debt->created_date)); ?></td>
                                <td>
                                    <div class="row-actions">
                                        <?php if ($debt->remaining_amount > 0): ?>
                                            <button type="button" class="button button-small cdm-add-payment-btn" 
                                                    data-debt-id="<?php echo $debt->id; ?>"
                                                    data-customer-name="<?php echo $customer ? esc_attr($customer->display_name) : 'Unknown'; ?>"
                                                    data-remaining="<?php echo $debt->remaining_amount; ?>">
                                                <?php _e('Add Payment', 'customer-debt-manager'); ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Get filtered and sorted debts
     */
    private function get_filtered_debts($search = '', $orderby = 'created_date', $order = 'desc', $status_filter = 'all', $type_filter = 'all') {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'customer_debts';
        
        // Build the query
        $sql = "SELECT d.*, u.display_name as customer_name, u.user_email as customer_email 
                FROM {$table_name} d
                LEFT JOIN {$wpdb->users} u ON d.customer_id = u.ID";
        
        $where_conditions = array();
        $params = array();
        
        // Search functionality
        if (!empty($search)) {
            $where_conditions[] = "(u.display_name LIKE %s OR u.user_email LIKE %s OR d.order_id LIKE %s OR d.id LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }
        
        // Status filter
        if ($status_filter !== 'all') {
            $where_conditions[] = "d.status = %s";
            $params[] = $status_filter;
        }
        
        // Type filter - need to check order meta (HPOS compatible)
        if ($type_filter !== 'all') {
            if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
                // HPOS enabled - use order meta table
                $orders_meta_table = $wpdb->prefix . 'wc_orders_meta';
                if ($type_filter === 'cod') {
                    $where_conditions[] = "d.order_id IN (
                        SELECT order_id FROM {$orders_meta_table} 
                        WHERE meta_key = '_is_cod_debt' AND meta_value = 'yes'
                    )";
                } elseif ($type_filter === 'credit') {
                    $where_conditions[] = "d.order_id IN (
                        SELECT order_id FROM {$orders_meta_table} 
                        WHERE meta_key = '_is_debt_payment' AND meta_value = 'yes'
                    )";
                }
            } else {
                // Legacy posts table
                if ($type_filter === 'cod') {
                    $where_conditions[] = "d.order_id IN (
                        SELECT post_id FROM {$wpdb->postmeta} 
                        WHERE meta_key = '_is_cod_debt' AND meta_value = 'yes'
                    )";
                } elseif ($type_filter === 'credit') {
                    $where_conditions[] = "d.order_id IN (
                        SELECT post_id FROM {$wpdb->postmeta} 
                        WHERE meta_key = '_is_debt_payment' AND meta_value = 'yes'
                    )";
                }
            }
        }
        
        // Add WHERE clause if we have conditions
        if (!empty($where_conditions)) {
            $sql .= " WHERE " . implode(' AND ', $where_conditions);
        }
        
        // Validate and sanitize orderby
        $allowed_orderby = array('id', 'customer_name', 'order_id', 'debt_amount', 'paid_amount', 'remaining_amount', 'status', 'created_date');
        if (!in_array($orderby, $allowed_orderby)) {
            $orderby = 'created_date';
        }
        
        // Handle customer name sorting
        if ($orderby === 'customer_name') {
            $orderby = 'u.display_name';
        } else {
            $orderby = 'd.' . $orderby;
        }
        
        // Validate order direction
        $order = ($order === 'asc') ? 'ASC' : 'DESC';
        
        $sql .= " ORDER BY {$orderby} {$order}";
        
        // Execute query
        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($sql, $params));
        } else {
            return $wpdb->get_results($sql);
        }
    }
    
    /**
     * Get sort URL for table headers
     */
    private function get_sort_url($column, $current_orderby, $current_order, $is_customer_tab = false) {
        $new_order = 'asc';
        
        // If clicking on the same column, reverse the order
        if ($current_orderby === $column) {
            $new_order = ($current_order === 'asc') ? 'desc' : 'asc';
        }
        
        $args = array(
            'page' => 'customer-debts',
            'orderby' => $column,
            'order' => $new_order
        );
        
        // Preserve current tab
        if (!empty($_GET['tab'])) {
            $args['tab'] = $_GET['tab'];
        } elseif ($is_customer_tab) {
            $args['tab'] = 'by_customer';
        }
        
        // Preserve current search and filters
        if (!empty($_GET['s'])) {
            $args['s'] = $_GET['s'];
        }
        if (!empty($_GET['status_filter']) && $_GET['status_filter'] !== 'all') {
            $args['status_filter'] = $_GET['status_filter'];
        }
        if (!empty($_GET['type_filter']) && $_GET['type_filter'] !== 'all') {
            $args['type_filter'] = $_GET['type_filter'];
        }
        
        return admin_url('admin.php?' . http_build_query($args));
    }

    /**
     * AJAX handler for debt payments history
     */
    public function ajax_get_debt_payments() {
        if (!check_ajax_referer('cdm_get_payments', 'nonce', false)) {
            wp_die(__('Security check failed', 'customer-debt-manager'));
        }
        
        $debt_id = isset($_POST['debt_id']) ? absint(wp_unslash($_POST['debt_id'])) : 0;

        if (!$debt_id) {
            wp_die(__('Invalid debt ID.', 'customer-debt-manager'));
        }
        $debt = $this->db->get_debt($debt_id);
        $payments = $this->db->get_debt_payments($debt_id);
        
        if (!$debt) {
            echo '<p>' . __('Debt not found.', 'customer-debt-manager') . '</p>';
            wp_die();
        }
        
        $customer = get_userdata($debt->customer_id);
        $order = wc_get_order($debt->order_id);
        
        echo '<div class="debt-info" style="background: #f9f9f9; padding: 15px; margin-bottom: 20px; border-radius: 4px;">';
        echo '<h4 style="margin: 0 0 10px 0;">' . __('Debt Information', 'customer-debt-manager') . '</h4>';
        echo '<p><strong>' . __('Customer:', 'customer-debt-manager') . '</strong> ' . ($customer ? esc_html($customer->display_name) : 'Unknown') . '</p>';
        echo '<p><strong>' . __('Order:', 'customer-debt-manager') . '</strong> #' . $debt->order_id . '</p>';
        echo '<p><strong>' . __('Total Debt:', 'customer-debt-manager') . '</strong> ' . wc_price($debt->debt_amount) . '</p>';
        echo '<p><strong>' . __('Paid Amount:', 'customer-debt-manager') . '</strong> ' . wc_price($debt->paid_amount) . '</p>';
        echo '<p><strong>' . __('Remaining:', 'customer-debt-manager') . '</strong> ' . wc_price($debt->remaining_amount) . '</p>';
        echo '</div>';
        
        if (empty($payments)) {
            echo '<p>' . __('No payments found for this debt.', 'customer-debt-manager') . '</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped" style="margin: 0;">';
            echo '<thead>';
            echo '<tr>';
            echo '<th style="padding: 8px;">' . __('Date', 'customer-debt-manager') . '</th>';
            echo '<th style="padding: 8px;">' . __('Amount', 'customer-debt-manager') . '</th>';
            echo '<th style="padding: 8px;">' . __('Type', 'customer-debt-manager') . '</th>';
            echo '<th style="padding: 8px;">' . __('Note', 'customer-debt-manager') . '</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            
            foreach ($payments as $payment) {
                echo '<tr>';
                echo '<td style="padding: 8px;">' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($payment->payment_date)) . '</td>';
                echo '<td style="padding: 8px;"><strong style="color: #00a32a;">' . wc_price($payment->payment_amount) . '</strong></td>';
                echo '<td style="padding: 8px;">' . ucfirst(str_replace('_', ' ', $payment->payment_type)) . '</td>';
                echo '<td style="padding: 8px;">' . esc_html($payment->payment_note ?: '—') . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
            
            echo '<div style="margin-top: 15px; padding: 10px; background: #e7f3ff; border-left: 4px solid #0073aa;">';
            echo '<p style="margin: 0;"><strong>' . __('Total Payments:', 'customer-debt-manager') . '</strong> ' . wc_price(array_sum(array_column($payments, 'payment_amount'))) . '</p>';
            echo '</div>';
        }
        
        wp_die();
    }
    
    /**
     * AJAX handler for adding payments
     */
    public function ajax_add_payment() {
        if (!check_ajax_referer('cdm_add_payment', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'customer-debt-manager')));
            return;
        }
        
        $debt_id = isset($_POST['debt_id']) ? absint(wp_unslash($_POST['debt_id'])) : 0;
        $payment_raw = isset($_POST['payment_amount']) ? wp_unslash($_POST['payment_amount']) : '';
        if ($payment_raw !== '' && function_exists('wc_format_decimal')) {
            $payment_amount = floatval(wc_format_decimal($payment_raw));
        } else {
            $payment_amount = $payment_raw !== '' ? floatval($payment_raw) : 0;
        }
        $payment_type = isset($_POST['payment_type']) ? sanitize_text_field(wp_unslash($_POST['payment_type'])) : 'cash';
        $payment_note = isset($_POST['payment_note']) ? sanitize_textarea_field(wp_unslash($_POST['payment_note'])) : '';
        
        if ($debt_id && $payment_amount > 0) {
            $debt = $this->db->get_debt($debt_id);
            if ($debt && $payment_amount <= $debt->remaining_amount) {
                $result = $this->db->add_payment_simple($debt_id, $payment_amount, $payment_type, $payment_note);
                
                if ($result) {
                    wp_send_json_success(array(
                        'message' => sprintf(__('Payment of %s added successfully!', 'customer-debt-manager'), wc_price($payment_amount))
                    ));
                } else {
                    wp_send_json_error(array(
                        'message' => __('Failed to add payment. Please try again.', 'customer-debt-manager')
                    ));
                }
            } else {
                wp_send_json_error(array(
                    'message' => __('Invalid payment amount or debt not found.', 'customer-debt-manager')
                ));
            }
        } else {
            wp_send_json_error(array(
                'message' => __('Please enter a valid payment amount.', 'customer-debt-manager')
            ));
        }
    }
    
    /**
     * AJAX handler for getting debt details
     */
    public function ajax_get_debt_details() {
        if (!check_ajax_referer('cdm_get_debt_details', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'customer-debt-manager')));
            return;
        }
        
        $debt_id = isset($_POST['debt_id']) ? absint(wp_unslash($_POST['debt_id'])) : 0;
        $debt = $this->db->get_debt($debt_id);
        
        if ($debt) {
            $customer = get_userdata($debt->customer_id);
            $order = wc_get_order($debt->order_id);
            
            wp_send_json_success(array(
                'debt' => $debt,
                'customer' => $customer ? $customer->display_name : 'Unknown',
                'order' => $order ? $order->get_order_number() : $debt->order_id
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Debt not found.', 'customer-debt-manager')
            ));
        }
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our debt management pages
        if (strpos($hook, 'customer-debts') !== false) {
            // Enqueue WordPress admin styles if needed
            wp_enqueue_style('wp-admin');
            
            // Enqueue our admin CSS
            wp_enqueue_style(
                'cdm-admin-css',
                CDM_PLUGIN_URL . 'assets/admin.css',
                array(),
                CDM_VERSION
            );

            // Enqueue our admin JS
            wp_enqueue_script(
                'cdm-admin-js',
                CDM_PLUGIN_URL . 'assets/admin.js',
                array('jquery'),
                CDM_VERSION,
                true
            );
            
            // Localize script for AJAX
            wp_localize_script('cdm-admin-js', 'cdm_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'currency_symbol' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '',
                'nonce' => wp_create_nonce('cdm_add_payment'), // Legacy single nonce
                'nonces' => array(
                    'add_payment' => wp_create_nonce('cdm_add_payment'),
                    'get_payments' => wp_create_nonce('cdm_get_payments'),
                    'get_debt_details' => wp_create_nonce('cdm_get_debt_details'),
                    'manual_adjustment' => wp_create_nonce('cdm_manual_adjustment'),
                    'get_customer_details' => wp_create_nonce('cdm_get_customer_details'),
                    'cleanup_data' => wp_create_nonce('cdm_cleanup_data'),
                    'get_customer_summary' => wp_create_nonce('cdm_get_customer_summary')
                )
            ));
        }
    }
    /**
     * Display debt info in order details
     */
    public function display_debt_info_in_order($order) {
        // Simple implementation for now
    }
    
    /**
     * Add debt meta box to order edit page
     */
    public function add_debt_meta_box() {
        // Simple implementation for now
    }
    
    /**
     * Debt meta box content
     */
    public function debt_meta_box_content($post_or_order) {
        echo '<p>Debt information will be displayed here.</p>';
    }
    
    /**
     * AJAX handler for manual debt adjustment
     */
    public function ajax_manual_debt_adjustment() {
        // Verify nonce
        if (!check_ajax_referer('cdm_manual_adjustment', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'customer-debt-manager')));
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'customer-debt-manager')));
            return;
        }
        
        $customer_id = isset($_POST['customer_id']) ? absint(wp_unslash($_POST['customer_id'])) : 0;
        $adjustment_raw = isset($_POST['adjustment_amount']) ? wp_unslash($_POST['adjustment_amount']) : '';
        if ($adjustment_raw !== '' && function_exists('wc_format_decimal')) {
            $adjustment_amount = floatval(wc_format_decimal($adjustment_raw));
        } else {
            $adjustment_amount = $adjustment_raw !== '' ? floatval($adjustment_raw) : 0;
        }
        $direction = isset($_POST['adjustment_direction']) ? sanitize_key(wp_unslash($_POST['adjustment_direction'])) : 'increase';
        $reason = isset($_POST['adjustment_reason']) ? sanitize_textarea_field(wp_unslash($_POST['adjustment_reason'])) : '';
        
        // Enhanced validation
        if (!$customer_id) {
            wp_send_json_error(array('message' => __('Invalid customer ID', 'customer-debt-manager')));
            return;
        }
        
        if (!in_array($direction, array('increase', 'decrease'), true)) {
            wp_send_json_error(array('message' => __('Invalid adjustment action.', 'customer-debt-manager')));
            return;
        }
        
        if ($adjustment_amount < 0) {
            $adjustment_amount = abs($adjustment_amount);
            if ($direction === 'increase') {
                $direction = 'decrease';
            }
        }

        if ($adjustment_amount <= 0) {
            wp_send_json_error(array('message' => __('Adjustment amount must be greater than zero.', 'customer-debt-manager')));
            return;
        }

        if (empty($reason)) {
            wp_send_json_error(array('message' => __('Please provide a reason for the adjustment', 'customer-debt-manager')));
            return;
        }
        
        // Verify customer exists
        $customer = get_userdata($customer_id);
        if (!$customer) {
            wp_send_json_error(array('message' => __('Customer not found', 'customer-debt-manager')));
            return;
        }
        
        // Create manual debt adjustment
        $result = $this->db->create_manual_debt_adjustment($customer_id, $adjustment_amount, $reason, $direction);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }

        if (!$result) {
            wp_send_json_error(array('message' => __('Failed to apply debt adjustment. Please check the error log for details.', 'customer-debt-manager')));
            return;
        }

        $direction_label = $direction === 'decrease' ? __('debt reduction', 'customer-debt-manager') : __('debt increase', 'customer-debt-manager');

        wp_send_json_success(array(
            'message' => sprintf(
                __('Successfully recorded a %1$s of %2$s for %3$s', 'customer-debt-manager'),
                $direction_label,
                wc_price($adjustment_amount),
                $customer->display_name
            ),
            'adjustment_id' => $result,
            'customer_name' => $customer->display_name,
            'adjustment_amount' => $adjustment_amount,
            'adjustment_direction' => $direction
        ));
    }
    
    /**
     * AJAX handler for getting customer debt details
     */
    public function ajax_get_customer_debt_details() {
        // Verify nonce
        if (!check_ajax_referer('cdm_get_customer_details', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'customer-debt-manager')));
            return;
        }
        
        $customer_id = isset($_POST['customer_id']) ? absint(wp_unslash($_POST['customer_id'])) : 0;
        
        if (!$customer_id) {
            wp_send_json_error(array('message' => __('Invalid customer ID', 'customer-debt-manager')));
            return;
        }
        
        // Get customer debt details
        $customer_debts = $this->db->get_customer_debts($customer_id);
        $customer_summary = $this->db->get_customer_debt_summary($customer_id);
        $customer_payments = $this->db->get_customer_payments($customer_id, 20);
        $customer = get_userdata($customer_id);
        
        if (!$customer) {
            wp_send_json_error(array('message' => __('Customer not found', 'customer-debt-manager')));
            return;
        }
        
        ob_start();
        ?>
        <div class="cdm-customer-details">
            <h4><?php _e('Customer Information', 'customer-debt-manager'); ?></h4>
            <table class="form-table">
                <tr>
                    <th><?php _e('Name', 'customer-debt-manager'); ?></th>
                    <td><?php echo esc_html($customer->display_name); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Email', 'customer-debt-manager'); ?></th>
                    <td><?php echo esc_html($customer->user_email); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Current Debt', 'customer-debt-manager'); ?></th>
                    <td><?php echo wc_price($customer_summary->total_remaining_amount); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Total Paid', 'customer-debt-manager'); ?></th>
                    <td><?php echo wc_price($customer_summary->total_paid_amount); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Remaining Balance', 'customer-debt-manager'); ?></th>
                    <td><strong class="<?php echo $customer_summary->total_remaining_amount > 0 ? 'cdm-amount-outstanding' : 'cdm-amount-paid'; ?>">
                        <?php echo wc_price($customer_summary->total_remaining_amount); ?></strong></td>
                </tr>
            </table>
            
            <h4><?php _e('Individual Debts', 'customer-debt-manager'); ?></h4>
            <?php if (empty($customer_debts)): ?>
                <p><?php _e('No debts found for this customer.', 'customer-debt-manager'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Order', 'customer-debt-manager'); ?></th>
                            <th><?php _e('Amount', 'customer-debt-manager'); ?></th>
                            <th><?php _e('Paid', 'customer-debt-manager'); ?></th>
                            <th><?php _e('Remaining', 'customer-debt-manager'); ?></th>
                            <th><?php _e('Status', 'customer-debt-manager'); ?></th>
                            <th><?php _e('Date', 'customer-debt-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customer_debts as $debt): ?>
                            <tr>
                                <td>
                                    <?php if ($debt->order_id == 0): ?>
                                        <em><?php _e('Manual Adjustment', 'customer-debt-manager'); ?></em>
                                    <?php else: ?>
                                        <a href="<?php echo $this->get_order_edit_url($debt->order_id); ?>" target="_blank">
                                            #<?php echo $debt->order_id; ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo wc_price($debt->debt_amount); ?></td>
                                <td><?php echo wc_price($debt->paid_amount); ?></td>
                                <td><?php echo wc_price($debt->remaining_amount); ?></td>
                                <td><span class="cdm-debt-status cdm-debt-status-<?php echo $debt->status; ?>"><?php echo ucfirst($debt->status); ?></span></td>
                                <td><?php echo date_i18n(get_option('date_format'), strtotime($debt->created_date)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            
            <?php if (!empty($customer_payments)): ?>
                <h4><?php _e('Recent Payments', 'customer-debt-manager'); ?></h4>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Amount', 'customer-debt-manager'); ?></th>
                            <th><?php _e('Type', 'customer-debt-manager'); ?></th>
                            <th><?php _e('Note', 'customer-debt-manager'); ?></th>
                            <th><?php _e('Date', 'customer-debt-manager'); ?></th>
                            <th><?php _e('Added By', 'customer-debt-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customer_payments as $payment): ?>
                            <tr>
                                <td><?php echo wc_price($payment->payment_amount); ?></td>
                                <td><?php echo ucfirst($payment->payment_type); ?></td>
                                <td><?php echo $payment->payment_note ? esc_html($payment->payment_note) : '-'; ?></td>
                                <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($payment->payment_date)); ?></td>
                                <td><?php echo $payment->added_by_name ? esc_html($payment->added_by_name) : __('Unknown', 'customer-debt-manager'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        $content = ob_get_clean();
        
        wp_send_json_success(array('content' => $content));
    }

    /**
     * AJAX handler for retrieving a customer's latest debt summary
     */
    public function ajax_get_customer_debt_summary() {
        if (!check_ajax_referer('cdm_get_customer_summary', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'customer-debt-manager')));
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'customer-debt-manager')));
            return;
        }

        $customer_id = isset($_POST['customer_id']) ? absint(wp_unslash($_POST['customer_id'])) : 0;

        if (!$customer_id) {
            wp_send_json_error(array('message' => __('Invalid customer ID', 'customer-debt-manager')));
            return;
        }

        $summary = $this->db->get_customer_total_debt($customer_id);
        $debts = $this->db->get_customer_debts($customer_id);

        $total_debt_amount = $summary && isset($summary->total_debt) ? (float) $summary->total_debt : 0;
        $total_paid_amount = $summary && isset($summary->total_paid) ? (float) $summary->total_paid : 0;
        $total_remaining_amount = $summary && isset($summary->total_remaining) ? (float) $summary->total_remaining : 0;

        $active_debt_ids = array();
        $active_debt_count = 0;

        if (!empty($debts)) {
            foreach ($debts as $debt) {
                if (isset($debt->remaining_amount) && floatval($debt->remaining_amount) > 0) {
                    $active_debt_ids[] = (int) $debt->id;
                }
            }
            $active_debt_count = count($active_debt_ids);
        }

        // Format balance with currency symbol (decode HTML entities)
        if (function_exists('get_woocommerce_currency_symbol') && function_exists('wc_get_price_decimal_separator') && function_exists('wc_get_price_thousand_separator')) {
            $currency_symbol = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8');
            $decimal_separator = wc_get_price_decimal_separator();
            $thousand_separator = wc_get_price_thousand_separator();
            $decimals = wc_get_price_decimals();
            $formatted_balance = $currency_symbol . number_format($total_remaining_amount, $decimals, $decimal_separator, $thousand_separator);
        } else {
            $formatted_balance = number_format($total_remaining_amount, 2, '.', '');
        }

        wp_send_json_success(array(
            'customer_id' => $customer_id,
            'total_debt_amount' => $total_debt_amount,
            'total_paid_amount' => $total_paid_amount,
            'total_remaining_amount' => $total_remaining_amount,
            'has_active_debt' => $active_debt_count > 0,
            'active_debt_count' => $active_debt_count,
            'active_debt_ids' => $active_debt_ids,
            'formatted_balance' => $formatted_balance,
            'generated_at' => current_time('mysql', true)
        ));
    }

    /**
     * AJAX handler for cleanup debt data
     */
    public function ajax_cleanup_debt_data() {
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'customer-debt-manager')));
            return;
        }

        // Verify nonce
        if (!check_ajax_referer('cdm_cleanup_data', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'customer-debt-manager')));
            return;
        }

        $cleanup_results = $this->db->cleanup_debt_data();

        if (!empty($cleanup_results['success'])) {
            $manual_adjustments = isset($cleanup_results['manual_adjustments_deleted']) ? intval($cleanup_results['manual_adjustments_deleted']) : 0;
            $adjustment_payments = isset($cleanup_results['adjustment_payments_deleted']) ? intval($cleanup_results['adjustment_payments_deleted']) : 0;
            $order_payments = isset($cleanup_results['order_payments_deleted']) ? intval($cleanup_results['order_payments_deleted']) : 0;
            $orders_reset = isset($cleanup_results['orders_reset']) ? intval($cleanup_results['orders_reset']) : 0;

            $message = isset($cleanup_results['message']) && $cleanup_results['message']
                ? $cleanup_results['message']
                : sprintf(
                    __('Cleanup completed! Manual adjustments deleted: %1$d, adjustment payments removed: %2$d, order payments removed: %3$d, orders reset: %4$d', 'customer-debt-manager'),
                    $manual_adjustments,
                    $adjustment_payments,
                    $order_payments,
                    $orders_reset
                );

            wp_send_json_success(array(
                'message' => $message,
                'cleanup_results' => $cleanup_results
            ));
        } else {
            $error_message = isset($cleanup_results['message']) ? $cleanup_results['message'] : __('Cleanup failed.', 'customer-debt-manager');

            wp_send_json_error(array(
                'message' => $error_message
            ));
        }
    }
}
