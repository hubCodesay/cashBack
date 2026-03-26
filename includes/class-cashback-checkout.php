<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

/**
 * WCS_Cashback_Checkout
 *
 * Handles:
 * - Displaying cashback info in cart & checkout
 * - Applying cashback discount via WC fees
 * - Earning cashback on payment (only when NOT using cashback)
 * - AJAX endpoints for apply / remove cashback
 */
class WCS_Cashback_Checkout {
	private static $instance = null;

	/**
	 * Request-level settings cache.
	 *
	 * @var array|null
	 */
	private static $settings_cache = null;

	/** Track whether each context block was already rendered (prevent duplicates) */
	private $rendered = array('cart' => false, 'checkout' => false);

	public static function get_instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get the saved position settings from admin panel
	 */
	private function get_position_settings() {
		$settings = $this->get_settings();
		return array(
			'cart'     => isset($settings['cart_position']) ? $settings['cart_position'] : 'woocommerce_before_cart_totals',
			'checkout' => isset($settings['checkout_position']) ? $settings['checkout_position'] : 'woocommerce_review_order_before_payment',
		);
	}

	/**
	 * Return plugin settings cached for the current request.
	 *
	 * @return array
	 */
	private function get_settings() {
		if (self::$settings_cache !== null) {
			return self::$settings_cache;
		}

		$settings = get_option('wcs_cashback_settings');
		self::$settings_cache = is_array($settings) ? $settings : array();

		return self::$settings_cache;
	}

	private function __construct() {
		$positions = $this->get_position_settings();

		// ── Display blocks (dynamic position from settings) ──
		if ($positions['cart'] !== 'none') {
			add_action($positions['cart'], array($this, 'display_cashback_in_cart'));
		}
		if ($positions['checkout'] !== 'none') {
			add_action($positions['checkout'], array($this, 'display_cashback_in_checkout'));
		}

		// ── Potential earning info ──────────────────────────
		// These always attach to totals table rows (inside <table>)
		if ($positions['cart'] !== 'none') {
			add_action('woocommerce_cart_totals_after_order_total', array($this, 'display_potential_earning_cart'));
		}
		if ($positions['checkout'] !== 'none') {
			add_action('woocommerce_review_order_after_order_total', array($this, 'display_potential_earning_checkout'));
		}

		// ── Cart fee (discount) ─────────────────────────────
		add_action('woocommerce_cart_calculate_fees', array($this, 'apply_cashback_to_cart'));

		// ── Order meta & processing ─────────────────────────
		add_action('woocommerce_checkout_create_order', array($this, 'save_cashback_to_order'), 20, 2);
		// Fallback: also ensure meta is saved when order is created via store API (blocks checkout)
		add_action('woocommerce_store_api_checkout_order_processed', array($this, 'save_cashback_to_order_fallback'));

		// ── UNIVERSAL: multiple hooks for cashback earning ──
		// Different payment gateways trigger different hooks/statuses.
		// We hook into all common ones and use _wcs_cashback_processed to prevent double-processing.
		add_action('woocommerce_payment_complete', array($this, 'process_cashback_on_payment'));
		add_action('woocommerce_order_status_completed', array($this, 'process_cashback_on_payment'));
		add_action('woocommerce_order_status_processing', array($this, 'process_cashback_on_payment'));
		add_action('woocommerce_order_status_on-hold', array($this, 'process_cashback_on_payment'));
		// Universal fallback: catch ANY paid status transition
		add_action('woocommerce_order_status_changed', array($this, 'process_cashback_on_status_change'), 20, 3);

		// ── AJAX ────────────────────────────────────────────
		add_action('wp_ajax_wcs_apply_cashback', array($this, 'ajax_apply_cashback'));
		add_action('wp_ajax_nopriv_wcs_apply_cashback', array($this, 'ajax_apply_cashback'));
		add_action('wp_ajax_wcs_remove_cashback', array($this, 'ajax_remove_cashback'));
		add_action('wp_ajax_nopriv_wcs_remove_cashback', array($this, 'ajax_remove_cashback'));

		// Legacy endpoint kept for backward compatibility
		add_action('wp_ajax_wcs_set_cashback', array($this, 'ajax_apply_cashback'));
		add_action('wp_ajax_nopriv_wcs_set_cashback', array($this, 'ajax_apply_cashback'));

		// ── Clear session after order ───────────────────────
		add_action('woocommerce_thankyou', array($this, 'clear_session_after_order'));

		// ── Blocks fallback (wp_footer) ─────────────────────
		add_action('wp_footer', array($this, 'render_blocks_fallback'));

		// ── AJAX for blocks mode data refresh ───────────────
		add_action('wp_ajax_wcs_get_cashback_data', array($this, 'ajax_get_cashback_data'));
		add_action('wp_ajax_nopriv_wcs_get_cashback_data', array($this, 'ajax_get_cashback_data'));
	}

	/* ═══════════════════════════════════════════════════════
	 *  DISPLAY — Cart (with duplicate guard)
	 * ═══════════════════════════════════════════════════════ */
	public function display_cashback_in_cart() {
		static $done = false;
		if ($done) return;
		$done = true;
		$this->render_cashback_block('cart');
	}

	/* ═══════════════════════════════════════════════════════
	 *  DISPLAY — Checkout (with duplicate guard)
	 * ═══════════════════════════════════════════════════════ */
	public function display_cashback_in_checkout() {
		static $done = false;
		if ($done) return;
		$done = true;
		$this->render_cashback_block('checkout');
	}

