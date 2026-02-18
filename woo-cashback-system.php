<?php
/**
 * Plugin Name: WooCommerce Cashback System
 * Plugin URI: https://example.com/woo-cashback-system
 * Description: A comprehensive cashback system for WooCommerce that allows users to earn and spend cashback on purchases with configurable rates and limits.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * Text Domain: woo-cashback-system
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 * Requires Plugins: woocommerce
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WCS_VERSION', '1.0.0');
define('WCS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WCS_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Check if WooCommerce is active
 */
function wcs_is_woocommerce_active() {
    return class_exists('WooCommerce');
}

/**
 * Display admin notice if WooCommerce is not active
 */
function wcs_woocommerce_missing_notice() {
    ?>
    <div class="error">
        <p><?php _e('WooCommerce Cashback System requires WooCommerce to be installed and active.', 'woo-cashback-system'); ?></p>
    </div>
    <?php
}

/**
 * Declare WooCommerce HPOS compatibility
 */
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

/**
 * Plugin activation
 */
function wcs_activate_plugin() {
    // Load required classes
    require_once WCS_PLUGIN_DIR . 'includes/class-cashback-database.php';
    require_once WCS_PLUGIN_DIR . 'includes/class-cashback-user.php';
    
    // Create database tables
    WCS_Cashback_Database::create_tables();
    
    // Set default options
    $default_settings = array(
        'enabled' => 'yes',
        'tier_1_threshold' => 500,
        'tier_1_percentage' => 3,
        'tier_2_threshold' => 1000,
        'tier_2_percentage' => 5,
        'tier_3_threshold' => 1500,
        'tier_3_percentage' => 7,
        'max_cashback_limit' => 10000,
        'usage_limit_percentage' => 50,
        'enable_notifications' => 'yes',
    );
    
    if (!get_option('wcs_cashback_settings')) {
        add_option('wcs_cashback_settings', $default_settings);
    }
    
    // Ініціалізація endpoint перед flush
    WCS_Cashback_User::get_instance();
    
    // Flush rewrite rules
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'wcs_activate_plugin');

/**
 * Plugin deactivation
 */
function wcs_deactivate_plugin() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'wcs_deactivate_plugin');

/**
 * Initialize plugin
 */
function wcs_init_plugin() {
    // Check if WooCommerce is active
    if (!wcs_is_woocommerce_active()) {
        add_action('admin_notices', 'wcs_woocommerce_missing_notice');
        return;
    }
    
    // Load plugin files
    require_once WCS_PLUGIN_DIR . 'includes/class-cashback-database.php';
    require_once WCS_PLUGIN_DIR . 'includes/class-cashback-calculator.php';
    require_once WCS_PLUGIN_DIR . 'includes/class-cashback-admin.php';
    require_once WCS_PLUGIN_DIR . 'includes/class-cashback-user.php';
    require_once WCS_PLUGIN_DIR . 'includes/class-cashback-checkout.php';
    require_once WCS_PLUGIN_DIR . 'includes/class-cashback-notifications.php';
    require_once WCS_PLUGIN_DIR . 'includes/class-vip-discounts.php';
    
    // Initialize classes - User раніше для реєстрації endpoint
    WCS_Cashback_User::get_instance();
    WCS_Cashback_Admin::get_instance();
    WCS_Cashback_Checkout::get_instance();
    WCS_Cashback_Notifications::get_instance();
    WCS_VIP_Discounts::get_instance();
    
    // Self-healing: Ensure tables exist (in case activation hook didn't fire during development)
    WCS_Cashback_Database::create_tables();
}
add_action('plugins_loaded', 'wcs_init_plugin', 5);

/**
 * Load text domain
 */
function wcs_load_textdomain() {
    load_plugin_textdomain('woo-cashback-system', false, dirname(WCS_PLUGIN_BASENAME) . '/languages');
}
add_action('init', 'wcs_load_textdomain');

/**
 * Enqueue admin scripts and styles
 */
function wcs_admin_scripts($hook) {
    if (strpos($hook, 'wcs-cashback') === false) {
        return;
    }
    
    wp_enqueue_style('wcs-admin-style', WCS_PLUGIN_URL . 'admin/css/admin-style.css', array(), WCS_VERSION);
    wp_enqueue_script('wcs-admin-script', WCS_PLUGIN_URL . 'admin/js/admin-script.js', array('jquery'), WCS_VERSION, true);
    
    // Enqueue Select2 for VIP discounts and Brands selection (bundled with WooCommerce)
    if (strpos($hook, 'wcs-cashback') !== false) {
        wp_enqueue_style('select2', WC()->plugin_url() . '/assets/css/select2.css');
        wp_enqueue_script('select2', WC()->plugin_url() . '/assets/js/select2/select2.full.min.js', array('jquery'), null, true);
    }
    
    wp_localize_script('wcs-admin-script', 'wcs_admin', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wcs_admin_nonce'),
    ));
}
add_action('admin_enqueue_scripts', 'wcs_admin_scripts');

