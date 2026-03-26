<?php
/**
 * Cashback Calculator Class
 * Calculates cashback percentage based on tier settings
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WCS_Cashback_Calculator {
    /**
     * Request-level settings cache.
     *
     * @var array|null
     */
    private static $settings_cache = null;

    /**
     * Cached list of WooCommerce product IDs linked to LMS courses.
     *
     * @var array|null
     */
    private static $course_product_ids = null;

    /**
     * Get merged cashback settings from the main option with legacy fallback.
     *
     * @return array
     */
    private static function get_settings() {
        if (self::$settings_cache !== null) {
            return self::$settings_cache;
        }

        $settings = get_option('wcs_cashback_settings');
        if (!is_array($settings)) {
            $settings = array();
        }

        self::$settings_cache = array(
            'enabled'               => isset($settings['enabled']) ? $settings['enabled'] : get_option('wcs_cashback_enabled', 'yes'),
            'tier_1_threshold'      => isset($settings['tier_1_threshold']) ? floatval($settings['tier_1_threshold']) : floatval(get_option('wcs_tier_1_threshold', 500)),
            'tier_1_percentage'     => isset($settings['tier_1_percentage']) ? floatval($settings['tier_1_percentage']) : floatval(get_option('wcs_tier_1_percentage', 3)),
            'tier_2_threshold'      => isset($settings['tier_2_threshold']) ? floatval($settings['tier_2_threshold']) : floatval(get_option('wcs_tier_2_threshold', 1000)),
            'tier_2_percentage'     => isset($settings['tier_2_percentage']) ? floatval($settings['tier_2_percentage']) : floatval(get_option('wcs_tier_2_percentage', 5)),
            'tier_3_threshold'      => isset($settings['tier_3_threshold']) ? floatval($settings['tier_3_threshold']) : floatval(get_option('wcs_tier_3_threshold', 1500)),
            'tier_3_percentage'     => isset($settings['tier_3_percentage']) ? floatval($settings['tier_3_percentage']) : floatval(get_option('wcs_tier_3_percentage', 7)),
            'max_cashback_limit'    => isset($settings['max_cashback_limit']) ? floatval($settings['max_cashback_limit']) : floatval(get_option('wcs_max_cashback_limit', 10000)),
            'usage_limit_percentage'=> isset($settings['usage_limit_percentage']) ? floatval($settings['usage_limit_percentage']) : floatval(get_option('wcs_usage_limit_percentage', 50)),
            'use_brands_logic'      => isset($settings['use_brands_logic']) ? $settings['use_brands_logic'] : 'no',
            'brand_taxonomy'        => isset($settings['brand_taxonomy']) ? $settings['brand_taxonomy'] : 'product_brand',
            'brand_rules'           => isset($settings['brand_rules']) ? (array) $settings['brand_rules'] : array(),
            'exclude_sale_items'    => isset($settings['exclude_sale_items']) ? $settings['exclude_sale_items'] : 'yes',
            'allow_course_cashback' => isset($settings['allow_course_cashback']) ? $settings['allow_course_cashback'] : 'no',
            'disable_earning_when_using_cashback' => isset($settings['disable_earning_when_using_cashback']) ? $settings['disable_earning_when_using_cashback'] : 'yes',
            'excluded_category_ids' => isset($settings['excluded_category_ids']) ? array_values(array_filter(array_map('absint', (array) $settings['excluded_category_ids']))) : array(),
            'excluded_brand_ids'    => isset($settings['excluded_brand_ids']) ? array_values(array_filter(array_map('absint', (array) $settings['excluded_brand_ids']))) : array(),
            'exclusion_rules'       => isset($settings['exclusion_rules']) ? (array) $settings['exclusion_rules'] : array(),
        );

        if (empty(self::$settings_cache['exclusion_rules'])) {
            if (!empty(self::$settings_cache['excluded_category_ids'])) {
                self::$settings_cache['exclusion_rules'][] = array(
                    'type' => 'category',
                    'ids' => self::$settings_cache['excluded_category_ids'],
                );
            }
            if (!empty(self::$settings_cache['excluded_brand_ids'])) {
                self::$settings_cache['exclusion_rules'][] = array(
                    'type' => 'brand',
                    'ids' => self::$settings_cache['excluded_brand_ids'],
                );
            }
        }

        return self::$settings_cache;
    }

    /**
     * Check whether cashback must be disabled for discounted (sale) product.
     *
     * @param WC_Product $product Product object
     * @return bool
     */
    private static function is_discounted_product($product, $line_subtotal = null, $line_total = null) {
        if (!$product || !is_object($product)) {
            // Even if product object is missing, line subtotal/total can indicate discount.
            if ($line_subtotal !== null && $line_total !== null && floatval($line_subtotal) > floatval($line_total)) {
                return true;
            }
            return false;
        }

        if (method_exists($product, 'is_on_sale') && $product->is_on_sale()) {
            return true;
        }

        $regular_price = method_exists($product, 'get_regular_price') ? floatval($product->get_regular_price()) : 0;
        $sale_price = method_exists($product, 'get_sale_price') ? floatval($product->get_sale_price()) : 0;
        $line_discounted = ($line_subtotal !== null && $line_total !== null && floatval($line_subtotal) > floatval($line_total));

        return $line_discounted || ($regular_price > 0 && $sale_price > 0 && $sale_price < $regular_price);
    }

    /**
     * Return product IDs that are attached to courses in SmartLearn LMS.
     *
     * @return array
     */
    public static function get_course_product_ids() {
        if (self::$course_product_ids !== null) {
            return self::$course_product_ids;
        }

        $ids = array();

        if (post_type_exists('smartlearn_course')) {
            $course_posts = get_posts(array(
                'post_type'      => 'smartlearn_course',
                'post_status'    => array('publish', 'draft', 'pending', 'future', 'private'),
                'numberposts'    => -1,
                'fields'         => 'ids',
                'meta_key'       => '_smartlearn_course_product_id',
                'meta_compare'   => 'EXISTS',
                'suppress_filters' => false,
            ));

            foreach ((array) $course_posts as $course_id) {
                $product_id = absint(get_post_meta($course_id, '_smartlearn_course_product_id', true));
                if ($product_id > 0) {
                    $ids[] = $product_id;
                }
            }
        }

        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        self::$course_product_ids = apply_filters('wcs_course_product_ids', $ids);

        return self::$course_product_ids;
    }

    /**
     * Check whether a WooCommerce product is used as a course product.
     *
     * @param int $product_id Product ID.
     * @return bool
     */
    public static function is_course_product($product_id) {
        $product_id = absint($product_id);
        if ($product_id <= 0) {
            return false;
        }

        return in_array($product_id, self::get_course_product_ids(), true);
    }

    /**
     * Get cashback percentage for a given order subtotal
     *
     * @param float $subtotal Order subtotal
     * @return float Cashback percentage
     */
    public static function get_percentage($subtotal) {
        $subtotal = floatval($subtotal);
        $settings = self::get_settings();
        $tier_3_threshold = floatval($settings['tier_3_threshold']);
        $tier_3_percentage = floatval($settings['tier_3_percentage']);
        $tier_2_threshold = floatval($settings['tier_2_threshold']);
        $tier_2_percentage = floatval($settings['tier_2_percentage']);
        $tier_1_threshold = floatval($settings['tier_1_threshold']);
        $tier_1_percentage = floatval($settings['tier_1_percentage']);

        // Check from highest tier to lowest
        if ($subtotal >= $tier_3_threshold && $tier_3_percentage > 0) {
            return $tier_3_percentage;
        }
        if ($subtotal >= $tier_2_threshold && $tier_2_percentage > 0) {
            return $tier_2_percentage;
        }
        if ($subtotal >= $tier_1_threshold && $tier_1_percentage > 0) {
            return $tier_1_percentage;
        }

        return 0;
    }

    /**
     * Calculate cashback amount for a given subtotal or current cart
     * Now supports per-item brand logic if enabled in settings
     *
     * @param float $subtotal Order subtotal (fallback or base)
     * @param WC_Order|null $order Optional order object for order-specific calculation
     * @param float $cashback_used Optional amount of cashback used in this order
     * @return float Cashback amount
     */
    public static function calculate($subtotal, $order = null, $cashback_used = 0) {
        $cashback_used = floatval($cashback_used);
        $settings = self::get_settings();
        $disable_earning_when_using_cashback = isset($settings['disable_earning_when_using_cashback']) && $settings['disable_earning_when_using_cashback'] === 'yes';

        // If order object is provided, also check its meta
        if ($order instanceof WC_Order && $cashback_used <= 0) {
            $cashback_used = floatval($order->get_meta('_wcs_cashback_used', true));
        }

        if ($cashback_used > 0 && $disable_earning_when_using_cashback) {
            return 0;
        }

        if (isset($settings['enabled']) && $settings['enabled'] !== 'yes') {
            return 0;
        }

        // Check for applied coupons
        if ($order instanceof WC_Order) {
            $coupons = $order->get_coupon_codes();
            if (!empty($coupons)) {
                return 0;
            }
        } elseif (function_exists('WC') && WC()->cart) {
            $applied_coupons = WC()->cart->get_applied_coupons();
            if (!empty($applied_coupons)) {
                return 0;
            }
        }

        $use_brands = isset($settings['use_brands_logic']) && $settings['use_brands_logic'] === 'yes';
        $exclude_sale_items = isset($settings['exclude_sale_items']) && $settings['exclude_sale_items'] === 'yes';
        $allow_course_cashback = isset($settings['allow_course_cashback']) && $settings['allow_course_cashback'] === 'yes';
        $exclusion_rules = isset($settings['exclusion_rules']) ? (array) $settings['exclusion_rules'] : array();
        $subtotal = floatval($subtotal);
        $cashback_used = floatval($cashback_used);

        // --- Build item list for both modes to support sale exclusions ---
        $items = array();

        // Calculate a pay ratio if cashback was used, to reduce basis for each product
        // E.g. Subtotal 2000, Used 40. Ratio = (2000-40)/2000 = 0.98
        // Each item price will be multiplied by 0.98 for cashback purposes.
        if ($order instanceof WC_Order) {
            $total_subtotal = floatval($order->get_subtotal());
        } elseif (function_exists('WC') && WC()->cart) {
            $total_subtotal = floatval(WC()->cart->get_subtotal());
        } else {
            $total_subtotal = $subtotal;
        }
        if ($cashback_used > 0 && $total_subtotal > 0) {
            $pay_ratio = ($total_subtotal - $cashback_used) / $total_subtotal;
        } else {
            $pay_ratio = 1;
        }

        // If we have an order object, use its items
        if ($order instanceof WC_Order) {
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if ($product) {
                    $items[] = array(
                        'id'         => $product->get_id(),
                        'product'    => $product,
                        'line_total' => floatval($item->get_total()) * $pay_ratio,
                        'is_sale'    => self::is_discounted_product($product, $item->get_subtotal(), $item->get_total()),
                    );
                }
            }
        } 
        // Otherwise try to use the current cart
        elseif (function_exists('WC') && WC()->cart) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                if (isset($cart_item['data'])) {
                    $product = $cart_item['data'];
                    $items[] = array(
                        'id'         => $cart_item['product_id'],
                        'product'    => $product,
                        'line_total' => floatval($cart_item['line_total']) * $pay_ratio,
                        'is_sale'    => self::is_discounted_product(
                            $product,
                            isset($cart_item['line_subtotal']) ? $cart_item['line_subtotal'] : null,
                            isset($cart_item['line_total']) ? $cart_item['line_total'] : null
                        ),
                    );
                }
            }
        }

        if (empty($items)) {
            // Fallback if no items found and we cannot determine discount state.
            $percentage = self::get_percentage($subtotal);
            return $percentage > 0 ? round($subtotal * ($percentage / 100), 2) : 0;
        }

        $eligible_items = array();
        $has_sale_items = false;
        $has_course_items = false;
        $has_excluded_items = false;
        $brand_taxonomy = isset($settings['brand_taxonomy']) ? $settings['brand_taxonomy'] : 'product_brand';
        foreach ($items as $item) {
            if (!empty($item['is_sale'])) {
                $has_sale_items = true;
            }
            if (self::is_course_product($item['id'])) {
                $has_course_items = true;
            }

            foreach ($exclusion_rules as $rule) {
                $rule_type = isset($rule['type']) ? $rule['type'] : 'category';
                $rule_ids = isset($rule['ids']) ? array_values(array_filter(array_map('absint', (array) $rule['ids']))) : array();
                if (empty($rule_ids)) {
                    continue;
                }

                if ($rule_type === 'product' && in_array($item['id'], $rule_ids, true)) {
                    $has_excluded_items = true;
                    break;
                }

                if ($rule_type === 'category') {
                    $product_category_ids = wc_get_product_cat_ids($item['id']);
                    if (!empty(array_intersect($product_category_ids, $rule_ids))) {
                        $has_excluded_items = true;
                        break;
                    }
                }

                if ($rule_type === 'brand' && taxonomy_exists($brand_taxonomy)) {
                    $product_brand_ids = wp_get_post_terms($item['id'], $brand_taxonomy, array('fields' => 'ids'));
                    if (!is_wp_error($product_brand_ids) && !empty(array_intersect($product_brand_ids, $rule_ids))) {
                        $has_excluded_items = true;
                        break;
                    }
                }
            }

            $eligible_items[] = $item;
        }

        // Strict rule: if sale-item exclusion is enabled and order/cart contains at least
        // one discounted product, cashback must not be accrued at all.
        if ($exclude_sale_items && $has_sale_items) {
            return 0;
        }

        // Course products never accrue cashback. If such a product is in the cart/order,
        // cashback for the purchase is blocked entirely.
        if (!$allow_course_cashback && $has_course_items) {
            return 0;
        }

        if ($has_excluded_items) {
            return 0;
        }

        if (empty($eligible_items)) {
            return 0;
        }

        // If brands logic is OFF, calculate cashback from eligible subtotal only.
        $eligible_subtotal = 0;
        foreach ($eligible_items as $item) {
            $eligible_subtotal += floatval($item['line_total']);
        }

        if (!$use_brands) {
            $percentage = self::get_percentage($eligible_subtotal);
            if ($percentage <= 0) {
                return 0;
            }
            return round($eligible_subtotal * ($percentage / 100), 2);
        }

        // --- BRANDS LOGIC ON ---
        $total_cashback = 0;
        $rules = isset($settings['brand_rules']) ? (array)$settings['brand_rules'] : array();
        
        // Strictly use the global tier percentage for all 'default' items
        $other_pct = self::get_percentage($eligible_subtotal);

        foreach ($eligible_items as $item) {
            $product_id = $item['id'];
            $line_total = floatval($item['line_total']);
            
            $matched_pct = null;

            // Priority 1: Product rules (Exceptions)
            foreach ($rules as $rule) {
                if (($rule['type'] ?? '') === 'product' && in_array($product_id, (array)($rule['ids'] ?? array()), true)) {
                    $matched_pct = floatval($rule['percentage']);
                    break;
                }
            }

            // Priority 2: Brand rules
            if ($matched_pct === null) {
                $product_brand_ids = wp_get_post_terms($product_id, $brand_taxonomy, array('fields' => 'ids'));
                if (!is_wp_error($product_brand_ids) && !empty($product_brand_ids)) {
                    foreach ($rules as $rule) {
                        if (($rule['type'] ?? '') === 'brand') {
                            $intersect = array_intersect($product_brand_ids, (array)($rule['ids'] ?? array()));
                            if (!empty($intersect)) {
                                $matched_pct = floatval($rule['percentage']);
                                break;
                            }
                        }
                    }
                }
            }

            $item_pct = ($matched_pct !== null) ? $matched_pct : $other_pct;
            $total_cashback += ($line_total * ($item_pct / 100));
        }

        return round($total_cashback, 2);
    }

    /**
     * Get usage limit percentage from settings
     *
     * @return float
     */
    public static function get_usage_limit_percentage() {
        $settings = self::get_settings();
        return floatval($settings['usage_limit_percentage']);
    }

    /**
     * Get max cashback limit from settings
     *
     * @return float
     */
    public static function get_max_cashback_limit() {
        $settings = self::get_settings();
        return floatval($settings['max_cashback_limit']);
    }

    /**
     * Get all tiers info for display
     *
     * @return array
     */
    public static function get_tiers_info() {
        $tiers = array();
        $settings = self::get_settings();

        $t1_thresh = floatval($settings['tier_1_threshold']);
        $t1_pct = floatval($settings['tier_1_percentage']);
        if ($t1_pct > 0) {
            $tiers[] = array('threshold' => $t1_thresh, 'percentage' => $t1_pct);
        }

        $t2_thresh = floatval($settings['tier_2_threshold']);
        $t2_pct = floatval($settings['tier_2_percentage']);
        if ($t2_pct > 0) {
            $tiers[] = array('threshold' => $t2_thresh, 'percentage' => $t2_pct);
        }

        $t3_thresh = floatval($settings['tier_3_threshold']);
        $t3_pct = floatval($settings['tier_3_percentage']);
        if ($t3_pct > 0) {
            $tiers[] = array('threshold' => $t3_thresh, 'percentage' => $t3_pct);
        }

        return $tiers;
    }

    /**
     * Whether earning cashback should be blocked when cashback was used.
     *
     * @return bool
     */
    public static function should_disable_earning_when_using_cashback() {
        $settings = self::get_settings();

        return isset($settings['disable_earning_when_using_cashback']) && $settings['disable_earning_when_using_cashback'] === 'yes';
    }
}
