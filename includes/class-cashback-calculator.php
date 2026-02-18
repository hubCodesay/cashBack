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
     * Get cashback percentage for a given order subtotal
     *
     * @param float $subtotal Order subtotal
     * @return float Cashback percentage
     */
    public static function get_percentage($subtotal) {
        $subtotal = floatval($subtotal);

        // Get tier settings from WooCommerce options
        $tier_3_threshold = floatval(get_option('wcs_tier_3_threshold', 1500));
        $tier_3_percentage = floatval(get_option('wcs_tier_3_percentage', 7));
        $tier_2_threshold = floatval(get_option('wcs_tier_2_threshold', 1000));
        $tier_2_percentage = floatval(get_option('wcs_tier_2_percentage', 5));
        $tier_1_threshold = floatval(get_option('wcs_tier_1_threshold', 500));
        $tier_1_percentage = floatval(get_option('wcs_tier_1_percentage', 3));

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
     * @param float $subtotal Order subtotal (fallback)
     * @param WC_Order|null $order Optional order object for order-specific calculation
     * @return float Cashback amount
     */
    public static function calculate($subtotal, $order = null) {
        $settings = get_option('wcs_cashback_settings');
        $use_brands = isset($settings['use_brands_logic']) && $settings['use_brands_logic'] === 'yes';

        // If brands logic is OFF, use the old tier-based global percentage
        if (!$use_brands) {
            $percentage = self::get_percentage($subtotal);
            if ($percentage <= 0) {
                return 0;
            }
            return round($subtotal * ($percentage / 100), 2);
        }

        // --- BRANDS LOGIC ON ---
        $total_cashback = 0;
        $items = array();

        // If we have an order object, use its items
        if ($order instanceof WC_Order) {
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if ($product) {
                    $items[] = array(
                        'id'         => $product->get_id(),
                        'product'    => $product,
                        'line_total' => $item->get_total(),
                    );
                }
            }
        } 
        // Otherwise try to use the current cart
        elseif (function_exists('WC') && WC()->cart) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                if (isset($cart_item['data'])) {
                    $items[] = array(
                        'id'         => $cart_item['product_id'],
                        'product'    => $cart_item['data'],
                        'line_total' => $cart_item['line_total'],
                    );
                }
            }
        }

        // If we found specific items, calculate per-item
        if (!empty($items)) {
            $brand_taxonomy = isset($settings['brand_taxonomy']) ? $settings['brand_taxonomy'] : 'product_brand';
            $rules = isset($settings['brand_rules']) ? (array)$settings['brand_rules'] : array();
            
            // Strictly use the global tier percentage for all 'default' items
            $other_pct = self::get_percentage($subtotal);

            foreach ($items as $item) {
                $product_id = $item['id'];
                $product    = $item['product'];
                $line_total = floatval($item['line_total']);
                
                $matched_pct = null;

                // Priority 1: Product rules (Exceptions)
                foreach ($rules as $rule) {
                    if ($rule['type'] === 'product' && in_array($product_id, (array)$rule['ids'])) {
                        $matched_pct = floatval($rule['percentage']);
                        break;
                    }
                }

                // Priority 2: Brand rules
                if ($matched_pct === null) {
                    $product_brand_ids = wp_get_post_terms($product_id, $brand_taxonomy, array('fields' => 'ids'));
                    if (!is_wp_error($product_brand_ids) && !empty($product_brand_ids)) {
                        foreach ($rules as $rule) {
                            if ($rule['type'] === 'brand') {
                                $intersect = array_intersect($product_brand_ids, (array)$rule['ids']);
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
        } else {
            // Fallback if no items found
            $pct = self::get_percentage($subtotal);
            $total_cashback = $subtotal * ($pct / 100);
        }

        return round($total_cashback, 2);
    }

    /**
     * Get usage limit percentage from settings
     *
     * @return float
     */
    public static function get_usage_limit_percentage() {
        return floatval(get_option('wcs_usage_limit_percentage', 50));
    }

    /**
     * Get max cashback limit from settings
     *
     * @return float
     */
    public static function get_max_cashback_limit() {
        return floatval(get_option('wcs_max_cashback_limit', 10000));
    }

    /**
     * Get all tiers info for display
     *
     * @return array
     */
    public static function get_tiers_info() {
        $tiers = array();

        $t1_thresh = floatval(get_option('wcs_tier_1_threshold', 500));
        $t1_pct = floatval(get_option('wcs_tier_1_percentage', 3));
        if ($t1_pct > 0) {
            $tiers[] = array('threshold' => $t1_thresh, 'percentage' => $t1_pct);
        }

        $t2_thresh = floatval(get_option('wcs_tier_2_threshold', 1000));
        $t2_pct = floatval(get_option('wcs_tier_2_percentage', 5));
        if ($t2_pct > 0) {
            $tiers[] = array('threshold' => $t2_thresh, 'percentage' => $t2_pct);
        }

        $t3_thresh = floatval(get_option('wcs_tier_3_threshold', 1500));
        $t3_pct = floatval(get_option('wcs_tier_3_percentage', 7));
        if ($t3_pct > 0) {
            $tiers[] = array('threshold' => $t3_thresh, 'percentage' => $t3_pct);
        }

        return $tiers;
    }
}
