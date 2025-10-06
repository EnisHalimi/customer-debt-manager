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
     * Create a new debt record
     */
    public function create_debt($customer_id, $order_id, $debt_amount) {
        global $wpdb;
        
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("CDM: Attempting to create debt - Customer: {$customer_id}, Order: {$order_id}, Amount: {$debt_amount}");
        }
        
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
            // Log the error
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("CDM: Failed to create debt record - SQL Error: " . $wpdb->last_error);
            }
            return false;
        }
        
        $insert_id = $wpdb->insert_id;
        
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("CDM: Debt record created successfully - Debt ID: {$insert_id}");
        }
        
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
                throw new Exception('Failed to insert payment');
            }
            
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
                throw new Exception('Failed to update debt');
            }
            
            $wpdb->query('COMMIT');
            return $wpdb->insert_id;
            
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
}
