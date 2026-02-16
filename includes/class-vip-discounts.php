<?php
/**
 * VIP Discounts Class
 *
 * Manages per-customer discount rules:
 *  - Admin can assign specific users + product categories + individual products + discount (% or fixed per item)
 *  - When a VIP user has matching products in cart, a discount is applied automatically
 *  - Discounted items do NOT earn cashback
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WCS_VIP_Discounts {

    private static $instance = null;

    /** Option key where all VIP rules are stored */
    const OPTION_KEY = 'wcs_vip_discount_rules';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Apply discount to cart
        add_action('woocommerce_cart_calculate_fees', array($this, 'apply_vip_discounts'), 15);

        // AJAX endpoints
        add_action('wp_ajax_wcs_save_vip_rule',     array($this, 'ajax_save_rule'));
        add_action('wp_ajax_wcs_delete_vip_rule',    array($this, 'ajax_delete_rule'));
        add_action('wp_ajax_wcs_search_users',       array($this, 'ajax_search_users'));
        add_action('wp_ajax_wcs_search_products',    array($this, 'ajax_search_products'));
    }

    /* ═══════════════════════════════════════════════════════
     *  RULES CRUD
     * ═══════════════════════════════════════════════════════ */

    /**
     * Get all VIP rules
     * @return array
     */
    public static function get_rules() {
        $rules = get_option(self::OPTION_KEY, array());
        return is_array($rules) ? $rules : array();
    }

    /**
     * Save a single rule (add or update by index)
     */
    public static function save_rule($rule_data, $index = null) {
        $rules = self::get_rules();

        $sanitized = array(
            'user_ids'       => array_map('intval', (array) $rule_data['user_ids']),
            'category_ids'   => isset($rule_data['category_ids']) ? array_map('intval', array_filter((array) $rule_data['category_ids'])) : array(),
            'product_ids'    => isset($rule_data['product_ids']) ? array_map('intval', array_filter((array) $rule_data['product_ids'])) : array(),
            'discount_type'  => in_array($rule_data['discount_type'], array('percentage', 'fixed')) ? $rule_data['discount_type'] : 'percentage',
            'discount_value' => floatval($rule_data['discount_value']),
            'label'          => sanitize_text_field($rule_data['label']),
            'enabled'        => !empty($rule_data['enabled']),
        );

        if ($index !== null && isset($rules[$index])) {
            $rules[$index] = $sanitized;
        } else {
            $rules[] = $sanitized;
        }

        update_option(self::OPTION_KEY, $rules);
        return $sanitized;
    }

    /**
     * Delete a rule by index
     */
    public static function delete_rule($index) {
        $rules = self::get_rules();
        if (isset($rules[$index])) {
            array_splice($rules, $index, 1);
            update_option(self::OPTION_KEY, $rules);
            return true;
        }
        return false;
    }

    /* ═══════════════════════════════════════════════════════
     *  CART — Apply VIP discounts as negative fees
     * ═══════════════════════════════════════════════════════ */

    public function apply_vip_discounts($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }

        $rules = self::get_rules();
        if (empty($rules)) {
            return;
        }

        // Collect all discounts for this user
        $total_discount = 0;
        $discount_labels = array();
        $discounted_product_ids = array();

        foreach ($rules as $rule) {
            if (empty($rule['enabled'])) {
                continue;
            }
            if (!in_array($user_id, (array) $rule['user_ids'])) {
                continue;
            }

            $rule_category_ids = isset($rule['category_ids']) ? (array) $rule['category_ids'] : array();
            $rule_product_ids  = isset($rule['product_ids']) ? (array) $rule['product_ids'] : array();
            $rule_discount = 0;

            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                $product_id = $cart_item['product_id'];
                $product    = $cart_item['data'];
                $qty        = $cart_item['quantity'];

                $matches = false;

                // Check 1: specific product match
                if (!empty($rule_product_ids) && in_array($product_id, $rule_product_ids)) {
                    $matches = true;
                }

                // Check 2: category match
                if (!$matches && !empty($rule_category_ids)) {
                    $product_cats = wc_get_product_cat_ids($product_id);
                    $intersect    = array_intersect($product_cats, $rule_category_ids);
                    if (!empty($intersect)) {
                        $matches = true;
                    }
                }

                if (!$matches) {
                    continue;
                }

                $item_price = floatval($product->get_price());

                if ($rule['discount_type'] === 'percentage') {
                    $item_discount = round($item_price * ($rule['discount_value'] / 100), 2) * $qty;
                } else {
                    $item_discount = min($rule['discount_value'], $item_price) * $qty;
                }

                $rule_discount += $item_discount;
                $discounted_product_ids[] = $product_id;
            }

            if ($rule_discount > 0) {
                $total_discount += $rule_discount;
                $label = !empty($rule['label']) ? $rule['label'] : __('VIP Знижка', 'woo-cashback-system');
                $discount_labels[] = $label;
            }
        }

        if ($total_discount > 0) {
            $fee_label = implode(' + ', array_unique($discount_labels));
            $cart->add_fee($fee_label, -1 * $total_discount);

            if (WC()->session) {
                WC()->session->set('wcs_vip_discounted_products', array_unique($discounted_product_ids));
                WC()->session->set('wcs_vip_discount_amount', $total_discount);
            }
        } else {
            if (WC()->session) {
                WC()->session->set('wcs_vip_discounted_products', array());
                WC()->session->set('wcs_vip_discount_amount', 0);
            }
        }
    }

    /* ═══════════════════════════════════════════════════════
     *  Check if user has VIP rules
     * ═══════════════════════════════════════════════════════ */

    public static function user_has_vip_rules($user_id) {
        $rules = self::get_rules();
        foreach ($rules as $rule) {
            if (!empty($rule['enabled']) && in_array($user_id, (array) $rule['user_ids'])) {
                return true;
            }
        }
        return false;
    }

    public static function get_user_vip_info($user_id) {
        $rules = self::get_rules();
        $info = array();

        foreach ($rules as $rule) {
            if (empty($rule['enabled']) || !in_array($user_id, (array) $rule['user_ids'])) {
                continue;
            }

            $cat_names = array();
            foreach ((array) ($rule['category_ids'] ?? array()) as $cat_id) {
                $term = get_term($cat_id, 'product_cat');
                if ($term && !is_wp_error($term)) {
                    $cat_names[] = $term->name;
                }
            }

            $prod_names = array();
            foreach ((array) ($rule['product_ids'] ?? array()) as $pid) {
                $p = wc_get_product($pid);
                if ($p) {
                    $prod_names[] = $p->get_name();
                }
            }

            $info[] = array(
                'categories'     => implode(', ', $cat_names),
                'products'       => implode(', ', $prod_names),
                'discount_type'  => $rule['discount_type'],
                'discount_value' => $rule['discount_value'],
                'label'          => $rule['label'],
            );
        }

        return $info;
    }

    /* ═══════════════════════════════════════════════════════
     *  AJAX — Save rule
     * ═══════════════════════════════════════════════════════ */

    public function ajax_save_rule() {
        check_ajax_referer('wcs_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => '❌ Доступ заборонено'));
        }

        $rule_data = array(
            'user_ids'       => isset($_POST['user_ids']) ? array_map('intval', (array) $_POST['user_ids']) : array(),
            'category_ids'   => isset($_POST['category_ids']) ? array_map('intval', (array) $_POST['category_ids']) : array(),
            'product_ids'    => isset($_POST['product_ids']) ? array_map('intval', (array) $_POST['product_ids']) : array(),
            'discount_type'  => isset($_POST['discount_type']) ? sanitize_text_field($_POST['discount_type']) : 'percentage',
            'discount_value' => isset($_POST['discount_value']) ? floatval($_POST['discount_value']) : 0,
            'label'          => isset($_POST['label']) ? sanitize_text_field($_POST['label']) : '',
            'enabled'        => isset($_POST['enabled']) ? (bool) $_POST['enabled'] : true,
        );

        if (empty($rule_data['user_ids'])) {
            wp_send_json_error(array('message' => '❌ Виберіть хоча б одного клієнта'));
        }
        if (empty($rule_data['category_ids']) && empty($rule_data['product_ids'])) {
            wp_send_json_error(array('message' => '❌ Виберіть хоча б одну категорію або товар'));
        }
        if ($rule_data['discount_value'] <= 0) {
            wp_send_json_error(array('message' => '❌ Вкажіть суму знижки'));
        }

        $index = isset($_POST['rule_index']) && $_POST['rule_index'] !== '' ? intval($_POST['rule_index']) : null;
        self::save_rule($rule_data, $index);

        wp_send_json_success(array('message' => '✅ Правило успішно збережено'));
    }

    /* ═══════════════════════════════════════════════════════
     *  AJAX — Delete rule
     * ═══════════════════════════════════════════════════════ */

    public function ajax_delete_rule() {
        check_ajax_referer('wcs_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => '❌ Доступ заборонено'));
        }

        $index = isset($_POST['rule_index']) ? intval($_POST['rule_index']) : -1;

        if (self::delete_rule($index)) {
            wp_send_json_success(array('message' => '✅ Правило видалено'));
        } else {
            wp_send_json_error(array('message' => '❌ Правило не знайдено'));
        }
    }

    /* ═══════════════════════════════════════════════════════
     *  AJAX — Search users (Select2-compatible)
     *  If term is empty/short, returns first 50 registered users
     * ═══════════════════════════════════════════════════════ */

    public function ajax_search_users() {
        check_ajax_referer('wcs_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error();
        }

        $term = isset($_GET['term']) ? sanitize_text_field($_GET['term']) : '';

        $args = array(
            'number'  => 50,
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'fields'  => array('ID', 'display_name', 'user_email'),
        );

        // If there's a search term, add search filter
        if (strlen($term) >= 2) {
            $args['search'] = '*' . $term . '*';
            $args['search_columns'] = array('user_login', 'user_email', 'display_name');
        }

        $users = get_users($args);

        $results = array();
        foreach ($users as $user) {
            $results[] = array(
                'id'   => $user->ID,
                'text' => sprintf('%s (%s)', $user->display_name, $user->user_email),
            );
        }

        wp_send_json($results);
    }

    /* ═══════════════════════════════════════════════════════
     *  AJAX — Search products (Select2-compatible)
     * ═══════════════════════════════════════════════════════ */

    public function ajax_search_products() {
        check_ajax_referer('wcs_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error();
        }

        $term = isset($_GET['term']) ? sanitize_text_field($_GET['term']) : '';

        $args = array(
            'status'  => 'publish',
            'limit'   => 30,
            'orderby' => 'title',
            'order'   => 'ASC',
            'return'  => 'objects',
        );

        if (strlen($term) >= 2) {
            $args['s'] = $term;
        }

        $products = wc_get_products($args);

        $results = array();
        foreach ($products as $product) {
            $price = $product->get_price() ? wc_price($product->get_price()) : '';
            // Strip HTML from price for Select2 text
            $price_text = $product->get_price() ? ' — ' . strip_tags(wc_price($product->get_price())) : '';
            $results[] = array(
                'id'   => $product->get_id(),
                'text' => $product->get_name() . $price_text . ' (ID: ' . $product->get_id() . ')',
            );
        }

        wp_send_json($results);
    }
}