/**
 * Enqueue public scripts and styles
 */
function wcs_public_scripts() {
    if (is_account_page() || is_checkout() || is_cart()) {
        wp_enqueue_style('wcs-public-style', WCS_PLUGIN_URL . 'public/css/public-style.css', array(), time());
        wp_enqueue_script('wcs-public-script', WCS_PLUGIN_URL . 'public/js/public-script.js', array('jquery'), time() + 3, true);
        
        $settings = get_option('wcs_cashback_settings');
        $cart_position = isset($settings['cart_position']) ? $settings['cart_position'] : 'woocommerce_cart_totals_before_order_total';

        wp_localize_script('wcs-public-script', 'wcs_public', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wcs_public_nonce'),
            'currency_symbol' => get_woocommerce_currency_symbol(),
            'cart_position' => $cart_position
        ));
    }
}
add_action('wp_enqueue_scripts', 'wcs_public_scripts');

/**
 * Add settings link on plugins page
 */
function wcs_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wcs-cashback') . '" style="color:#2271b1;font-weight:600;">' . __('Settings', 'woo-cashback-system') . '</a>';
    $users_link = '<a href="' . admin_url('admin.php?page=wcs-cashback-users') . '">' . __('Manage Users', 'woo-cashback-system') . '</a>';
    $flush_link = '<a href="' . wp_nonce_url(admin_url('admin.php?page=wcs-cashback&wcs_flush_rewrite=1'), 'wcs_flush_rewrite') . '" style="color:#d63638;">' . __('Fix 404', 'woo-cashback-system') . '</a>';
    
    array_unshift($links, $settings_link, $users_link, $flush_link);
    
    return $links;
}
add_filter('plugin_action_links_' . WCS_PLUGIN_BASENAME, 'wcs_add_settings_link');

/**
 * Handle flush rewrite rules
 */
function wcs_handle_flush_rewrite() {
    if (isset($_GET['wcs_flush_rewrite']) && $_GET['wcs_flush_rewrite'] == '1') {
        if (check_admin_referer('wcs_flush_rewrite')) {
            // Переконайтеся що endpoint зареєстровано
            WCS_Cashback_User::get_instance();
            
            // Очистити rewrite rules
            flush_rewrite_rules();
            
            // Редірект з повідомленням
            wp_redirect(add_query_arg(array(
                'page' => 'wcs-cashback',
                'wcs_flushed' => '1'
            ), admin_url('admin.php')));
            exit;
        }
    }
    
    // Показати повідомлення після flush
    if (isset($_GET['wcs_flushed']) && $_GET['wcs_flushed'] == '1') {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p><strong>✅ Permalinks оновлено!</strong> Сторінка /my-account/cashback/ тепер має працювати.</p></div>';
        });
    }
}
add_action('admin_init', 'wcs_handle_flush_rewrite');

/**
 * Query vars filter to recognize cashback endpoint
 */
function wcs_add_query_vars($vars) {
    $vars[] = 'cashback';
    return $vars;
}
add_filter('query_vars', 'wcs_add_query_vars');

/**
 * Shortcode to display potential cashback earning
 * Usage: [wcs_cashback_earning]
 */
function wcs_shortcode_cashback_earning($atts) {
    if (!class_exists('WooCommerce')) {
        return '';
    }
    
    ob_start();
    
    $checkout = WCS_Cashback_Checkout::get_instance();
    if (method_exists($checkout, 'display_potential_cashback_earning')) {
        $checkout->display_potential_cashback_earning();
    }
    
    return ob_get_clean();
}
add_shortcode('wcs_cashback_earning', 'wcs_shortcode_cashback_earning');

/**
 * Shortcode to display cashback usage option
 * Usage: [wcs_cashback_usage]
 */
function wcs_shortcode_cashback_usage($atts) {
    if (!class_exists('WooCommerce')) {
        return '';
    }
    
    ob_start();
    
    $checkout = WCS_Cashback_Checkout::get_instance();
    if (method_exists($checkout, 'display_cashback_in_cart')) {
        $checkout->display_cashback_in_cart();
    }
    
    return ob_get_clean();
}
add_shortcode('wcs_cashback_usage', 'wcs_shortcode_cashback_usage');

/**
 * Clear cashback session on user logout
 */
function wcs_clear_session_on_logout() {
    if (function_exists('WC') && WC()->session) {
        WC()->session->set('wcs_applied_cashback', 0);
        WC()->session->set('wcs_cashback_to_use', 0);
    }
}
add_action('wp_logout', 'wcs_clear_session_on_logout');

/**
 * Clear cashback session on user login (fresh start)
 */
function wcs_clear_session_on_login($user_login, $user) {
    if (function_exists('WC') && WC()->session) {
        WC()->session->set('wcs_applied_cashback', 0);
        WC()->session->set('wcs_cashback_to_use', 0);
    }
}
add_action('wp_login', 'wcs_clear_session_on_login', 10, 2);