	/* ═══════════════════════════════════════════════════════
	 *  DISPLAY — Potential earning (shown after order total)
	 * ═══════════════════════════════════════════════════════ */
	public function display_potential_earning_cart() {
		$this->render_potential_earning();
	}
	public function display_potential_earning_checkout() {
		$this->render_potential_earning();
	}

	/* ───────────────────────────────────────────────────────
	 *  Render potential earning row
	 * ─────────────────────────────────────────────────────── */
	private function render_potential_earning() {
		if (!class_exists('WCS_Cashback_Calculator') || !WC()->cart) {
			return;
		}

		$subtotal = floatval(WC()->cart->get_subtotal());
		$applied  = $this->get_applied_amount();

		// Hide if coupons are applied
		if (!empty(WC()->cart->get_applied_coupons())) {
			return;
		}

		$calculation_base = max(0, $subtotal - $applied);
		$potential = WCS_Cashback_Calculator::calculate($calculation_base, null, $applied);
		
		// Calculate effective percentage for display
		$effective_pct = ($calculation_base > 0) ? round(($potential / $calculation_base) * 100, 1) : 0;

		echo '<tr class="wcs-potential-earning-row">';
		echo '<th>' . __('Кешбек з цього замовлення', 'woo-cashback-system') . '</th>';

		if ($potential > 0) {
			echo '<td><span class="wcs-earn-amount">+' . wc_price($potential) . '</span> <small style="color:#999;">(' . $effective_pct . '%)</small></td>';
		} elseif ($applied > 0) {
			echo '<td><span class="wcs-no-earning">' . __('Не нараховується', 'woo-cashback-system') . '</span></td>';
		} elseif ($this->cart_has_course_products()) {
			echo '<td><span class="wcs-no-earning">' . __('У кошику є курс, на курси кешбек не нараховується.', 'woo-cashback-system') . '</span></td>';
		} elseif ($this->cart_has_excluded_categories()) {
			echo '<td><span class="wcs-no-earning">' . __('У кошику є товар з виключеної категорії, кешбек не нараховується.', 'woo-cashback-system') . '</span></td>';
		} elseif ($this->cart_has_excluded_brands()) {
			echo '<td><span class="wcs-no-earning">' . __('У кошику є товар виключеного бренду, кешбек не нараховується.', 'woo-cashback-system') . '</span></td>';
		} elseif ($this->cart_has_discounted_products()) {
			echo '<td><span class="wcs-no-earning">' . __('Товар у вас зі знижкою, він не проходить для кешбеку.', 'woo-cashback-system') . '</span></td>';
		} else {
			// Show next tier info so customer knows how much more to spend
			$next_tier = $this->get_next_tier_info($calculation_base);
			if ($next_tier) {
				$diff = $next_tier['threshold'] - $calculation_base;
				echo '<td><span class="wcs-earn-hint">'
					. sprintf(
						__('Додайте ще %s і отримайте %s%% кешбеку', 'woo-cashback-system'),
						wc_price($diff),
						$next_tier['percentage']
					)
					. '</span></td>';
			} else {
				echo '<td><span class="wcs-no-earning">—</span></td>';
			}
		}
		echo '</tr>';
	}

	/**
	 * Get info about the next (lowest) tier above the given subtotal
	 * Returns array('threshold' => ..., 'percentage' => ...) or null if no tier above
	 */
	private function get_next_tier_info($subtotal) {
		if (!class_exists('WCS_Cashback_Calculator')) {
			return null;
		}

		$tiers = WCS_Cashback_Calculator::get_tiers_info();
		if (empty($tiers)) {
			return null;
		}

		// Sort by threshold ascending
		usort($tiers, function($a, $b) {
			return $a['threshold'] - $b['threshold'];
		});

		// Find the first tier whose threshold is above subtotal
		foreach ($tiers as $tier) {
			if ($subtotal < $tier['threshold']) {
				return $tier;
			}
		}

		return null; // already at or above all thresholds
	}

	/**
	 * Return true if sale-item exclusion is enabled in admin settings.
	 */
	private function is_sale_exclusion_enabled() {
		$settings = $this->get_settings();
		return is_array($settings) && isset($settings['exclude_sale_items']) && $settings['exclude_sale_items'] === 'yes';
	}

