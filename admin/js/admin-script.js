/**
 * WooCommerce Cashback System - Admin JavaScript
 */

jQuery(document).ready(function($) {
    'use strict';
    
    /**
     * Update user max limit
     */
    $('.wcs-update-limit').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var userId = button.data('user-id');
        var input = $('.wcs-user-max-limit[data-user-id="' + userId + '"]');
        var maxLimit = input.val();
        
        if (!maxLimit || maxLimit < 0) {
            alert('Please enter a valid maximum limit.');
            return;
        }
        
        // Show loading state
        button.prop('disabled', true).text('Updating...');
        
        $.ajax({
            url: wcs_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'wcs_update_user_balance',
                nonce: wcs_admin.nonce,
                user_id: userId,
                max_limit: maxLimit
            },
            success: function(response) {
                if (response.success) {
                    showNotification(response.data.message, 'success');
                } else {
                    showNotification(response.data.message, 'error');
                }
            },
            error: function() {
                showNotification('An error occurred. Please try again.', 'error');
            },
            complete: function() {
                button.prop('disabled', false).text('Update');
            }
        });
    });
    
    /**
     * Reset user balance
     */
    $('.wcs-reset-balance').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('Are you sure you want to reset this user\'s cashback balance? This action cannot be undone.')) {
            return;
        }
        
        var button = $(this);
        var userId = button.data('user-id');
        
        // Show loading state
        button.prop('disabled', true).text('Resetting...');
        
        $.ajax({
            url: wcs_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'wcs_reset_user_balance',
                nonce: wcs_admin.nonce,
                user_id: userId
            },
            success: function(response) {
                if (response.success) {
                    showNotification(response.data.message, 'success');
                    // Reload page after 1 second
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showNotification(response.data.message, 'error');
                    button.prop('disabled', false).text('Reset');
                }
            },
            error: function() {
                showNotification('An error occurred. Please try again.', 'error');
                button.prop('disabled', false).text('Reset');
            }
        });
    });
    
    /**
     * Show notification
     */
    function showNotification(message, type) {
        var notificationClass = type === 'success' ? 'notice-success' : 'notice-error';
        var notification = $('<div class="notice ' + notificationClass + ' is-dismissible"><p>' + message + '</p></div>');
        
        $('.wrap h1').after(notification);
        
        // Auto dismiss after 5 seconds
        setTimeout(function() {
            notification.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }
    
    /**
     * Confirm settings changes
     */
    $('form[action="options.php"]').on('submit', function(e) {
        var enabled = $('#enabled').is(':checked');
        
        if (!enabled) {
            if (!confirm('You are about to disable the cashback system. Users will not be able to earn or use cashback while it is disabled. Continue?')) {
                e.preventDefault();
                return false;
            }
        }
    });
    
    /**
     * Validate tier thresholds
     */
    $('input[name*="tier_"][name*="threshold"]').on('change', function() {
        var tier1 = parseFloat($('#tier_1_threshold').val()) || 0;
        var tier2 = parseFloat($('#tier_2_threshold').val()) || 0;
        var tier3 = parseFloat($('#tier_3_threshold').val()) || 0;
        
        if (tier2 < tier1) {
            alert('Tier 2 threshold must be greater than or equal to Tier 1 threshold.');
            $('#tier_2_threshold').val(tier1);
        }
        
        if (tier3 < tier2) {
            alert('Tier 3 threshold must be greater than or equal to Tier 2 threshold.');
            $('#tier_3_threshold').val(tier2);
        }
    });

    /**
     * Brands logic: Multi-Rule Repeater & AJAX Select2
     */
    var rulesContainer = $('.wcs-rules-list');
    var addRuleBtn = $('#wcs-add-rule');
    var brandTaxonomy = $('#brand_taxonomy');

    function initSelect2(row) {
        var select = row.find('.wcs-select2-ajax');
        var typeSelect = row.find('.rule-type-select');
        
        select.select2({
            ajax: {
                url: wcs_admin.ajax_url,
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    var type = typeSelect.val();
                    return {
                        action: type === 'brand' ? 'wcs_search_brands' : 'wcs_search_products',
                        nonce: wcs_admin.nonce,
                        term: params.term,
                        taxonomy: brandTaxonomy.val()
                    };
                },
                processResults: function (data) {
                    return { results: data };
                },
                cache: true
            },
            placeholder: 'Почніть вводити назву...',
            minimumInputLength: 2,
            width: '100%'
        });
    }

    // Initialize existing rows
    $('.wcs-rule-row').each(function() {
        initSelect2($(this));
    });

    // Add new rule row
    addRuleBtn.on('click', function() {
        var index = rulesContainer.children().length;
        var html = `
            <div class="wcs-rule-row" data-index="${index}">
                <div class="col-type">
                    <select name="wcs_cashback_settings[brand_rules][${index}][type]" class="rule-type-select">
                        <option value="brand">Бренд</option>
                        <option value="product">Товар (Виняток)</option>
                    </select>
                </div>
                <div class="col-select">
                    <select name="wcs_cashback_settings[brand_rules][${index}][ids][]" class="rule-ids-select wcs-select2-ajax" multiple style="width: 100%;"></select>
                </div>
                <div class="col-pct">
                    <input type="number" step="0.01" name="wcs_cashback_settings[brand_rules][${index}][percentage]" value="0" style="width: 70px;"> %
                </div>
                <div class="col-action">
                    <button type="button" class="button wcs-remove-rule" title="Видалити">❌</button>
                </div>
            </div>
        `;
        var newRow = $(html);
        rulesContainer.append(newRow);
        initSelect2(newRow);
    });

    // Remove rule row
    rulesContainer.on('click', '.wcs-remove-rule', function() {
        $(this).closest('.wcs-rule-row').remove();
        // Option-ally: re-index names, but PHP handles non-sequential indices fine.
    });

    // Clear selection when type changes
    rulesContainer.on('change', '.rule-type-select', function() {
        var row = $(this).closest('.wcs-rule-row');
        row.find('.wcs-select2-ajax').val(null).trigger('change');
    });

    // Refresh page when brand taxonomy changes
    brandTaxonomy.on('change', function() {
        if (confirm('Таксономія змінилася. Для коректної роботи потрібно перезавантажити сторінку. Продовжити?')) {
            var url = new URL(window.location.href);
            url.searchParams.set('tab', 'brands');
            url.searchParams.set('wcs_taxonomy', $(this).val());
            window.location.href = url.href;
        }
    });
});
