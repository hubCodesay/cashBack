<?php
/**
 * Database Handler Class
 * Manages database tables and operations for cashback data
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WCS_Cashback_Database {
    /**
     * Request-level cache for balances.
     *
     * @var array<int, object>
     */
    private static $balance_cache = array();

    /**
     * Cache table existence checks within the current request.
     *
     * @var array<string, bool>
     */
    private static $table_exists_cache = array();
    
    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Table for user cashback balances
        $table_balances = $wpdb->prefix . 'wcs_cashback_balances';
        
        $sql_balances = "CREATE TABLE $table_balances (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            balance decimal(10,2) NOT NULL DEFAULT 0.00,
            total_earned decimal(10,2) NOT NULL DEFAULT 0.00,
            total_spent decimal(10,2) NOT NULL DEFAULT 0.00,
            max_limit decimal(10,2) NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_id (user_id),
            KEY balance (balance)
        ) $charset_collate;";
        
        // Table for cashback transactions
        $table_transactions = $wpdb->prefix . 'wcs_cashback_transactions';
        
        $sql_transactions = "CREATE TABLE $table_transactions (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            order_id bigint(20) NOT NULL,
            transaction_type enum('earned','spent','adjustment') NOT NULL DEFAULT 'earned',
            amount decimal(10,2) NOT NULL,
            balance_before decimal(10,2) NOT NULL,
            balance_after decimal(10,2) NOT NULL,
            order_total decimal(10,2) NULL,
            cashback_percentage decimal(5,2) NULL,
            description text NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY order_id (order_id),
            KEY transaction_type (transaction_type),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_balances);
        dbDelta($sql_transactions);

        self::$table_exists_cache[$table_balances] = true;
        self::$table_exists_cache[$table_transactions] = true;
    }

    /**
     * Check whether a custom table exists.
     *
     * @param string $table_name
     * @return bool
     */
    private static function table_exists($table_name) {
        global $wpdb;

        if (isset(self::$table_exists_cache[$table_name])) {
            return self::$table_exists_cache[$table_name];
        }

        $exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name);
        self::$table_exists_cache[$table_name] = $exists;

        return $exists;
    }
    
    /**
     * Get user cashback balance
     */
    public static function get_user_balance($user_id) {
        global $wpdb;
        $user_id = absint($user_id);
        if ($user_id <= 0) {
            return (object) array(
                'user_id' => 0,
                'balance' => 0.00,
                'total_earned' => 0.00,
                'total_spent' => 0.00,
                'max_limit' => null,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            );
        }

        if (isset(self::$balance_cache[$user_id])) {
            return self::$balance_cache[$user_id];
        }

        $table = $wpdb->prefix . 'wcs_cashback_balances';

        $balance = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d",
            $user_id
        ));
        
        if (!$balance) {
            // Create new balance record
            self::create_user_balance($user_id);
            $balance = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE user_id = %d",
                $user_id
            ));
            
            // Якщо все ще null, повернути об'єкт з нульовими значеннями
            if (!$balance) {
                $balance = (object) array(
                    'user_id' => $user_id,
                    'balance' => 0.00,
                    'total_earned' => 0.00,
                    'total_spent' => 0.00,
                    'max_limit' => null,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                );
            }
        }

        self::$balance_cache[$user_id] = $balance;
        return $balance;
    }
    
    /**
     * Create user balance record
     */
    public static function create_user_balance($user_id) {
        global $wpdb;
        $user_id = absint($user_id);
        $table = $wpdb->prefix . 'wcs_cashback_balances';

        // Ensure table exists
        if (!self::table_exists($table)) {
            self::create_tables();
        }
        
        // Check if already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE user_id = %d",
            $user_id
        ));
        
        if ($existing) {
            return $existing;
        }
        
        $result = $wpdb->insert(
            $table,
            array(
                'user_id' => $user_id,
                'balance' => 0.00,
                'total_earned' => 0.00,
                'total_spent' => 0.00,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('%d', '%f', '%f', '%f', '%s', '%s')
        );
        
        if ($result === false) {
            error_log('WCS Cashback: Failed to create user balance for user ' . $user_id . ': ' . $wpdb->last_error);
            return false;
        }

        unset(self::$balance_cache[$user_id]);
        return $wpdb->insert_id;
    }
    
    /**
     * Update user balance
     */
    public static function update_balance($user_id, $amount, $type = 'earned') {
        global $wpdb;
        $user_id = absint($user_id);
        $amount = floatval($amount);
        $table = $wpdb->prefix . 'wcs_cashback_balances';

        // Ensure table exists
        if (!self::table_exists($table)) {
            self::create_tables();
        }
        
        // Get current balance (this will create if not exists)
        $current = self::get_user_balance($user_id);
        $balance_before = floatval($current->balance);
        $new_balance = $balance_before;
        $result = false;
        
        if ($type === 'earned') {
            $new_balance = $balance_before + $amount;
            $result = $wpdb->update(
                $table,
                array(
                    'balance' => $new_balance,
                    'total_earned' => floatval($current->total_earned) + $amount,
                    'updated_at' => current_time('mysql'),
                ),
                array('user_id' => $user_id),
                array('%f', '%f', '%s'),
                array('%d')
            );
        } elseif ($type === 'spent') {
            $new_balance = max(0, $balance_before - $amount);
            $result = $wpdb->update(
                $table,
                array(
                    'balance' => $new_balance,
                    'total_spent' => floatval($current->total_spent) + $amount,
                    'updated_at' => current_time('mysql'),
                ),
                array('user_id' => $user_id),
                array('%f', '%f', '%s'),
                array('%d')
            );
        } elseif ($type === 'adjustment') {
            $new_balance = $amount;
            $result = $wpdb->update(
                $table,
                array(
                    'balance' => $new_balance,
                    'updated_at' => current_time('mysql'),
                ),
                array('user_id' => $user_id),
                array('%f', '%s'),
                array('%d')
            );
        }
        
        // Log any database errors
        if ($result === false && !empty($wpdb->last_error)) {
            error_log('WCS Cashback DB Error: ' . $wpdb->last_error);
        }

        if ($result !== false) {
            $current->balance = $new_balance;
            if ($type === 'earned') {
                $current->total_earned = floatval($current->total_earned) + $amount;
            } elseif ($type === 'spent') {
                $current->total_spent = floatval($current->total_spent) + $amount;
            }
            $current->updated_at = current_time('mysql');
            self::$balance_cache[$user_id] = $current;
        }

        return $new_balance;
    }
    
    /**
     * Add cashback transaction
     */
    public static function add_transaction($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcs_cashback_transactions';
        
        $defaults = array(
            'user_id' => 0,
            'order_id' => 0,
            'transaction_type' => 'earned',
            'amount' => 0.00,
            'balance_before' => 0.00,
            'balance_after' => 0.00,
            'order_total' => null,
            'cashback_percentage' => null,
            'description' => '',
        );
        
        $data = wp_parse_args($data, $defaults);
        
        $wpdb->insert(
            $table,
            $data,
            array('%d', '%d', '%s', '%f', '%f', '%f', '%f', '%f', '%s')
        );
        
        return $wpdb->insert_id;
    }
    
    /**
     * Get user transactions
     */
    public static function get_user_transactions($user_id, $limit = 20, $offset = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcs_cashback_transactions';
        
        $transactions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $user_id,
            $limit,
            $offset
        ));
        
        return $transactions;
    }
    
    /**
     * Get transaction by order ID
     */
    public static function get_transaction_by_order($order_id, $type = 'earned') {
        global $wpdb;
        $table = $wpdb->prefix . 'wcs_cashback_transactions';
        
        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE order_id = %d AND transaction_type = %s ORDER BY created_at DESC LIMIT 1",
            $order_id,
            $type
        ));
        
        return $transaction;
    }
    
    /**
     * Get all users with cashback
     */
    public static function get_all_users_with_cashback($orderby = 'balance', $order = 'DESC', $limit = 20, $offset = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcs_cashback_balances';
        
        $allowed_orderby = array('balance', 'total_earned', 'total_spent', 'created_at', 'updated_at');
        $orderby = in_array($orderby, $allowed_orderby) ? $orderby : 'balance';
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table ORDER BY $orderby $order LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));
        
        return $results;
    }
    
    /**
     * Count total users with cashback
     */
    public static function count_users_with_cashback() {
        global $wpdb;
        $table = $wpdb->prefix . 'wcs_cashback_balances';
        
        return $wpdb->get_var("SELECT COUNT(*) FROM $table");
    }
    
    /**
     * Set user max limit
     */
    public static function set_user_max_limit($user_id, $limit) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcs_cashback_balances';
        
        $wpdb->update(
            $table,
            array('max_limit' => $limit),
            array('user_id' => $user_id),
            array('%f'),
            array('%d')
        );
    }
    
    /**
     * Get total cashback statistics
     */
    public static function get_statistics() {
        global $wpdb;
        $table = $wpdb->prefix . 'wcs_cashback_balances';
        
        $stats = $wpdb->get_row(
            "SELECT 
                COALESCE(SUM(balance), 0) as total_balance,
                COALESCE(SUM(total_earned), 0) as total_earned,
                COALESCE(SUM(total_spent), 0) as total_spent,
                COUNT(*) as total_users
            FROM $table"
        );
        
        // Якщо таблиця не існує або порожня, повернути об'єкт з нульовими значеннями
        if (!$stats) {
            $stats = (object) array(
                'total_balance' => 0,
                'total_earned' => 0,
                'total_spent' => 0,
                'total_users' => 0
            );
        }
        
        return $stats;
    }
}
