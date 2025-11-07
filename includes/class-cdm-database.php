<?php
/**
 * Database handler for Customer Debt Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class CDM_Database {
    
    private $table_name;
    private $payments_table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'customer_debts';
        $this->payments_table_name = $wpdb->prefix . 'customer_debt_payments';
    }
    
    /**
     * Create database tables
     */
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Customer debts table
        $sql_debts = "CREATE TABLE {$this->table_name} (
            id int(11) NOT NULL AUTO_INCREMENT,
            customer_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            debt_amount decimal(10,2) NOT NULL DEFAULT '0.00',
            paid_amount decimal(10,2) NOT NULL DEFAULT '0.00',
            remaining_amount decimal(10,2) NOT NULL DEFAULT '0.00',
            status varchar(20) NOT NULL DEFAULT 'active',
            created_date datetime DEFAULT CURRENT_TIMESTAMP,
            updated_date datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY customer_id (customer_id),
            KEY order_id (order_id),
            KEY status (status)
        ) $charset_collate;";
        
        // Debt payments table (for tracking individual payments)
        $sql_payments = "CREATE TABLE {$this->payments_table_name} (
            id int(11) NOT NULL AUTO_INCREMENT,
            debt_id int(11) NOT NULL,
            customer_id bigint(20) unsigned NOT NULL,
            payment_amount decimal(10,2) NOT NULL,
            payment_type varchar(20) NOT NULL DEFAULT 'cash',
            payment_note text,
            payment_date datetime DEFAULT CURRENT_TIMESTAMP,
            added_by bigint(20) unsigned NOT NULL,
            PRIMARY KEY (id),
            KEY debt_id (debt_id),
            KEY customer_id (customer_id),
            KEY payment_date (payment_date)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_debts);
        dbDelta($sql_payments);
    }
    
    /**
     * Test database connectivity and table existence
     */
    public function test_database() {
        global $wpdb;
        
        // Check if tables exist
        $debts_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
        $payments_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->payments_table_name}'") === $this->payments_table_name;
        
        $test_results = array(
            'debts_table_exists' => $debts_table_exists,
            'payments_table_exists' => $payments_table_exists,
            'debts_table_name' => $this->table_name,
            'payments_table_name' => $this->payments_table_name,
            'db_error' => $wpdb->last_error
        );
        
        return $test_results;
    }
    
    /**
     * Create a new debt record
     */
    public function create_debt($customer_id, $order_id, $debt_amount) {
        global $wpdb;
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'customer_id' => $customer_id,
                'order_id' => $order_id,
                'debt_amount' => $debt_amount,
                'remaining_amount' => $debt_amount,
                'status' => 'active'
            ),
            array('%d', '%d', '%f', '%f', '%s')
        );
        
        if ($result === false) {
            return false;
        }
        
        $insert_id = $wpdb->insert_id;
        
        return $insert_id;
    }
    
    /**
     * Add a payment to a debt
     */
    public function add_payment($debt_id, $customer_id, $payment_amount, $payment_type = 'cash', $payment_note = '', $added_by = 0) {
        global $wpdb;
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Get current debt
            $debt = $this->get_debt_by_id($debt_id);
            if (!$debt) {
                throw new Exception('Debt not found');
            }
            
            // Check if payment amount is valid
            if ($payment_amount <= 0 || $payment_amount > $debt->remaining_amount) {
                throw new Exception('Invalid payment amount');
            }
            
            // Insert payment record
            $payment_result = $wpdb->insert(
                $this->payments_table_name,
                array(
                    'debt_id' => $debt_id,
                    'customer_id' => $customer_id,
                    'payment_amount' => $payment_amount,
                    'payment_type' => $payment_type,
                    'payment_note' => $payment_note,
                    'added_by' => $added_by ? $added_by : get_current_user_id()
                ),
                array('%d', '%d', '%f', '%s', '%s', '%d')
            );
            
            if (!$payment_result) {
                throw new Exception('Failed to insert payment: ' . $wpdb->last_error);
            }

            $payment_id = intval($wpdb->insert_id);
            
            // Update debt record
            $new_paid_amount = $debt->paid_amount + $payment_amount;
            $new_remaining_amount = $debt->debt_amount - $new_paid_amount;
            $new_status = $new_remaining_amount <= 0 ? 'paid' : 'active';
            
            $debt_update = $wpdb->update(
                $this->table_name,
                array(
                    'paid_amount' => $new_paid_amount,
                    'remaining_amount' => $new_remaining_amount,
                    'status' => $new_status
                ),
                array('id' => $debt_id),
                array('%f', '%f', '%s'),
                array('%d')
            );
            
            if ($debt_update === false) {
                throw new Exception('Failed to update debt: ' . $wpdb->last_error);
            }
            
            $wpdb->query('COMMIT');

            /**
             * Fires after a debt payment has been successfully recorded.
             *
             * @param int    $payment_id    Payment record ID.
             * @param int    $debt_id       Debt record ID.
             * @param float  $payment_amount Amount paid.
             * @param string $payment_type   Payment type.
             * @param int    $customer_id    Customer ID.
             */
            do_action('cdm_payment_received', $payment_id, $debt_id, $payment_amount, $payment_type, $customer_id);

            return $payment_id;
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }
    
    /**
     * Get debt by ID
     */
    public function get_debt_by_id($debt_id) {
        global $wpdb;
        
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $debt_id)
        );
    }
    
    /**
     * Get debts by customer ID
     */
    public function get_customer_debts($customer_id, $status = 'all') {
        global $wpdb;
        
        $sql = "SELECT * FROM {$this->table_name} WHERE customer_id = %d";
        $params = array($customer_id);
        
        if ($status !== 'all') {
            $sql .= " AND status = %s";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY created_date DESC";
        
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }
    
    /**
     * Get customer total debt
     */
    public function get_customer_total_debt($customer_id) {
        global $wpdb;
        
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT 
                    SUM(debt_amount) as total_debt,
                    SUM(paid_amount) as total_paid,
                    SUM(remaining_amount) as total_remaining
                FROM {$this->table_name} 
                WHERE customer_id = %d AND status = 'active'",
                $customer_id
            )
        );
        
        return $result;
    }
    
    /**
     * Get all debts (for admin)
     */
    public function get_all_debts($limit = -1, $offset = 0, $status = 'all') {
        global $wpdb;
        
        $sql = "SELECT d.*, u.display_name as customer_name, u.user_email as customer_email 
                FROM {$this->table_name} d
                LEFT JOIN {$wpdb->users} u ON d.customer_id = u.ID";
        
        $params = array();
        
        if ($status !== 'all') {
            $sql .= " WHERE d.status = %s";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY d.created_date DESC";
        
        if ($limit > 0) {
            $sql .= " LIMIT %d OFFSET %d";
            $params[] = $limit;
            $params[] = $offset;
        }
        
        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($sql, $params));
        } else {
            return $wpdb->get_results($sql);
        }
    }
    
    /**
     * Get debt payments
     */
    public function get_debt_payments($debt_id) {
        global $wpdb;
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.*, u.display_name as added_by_name 
                FROM {$this->payments_table_name} p
                LEFT JOIN {$wpdb->users} u ON p.added_by = u.ID
                WHERE p.debt_id = %d 
                ORDER BY p.payment_date DESC",
                $debt_id
            )
        );
    }
    
    /**
     * Get customer payment history
     */
    public function get_customer_payments($customer_id, $limit = 50) {
        global $wpdb;
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.*, d.order_id, u.display_name as added_by_name 
                FROM {$this->payments_table_name} p
                LEFT JOIN {$this->table_name} d ON p.debt_id = d.id
                LEFT JOIN {$wpdb->users} u ON p.added_by = u.ID
                WHERE p.customer_id = %d 
                ORDER BY p.payment_date DESC
                LIMIT %d",
                $customer_id,
                $limit
            )
        );
    }
    
    /**
     * Get debt by ID (alias for get_debt_by_id)
     */
    public function get_debt($debt_id) {
        return $this->get_debt_by_id($debt_id);
    }
    
    /**
     * Add payment with simplified parameters for admin
     */
    public function add_payment_simple($debt_id, $payment_amount, $payment_type = 'cash', $payment_note = '', $added_by = null) {
        // Get debt info to get customer_id
        $debt = $this->get_debt($debt_id);
        if (!$debt) {
            return false;
        }
        
        if ($added_by === null) {
            $added_by = get_current_user_id();
        }
        
        return $this->add_payment($debt_id, $debt->customer_id, $payment_amount, $payment_type, $payment_note, $added_by);
    }
    
    /**
     * Get debts grouped by customer with aggregated totals
     */
    public function get_debts_by_customer($search = '', $orderby = 'customer_name', $order = 'asc', $status_filter = 'all') {
        global $wpdb;
        
        // Build the query to aggregate debts by customer
        $sql = "SELECT 
                    d.customer_id,
                    u.display_name as customer_name,
                    u.user_email as customer_email,
                    COUNT(d.id) as debt_count,
                    SUM(d.debt_amount) as total_debt_amount,
                    SUM(d.paid_amount) as total_paid_amount,
                    SUM(d.remaining_amount) as total_remaining_amount,
                    MAX(d.created_date) as latest_debt_date,
                    MIN(d.created_date) as first_debt_date,
                    GROUP_CONCAT(DISTINCT d.status) as debt_statuses
                FROM {$this->table_name} d
                LEFT JOIN {$wpdb->users} u ON d.customer_id = u.ID";
        
        $where_conditions = array();
        $params = array();
        
        // Search functionality
        if (!empty($search)) {
            $where_conditions[] = "(u.display_name LIKE %s OR u.user_email LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $search_term;
            $params[] = $search_term;
        }
        
        // Status filter - only show customers with debts of specific status
        if ($status_filter !== 'all') {
            $where_conditions[] = "d.status = %s";
            $params[] = $status_filter;
        }
        
        // Add WHERE clause if we have conditions
        if (!empty($where_conditions)) {
            $sql .= " WHERE " . implode(' AND ', $where_conditions);
        }
        
        // Group by customer
        $sql .= " GROUP BY d.customer_id, u.display_name, u.user_email";
        
        // Add ordering
        $valid_orderby = array('customer_name', 'debt_count', 'total_debt_amount', 'total_paid_amount', 'total_remaining_amount', 'latest_debt_date');
        if (in_array($orderby, $valid_orderby)) {
            $sql .= " ORDER BY {$orderby}";
            if (strtolower($order) === 'desc') {
                $sql .= " DESC";
            } else {
                $sql .= " ASC";
            }
        } else {
            $sql .= " ORDER BY customer_name ASC";
        }
        
        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($sql, $params));
        } else {
            return $wpdb->get_results($sql);
        }
    }
    
    /**
     * Create a manual debt adjustment (increase or decrease an outstanding balance)
     */
    public function create_manual_debt_adjustment($customer_id, $adjustment_amount, $reason = '', $direction = 'increase', $added_by = null) {
        global $wpdb;
        
        // Backward compatibility: if $direction contains the previous $added_by value
        if (!is_string($direction) || !in_array($direction, array('increase', 'decrease'), true)) {
            $added_by = $direction;
            $direction = $adjustment_amount >= 0 ? 'increase' : 'decrease';
        }

        if ($added_by === null) {
            $added_by = get_current_user_id();
        }

        $direction = ($direction === 'decrease') ? 'decrease' : 'increase';
        $adjustment_amount = floatval($adjustment_amount);
        $normalized_amount = abs($adjustment_amount);

        if ($normalized_amount == 0) {
            return new WP_Error('cdm_manual_adjustment_invalid_amount', __('Adjustment amount must be greater than zero.', 'customer-debt-manager'));
        }
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            if ($direction === 'increase') {
                // POSITIVE ADJUSTMENT: Add new debt
                $insert_data = array(
                    'customer_id' => $customer_id,
                    'order_id' => 0, // 0 indicates manual adjustment
                    'debt_amount' => $normalized_amount,
                    'remaining_amount' => $normalized_amount,
                    'paid_amount' => 0,
                    'status' => 'active'
                );
                
                $result = $wpdb->insert(
                    $this->table_name,
                    $insert_data,
                    array('%d', '%d', '%f', '%f', '%f', '%s')
                );
                
                if (!$result) {
                    throw new Exception('Failed to create debt record: ' . $wpdb->last_error);
                }
                
                $debt_id = $wpdb->insert_id;
                
            } else {
                // NEGATIVE ADJUSTMENT: Reduce existing debts by applying payments
                $reduction_amount = $normalized_amount;
                
                // Get customer's active debts (with remaining amount > 0) ordered by oldest first
                $active_debts = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, debt_amount, remaining_amount, paid_amount FROM {$this->table_name} 
                     WHERE customer_id = %d AND remaining_amount > 0 AND status = 'active' 
                     ORDER BY created_date ASC",
                    $customer_id
                ));
                
                if (empty($active_debts)) {
                    throw new Exception(__('This customer has no outstanding debt to reduce. You can only decrease debt for customers who have active debt records.', 'customer-debt-manager'));
                }
                
                // Check if the reduction amount exceeds the total outstanding debt
                $total_outstanding = array_sum(array_column($active_debts, 'remaining_amount'));
                if ($reduction_amount > $total_outstanding) {
                    $currency_symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '';
                    throw new Exception(sprintf(
                        __('Reduction amount (%s%.2f) exceeds the customer\'s total outstanding debt (%s%.2f). Please enter a smaller amount.', 'customer-debt-manager'),
                        $currency_symbol,
                        $reduction_amount,
                        $currency_symbol,
                        $total_outstanding
                    ));
                }
                
                $remaining_reduction = $reduction_amount;
                $debt_id = null;
                
                foreach ($active_debts as $debt) {
                    if ($remaining_reduction <= 0) break;
                    
                    $debt_remaining = floatval($debt->remaining_amount);
                    $apply_to_this_debt = min($remaining_reduction, $debt_remaining);
                    
                    // Create payment record for this reduction
                    $payment_result = $wpdb->insert(
                        $this->payments_table_name,
                        array(
                            'debt_id' => $debt->id,
                            'customer_id' => $customer_id,
                            'payment_amount' => $apply_to_this_debt,
                            'payment_type' => 'adjustment',
                            'payment_note' => $reason ? $reason : __('Manual debt reduction', 'customer-debt-manager'),
                            'added_by' => $added_by
                        ),
                        array('%d', '%d', '%f', '%s', '%s', '%d')
                    );
                    
                    if (!$payment_result) {
                        throw new Exception('Failed to create payment record for debt ID: ' . $debt->id);
                    }
                    
                    // Update debt record with new amounts
                    $new_paid = floatval($debt->paid_amount) + $apply_to_this_debt;
                    $new_remaining = $debt_remaining - $apply_to_this_debt;
                    $new_status = $new_remaining <= 0 ? 'paid' : 'active';
                    
                    $update_result = $wpdb->update(
                        $this->table_name,
                        array(
                            'paid_amount' => $new_paid,
                            'remaining_amount' => $new_remaining,
                            'status' => $new_status
                        ),
                        array('id' => $debt->id),
                        array('%f', '%f', '%s'),
                        array('%d')
                    );
                    
                    if ($update_result === false) {
                        throw new Exception('Failed to update debt record ID: ' . $debt->id);
                    }
                    
                    $remaining_reduction -= $apply_to_this_debt;
                    $debt_id = $debt->id; // Return the last affected debt ID
                }

                if ($remaining_reduction > 0) {
                    $formatted_difference = function_exists('wc_price') ? wc_price($remaining_reduction) : $remaining_reduction;
                    throw new Exception(
                        sprintf(
                            __('Adjustment exceeds outstanding balance by %s', 'customer-debt-manager'),
                            $formatted_difference
                        )
                    );
                }
                
            }
            
            $wpdb->query('COMMIT');
            return $debt_id;
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('cdm_manual_adjustment_failed', $e->getMessage());
        }
    }
    
    /**
     * Get customer debt summary including manual adjustments
     */
    public function get_customer_debt_summary($customer_id) {
        global $wpdb;
        
        $summary = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT 
                    COUNT(id) as debt_count,
                    SUM(debt_amount) as total_debt_amount,
                    SUM(paid_amount) as total_paid_amount,
                    SUM(remaining_amount) as total_remaining_amount,
                    COUNT(CASE WHEN order_id = 0 THEN 1 END) as manual_adjustments_count
                FROM {$this->table_name}
                WHERE customer_id = %d",
                $customer_id
            )
        );
        
        return $summary;
    }
    
    /**
     * Get ALL customers with their debt information (including customers with no debt)
     */
    public function get_all_customers_with_debt_info($search = '', $orderby = 'customer_name', $order = 'asc', $status_filter = 'all') {
        global $wpdb;
        
        // Get all customers who have made WooCommerce orders (have customer role or have orders)
        $sql = "SELECT 
                    u.ID as customer_id,
                    u.display_name as customer_name,
                    u.user_email as customer_email,
                    COALESCE(debt_summary.debt_count, 0) as debt_count,
                    COALESCE(debt_summary.total_debt_amount, 0) as total_debt_amount,
                    COALESCE(debt_summary.total_paid_amount, 0) as total_paid_amount,
                    COALESCE(debt_summary.total_remaining_amount, 0) as total_remaining_amount,
                    debt_summary.latest_debt_date,
                    debt_summary.first_debt_date,
                    debt_summary.debt_statuses,
                    u.user_registered as customer_registered
                FROM {$wpdb->users} u
                LEFT JOIN (
                    SELECT 
                        d.customer_id,
                        COUNT(d.id) as debt_count,
                        SUM(d.debt_amount) as total_debt_amount,
                        SUM(d.paid_amount) as total_paid_amount,
                        SUM(d.remaining_amount) as total_remaining_amount,
                        MAX(d.created_date) as latest_debt_date,
                        MIN(d.created_date) as first_debt_date,
                        GROUP_CONCAT(DISTINCT d.status) as debt_statuses
                    FROM {$this->table_name} d
                    GROUP BY d.customer_id
                ) debt_summary ON u.ID = debt_summary.customer_id
                LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = '{$wpdb->prefix}capabilities'";
        
        $where_conditions = array();
        $params = array();
        
        // Build customer identification query based on HPOS availability
        $customer_identification_query = "um.meta_value LIKE %s OR debt_summary.customer_id IS NOT NULL";
        
        // Check if HPOS is enabled and wc_orders table exists
        $hpos_enabled = false;
        if (class_exists('CustomerDebtManager') && CustomerDebtManager::is_hpos_enabled()) {
            $wc_orders_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wc_orders'") === $wpdb->prefix.'wc_orders';
            if ($wc_orders_table_exists) {
                $hpos_enabled = true;
            }
        }
        
        if ($hpos_enabled) {
            // HPOS enabled - use wc_orders table
            $customer_identification_query .= " OR u.ID IN (
                SELECT DISTINCT customer_id FROM {$wpdb->prefix}wc_orders WHERE customer_id > 0
                UNION
                SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_customer_user' AND meta_value > 0
            )";
        } else {
            // HPOS not enabled - use traditional post meta approach only
            $customer_identification_query .= " OR u.ID IN (
                SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_customer_user' AND meta_value > 0
            )";
        }
        
        // Only show users who are customers (have customer role or have made orders)
        $where_conditions[] = "(" . $customer_identification_query . ")";
        $params[] = '%customer%';
        
        // Search functionality
        if (!empty($search)) {
            $where_conditions[] = "(u.display_name LIKE %s OR u.user_email LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $search_term;
            $params[] = $search_term;
        }
        
        // Status filter - only show customers with debts of specific status
        if ($status_filter === 'active') {
            $where_conditions[] = "debt_summary.total_remaining_amount > 0";
        } elseif ($status_filter === 'paid') {
            $where_conditions[] = "debt_summary.debt_count > 0 AND debt_summary.total_remaining_amount = 0";
        } elseif ($status_filter === 'no_debt') {
            $where_conditions[] = "debt_summary.customer_id IS NULL";
        }
        
        // Add WHERE clause if we have conditions
        if (!empty($where_conditions)) {
            $sql .= " WHERE " . implode(' AND ', $where_conditions);
        }
        
        // Add ordering
        $valid_orderby = array('customer_name', 'debt_count', 'total_debt_amount', 'total_paid_amount', 'total_remaining_amount', 'latest_debt_date', 'customer_registered');
        if (in_array($orderby, $valid_orderby)) {
            $sql .= " ORDER BY {$orderby}";
            if (strtolower($order) === 'desc') {
                $sql .= " DESC";
            } else {
                $sql .= " ASC";
            }
        } else {
            $sql .= " ORDER BY customer_name ASC";
        }
        
        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($sql, $params));
        } else {
            return $wpdb->get_results($sql);
        }
    }
    
    /**
     * Debug function to check debt calculation consistency
     */
    public function debug_debt_calculations($customer_id = null) {
        global $wpdb;
        
        $where_clause = $customer_id ? "WHERE customer_id = %d" : "";
        $params = $customer_id ? array($customer_id) : array();
        
        $sql = "SELECT 
                    customer_id,
                    COUNT(*) as record_count,
                    SUM(debt_amount) as total_debt,
                    SUM(remaining_amount) as total_remaining,
                    SUM(paid_amount) as total_paid,
                    GROUP_CONCAT(CONCAT('ID:', id, ' Debt:', debt_amount, ' Remaining:', remaining_amount, ' Paid:', paid_amount) SEPARATOR ' | ') as record_details
                FROM {$this->table_name}
                {$where_clause}
                GROUP BY customer_id";
        
        if (!empty($params)) {
            $results = $wpdb->get_results($wpdb->prepare($sql, $params));
        } else {
            $results = $wpdb->get_results($sql);
        }
        
        return $results;
    }
    
    /**
     * Clean up debt data - removes test/incorrect records
     */
    public function cleanup_debt_data() {
        global $wpdb;
        
        $wpdb->query('START TRANSACTION');
        
        try {
            // 1. Delete all manual adjustment records (order_id = 0)
            $manual_adjustments_deleted = $wpdb->query(
                "DELETE FROM {$this->table_name} WHERE order_id = 0"
            );

            if ($manual_adjustments_deleted === false) {
                throw new Exception('Failed to delete manual adjustments: ' . $wpdb->last_error);
            }

            // 2. Delete corresponding payment records for manual adjustments
            $adjustment_payments_deleted = $wpdb->query(
                "DELETE FROM {$this->payments_table_name} 
                 WHERE payment_type = 'adjustment'"
            );

            if ($adjustment_payments_deleted === false) {
                throw new Exception('Failed to delete adjustment payments: ' . $wpdb->last_error);
            }

            // 3. Reset all remaining debt records to their original state
            // (remove any payments and reset remaining amounts)
            $orders_reset = $wpdb->query(
                "UPDATE {$this->table_name} 
                 SET paid_amount = 0, 
                     remaining_amount = debt_amount, 
                     status = IF(debt_amount > 0, 'active', 'paid')
                 WHERE order_id > 0"
            );

            if ($orders_reset === false) {
                throw new Exception('Failed to reset order debts: ' . $wpdb->last_error);
            }

            // 4. Delete all payment records for order debts
            $order_payments_deleted = $wpdb->query(
                "DELETE p FROM {$this->payments_table_name} p
                 INNER JOIN {$this->table_name} d ON p.debt_id = d.id
                 WHERE d.order_id > 0"
            );

            if ($order_payments_deleted === false) {
                throw new Exception('Failed to delete order payments: ' . $wpdb->last_error);
            }

            $wpdb->query('COMMIT');

            $manual_adjustments_deleted = intval($manual_adjustments_deleted);
            $adjustment_payments_deleted = intval($adjustment_payments_deleted);
            $orders_reset = intval($orders_reset);
            $order_payments_deleted = intval($order_payments_deleted);

            $total_payments_deleted = $adjustment_payments_deleted + $order_payments_deleted;

            return array(
                'success' => true,
                'manual_adjustments_deleted' => $manual_adjustments_deleted,
                'adjustment_payments_deleted' => $adjustment_payments_deleted,
                'order_payments_deleted' => $order_payments_deleted,
                'payments_deleted' => $total_payments_deleted,
                'orders_reset' => $orders_reset,
                'message' => sprintf(
                    __(
                        'Manual adjustments removed: %1$d, adjustment payments removed: %2$d, order payments removed: %3$d, orders reset: %4$d',
                        'customer-debt-manager'
                    ),
                    $manual_adjustments_deleted,
                    $adjustment_payments_deleted,
                    $order_payments_deleted,
                    $orders_reset
                )
            );
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return array(
                'success' => false,
                'message' => 'Cleanup failed: ' . $e->getMessage(),
                'error' => $e->getMessage()
            );
        }
    }
}
