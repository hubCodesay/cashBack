/**
 * WooCommerce Cashback System — Public JS
 * Universal: works with both Classic (shortcode) and Block-based cart/checkout
 */
(function ($) {
	'use strict';

	if (typeof wcs_public === 'undefined') return;

	var ajaxUrl = wcs_public.ajax_url;
	var nonce   = wcs_public.nonce;
	var isBlocksMode = false;
	var debounceTimer = null;
	var ajaxInProgress = false;

	/* ═══════════════════════════════════════════════════════
	 *  INITIALIZATION
	 * ═══════════════════════════════════════════════════════ */
	$(document).ready(function () {
		isBlocksMode = detectBlocksMode();

		if (isBlocksMode) {
			// Wait for blocks to fully render, then inject
			setTimeout(initBlocksMode, 800);
		} else {
			removeDuplicateBlocks();
		}
	});

	// Classic mode: handle WC AJAX updates
	$(document.body).on('updated_checkout updated_cart_totals', function () {
		if (!isBlocksMode) {
			removeDuplicateBlocks();
		}
	});

	/* ═══════════════════════════════════════════════════════
	 *  DETECT BLOCKS vs CLASSIC MODE
	 * ═══════════════════════════════════════════════════════ */
	function detectBlocksMode() {
		return (
			$('.wp-block-woocommerce-cart').length > 0 ||
			$('.wp-block-woocommerce-checkout').length > 0 ||
			$('.wc-block-cart').length > 0 ||
			$('.wc-block-checkout').length > 0
		);
	}

	/* ═══════════════════════════════════════════════════════
	 *  BLOCKS MODE
	 * ═══════════════════════════════════════════════════════ */
	function initBlocksMode() {
		positionCashbackBlock();
		injectEarningInfo();
		startObserving();
	}

	/**
	 * Move the hidden fallback cashback block into the correct position
	 */
	function positionCashbackBlock() {
		if ($('.wcs-cashback-block:visible').length > 0) return; // already visible

		var $fallback = $('#wcs-blocks-fallback');
		if (!$fallback.length) return;

		var $block = $fallback.find('.wcs-cashback-block').first();
		if (!$block.length) return;

		var $anchor = findBlocksAnchor();
		if (!$anchor.length) return;

		$block.detach();
		$anchor.after($block);
		$fallback.remove();
	}

	/**
	 * Find the best DOM anchor point in WC Blocks layout
	 */
	function findBlocksAnchor() {
		var selectors = [
			// Cart: after the totals footer item (Total row)
			'.wc-block-components-totals-footer-item:last',
			'.wc-block-cart__totals-footer',
			// Cart: inside the sidebar
			'.wc-block-cart__totals .wp-block-woocommerce-cart-totals-block',
			'.wc-block-cart__sidebar',
			'.wc-block-cart__sidebar-container',
			// Checkout: order summary
			'.wp-block-woocommerce-checkout-order-summary-block',
			'.wc-block-checkout__sidebar',
			'.wc-block-components-checkout-step--summary',
			'.wc-block-components-order-summary',
			// Universal fallbacks
			'.wc-block-components-totals-wrapper',
			'.wc-block-checkout__order-summary-title',
			'.wc-block-cart__totals-title'
		];

		for (var i = 0; i < selectors.length; i++) {
			var $el = $(selectors[i]).first();
			if ($el.length) return $el;
		}
		return $();
	}

	/**
	 * Inject the potential earning info from JSON data
	 */
	function injectEarningInfo() {
		// Already in DOM?
		if ($('.wcs-potential-earning-block').length > 0) return;

		var $data = $('#wcs-earning-data');
		if (!$data.length) return;

		try {
			var data = JSON.parse($data.text());
		} catch (e) { return; }

		if (!data.earning_html) return;

		// Insert after cashback block, or after the blocks anchor
		var $after = $('.wcs-cashback-block:visible').last();
		if (!$after.length) $after = findBlocksAnchor();
		if ($after.length) {
			$after.after(data.earning_html);
		}
	}

	/* ═══════════════════════════════════════════════════════
	 *  MUTATION OBSERVER — re-inject after blocks re-render
	 * ═══════════════════════════════════════════════════════ */
	function startObserving() {
		var containerSelectors = [
			'.wp-block-woocommerce-cart',
			'.wp-block-woocommerce-checkout',
			'.wc-block-cart',
			'.wc-block-checkout',
			'.wc-block-components-sidebar', // Some themes use sidebar for everything
			'#remote-checkout-form'         // Custom checkout plugins
		];

		var container = null;
		for (var i = 0; i < containerSelectors.length; i++) {
			container = document.querySelector(containerSelectors[i]);
			if (container) break;
		}

		if (!container) {
			// Fallback: observe the main content area if we can't find a specific container
			container = document.querySelector('.entry-content') || document.querySelector('#content') || document.body;
		}

		var observer = new MutationObserver(function () {
			if (debounceTimer) clearTimeout(debounceTimer);
			debounceTimer = setTimeout(function () {
				var needsBlock   = ($('.wcs-cashback-block:visible').length === 0);
				var needsEarning = ($('.wcs-potential-earning-block').length === 0);

				if (needsBlock || needsEarning) {
					refreshViaAjax();
				}
			}, 800); // Slightly longer debounce for slower themes
		});

		observer.observe(container, { childList: true, subtree: true });
	}

	/**
	 * Fetch fresh cashback data via AJAX and re-inject blocks
	 */
	function refreshViaAjax() {
		if (ajaxInProgress) return;
		ajaxInProgress = true;

		var context = ($('.wp-block-woocommerce-cart, .wc-block-cart').length > 0) ? 'cart' : 'checkout';

		$.post(ajaxUrl, {
			action: 'wcs_get_cashback_data',
			nonce:  nonce,
			context: context
		}, function (res) {
			ajaxInProgress = false;
			if (!res.success) return;

			var d = res.data;

			// Re-inject cashback block
			if ($('.wcs-cashback-block:visible').length === 0 && d.block_html) {
				var $anchor = findBlocksAnchor();
				if ($anchor.length) {
					$anchor.after(d.block_html);
				}
			}

			// Re-inject earning block
			if ($('.wcs-potential-earning-block').length === 0 && d.earning_html) {
				var $after = $('.wcs-cashback-block:visible').last();
				if (!$after.length) $after = findBlocksAnchor();
				if ($after.length) {
					$after.after(d.earning_html);
				}
			}
		}).fail(function () {
			ajaxInProgress = false;
		});
	}

	/* ═══════════════════════════════════════════════════════
	 *  CLASSIC MODE — de-duplicate blocks
	 * ═══════════════════════════════════════════════════════ */
	function removeDuplicateBlocks() {
		var seen = {};
		$('.wcs-cashback-block').each(function () {
			var id = this.id || 'unnamed';
			if (seen[id]) {
				$(this).remove();
			} else {
				seen[id] = true;
			}
		});
	}

	/* ═══════════════════════════════════════════════════════
	 *  APPLY CASHBACK (works in both modes)
	 * ═══════════════════════════════════════════════════════ */
	$(document).on('click', '.wcs-cb-apply-btn', function (e) {
		e.preventDefault();
		var $btn     = $(this);
		var context  = $btn.data('context');
		var $input   = $('#wcs-amount-' + context);
		var amount   = parseFloat($input.val()) || 0;
		var $msg     = $('#wcs-cb-message-' + context);

		if (amount <= 0) {
			showMessage($msg, 'Введіть суму більшу за 0', 'error');
			return;
		}

		$btn.prop('disabled', true).text('…');

		$.post(ajaxUrl, {
			action: 'wcs_apply_cashback',
			nonce:  nonce,
			amount: amount
		}, function (res) {
			if (res.success) {
				showMessage($msg, res.data.message, 'success');
				refreshPage();
			} else {
				showMessage($msg, res.data.message || 'Помилка', 'error');
				$btn.prop('disabled', false).text('Використати');
			}
		}).fail(function () {
			showMessage($msg, 'Помилка з\'єднання', 'error');
			$btn.prop('disabled', false).text('Використати');
		});
	});

	/* ═══════════════════════════════════════════════════════
	 *  REMOVE CASHBACK (works in both modes)
	 * ═══════════════════════════════════════════════════════ */
	$(document).on('click', '.wcs-cb-remove-btn', function (e) {
		e.preventDefault();
		var $btn    = $(this);
		var context = $btn.data('context');
		var $msg    = $('#wcs-cb-message-' + context);

		$btn.prop('disabled', true).text('…');

		$.post(ajaxUrl, {
			action: 'wcs_remove_cashback',
			nonce:  nonce
		}, function (res) {
			if (res.success) {
				showMessage($msg, res.data.message, 'success');
				refreshPage();
			} else {
				showMessage($msg, res.data.message || 'Помилка', 'error');
				$btn.prop('disabled', false).text('Скасувати');
			}
		}).fail(function () {
			showMessage($msg, 'Помилка з\'єднання', 'error');
			$btn.prop('disabled', false).text('Скасувати');
		});
	});

	/* ═══════════════════════════════════════════════════════
	 *  USE-ALL SHORTCUT — click balance to auto-fill max
	 * ═══════════════════════════════════════════════════════ */
	$(document).on('click', '.wcs-cb-has-balance', function () {
		var $block = $(this).closest('.wcs-cashback-block');
		var $input = $block.find('.wcs-cb-input');
		if ($input.length) {
			$input.val($input.attr('max')).trigger('focus');
		}
	});

	/* ═══════════════════════════════════════════════════════
	 *  HELPERS
	 * ═══════════════════════════════════════════════════════ */
	function showMessage($el, text, type) {
		$el.text(text)
		   .removeClass('wcs-msg-success wcs-msg-error')
		   .addClass(type === 'success' ? 'wcs-msg-success' : 'wcs-msg-error')
		   .slideDown(200);

		setTimeout(function () { $el.slideUp(200); }, 4000);
	}

	function refreshPage() {
		if (isBlocksMode) {
			// Blocks mode: reload to get fresh server-rendered data
			window.location.reload();
			return;
		}

		// Classic: use WC built-in triggers
		if ($('form.checkout').length) {
			$(document.body).trigger('update_checkout');
		}
		if ($('.woocommerce-cart-form').length) {
			$(document.body).trigger('wc_update_cart');
			setTimeout(function () {
				window.location.reload();
			}, 500);
		}
	}

})(jQuery);
