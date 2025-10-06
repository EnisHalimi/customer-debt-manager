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
        add_menu_page(
            __('Customer Debts', 'customer-debt-manager'),
            __('Customer Debts', 'customer-debt-manager'),
            'manage_woocommerce',
            'customer-debts',
            array($this, 'admin_page'),
            'dashicons-money-alt',
            56
        );
    }
    
    /**
     * Admin page content
     */
    public function admin_page() {
        // Handle search and sorting parameters
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'created_date';
        $order = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'asc' : 'desc';
        $status_filter = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : 'all';
        $type_filter = isset($_GET['type_filter']) ? sanitize_text_field($_GET['type_filter']) : 'all';
        
        // Get all customer debts with filtering
        $debts = $this->get_filtered_debts($search, $orderby, $order, $status_filter, $type_filter);
        
        ?>
        <div class="wrap">
            <h1><?php _e('Customer Debt Management', 'customer-debt-manager'); ?></h1>
            
            <form method="get" action="">
                <input type="hidden" name="page" value="customer-debts">
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
                
                foreach ($debts as $debt) {
                    $total_outstanding += $debt->remaining_amount;
                    $total_paid += $debt->paid_amount;
                    if ($debt->status === 'active') $active_debts++;
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
                    <p style="margin: 0; color: #646970;"><?php _e('Active Debts', 'customer-debt-manager'); ?></p>
                </div>
                
                <div class="cdm-summary-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                    <h3 style="margin: 0 0 10px 0; color: #646970;"><?php echo count($debts); ?></h3>
                    <p style="margin: 0; color: #646970;"><?php _e('Total Debts', 'customer-debt-manager'); ?></p>
                </div>
            </div>
            
            <!-- Search and Filters -->
            <div class="tablenav top">
                <div class="alignleft actions">
                    <!-- Status Filter -->
                    <select name="status_filter" id="status_filter">
                        <option value="all" <?php selected($status_filter, 'all'); ?>><?php _e('All Statuses', 'customer-debt-manager'); ?></option>
                        <option value="active" <?php selected($status_filter, 'active'); ?>><?php _e('Active', 'customer-debt-manager'); ?></option>
                        <option value="paid" <?php selected($status_filter, 'paid'); ?>><?php _e('Paid', 'customer-debt-manager'); ?></option>
                    </select>
                    
                    <!-- Type Filter -->
                    <select name="type_filter" id="type_filter">
                        <option value="all" <?php selected($type_filter, 'all'); ?>><?php _e('All Types', 'customer-debt-manager'); ?></option>
                        <option value="cod" <?php selected($type_filter, 'cod'); ?>><?php _e('COD Only', 'customer-debt-manager'); ?></option>
                        <option value="credit" <?php selected($type_filter, 'credit'); ?>><?php _e('Credit Only', 'customer-debt-manager'); ?></option>
                    </select>
                    
                    <input type="submit" class="button" value="<?php _e('Filter', 'customer-debt-manager'); ?>" id="filter-submit">
                </div>
                
                <div class="alignright">
                    <div class="search-box">
                        <input type="search" name="s" id="debt-search-input" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Search debts...', 'customer-debt-manager'); ?>">
                        <input type="submit" class="button" value="<?php _e('Search', 'customer-debt-manager'); ?>" id="search-submit">
                    </div>
                </div>
                
                <br class="clear">
            </div>
            
            <!-- Debts Table -->
            <div class="cdm-debts-table">
                <h2><?php _e('All Customer Debts', 'customer-debt-manager'); ?></h2>
                
                <?php if (empty($debts)): ?>
                    <div class="notice notice-info">
                        <p><?php _e('No customer debts found.', 'customer-debt-manager'); ?></p>
                    </div>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col" class="manage-column column-debt-id sortable <?php echo $orderby === 'id' ? 'sorted' : ''; ?> <?php echo $orderby === 'id' ? $order : ''; ?>">
                                    <a href="<?php echo $this->get_sort_url('id', $orderby, $order); ?>">
                                        <span><?php _e('Debt ID', 'customer-debt-manager'); ?></span>
                                        <span class="sorting-indicator"></span>
                                    </a>
                                </th>
                                <th scope="col" class="manage-column column-customer sortable <?php echo $orderby === 'customer_name' ? 'sorted' : ''; ?> <?php echo $orderby === 'customer_name' ? $order : ''; ?>">
                                    <a href="<?php echo $this->get_sort_url('customer_name', $orderby, $order); ?>">
                                        <span><?php _e('Customer', 'customer-debt-manager'); ?></span>
                                        <span class="sorting-indicator"></span>
                                    </a>
                                </th>
                                <th scope="col" class="manage-column column-order sortable <?php echo $orderby === 'order_id' ? 'sorted' : ''; ?> <?php echo $orderby === 'order_id' ? $order : ''; ?>">
                                    <a href="<?php echo $this->get_sort_url('order_id', $orderby, $order); ?>">
                                        <span><?php _e('Order', 'customer-debt-manager'); ?></span>
                                        <span class="sorting-indicator"></span>
                                    </a>
                                </th>
                                <th scope="col" class="manage-column column-type"><?php _e('Type', 'customer-debt-manager'); ?></th>
                                <th scope="col" class="manage-column column-total-debt sortable <?php echo $orderby === 'debt_amount' ? 'sorted' : ''; ?> <?php echo $orderby === 'debt_amount' ? $order : ''; ?>">
                                    <a href="<?php echo $this->get_sort_url('debt_amount', $orderby, $order); ?>">
                                        <span><?php _e('Total Debt', 'customer-debt-manager'); ?></span>
                                        <span class="sorting-indicator"></span>
                                    </a>
                                </th>
                                <th scope="col" class="manage-column column-paid sortable <?php echo $orderby === 'paid_amount' ? 'sorted' : ''; ?> <?php echo $orderby === 'paid_amount' ? $order : ''; ?>">
                                    <a href="<?php echo $this->get_sort_url('paid_amount', $orderby, $order); ?>">
                                        <span><?php _e('Paid', 'customer-debt-manager'); ?></span>
                                        <span class="sorting-indicator"></span>
                                    </a>
                                </th>
                                <th scope="col" class="manage-column column-remaining sortable <?php echo $orderby === 'remaining_amount' ? 'sorted' : ''; ?> <?php echo $orderby === 'remaining_amount' ? $order : ''; ?>">
                                    <a href="<?php echo $this->get_sort_url('remaining_amount', $orderby, $order); ?>">
                                        <span><?php _e('Remaining', 'customer-debt-manager'); ?></span>
                                        <span class="sorting-indicator"></span>
                                    </a>
                                </th>
                                <th scope="col" class="manage-column column-status sortable <?php echo $orderby === 'status' ? 'sorted' : ''; ?> <?php echo $orderby === 'status' ? $order : ''; ?>">
                                    <a href="<?php echo $this->get_sort_url('status', $orderby, $order); ?>">
                                        <span><?php _e('Status', 'customer-debt-manager'); ?></span>
                                        <span class="sorting-indicator"></span>
                                    </a>
                                </th>
                                <th scope="col" class="manage-column column-created sortable <?php echo $orderby === 'created_date' ? 'sorted' : ''; ?> <?php echo $orderby === 'created_date' ? $order : ''; ?>">
                                    <a href="<?php echo $this->get_sort_url('created_date', $orderby, $order); ?>">
                                        <span><?php _e('Created', 'customer-debt-manager'); ?></span>
                                        <span class="sorting-indicator"></span>
                                    </a>
                                </th>
                                <th scope="col" class="manage-column column-actions"><?php _e('Actions', 'customer-debt-manager'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="the-list">
                            <?php foreach ($debts as $debt): ?>
                                <?php
                                $customer = get_userdata($debt->customer_id);
                                $order = wc_get_order($debt->order_id);
                                
                                // Determine debt type
                                $is_cod_debt = $order ? ($order->get_meta('_is_cod_debt') === 'yes') : false;
                                $is_debt_payment = $order ? ($order->get_meta('_is_debt_payment') === 'yes') : false;
                                
                                if ($is_cod_debt) {
                                    $debt_type = 'COD';
                                    $debt_type_color = '#ff6b35';
                                } elseif ($is_debt_payment) {
                                    $debt_type = 'Credit';
                                    $debt_type_color = '#0073aa';
                                } else {
                                    $debt_type = 'Unknown';
                                    $debt_type_color = '#6c757d';
                                }
                                ?>
                                <tr>
                                    <td class="debt-id column-debt-id"><strong>#<?php echo $debt->id; ?></strong></td>
                                    <td class="customer column-customer">
                                        <?php if ($customer): ?>
                                            <strong><?php echo esc_html($customer->display_name); ?></strong><br>
                                            <span class="description"><?php echo esc_html($customer->user_email); ?></span>
                                        <?php else: ?>
                                            <em class="description"><?php _e('Customer not found', 'customer-debt-manager'); ?></em>
                                        <?php endif; ?>
                                    </td>
                                    <td class="order column-order">
                                        <?php if ($order): ?>
                                            <a href="<?php echo $this->get_order_edit_url($debt->order_id); ?>" target="_blank">
                                                <strong>#<?php echo $debt->order_id; ?></strong>
                                            </a><br>
                                            <span class="description"><?php echo $order->get_status(); ?></span>
                                        <?php else: ?>
                                            <em class="description">#<?php echo $debt->order_id; ?> <?php _e('(Order not found)', 'customer-debt-manager'); ?></em>
                                        <?php endif; ?>
                                    </td>
                                    <td class="type column-type">
                                        <span class="cdm-debt-type cdm-debt-type-<?php echo strtolower($debt_type); ?>">
                                            <?php echo $debt_type; ?>
                                        </span>
                                        <?php if ($is_cod_debt): ?>
                                            <br><span class="description"><?php _e('Cash on Delivery', 'customer-debt-manager'); ?></span>
                                        <?php elseif ($is_debt_payment): ?>
                                            <br><span class="description"><?php _e('Credit Order', 'customer-debt-manager'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="total-debt column-total-debt">
                                        <strong><?php echo wc_price($debt->debt_amount); ?></strong>
                                    </td>
                                    <td class="paid column-paid">
                                        <strong class="cdm-paid-amount"><?php echo wc_price($debt->paid_amount); ?></strong>
                                    </td>
                                    <td class="remaining column-remaining">
                                        <strong class="<?php echo $debt->remaining_amount > 0 ? 'cdm-amount-outstanding' : 'cdm-amount-paid'; ?>">
                                            <?php echo wc_price($debt->remaining_amount); ?>
                                        </strong>
                                    </td>
                                    <td class="status column-status">
                                        <span class="cdm-debt-status cdm-debt-status-<?php echo $debt->status; ?>">
                                            <?php echo ucfirst($debt->status); ?>
                                        </span>
                                    </td>
                                    <td class="created column-created">
                                        <abbr title="<?php echo esc_attr($debt->created_date); ?>">
                                            <?php echo date_i18n(get_option('date_format'), strtotime($debt->created_date)); ?>
                                        </abbr>
                                    </td>
                                    <td class="actions column-actions">
                                        <div class="row-actions">
                                            <?php if ($debt->remaining_amount > 0): ?>
                                                <span class="add-payment">
                                                    <button type="button" class="button button-primary cdm-add-payment-btn" 
                                                            data-debt-id="<?php echo $debt->id; ?>" 
                                                            data-customer-name="<?php echo $customer ? esc_attr($customer->display_name) : 'Unknown'; ?>"
                                                            data-remaining="<?php echo $debt->remaining_amount; ?>">
                                                        <?php _e('Add Payment', 'customer-debt-manager'); ?>
                                                    </button>
                                                </span>
                                                <span class="sep"> | </span>
                                            <?php endif; ?>
                                            <span class="view-payments">
                                                <button type="button" class="button cdm-view-payments-btn" 
                                                        data-debt-id="<?php echo $debt->id; ?>">
                                                    <?php _e('View Payments', 'customer-debt-manager'); ?>
                                                </button>
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
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
        function openCdmPaymentModal(debtId, customerName, remainingAmount) {
            document.getElementById('modal-debt-id').value = debtId;
            document.getElementById('modal-customer-name').textContent = customerName;
            document.getElementById('modal-remaining-amount').textContent = '<?php echo get_woocommerce_currency_symbol(); ?>' + remainingAmount;
            document.getElementById('payment_amount').value = '';
            document.getElementById('payment_amount').max = remainingAmount;
            document.getElementById('payment_type').value = 'cash';
            document.getElementById('payment_note').value = '';
            document.getElementById('cdm-payment-modal').style.display = 'block';
        }
        
        function closeCdmPaymentModal() {
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
                            alert(response.data.message);
                            closeCdmPaymentModal();
                            // Reload the page to show updated data
                            location.reload();
                        } else {
                            alert(response.data.message || 'An error occurred while processing the payment.');
                        }
                    } catch (e) {
                        alert('An error occurred while processing the payment.');
                        console.error('AJAX Error:', xhr.responseText);
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
                        alert('Please enter a valid payment amount.');
                        return false;
                    }
                    submitPaymentForm(this);
                });
            }
            
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
        });
        </script>
        
        <style>
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
        </style>
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
    private function get_sort_url($column, $current_orderby, $current_order) {
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
        if (!wp_verify_nonce($_POST['nonce'], 'cdm_get_payments')) {
            wp_die('Security check failed');
        }
        
        $debt_id = intval($_POST['debt_id']);
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
        if (!wp_verify_nonce($_POST['nonce'], 'cdm_add_payment')) {
            wp_die('Security check failed');
        }
        
        $debt_id = intval($_POST['debt_id']);
        $payment_amount = floatval($_POST['payment_amount']);
        $payment_type = sanitize_text_field($_POST['payment_type']);
        $payment_note = sanitize_textarea_field($_POST['payment_note']);
        
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
        if (!wp_verify_nonce($_POST['nonce'], 'cdm_get_debt_details')) {
            wp_die('Security check failed');
        }
        
        $debt_id = intval($_POST['debt_id']);
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
            
            // Localize script for AJAX
            wp_localize_script('jquery', 'cdm_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonces' => array(
                    'add_payment' => wp_create_nonce('cdm_add_payment'),
                    'get_payments' => wp_create_nonce('cdm_get_payments'),
                    'get_debt_details' => wp_create_nonce('cdm_get_debt_details')
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
}