	/**
	 * Check if cart contains at least one discounted/sale product.
	 */
	private function cart_has_discounted_products() {
		if (!$this->is_sale_exclusion_enabled() || !WC()->cart) {
			return false;
		}

		foreach (WC()->cart->get_cart() as $cart_item) {
			$product = isset($cart_item['data']) ? $cart_item['data'] : null;
			$is_sale = $product && method_exists($product, 'is_on_sale') ? $product->is_on_sale() : false;
			$line_discounted = isset($cart_item['line_subtotal'], $cart_item['line_total']) && floatval($cart_item['line_subtotal']) > floatval($cart_item['line_total']);

			if ($is_sale || $line_discounted) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if cart contains at least one product linked to a course.
	 */
	private function cart_has_course_products() {
		if (!WC()->cart || !class_exists('WCS_Cashback_Calculator')) {
			return false;
		}

		foreach (WC()->cart->get_cart() as $cart_item) {
			$product_id = isset($cart_item['product_id']) ? absint($cart_item['product_id']) : 0;
			if ($product_id > 0 && WCS_Cashback_Calculator::is_course_product($product_id)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if cart contains at least one product from excluded categories.
	 */
	private function cart_has_excluded_categories() {
		if (!WC()->cart) {
			return false;
		}

		$settings = $this->get_settings();
		$excluded_category_ids = isset($settings['excluded_category_ids']) ? array_filter(array_map('absint', (array) $settings['excluded_category_ids'])) : array();
		if (empty($excluded_category_ids)) {
			return false;
		}

		foreach (WC()->cart->get_cart() as $cart_item) {
			$product_id = isset($cart_item['product_id']) ? absint($cart_item['product_id']) : 0;
			if ($product_id > 0) {
				$product_category_ids = wc_get_product_cat_ids($product_id);
				if (!empty(array_intersect($product_category_ids, $excluded_category_ids))) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check if cart contains at least one product from excluded brands.
	 */
	private function cart_has_excluded_brands() {
		if (!WC()->cart) {
			return false;
		}

		$settings = $this->get_settings();
		$excluded_brand_ids = isset($settings['excluded_brand_ids']) ? array_filter(array_map('absint', (array) $settings['excluded_brand_ids'])) : array();
		$brand_taxonomy = isset($settings['brand_taxonomy']) ? sanitize_key($settings['brand_taxonomy']) : 'product_brand';
		if (empty($excluded_brand_ids) || !taxonomy_exists($brand_taxonomy)) {
			return false;
		}

		foreach (WC()->cart->get_cart() as $cart_item) {
			$product_id = isset($cart_item['product_id']) ? absint($cart_item['product_id']) : 0;
			if ($product_id > 0) {
				$product_brand_ids = wp_get_post_terms($product_id, $brand_taxonomy, array('fields' => 'ids'));
				if (!is_wp_error($product_brand_ids) && !empty(array_intersect($product_brand_ids, $excluded_brand_ids))) {
					return true;
				}
			}
		}

		return false;
	}

	/* ═══════════════════════════════════════════════════════
	 *  Render main cashback block (cart & checkout)
	 * ═══════════════════════════════════════════════════════ */
	private function render_cashback_block($context = 'cart') {
		// ── Duplicate guard (global-scope flag) ──
		$flag_key = 'wcs_rendered_' . $context;
		if (!empty($GLOBALS[$flag_key])) {
			return; // already rendered in this request
		}
		$GLOBALS[$flag_key] = true;

		if (!function_exists('wc_price')) {
			return;
		}

		// Hide block if coupons are applied
		if (WC()->cart && !empty(WC()->cart->get_applied_coupons())) {
			return;
		}

		$is_logged = is_user_logged_in();
		$user_id   = $is_logged ? get_current_user_id() : 0;

		// Get balance
		$balance = 0.00;
		if ($is_logged && class_exists('WCS_Cashback_Database')) {
			$data    = WCS_Cashback_Database::get_user_balance($user_id);
			$balance = $data ? floatval($data->balance) : 0.00;
		}

		// Cart subtotal for max calculations
		$cart_subtotal = 0.00;
		if (WC()->cart) {
			$cart_subtotal = floatval(WC()->cart->get_subtotal());
		}

		// Usage limit from settings
		$usage_pct   = class_exists('WCS_Cashback_Calculator') ? WCS_Cashback_Calculator::get_usage_limit_percentage() : 100;
		$max_allowed = min($balance, round($cart_subtotal * ($usage_pct / 100), 2));

		// Currently applied
		$applied = $this->get_applied_amount();

		// ── Render ──
		?>
		<div class="wcs-cashback-block" id="wcs-cashback-block-<?php echo esc_attr($context); ?>">
			<div class="wcs-cb-header">
				<span class="wcs-cb-icon">💰</span>
				<span class="wcs-cb-title"><?php _e('Кешбек', 'woo-cashback-system'); ?></span>
			</div>

			<?php if (!$is_logged) : ?>
				<p class="wcs-cb-login-msg">
					<?php printf(
						__('🔒 <a href="%s">Увійдіть</a>, щоб використати кешбек', 'woo-cashback-system'),
						esc_url(wp_login_url(wc_get_page_permalink('cart')))
					); ?>
				</p>
			<?php elseif ($balance <= 0) : ?>
				<div class="wcs-cb-balance-row">
					<span class="wcs-cb-label"><?php _e('Ваш баланс:', 'woo-cashback-system'); ?></span>
					<span class="wcs-cb-value"><?php echo wc_price(0); ?></span>
				</div>
				<p class="wcs-cb-empty"><?php _e('У вас поки немає накопиченого кешбеку.', 'woo-cashback-system'); ?></p>
			<?php else : ?>
				<div class="wcs-cb-balance-row">
					<span class="wcs-cb-label"><?php _e('Ваш баланс:', 'woo-cashback-system'); ?></span>
					<span class="wcs-cb-value wcs-cb-has-balance"><?php echo wc_price($balance); ?></span>
				</div>

				<?php if ($applied > 0) : ?>
					<!-- Cashback applied state -->
					<div class="wcs-cb-applied" id="wcs-cb-applied-<?php echo esc_attr($context); ?>">
						<div class="wcs-cb-applied-row">
							<span>✅ <?php _e('Використано:', 'woo-cashback-system'); ?></span>
							<span class="wcs-cb-applied-amount">-<?php echo wc_price($applied); ?></span>
						</div>
						<button type="button" class="wcs-cb-remove-btn" data-context="<?php echo esc_attr($context); ?>">
							<?php _e('Скасувати', 'woo-cashback-system'); ?>
						</button>
					</div>
					<?php 
					$subtotal = floatval(WC()->cart->get_subtotal());
					$remaining = max(0, $subtotal - $applied);
					$potential_on_remaining = WCS_Cashback_Calculator::calculate($remaining, null, $applied);
					
					if ($potential_on_remaining > 0) :
					?>
						<p class="wcs-cb-earn-on-remaining">
							✨ <?php printf(__('Ви отримаєте ще %s кешбеку на залишкову суму до оплати', 'woo-cashback-system'), '<strong>'.wc_price($potential_on_remaining).'</strong>'); ?>
						</p>
					<?php endif; ?>
				<?php else : ?>
					<!-- Cashback input form -->
					<div class="wcs-cb-form" id="wcs-cb-form-<?php echo esc_attr($context); ?>">
						<div class="wcs-cb-input-row">
							<label for="wcs-amount-<?php echo esc_attr($context); ?>">
								<?php _e('Сума:', 'woo-cashback-system'); ?>
							</label>
							<input
								type="number"
								id="wcs-amount-<?php echo esc_attr($context); ?>"
								class="wcs-cb-input"
								step="0.01"
								min="0"
								max="<?php echo esc_attr($max_allowed); ?>"
								value="<?php echo esc_attr($max_allowed); ?>"
								placeholder="0.00"
							/>
							<button type="button" class="wcs-cb-apply-btn" data-context="<?php echo esc_attr($context); ?>">
								<?php _e('Використати', 'woo-cashback-system'); ?>
							</button>
						</div>
						<small class="wcs-cb-max-info">
							<?php printf(
								__('Максимум: %s (до %s%% від суми замовлення)', 'woo-cashback-system'),
								wc_price($max_allowed),
								$usage_pct
							); ?>
						</small>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="wcs-cb-message" id="wcs-cb-message-<?php echo esc_attr($context); ?>" style="display:none;"></div>
		</div>
		<?php
	}

	/* ═══════════════════════════════════════════════════════
	 *  Display potential cashback earning (shortcode support)
	 * ═══════════════════════════════════════════════════════ */
	public function display_potential_cashback_earning() {
		if (!class_exists('WCS_Cashback_Calculator') || !WC()->cart) {
			return;
		}

		// Hide if coupons are applied
		if (!empty(WC()->cart->get_applied_coupons())) {
			return;
		}

		$subtotal    = floatval(WC()->cart->get_subtotal());
		$applied     = $this->get_applied_amount();
		$calculation_base = max(0, $subtotal - $applied);
		$potential   = WCS_Cashback_Calculator::calculate($calculation_base, null, $applied);
		$percentage  = ($subtotal > 0) ? round(($potential / $subtotal) * 100, 1) : 0;
		$applied     = $this->get_applied_amount();
		echo '<div class="wcs-potential-cashback">';
		echo '<strong>' . __('Потенційний кешбек:', 'woo-cashback-system') . '</strong> ';
		if ($potential > 0) {
			echo wc_price($potential) . ' (' . $percentage . '%)';
		} elseif ($this->cart_has_course_products()) {
			echo __('У кошику є курс, на курси кешбек не нараховується.', 'woo-cashback-system');
		} elseif ($this->cart_has_excluded_categories()) {
			echo __('У кошику є товар з виключеної категорії, кешбек не нараховується.', 'woo-cashback-system');
		} elseif ($this->cart_has_excluded_brands()) {
			echo __('У кошику є товар виключеного бренду, кешбек не нараховується.', 'woo-cashback-system');
		} elseif ($this->cart_has_discounted_products()) {
			echo __('Товар у вас зі знижкою, він не проходить для кешбеку.', 'woo-cashback-system');
		} else {
			echo wc_price(0);
		}
		echo '</div>';
	}

	/* ═══════════════════════════════════════════════════════
	 *  BLOCKS FALLBACK — wp_footer rendering for block-based cart/checkout
	 * ═══════════════════════════════════════════════════════ */
	public function render_blocks_fallback() {
		if (!function_exists('is_cart') || !function_exists('is_checkout')) return;
		if (!is_cart() && !is_checkout()) return;
		if (!function_exists('wc_price')) return;

		$context = is_cart() ? 'cart' : 'checkout';
		$flag_key = 'wcs_rendered_' . $context;
		$classic_rendered = !empty($GLOBALS[$flag_key]);

		// Render hidden cashback block if classic hooks didn't fire
		if (!$classic_rendered) {
			echo '<div id="wcs-blocks-fallback" style="display:none;" data-context="' . esc_attr($context) . '">';
			$this->render_cashback_block($context);
			echo '</div>';
		}

		// Output earning data as JSON for JS (both modes)
		$this->render_earning_data_json($context);
	}

	/**
	 * Output earning data as JSON script tag for JS consumption
	 */
	private function render_earning_data_json($context = 'cart') {
		if (!class_exists('WCS_Cashback_Calculator') || !WC()->cart) return;

		$subtotal  = floatval(WC()->cart->get_subtotal());
		$applied   = $this->get_applied_amount();
		$calculation_base = max(0, $subtotal - $applied);
		$potential  = WCS_Cashback_Calculator::calculate($calculation_base, null, $applied);
		$percentage = ($calculation_base > 0 && $potential > 0) ? round(($potential / $calculation_base) * 100, 1) : 0;

		$has_coupons = !empty(WC()->cart->get_applied_coupons());
		$has_course_products = $this->cart_has_course_products();
		$has_excluded_categories = $this->cart_has_excluded_categories();
		$has_excluded_brands = $this->cart_has_excluded_brands();
		$has_discounted_products = $this->cart_has_discounted_products();

		$earning_html = '';
		if ($has_coupons) {
			$earning_html = ''; // Hidden if coupons applied
		} elseif ($potential > 0) {
			$earning_html = '<div class="wcs-potential-earning-block">'
				. '<span class="wcs-earning-label">' . __('Кешбек з цього замовлення', 'woo-cashback-system') . '</span>'
				. '<span class="wcs-earn-amount">+' . wc_price($potential) . '</span> <small>(' . $percentage . '%)</small>'
				. '</div>';
		} elseif ($applied > 0) {
			$earning_html = '<div class="wcs-potential-earning-block">'
				. '<span class="wcs-earning-label">' . __('Кешбек з цього замовлення', 'woo-cashback-system') . '</span>'
				. '<span class="wcs-no-earning">' . __('Не нараховується', 'woo-cashback-system') . '</span>'
				. '</div>';
		} elseif ($has_course_products) {
			$earning_html = '<div class="wcs-potential-earning-block">'
				. '<span class="wcs-earning-label">' . __('Кешбек з цього замовлення', 'woo-cashback-system') . '</span>'
				. '<span class="wcs-no-earning">' . __('У кошику є курс, на курси кешбек не нараховується.', 'woo-cashback-system') . '</span>'
				. '</div>';
		} elseif ($has_excluded_categories) {
			$earning_html = '<div class="wcs-potential-earning-block">'
				. '<span class="wcs-earning-label">' . __('Кешбек з цього замовлення', 'woo-cashback-system') . '</span>'
				. '<span class="wcs-no-earning">' . __('У кошику є товар з виключеної категорії, кешбек не нараховується.', 'woo-cashback-system') . '</span>'
				. '</div>';
		} elseif ($has_excluded_brands) {
			$earning_html = '<div class="wcs-potential-earning-block">'
				. '<span class="wcs-earning-label">' . __('Кешбек з цього замовлення', 'woo-cashback-system') . '</span>'
				. '<span class="wcs-no-earning">' . __('У кошику є товар виключеного бренду, кешбек не нараховується.', 'woo-cashback-system') . '</span>'
				. '</div>';
		} elseif ($has_discounted_products) {
			$earning_html = '<div class="wcs-potential-earning-block">'
				. '<span class="wcs-earning-label">' . __('Кешбек з цього замовлення', 'woo-cashback-system') . '</span>'
				. '<span class="wcs-no-earning">' . __('Товар у вас зі знижкою, він не проходить для кешбеку.', 'woo-cashback-system') . '</span>'
				. '</div>';
		} else {
			// Show next tier hint
			$next_tier = $this->get_next_tier_info($subtotal);
			if ($next_tier) {
				$diff = $next_tier['threshold'] - $subtotal;
				$earning_html = '<div class="wcs-potential-earning-block">'
					. '<span class="wcs-earning-label">' . __('Кешбек з цього замовлення', 'woo-cashback-system') . '</span>'
					. '<span class="wcs-earn-hint">'
					. sprintf(
						__('Додайте ще %s і отримайте %s%% кешбеку', 'woo-cashback-system'),
						wc_price($diff),
						$next_tier['percentage']
					)
					. '</span>'
					. '</div>';
			}
		}

		$data = array(
			'applied'      => $applied,
			'potential'    => $potential,
			'percentage'   => $percentage,
			'subtotal'     => $subtotal,
			'no_earn'      => ($applied > 0 || $has_coupons || $has_course_products || $has_excluded_categories || $has_excluded_brands || $has_discounted_products),
			'earning_html' => $earning_html,
		);

		echo '<script type="application/json" id="wcs-earning-data">' . wp_json_encode($data) . '</script>';
	}

	/* ═══════════════════════════════════════════════════════
	 *  AJAX — Get cashback data (for blocks mode refresh)
	 * ═══════════════════════════════════════════════════════ */
	public function ajax_get_cashback_data() {
		$nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
		if (!wp_verify_nonce($nonce, 'wcs_public_nonce')) {
			wp_send_json_error(array('message' => __('Невірний токен безпеки.', 'woo-cashback-system')));
		}

		if (!function_exists('wc_price')) {
			wp_send_json_error(array('message' => 'WooCommerce not loaded'));
		}

		$is_logged = is_user_logged_in();
		$user_id   = $is_logged ? get_current_user_id() : 0;
		$balance   = 0;

		if ($is_logged && class_exists('WCS_Cashback_Database')) {
			$data    = WCS_Cashback_Database::get_user_balance($user_id);
			$balance = $data ? floatval($data->balance) : 0;
		}

		$cart_subtotal = (WC()->cart) ? floatval(WC()->cart->get_subtotal()) : 0;
		$usage_pct     = class_exists('WCS_Cashback_Calculator') ? WCS_Cashback_Calculator::get_usage_limit_percentage() : 100;
		$max_allowed   = min($balance, round($cart_subtotal * ($usage_pct / 100), 2));
		$applied       = $this->get_applied_amount();
		$has_coupons   = (WC()->cart) ? !empty(WC()->cart->get_applied_coupons()) : false;
		$has_course_products = $this->cart_has_course_products();
		$has_excluded_categories = $this->cart_has_excluded_categories();
		$has_excluded_brands = $this->cart_has_excluded_brands();
		$has_discounted_products = $this->cart_has_discounted_products();

		$potential  = 0;
		$percentage = 0;
		if (class_exists('WCS_Cashback_Calculator') && !$has_coupons) {
			$calculation_base = max(0, $cart_subtotal - $applied);
			$potential  = WCS_Cashback_Calculator::calculate($calculation_base, null, $applied);
			$percentage = ($calculation_base > 0) ? round(($potential / $calculation_base) * 100, 1) : 0;
		}

		// Generate block HTML
		$context = isset($_POST['context']) ? sanitize_text_field($_POST['context']) : 'cart';
		ob_start();
		unset($GLOBALS['wcs_rendered_' . $context]);
		$this->render_cashback_block($context);
		$block_html = ob_get_clean();

		// Generate earning HTML
		$earning_html = '';
		if ($has_coupons) {
			$earning_html = ''; // Hidden if coupons applied
		} elseif ($potential > 0) {
			$earning_html = '<div class="wcs-potential-earning-block">'
				. '<span class="wcs-earning-label">' . __('Кешбек з цього замовлення', 'woo-cashback-system') . '</span>'
				. '<span class="wcs-earn-amount">+' . wc_price($potential) . '</span> <small>(' . $percentage . '%)</small>'
				. '</div>';
		} elseif ($has_course_products) {
			$earning_html = '<div class="wcs-potential-earning-block">'
				. '<span class="wcs-earning-label">' . __('Кешбек з цього замовлення', 'woo-cashback-system') . '</span>'
				. '<span class="wcs-no-earning">' . __('У кошику є курс, на курси кешбек не нараховується.', 'woo-cashback-system') . '</span>'
				. '</div>';
		} elseif ($has_excluded_categories) {
			$earning_html = '<div class="wcs-potential-earning-block">'
				. '<span class="wcs-earning-label">' . __('Кешбек з цього замовлення', 'woo-cashback-system') . '</span>'
				. '<span class="wcs-no-earning">' . __('У кошику є товар з виключеної категорії, кешбек не нараховується.', 'woo-cashback-system') . '</span>'
				. '</div>';
		} elseif ($has_excluded_brands) {
			$earning_html = '<div class="wcs-potential-earning-block">'
				. '<span class="wcs-earning-label">' . __('Кешбек з цього замовлення', 'woo-cashback-system') . '</span>'
				. '<span class="wcs-no-earning">' . __('У кошику є товар виключеного бренду, кешбек не нараховується.', 'woo-cashback-system') . '</span>'
				. '</div>';
		} elseif ($has_discounted_products) {
			$earning_html = '<div class="wcs-potential-earning-block">'
				. '<span class="wcs-earning-label">' . __('Кешбек з цього замовлення', 'woo-cashback-system') . '</span>'
				. '<span class="wcs-no-earning">' . __('Товар у вас зі знижкою, він не проходить для кешбеку.', 'woo-cashback-system') . '</span>'
				. '</div>';
		} else {
			// Show next tier hint
			$next_tier = $this->get_next_tier_info($cart_subtotal);
			if ($next_tier) {
				$diff = $next_tier['threshold'] - $cart_subtotal;
				$earning_html = '<div class="wcs-potential-earning-block">'
					. '<span class="wcs-earning-label">' . __('Кешбек з цього замовлення', 'woo-cashback-system') . '</span>'
					. '<span class="wcs-earn-hint">'
					. sprintf(
						__('Додайте ще %s і отримайте %s%% кешбеку', 'woo-cashback-system'),
						wc_price($diff),
						$next_tier['percentage']
					)
					. '</span>'
					. '</div>';
			}
		}

		wp_send_json_success(array(
			'is_logged_in'  => $is_logged,
			'balance'       => $balance,
			'potential'     => $potential,
			'percentage'    => $percentage,
			'applied'       => $applied,
			'max_allowed'   => $max_allowed,
			'no_earn'       => ($applied > 0 || $has_coupons || $has_course_products || $has_excluded_categories || $has_excluded_brands || $has_discounted_products),
			'block_html'    => $block_html,
			'earning_html'  => $earning_html,
		));
	}

	/* ═══════════════════════════════════════════════════════
	 *  CART FEE — apply cashback as negative fee
	 * ═══════════════════════════════════════════════════════ */
	public function apply_cashback_to_cart($cart) {
		if (is_admin() && !defined('DOING_AJAX')) {
			return;
		}
		if (!WC()->session) {
			return;
		}

		$amount = $this->get_applied_amount();
		if ($amount <= 0) {
			return;
		}

		// Disable cashback discount if coupons are applied
		if (!empty($cart->get_applied_coupons())) {
			return;
		}

		$user_id = get_current_user_id();
		if (!$user_id) {
			return;
		}

		// Re-validate against current balance
		if (class_exists('WCS_Cashback_Database')) {
			$balance_data = WCS_Cashback_Database::get_user_balance($user_id);
			$balance = $balance_data ? floatval($balance_data->balance) : 0;
			if ($amount > $balance) {
				$amount = $balance;
			}
		}

		if ($amount > 0) {
			$cart->add_fee(__('Кешбек', 'woo-cashback-system'), -1 * $amount);
		}
	}

	/* ═══════════════════════════════════════════════════════
	 *  AJAX — Apply cashback
	 * ═══════════════════════════════════════════════════════ */
	public function ajax_apply_cashback() {
		// Accept both old and new nonce keys
		$nonce = '';
		if (isset($_POST['nonce'])) {
			$nonce = sanitize_text_field($_POST['nonce']);
		} elseif (isset($_POST['wcs_nonce'])) {
			$nonce = sanitize_text_field($_POST['wcs_nonce']);
		} elseif (isset($_POST['wcs_set_cashback_nonce'])) {
			$nonce = sanitize_text_field($_POST['wcs_set_cashback_nonce']);
		}

		if (!wp_verify_nonce($nonce, 'wcs_public_nonce') && !wp_verify_nonce($nonce, 'wcs_set_cashback')) {
			wp_send_json_error(array('message' => __('Невірний токен безпеки.', 'woo-cashback-system')));
		}

		if (!is_user_logged_in()) {
			wp_send_json_error(array('message' => __('Потрібно увійти в акаунт.', 'woo-cashback-system')));
		}

		$amount  = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
		$user_id = get_current_user_id();

		if ($amount <= 0) {
			$this->set_applied_amount(0);
			wp_send_json_success(array('amount' => 0, 'message' => __('Кешбек скинуто.', 'woo-cashback-system')));
		}

		// Validate against balance
		$balance = 0;
		if (class_exists('WCS_Cashback_Database')) {
			$data    = WCS_Cashback_Database::get_user_balance($user_id);
			$balance = $data ? floatval($data->balance) : 0;
		}

		// Validate against cart subtotal & usage limit
		$cart_subtotal = (WC()->cart) ? floatval(WC()->cart->get_subtotal()) : 0;
		$usage_pct     = class_exists('WCS_Cashback_Calculator') ? WCS_Cashback_Calculator::get_usage_limit_percentage() : 100;
		$max_allowed   = min($balance, round($cart_subtotal * ($usage_pct / 100), 2));

		if ($amount > $max_allowed) {
			$amount = $max_allowed;
		}

		$this->set_applied_amount($amount);

		wp_send_json_success(array(
			'amount'  => $amount,
			'message' => sprintf(__('Використано %s кешбеку!', 'woo-cashback-system'), wc_price($amount)),
		));
	}

	/* ═══════════════════════════════════════════════════════
	 *  AJAX — Remove cashback
	 * ═══════════════════════════════════════════════════════ */
	public function ajax_remove_cashback() {
		$nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
		if (!wp_verify_nonce($nonce, 'wcs_public_nonce')) {
			wp_send_json_error(array('message' => __('Невірний токен безпеки.', 'woo-cashback-system')));
		}

		$this->set_applied_amount(0);

		wp_send_json_success(array(
			'amount'  => 0,
			'message' => __('Кешбек скасовано.', 'woo-cashback-system'),
		));
	}

	/* ═══════════════════════════════════════════════════════
	 *  ORDER — Save cashback meta before order is created
	 * ═══════════════════════════════════════════════════════ */
	public function save_cashback_to_order($order, $data) {
		$applied = $this->get_applied_amount();

		if ($applied > 0) {
			$order->update_meta_data('_wcs_cashback_used', $applied);
			$order->update_meta_data('_wcs_cashback_skip_earning', $this->should_skip_earning_when_cashback_used() ? 'yes' : 'no');
		} else {
			$order->update_meta_data('_wcs_cashback_used', 0);
			$order->update_meta_data('_wcs_cashback_skip_earning', 'no');
		}

		// Clear session
		$this->set_applied_amount(0);
	}

	/**
	 * Fallback: save cashback meta for Store API / Blocks checkout
	 * Called when woocommerce_store_api_checkout_order_processed fires
	 */
	public function save_cashback_to_order_fallback($order) {
		// Only run if meta was NOT already set by save_cashback_to_order
		$existing = $order->get_meta('_wcs_cashback_skip_earning', true);
		if ($existing !== '' && $existing !== null) {
			return; // already handled
		}

		$applied = $this->get_applied_amount();

		if ($applied > 0) {
			$order->update_meta_data('_wcs_cashback_used', $applied);
			$order->update_meta_data('_wcs_cashback_skip_earning', $this->should_skip_earning_when_cashback_used() ? 'yes' : 'no');
		} else {
			$order->update_meta_data('_wcs_cashback_used', 0);
			$order->update_meta_data('_wcs_cashback_skip_earning', 'no');
		}

		$order->save();
		$this->set_applied_amount(0);
	}

	/* ═══════════════════════════════════════════════════════
	 *  PAYMENT — Process cashback on completed payment
	 *
	 *  1. If customer USED cashback → deduct from balance, DO NOT earn new cashback
	 *  2. If customer did NOT use cashback → earn cashback based on tiers
	 * ═══════════════════════════════════════════════════════ */
	public function process_cashback_on_payment($order_id) {
		$order = wc_get_order($order_id);
		if (!$order) return;

		$user_id = $order->get_user_id();
		if (!$user_id) return;

		// Prevent double-processing
		if ($order->get_meta('_wcs_cashback_processed', true) === 'yes') {
			return;
		}

		if (!class_exists('WCS_Cashback_Database')) {
			return;
		}

		$cashback_used = floatval($order->get_meta('_wcs_cashback_used', true));
		$skip_earning  = $order->get_meta('_wcs_cashback_skip_earning', true);

		// ── UNIVERSAL FIX: if skip_earning meta is missing, default to 'no' ──
		// This handles cases where woocommerce_checkout_create_order didn't fire
		// (e.g. blocks checkout, store API, or custom gateway integrations)
		if ($skip_earning === '' || $skip_earning === null || $skip_earning === false) {
			// Check if there's a cashback fee in the order (negative fee = cashback used)
			$has_cashback_fee = false;
			foreach ($order->get_fees() as $fee) {
				if (strpos(strtolower($fee->get_name()), 'кешбек') !== false || strpos(strtolower($fee->get_name()), 'cashback') !== false) {
					if (floatval($fee->get_total()) < 0) {
						$has_cashback_fee = true;
						$cashback_used = abs(floatval($fee->get_total()));
					}
				}
			}

			if ($has_cashback_fee && $cashback_used > 0) {
				$order->update_meta_data('_wcs_cashback_used', $cashback_used);
			} else {
				$order->update_meta_data('_wcs_cashback_used', 0);
			}
		}

		// ── 1. Deduct used cashback ──
		if ($cashback_used > 0) {
			$balance_data = WCS_Cashback_Database::get_user_balance($user_id);
			$before = floatval($balance_data->balance);
			WCS_Cashback_Database::update_balance($user_id, $cashback_used, 'spent');
			$after  = floatval(WCS_Cashback_Database::get_user_balance($user_id)->balance);

			WCS_Cashback_Database::add_transaction(array(
				'user_id'          => $user_id,
				'order_id'         => $order_id,
				'transaction_type' => 'spent',
				'amount'           => $cashback_used,
				'balance_before'   => $before,
				'balance_after'    => $after,
				'order_total'      => floatval($order->get_total()),
				'description'      => sprintf('Використано кешбек в замовленні #%d', $order_id),
			));
		}

		// ── 2. Earn cashback ──
		if ($skip_earning !== 'yes' && class_exists('WCS_Cashback_Calculator')) {
			// Base calculation on Subtotal minus Used Cashback
			$subtotal = floatval($order->get_subtotal());
			$calculation_base = max(0, $subtotal - $cashback_used);
			
			$earned     = WCS_Cashback_Calculator::calculate($calculation_base, $order, $cashback_used);
			$percentage = ($calculation_base > 0) ? round(($earned / $calculation_base) * 100, 1) : 0;

			if ($earned > 0) {
				// Check max limit
				$max_limit   = WCS_Cashback_Calculator::get_max_cashback_limit();
				$current_balance_data = WCS_Cashback_Database::get_user_balance($user_id);
				$current_bal = floatval($current_balance_data->balance);

				if ($max_limit > 0 && ($current_bal + $earned) > $max_limit) {
					$earned = max(0, $max_limit - $current_bal);
				}

				if ($earned > 0) {
					$before = floatval(WCS_Cashback_Database::get_user_balance($user_id)->balance);
					WCS_Cashback_Database::update_balance($user_id, $earned, 'earned');
					$after  = floatval(WCS_Cashback_Database::get_user_balance($user_id)->balance);

					WCS_Cashback_Database::add_transaction(array(
						'user_id'             => $user_id,
						'order_id'            => $order_id,
						'transaction_type'    => 'earned',
						'amount'              => $earned,
						'balance_before'      => $before,
						'balance_after'       => $after,
						'order_total'         => floatval($order->get_total()),
						'cashback_percentage' => $percentage,
						'description'         => sprintf('Нараховано %s%% кешбек з замовлення #%d', $percentage, $order_id),
					));

					// Save earned amount on order
					$order->update_meta_data('_wcs_cashback_earned', $earned);

					// Fire hook for notifications
					do_action('wcs_cashback_earned', $user_id, $earned, $order_id);
				}
			}
		}

		// Mark as processed
		$order->update_meta_data('_wcs_cashback_processed', 'yes');
		$order->save();
	}

	/**
	 * Universal handler: process cashback on ANY status change to a paid status
	 * This catches custom statuses and edge cases missed by individual hooks
	 */
	public function process_cashback_on_status_change($order_id, $old_status, $new_status) {
		// List of statuses that count as "paid"
		$paid_statuses = array('processing', 'completed', 'on-hold');
		
		// Allow themes/plugins to add custom paid statuses
		$paid_statuses = apply_filters('wcs_cashback_paid_statuses', $paid_statuses);

		if (in_array($new_status, $paid_statuses)) {
			// process_cashback_on_payment already has double-processing protection
			$this->process_cashback_on_payment($order_id);
		}
	}

	/* ═══════════════════════════════════════════════════════
	 *  Clear session after thank-you page
	 * ═══════════════════════════════════════════════════════ */
	public function clear_session_after_order($order_id) {
		$this->set_applied_amount(0);
	}

	/* ═══════════════════════════════════════════════════════
	 *  Helpers — session amount
	 * ═══════════════════════════════════════════════════════ */
	private function get_applied_amount() {
		if (!WC()->session) return 0;
		return floatval(WC()->session->get('wcs_applied_cashback', 0));
	}

	private function set_applied_amount($amount) {
		if (!WC()->session) return;
		WC()->session->set('wcs_applied_cashback', floatval($amount));
		// Legacy key cleanup
		WC()->session->set('wcs_cashback_to_use', floatval($amount));
	}

	/**
	 * Whether earning cashback should be blocked when cashback was used.
	 *
	 * @return bool
	 */
	private function should_skip_earning_when_cashback_used() {
		if (class_exists('WCS_Cashback_Calculator')) {
			return WCS_Cashback_Calculator::should_disable_earning_when_using_cashback();
		}

		$settings = $this->get_settings();

		return isset($settings['disable_earning_when_using_cashback']) && $settings['disable_earning_when_using_cashback'] === 'yes';
	}
}
