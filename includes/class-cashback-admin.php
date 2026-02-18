<?php
/**
 * Admin Settings Class
 * Manages admin settings page and user management interface
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WCS_Cashback_Admin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'), 99);
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_wcs_update_user_balance', array($this, 'ajax_update_user_balance'));
        add_action('wp_ajax_wcs_reset_user_balance', array($this, 'ajax_reset_user_balance'));
        
        // New search endpoints for rules
        add_action('wp_ajax_wcs_search_brands',   array($this, 'ajax_search_brands'));
        add_action('wp_ajax_wcs_search_products', array($this, 'ajax_search_products'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Main menu page - Cashback as top-level menu
        add_menu_page(
            'Система Кешбеку',
            'Кешбек',
            'manage_woocommerce',
            'wcs-cashback',
            array($this, 'settings_page'),
            'dashicons-money-alt',
            55.5
        );
        
        // Dashboard submenu (rename first item)
        add_submenu_page(
            'wcs-cashback',
            'Налаштування Кешбеку',
            'Налаштування',
            'manage_woocommerce',
            'wcs-cashback',
            array($this, 'settings_page')
        );
        
        // Manage users submenu
        add_submenu_page(
            'wcs-cashback',
            'Управління Користувачами',
            'Користувачі',
            'manage_woocommerce',
            'wcs-cashback-users',
            array($this, 'users_page')
        );
        
        // VIP Discounts submenu
        add_submenu_page(
            'wcs-cashback',
            'VIP Знижки',
            '⭐ VIP Знижки',
            'manage_woocommerce',
            'wcs-cashback-vip',
            array($this, 'vip_discounts_page')
        );
        
        // Statistics submenu
        add_submenu_page(
            'wcs-cashback',
            'Статистика Кешбеку',
            'Статистика',
            'manage_woocommerce',
            'wcs-cashback-stats',
            array($this, 'statistics_page')
        );
        
        // User Details Page (Hidden)
        add_submenu_page(
            null, // Hidden from menu
            'Деталі Користувача',
            'Деталі',
            'manage_woocommerce',
            'wcs-cashback-user-detail',
            array($this, 'user_detail_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('wcs_cashback_settings_group', 'wcs_cashback_settings', array($this, 'sanitize_settings'));
    }
    
    /**
     * Sanitize settings
     */
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        // Get existing settings to prevent overwriting missing fields (due to tabs)
        $current_settings = get_option('wcs_cashback_settings');
        if (!is_array($current_settings)) {
            $current_settings = array();
        }
        
        $sanitized = $current_settings;
        
        // Update fields if they are present in input
        if (isset($input['enabled'])) $sanitized['enabled'] = 'yes';
        // Handle unchecked checkbox (if we are on the page where it exists)
        elseif (isset($_POST['_wp_http_referer']) && strpos($_POST['_wp_http_referer'], 'tab=general') !== false) $sanitized['enabled'] = 'no';
        
        if (isset($input['tier_1_threshold'])) $sanitized['tier_1_threshold'] = floatval($input['tier_1_threshold']);
        if (isset($input['tier_1_percentage'])) $sanitized['tier_1_percentage'] = floatval($input['tier_1_percentage']);
        if (isset($input['tier_2_threshold'])) $sanitized['tier_2_threshold'] = floatval($input['tier_2_threshold']);
        if (isset($input['tier_2_percentage'])) $sanitized['tier_2_percentage'] = floatval($input['tier_2_percentage']);
        if (isset($input['tier_3_threshold'])) $sanitized['tier_3_threshold'] = floatval($input['tier_3_threshold']);
        if (isset($input['tier_3_percentage'])) $sanitized['tier_3_percentage'] = floatval($input['tier_3_percentage']);
        if (isset($input['max_cashback_limit'])) $sanitized['max_cashback_limit'] = floatval($input['max_cashback_limit']);
        if (isset($input['usage_limit_percentage'])) $sanitized['usage_limit_percentage'] = floatval($input['usage_limit_percentage']);
        
        if (isset($input['enable_notifications'])) $sanitized['enable_notifications'] = 'yes';
        elseif (isset($_POST['_wp_http_referer']) && strpos($_POST['_wp_http_referer'], 'tab=general') !== false) $sanitized['enable_notifications'] = 'no';
        
        // Brand Rules (Repeater)
        if (isset($input['brand_rules']) && is_array($input['brand_rules'])) {
            $sanitized_rules = array();
            foreach ($input['brand_rules'] as $rule) {
                $sanitized_rules[] = array(
                    'type'       => in_array($rule['type'], array('brand', 'product')) ? $rule['type'] : 'brand',
                    'ids'        => isset($rule['ids']) ? array_map('intval', (array) $rule['ids']) : array(),
                    'percentage' => floatval($rule['percentage'])
                );
            }
            $sanitized['brand_rules'] = $sanitized_rules;
        } else {
            $sanitized['brand_rules'] = array();
        }

        if (isset($input['brand_taxonomy'])) $sanitized['brand_taxonomy'] = sanitize_text_field($input['brand_taxonomy']);
        if (isset($input['default_percentage'])) $sanitized['default_percentage'] = floatval($input['default_percentage']);
        if (isset($input['use_brands_logic'])) $sanitized['use_brands_logic'] = 'yes';
        else $sanitized['use_brands_logic'] = 'no';
        
        return $sanitized;
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
        
        $settings = get_option('wcs_cashback_settings');
        
        // Встановлення значень за замовчуванням, якщо налаштування ще не збережені
        if (!is_array($settings)) {
            $settings = array(
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
                // New display settings
                'cart_position' => 'woocommerce_cart_totals_before_order_total',
                'checkout_position' => 'woocommerce_review_order_before_payment',
                // New brand settings
                'use_brands_logic' => 'no',
                'brand_taxonomy' => 'product_brand',
                'brand_rules' => array(),
                'default_percentage' => 5
            );
        }
        
        // Ensure defaults for new settings exist (for existing installs)
        $settings['cart_position'] = isset($settings['cart_position']) ? $settings['cart_position'] : 'woocommerce_cart_totals_before_order_total';
        $settings['checkout_position'] = isset($settings['checkout_position']) ? $settings['checkout_position'] : 'woocommerce_review_order_before_payment';
        $settings['use_brands_logic'] = isset($settings['use_brands_logic']) ? $settings['use_brands_logic'] : 'no';
        $settings['brand_taxonomy'] = isset($settings['brand_taxonomy']) ? $settings['brand_taxonomy'] : 'product_brand';
        $settings['brand_rules'] = isset($settings['brand_rules']) ? (array)$settings['brand_rules'] : array();
        $settings['default_percentage'] = isset($settings['default_percentage']) ? floatval($settings['default_percentage']) : 5;
        
        ?>
        <div class="wrap">
            <h1>⚙️ Налаштування Системи Кешбеку</h1>
            <p class="description">Тут ви можете налаштувати всі параметри системи кешбеку для вашого магазину</p>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=wcs-cashback&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">🛠️ Загальні</a>
                <a href="?page=wcs-cashback&tab=brands" class="nav-tab <?php echo $active_tab == 'brands' ? 'nav-tab-active' : ''; ?>">🏷️ Бренди</a>
                <a href="?page=wcs-cashback&tab=display" class="nav-tab <?php echo $active_tab == 'display' ? 'nav-tab-active' : ''; ?>">🎨 Вигляд</a>
            </h2>
            
            <?php settings_errors('wcs_cashback_settings'); ?>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('wcs_cashback_settings_group');
                
                if ($active_tab == 'general'):
                ?>
                
                <!-- GENERAL TAB CONTENT -->
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="enabled">🔌 Увімкнути Систему Кешбеку</label>
                        </th>
                        <td>
                            <input type="checkbox" name="wcs_cashback_settings[enabled]" id="enabled" value="yes" <?php checked($settings['enabled'], 'yes'); ?>>
                            <p class="description">✅ Увімкніть цей параметр, щоб активувати систему кешбеку для всіх користувачів.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th colspan="2">
                            <h2>🎯 Рівні Кешбеку (Тарифи)</h2>
                            <p class="description">Налаштуйте відсотки кешбеку залежно від суми замовлення.</p>
                        </th>
                    </tr>
                    
                    <tr style="background: #f0f9ff;">
                        <th scope="row"><label for="tier_1_threshold">🥉 Рівень 1: Сума (грн)</label></th>
                        <td><input type="number" step="0.01" name="wcs_cashback_settings[tier_1_threshold]" id="tier_1_threshold" value="<?php echo esc_attr($settings['tier_1_threshold']); ?>" style="width: 150px;"></td>
                    </tr>
                    <tr style="background: #f0f9ff;">
                        <th scope="row"><label for="tier_1_percentage">🥉 Рівень 1: Відсоток (%)</label></th>
                        <td><input type="number" step="0.01" name="wcs_cashback_settings[tier_1_percentage]" id="tier_1_percentage" value="<?php echo esc_attr($settings['tier_1_percentage']); ?>" style="width: 150px;"></td>
                    </tr>

                    <tr style="background: #fff8e1;">
                        <th scope="row"><label for="tier_2_threshold">🥈 Рівень 2: Сума (грн)</label></th>
                        <td><input type="number" step="0.01" name="wcs_cashback_settings[tier_2_threshold]" id="tier_2_threshold" value="<?php echo esc_attr($settings['tier_2_threshold']); ?>" style="width: 150px;"></td>
                    </tr>
                    <tr style="background: #fff8e1;">
                        <th scope="row"><label for="tier_2_percentage">🥈 Рівень 2: Відсоток (%)</label></th>
                        <td><input type="number" step="0.01" name="wcs_cashback_settings[tier_2_percentage]" id="tier_2_percentage" value="<?php echo esc_attr($settings['tier_2_percentage']); ?>" style="width: 150px;"></td>
                    </tr>

                    <tr style="background: #e8f5e9;">
                        <th scope="row"><label for="tier_3_threshold">🥇 Рівень 3: Сума (грн)</label></th>
                        <td><input type="number" step="0.01" name="wcs_cashback_settings[tier_3_threshold]" id="tier_3_threshold" value="<?php echo esc_attr($settings['tier_3_threshold']); ?>" style="width: 150px;"></td>
                    </tr>
                    <tr style="background: #e8f5e9;">
                        <th scope="row"><label for="tier_3_percentage">🥇 Рівень 3: Відсоток (%)</label></th>
                        <td><input type="number" step="0.01" name="wcs_cashback_settings[tier_3_percentage]" id="tier_3_percentage" value="<?php echo esc_attr($settings['tier_3_percentage']); ?>" style="width: 150px;"></td>
                    </tr>
                    
                    <tr>
                        <th colspan="2"><h2>🛡️ Обмеження та Ліміти</h2></th>
                    </tr>
                    <tr>
                        <th scope="row"><label for="max_cashback_limit">💰 Макс. Ліміт Накопичення (грн)</label></th>
                        <td><input type="number" step="0.01" name="wcs_cashback_settings[max_cashback_limit]" id="max_cashback_limit" value="<?php echo esc_attr($settings['max_cashback_limit']); ?>" style="width: 150px;"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="usage_limit_percentage">🎯 Ліміт Використання (%)</label></th>
                        <td><input type="number" step="0.01" name="wcs_cashback_settings[usage_limit_percentage]" id="usage_limit_percentage" value="<?php echo esc_attr($settings['usage_limit_percentage']); ?>" style="width: 150px;"></td>
                    </tr>
                </table>

                <?php elseif ($active_tab == 'brands'): ?>
                
                <!-- BRANDS TAB CONTENT -->
                <div class="wcs-brands-settings">
                    <table class="form-table">
                        <tr>
                            <th colspan="2">
                                <h2>🏷️ Налаштування Правил Кешбеку</h2>
                                <p class="description">Створюйте правила для різних брендів або конкретних товарів (винятків).</p>
                            </th>
                        </tr>
                        <tr>
                            <th scope="row"><label for="use_brands_logic">🚀 Активувати логіку правил</label></th>
                            <td><input type="checkbox" name="wcs_cashback_settings[use_brands_logic]" id="use_brands_logic" value="yes" <?php checked($settings['use_brands_logic'], 'yes'); ?>></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="brand_taxonomy">📂 Таксономія брендів</label></th>
                            <td>
                                <select name="wcs_cashback_settings[brand_taxonomy]" id="brand_taxonomy">
                                    <?php
                                    $taxonomies = get_taxonomies(array('object_type' => array('product')), 'objects');
                                    foreach ($taxonomies as $taxonomy) {
                                        echo '<option value="' . esc_attr($taxonomy->name) . '" ' . selected($settings['brand_taxonomy'], $taxonomy->name, false) . '>' . esc_html($taxonomy->label) . '</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <div id="wcs-brand-rules-container">
                        <div class="wcs-rules-header">
                            <div class="col-type" style="width:150px; font-weight:bold;">Тип</div>
                            <div class="col-select" style="flex:1; font-weight:bold;">Бренди / Товари</div>
                            <div class="col-pct" style="width:100px; font-weight:bold;">Кешбек %</div>
                            <div class="col-action" style="width:40px;"></div>
                        </div>
                        
                        <div class="wcs-rules-list">
                            <?php 
                            $rules = !empty($settings['brand_rules']) ? $settings['brand_rules'] : array();
                            foreach ($rules as $index => $rule): 
                                $rule_type = $rule['type'] ?? 'brand';
                                $rule_ids  = (array)($rule['ids'] ?? array());
                                $rule_pct  = $rule['percentage'] ?? 0;
                            ?>
                            <div class="wcs-rule-row" data-index="<?php echo $index; ?>" style="display:flex; gap:10px; margin-bottom:10px; border-bottom:1px solid #eee; padding-bottom:10px;">
                                <div class="col-type" style="width:150px;">
                                    <select name="wcs_cashback_settings[brand_rules][<?php echo $index; ?>][type]" class="rule-type-select">
                                        <option value="brand" <?php selected($rule_type, 'brand'); ?>>Бренд</option>
                                        <option value="product" <?php selected($rule_type, 'product'); ?>>Товар (Виняток)</option>
                                    </select>
                                </div>
                                <div class="col-select" style="flex:1;">
                                    <select name="wcs_cashback_settings[brand_rules][<?php echo $index; ?>][ids][]" class="rule-ids-select wcs-select2-ajax" multiple style="width: 100%;">
                                        <?php 
                                        if ($rule_type === 'brand') {
                                            foreach ($rule_ids as $tid) {
                                                $term = get_term($tid, $settings['brand_taxonomy']);
                                                if ($term) echo '<option value="'.$tid.'" selected>'.$term->name.'</option>';
                                            }
                                        } else {
                                            foreach ($rule_ids as $pid) {
                                                $p = wc_get_product($pid);
                                                if ($p) echo '<option value="'.$pid.'" selected>'.$p->get_name().'</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="col-pct" style="width:100px;">
                                    <input type="number" step="0.01" name="wcs_cashback_settings[brand_rules][<?php echo $index; ?>][percentage]" value="<?php echo esc_attr($rule_pct); ?>" style="width: 70px;"> %
                                </div>
                                <div class="col-action" style="width:40px;">
                                    <button type="button" class="button wcs-remove-rule">❌</button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" id="wcs-add-rule" class="button button-primary">➕ Додати правило</button>
                    </div>

                    <table class="form-table">
                        <tr style="background: #f0f9ff;">
                            <th scope="row"><label for="default_percentage">📦 Відсоток для решти (%)</label></th>
                            <td><input type="number" step="0.01" name="wcs_cashback_settings[default_percentage]" id="default_percentage" value="<?php echo esc_attr($settings['default_percentage']); ?>" style="width: 100px;"></td>
                        </tr>
                    </table>
                </div>

                <style>#wcs-brand-rules-container { background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; margin:20px 0; }</style>

                <?php elseif ($active_tab == 'display'): ?>
                
                <!-- DISPLAY TAB CONTENT -->
                <table class="form-table">
                    <tr><th colspan="2"><h2>🎨 Налаштування Вигляду</h2></th></tr>
                    <tr>
                        <th scope="row"><label for="cart_position">🛒 Позиція в Кошику</label></th>
                        <td>
                            <select name="wcs_cashback_settings[cart_position]" id="cart_position">
                                <option value="woocommerce_cart_totals_before_order_total" <?php selected($settings['cart_position'], 'woocommerce_cart_totals_before_order_total'); ?>>В таблиці підсумків</option>
                                <option value="none" <?php selected($settings['cart_position'], 'none'); ?>>❌ Не відображати</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="checkout_position">💳 Позиція при Checkout</label></th>
                        <td>
                            <select name="wcs_cashback_settings[checkout_position]" id="checkout_position">
                                <option value="woocommerce_review_order_before_payment" <?php selected($settings['checkout_position'], 'woocommerce_review_order_before_payment'); ?>>Перед кнопкою оплати</option>
                                <option value="none" <?php selected($settings['checkout_position'], 'none'); ?>>❌ Не відображати</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <?php endif; ?>
                
                <!-- Hidden inputs for preserving tab state on save (optional but good practice) -->
                <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr(add_query_arg('tab', $active_tab, 'admin.php?page=wcs-cashback')); ?>">
                
                <?php submit_button('💾 Зберегти Налаштування', 'primary', 'submit', true, array('style' => 'font-size: 16px; padding: 10px 30px;')); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Users management page
     */
    public function users_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($paged - 1) * $per_page;
        
        $users = WCS_Cashback_Database::get_all_users_with_cashback('balance', 'DESC', $per_page, $offset);
        $total_users = WCS_Cashback_Database::count_users_with_cashback();
        $total_pages = ceil($total_users / $per_page);
        
        ?>
        <div class="wrap">
            <h1>👥 Управління Користувачами Кешбеку</h1>
            <p class="description">Перегляд, редагування балансів та індивідуальних лімітів для кожного користувача</p>
            
            <div class="wcs-info-box" style="border-left-color: #2271b1;">
                <h3>ℹ️ Що ви можете робити тут:</h3>
                <ul style="margin-bottom: 0;">
                    <li><strong>Переглядати баланси:</strong> Бачити скільки кешбеку накопичив кожен клієнт та історію транзакцій</li>
                    <li><strong>Встановлювати індивідуальні ліміти:</strong> Задавати персональні максимальні ліміти для VIP-клієнтів</li>
                    <li><strong>Скидати баланс:</strong> Обнуляти кешбек користувача (наприклад, при порушенні правил)</li>
                    <li><strong>Переглядати деталі:</strong> Докладна історія всіх операцій з кешбеком користувача</li>
                </ul>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>👤 Користувач</th>
                        <th>💰 Поточний Баланс</th>
                        <th>📈 Всього Заробив</th>
                        <th>📉 Всього Використав</th>
                        <th style="width: 200px;">🔒 Максимальний Ліміт</th>
                        <th>🕐 Останнє Оновлення</th>
                        <th style="width: 220px;">⚙️ Дії</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($users): ?>
                        <?php foreach ($users as $user_data): ?>
                            <?php
                            $user = get_userdata($user_data->user_id);
                            if (!$user) continue;
                            
                            $settings = get_option('wcs_cashback_settings');
                            $global_limit = isset($settings['max_cashback_limit']) ? $settings['max_cashback_limit'] : 10000;
                            $max_limit = !empty($user_data->max_limit) ? $user_data->max_limit : $global_limit;
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($user->display_name); ?></strong><br>
                                    <small style="color: #666;"><?php echo esc_html($user->user_email); ?></small>
                                </td>
                                <td>
                                    <strong style="font-size: 15px; color: #2e7d32;"><?php echo wc_price($user_data->balance); ?></strong>
                                </td>
                                <td style="color: #1976d2;"><?php echo wc_price($user_data->total_earned); ?></td>
                                <td style="color: #d32f2f;"><?php echo wc_price($user_data->total_spent); ?></td>
                                <td>
                                    <input type="number" step="0.01" value="<?php echo esc_attr($max_limit); ?>" 
                                           class="wcs-user-max-limit" data-user-id="<?php echo $user_data->user_id; ?>" 
                                           style="width: 90px;" title="Введіть новий ліміт та натисніть 'Оновити'">
                                    <button class="button wcs-update-limit" data-user-id="<?php echo $user_data->user_id; ?>" title="Зберегти новий ліміт">
                                        💾 Оновити
                                    </button>
                                </td>
                                <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($user_data->updated_at)); ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=wcs-cashback-user-detail&user_id=' . $user_data->user_id); ?>" 
                                       class="button" title="Переглянути всю історію транзакцій">
                                        📋 Деталі
                                    </a>
                                    <button class="button wcs-reset-balance" data-user-id="<?php echo $user_data->user_id; ?>" 
                                            title="Обнулити баланс кешбеку">
                                        🔄 Скинути
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px; color: #999;">
                                <div style="font-size: 48px;">😔</div>
                                <p style="font-size: 16px; margin: 10px 0 0 0;">
                                    Поки що немає користувачів з кешбеком.<br>
                                    <small>Користувачі з'являться тут після першого нарахування кешбеку.</small>
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1): ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => __('&laquo; Попередня'),
                            'next_text' => __('Наступна &raquo;'),
                            'total' => $total_pages,
                            'current' => $paged,
                        ));
                        ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="wcs-info-box" style="border-left-color: #ffc107;">
                <h3>💡 Підказки по роботі з користувачами:</h3>
                <ul style="margin-bottom: 0;">
                    <li><strong>Поточний Баланс:</strong> Скільки кешбеку зараз доступно користувачу для використання</li>
                    <li><strong>Всього Заробив:</strong> Загальна сума кешбеку нарахована за весь час (включаючи вже використаний)</li>
                    <li><strong>Всього Використав:</strong> Скільки кешбеку користувач витратив на оплату замовлень</li>
                    <li><strong>Максимальний Ліміт:</strong> Встановлюйте вищі ліміти для VIP-клієнтів (наприклад, 20000 грн замість стандартних 10000 грн)</li>
                    <li><strong>Скинути баланс:</strong> Обнулює тільки поточний баланс, історія транзакцій зберігається в розділі "Деталі"</li>
                </ul>
            </div>
        </div>
        <?php
    }
    
    /**
     * User Details Page
     */
    public function user_detail_page() {
        if (!current_user_can('manage_woocommerce')) return;
        
        $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        $user = get_userdata($user_id);
        
        if (!$user) {
            echo '<div class="wrap"><div class="notice notice-error"><p>Користувача не знайдено.</p></div></div>';
            return;
        }
        
        $balance_data = WCS_Cashback_Database::get_user_balance($user_id);
        $transactions = WCS_Cashback_Database::get_user_transactions($user_id, 100);
        
        // Ensure numbers
        $balance = isset($balance_data->balance) ? floatval($balance_data->balance) : 0;
        $earned = isset($balance_data->total_earned) ? floatval($balance_data->total_earned) : 0;
        $spent = isset($balance_data->total_spent) ? floatval($balance_data->total_spent) : 0;
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">👤 Кешбек: <?php echo esc_html($user->display_name); ?></h1>
            <a href="<?php echo admin_url('admin.php?page=wcs-cashback-users'); ?>" class="page-title-action">← Назад до списку</a>
            <hr class="wp-header-end">
            
            <div class="wcs-info-box" style="margin-top: 20px; border-left-color: #2271b1;">
                <p style="margin: 0;">
                    <strong>Email:</strong> <?php echo esc_html($user->user_email); ?> | 
                    <strong>ID:</strong> <?php echo $user_id; ?> | 
                    <strong>Зареєстрований:</strong> <?php echo date_i18n(get_option('date_format'), strtotime($user->user_registered)); ?>
                </p>
            </div>
            
            <div class="wcs-stats-grid">
                 <div class="wcs-stat-box balance">
                    <h3>Поточний Баланс</h3>
                    <p class="wcs-stat-value"><?php echo wc_price($balance); ?></p>
                 </div>
                 <div class="wcs-stat-box earned">
                    <h3>Всього Зароблено</h3>
                    <p class="wcs-stat-value"><?php echo wc_price($earned); ?></p>
                 </div>
                 <div class="wcs-stat-box spent">
                    <h3>Всього Витрачено</h3>
                    <p class="wcs-stat-value"><?php echo wc_price($spent); ?></p>
                 </div>
            </div>
            
            <h2 style="margin-top: 30px; margin-bottom: 20px;">📋 Історія Транзакцій</h2>
            
            <div class="card" style="padding: 0; margin-top: 0; max-width: 100%;">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th>Тип</th>
                            <th>Замовлення</th>
                            <th>Сума</th>
                            <th>Баланс Після</th>
                            <th>Опис</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($transactions && count($transactions) > 0): ?>
                            <?php foreach ($transactions as $transaction): ?>
                                <tr>
                                    <td><?php echo date_i18n('d.m.Y H:i', strtotime($transaction->created_at)); ?></td>
                                    <td>
                                        <?php 
                                        $type_labels = array(
                                            'earned' => '<span class="wcs-balance-earned">✅ Нараховано</span>',
                                            'spent' => '<span class="wcs-balance-spent">💳 Витрачено</span>',
                                            'adjustment' => '<span style="color:#2271b1;">⚙️ Коригування</span>'
                                        );
                                        echo isset($type_labels[$transaction->transaction_type]) ? $type_labels[$transaction->transaction_type] : $transaction->transaction_type; 
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($transaction->order_id > 0): ?>
                                            <a href="<?php echo get_edit_post_link($transaction->order_id); ?>">#<?php echo $transaction->order_id; ?></a>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $color = ($transaction->transaction_type === 'spent') ? '#d63638' : '#00a32a';
                                        $sign = ($transaction->transaction_type === 'earned') ? '+' : ($transaction->transaction_type === 'spent' ? '-' : '');
                                        echo '<strong style="color:'.$color.';">' . $sign . wc_price($transaction->amount) . '</strong>';
                                        ?>
                                    </td>
                                    <td><strong><?php echo wc_price($transaction->balance_after); ?></strong></td>
                                    <td><?php echo esc_html($transaction->description); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align: center; padding: 20px;">Історія транзакцій порожня.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
    public function statistics_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        $stats = WCS_Cashback_Database::get_statistics();
        
        // Перевірка на null і встановлення дефолтних значень
        if (!$stats) {
            $stats = (object) array(
                'total_balance' => 0,
                'total_earned' => 0,
                'total_spent' => 0,
                'total_users' => 0
            );
        }
        
        // Переконатися що всі властивості існують
        $stats->total_balance = isset($stats->total_balance) ? floatval($stats->total_balance) : 0;
        $stats->total_earned = isset($stats->total_earned) ? floatval($stats->total_earned) : 0;
        $stats->total_spent = isset($stats->total_spent) ? floatval($stats->total_spent) : 0;
        $stats->total_users = isset($stats->total_users) ? intval($stats->total_users) : 0;
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">📊 Статистика Системи Кешбеку</h1>
            <p class="description">Загальна інформація про роботу системи кешбеку в вашому магазині</p>
            
            <div class="wcs-info-box" style="border-left-color: #4caf50;">
                <h3>💡 Як читати статистику:</h3>
                <p style="margin: 0;">
                    <strong>Загальний Активний Баланс</strong> - це сума всього кешбеку на рахунках користувачів.<br>
                    Це ваші потенційні знижки, якщо всі користувачі вирішать витратити свій кешбек.
                </p>
            </div>
            
            <div class="wcs-stats-grid">
                <div class="wcs-stat-box balance">
                    <h3>💰 АКТИВНИЙ БАЛАНС</h3>
                    <p class="wcs-stat-value">
                        <?php echo wc_price($stats->total_balance); ?>
                    </p>
                    <div class="wcs-stat-desc">
                        <strong>Доступно клієнтам.</strong><br>Сума кешбеку на руках у всіх користувачів зараз.
                    </div>
                </div>
                
                <div class="wcs-stat-box earned">
                    <h3>📈 ВСЬОГО НАРАХОВАНО (+EARNED)</h3>
                    <p class="wcs-stat-value">
                        <?php echo wc_price($stats->total_earned); ?>
                    </p>
                    <div class="wcs-stat-desc">
                        <strong>Історичний максимум.</strong><br>Стільки бонусів ви видали за весь час роботи.
                    </div>
                </div>
                
                <div class="wcs-stat-box spent">
                    <h3>📉 ВСЬОГО ВИТРАЧЕНО (-SPENT)</h3>
                    <p class="wcs-stat-value">
                        <?php echo wc_price($stats->total_spent); ?>
                    </p>
                    <div class="wcs-stat-desc">
                        <strong>Реальна економія.</strong><br>На таку суму клієнти зменшили свої чеки.
                    </div>
                </div>
                
                <div class="wcs-stat-box users">
                    <h3>👥 КОРИСТУВАЧІВ</h3>
                    <p class="wcs-stat-value">
                        <?php echo number_format($stats->total_users); ?>
                    </p>
                    <div class="wcs-stat-desc">
                        <strong>Учасники програми.</strong><br>Кількість клієнтів, що мають історію кешбеку.
                    </div>
                </div>
            </div>
            
            <div class="wcs-info-box" style="border-left-color: #ffc107;">
                <h3>📊 Аналіз Показників:</h3>
                <ul style="margin-left: 15px;">
                    <li><strong>Коефіцієнт використання:</strong> 
                        <strong><?php 
                        $usage_rate = $stats->total_earned > 0 ? ($stats->total_spent / $stats->total_earned) * 100 : 0;
                        echo number_format($usage_rate, 1); 
                        ?>%</strong>
                        <span class="description">(Відсоток нарахованого кешбеку, який реально використовується)</span>
                    </li>
                    <li><strong>Середній баланс на користувача:</strong> 
                        <strong><?php 
                        $avg_balance = $stats->total_users > 0 ? $stats->total_balance / $stats->total_users : 0;
                        echo wc_price($avg_balance); 
                        ?></strong>
                        <span class="description">(середня сума на одному рахунку)</span>
                    </li>
                    <li><strong>Оптимальний рівень:</strong> 40-60%
                        <span class="description">(баланс між накопиченням та витратами)</span>
                    </li>
                    <li style="margin-top: 10px;"><strong>Рекомендація:</strong> 
                        <?php if ($usage_rate < 30): ?>
                            <span style="color: #d63638; font-weight: 500;">⚠️ Низький показник. Нагадайте клієнтам про кешбек через email.</span>
                        <?php elseif ($usage_rate > 70): ?>
                            <span style="color: #d63638; font-weight: 500;">⚠️ Дуже високий показник. Можливо варто знизити відсотки.</span>
                        <?php else: ?>
                            <span style="color: #00a32a; font-weight: 500;">✅ Оптимальний баланс!</span>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX: Update user max limit
     */
    public function ajax_update_user_balance() {
        check_ajax_referer('wcs_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => '❌ Доступ заборонено'));
        }
        
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $max_limit = isset($_POST['max_limit']) ? floatval($_POST['max_limit']) : 0;
        
        if (!$user_id) {
            wp_send_json_error(array('message' => '❌ Невірний ID користувача'));
        }
        
        WCS_Cashback_Database::set_user_max_limit($user_id, $max_limit);
        
        wp_send_json_success(array('message' => '✅ Максимальний ліміт успішно оновлено'));
    }
    
    /**
     * AJAX: Reset user balance
     */
    public function ajax_reset_user_balance() {
        check_ajax_referer('wcs_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => '❌ Доступ заборонено'));
        }
        
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        
        if (!$user_id) {
            wp_send_json_error(array('message' => '❌ Невірний ID користувача'));
        }
        
        // Get current balance
        $balance_data = WCS_Cashback_Database::get_user_balance($user_id);
        $balance_before = floatval($balance_data->balance);
        
        // Reset balance to 0
        WCS_Cashback_Database::update_balance($user_id, 0, 'adjustment');
        
        // Add transaction record
        WCS_Cashback_Database::add_transaction(array(
            'user_id' => $user_id,
            'order_id' => 0,
            'transaction_type' => 'adjustment',
            'amount' => $balance_before,
            'balance_before' => $balance_before,
            'balance_after' => 0,
            'description' => 'Баланс обнулено адміністратором',
        ));
        
        wp_send_json_success(array('message' => '✅ Баланс успішно скинуто'));
    }

    /* ═══════════════════════════════════════════════════════
     *  VIP Discounts Admin Page
     * ═══════════════════════════════════════════════════════ */
    public function vip_discounts_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $rules = WCS_VIP_Discounts::get_rules();

        // Get all product categories for the dropdown
        $product_categories = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
        ));

        // Preload all registered users for the dropdown
        $all_users = get_users(array(
            'number'  => 200,
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'fields'  => array('ID', 'display_name', 'user_email'),
        ));
        ?>
        <div class="wrap">
            <h1>⭐ VIP Знижки для Клієнтів</h1>
            <p class="description">Налаштуйте персональні знижки для VIP-клієнтів на певні категорії товарів або конкретні товари.<br>
                Коли VIP-клієнт купує товар із зазначеної категорії або конкретний товар — він отримує знижку замість кешбеку.</p>

            <div class="wcs-info-box" style="border-left-color: #ff9800; margin-top: 15px;">
                <h3>💡 Як це працює:</h3>
                <ul style="margin-bottom: 0;">
                    <li><strong>Додайте правило</strong> — виберіть клієнтів, категорії товарів та/або конкретні товари, і тип знижки</li>
                    <li><strong>Категорії</strong> — знижка діє на всі товари з обраних категорій</li>
                    <li><strong>Конкретні товари</strong> — знижка діє тільки на обрані товари (можна комбінувати з категоріями)</li>
                    <li><strong>Знижка в %</strong> — зменшує ціну кожного товару на вказаний відсоток</li>
                    <li><strong>Знижка в грн</strong> — зменшує ціну кожного товару на фіксовану суму</li>
                    <li><strong>Кешбек</strong> — на товари зі знижкою кешбек <u>не нараховується</u></li>
                </ul>
            </div>

            <!-- ═══ ADD / EDIT RULE FORM ═══ -->
            <div class="card" style="max-width: 850px; margin-top: 25px; padding: 24px;">
                <h2 id="wcs-vip-form-title" style="margin-top: 0;">➕ Додати Нове Правило</h2>
                <input type="hidden" id="wcs-vip-rule-index" value="">

                <table class="form-table" style="margin-top: 0;">
                    <tr>
                        <th><label>👤 Клієнти</label></th>
                        <td>
                            <select id="wcs-vip-users" multiple="multiple" style="width: 100%; min-width: 350px;">
                                <?php foreach ($all_users as $u) : ?>
                                    <option value="<?php echo esc_attr($u->ID); ?>">
                                        <?php echo esc_html($u->display_name); ?> (<?php echo esc_html($u->user_email); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Виберіть клієнтів зі списку або почніть вводити ім'я / email для пошуку</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>📂 Категорії Товарів</label></th>
                        <td>
                            <select id="wcs-vip-categories" multiple="multiple" style="width: 100%; min-width: 350px;">
                                <?php foreach ($product_categories as $cat) : ?>
                                    <option value="<?php echo esc_attr($cat->term_id); ?>">
                                        <?php echo esc_html($cat->name); ?> (<?php echo $cat->count; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Знижка діє на <strong>всі</strong> товари з обраних категорій (необов'язково, якщо вибрано конкретні товари)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>🛒 Конкретні Товари</label></th>
                        <td>
                            <select id="wcs-vip-products" multiple="multiple" style="width: 100%; min-width: 350px;"></select>
                            <p class="description">Почніть вводити назву товару для пошуку. Знижка діє тільки на обрані товари (необов'язково, якщо вибрано категорії)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>💰 Тип Знижки</label></th>
                        <td>
                            <select id="wcs-vip-discount-type" style="min-width: 200px;">
                                <option value="percentage">📊 Відсоток (%)</option>
                                <option value="fixed">💵 Фіксована сума (грн)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label>🔢 Розмір Знижки</label></th>
                        <td>
                            <input type="number" id="wcs-vip-discount-value" step="0.01" min="0" value="" 
                                   class="regular-text" style="width: 150px;" placeholder="Наприклад: 10">
                            <span id="wcs-vip-discount-suffix" style="font-weight: 600; margin-left: 5px;">%</span>
                            <p class="description" id="wcs-vip-discount-hint">
                                Для відсотків: 10 = 10% знижки. Для фіксованої: 50 = 50 грн знижки з кожного товару.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>🏷️ Назва (Мітка)</label></th>
                        <td>
                            <input type="text" id="wcs-vip-label" class="regular-text" style="width: 300px;" 
                                   placeholder="Наприклад: VIP знижка -10%" 
                                   value="">
                            <p class="description">Ця назва буде видна клієнту в кошику як назва знижки</p>
                        </td>
                    </tr>
                </table>

                <div style="padding-top: 10px; border-top: 1px solid #eee; margin-top: 10px;">
                    <button type="button" id="wcs-vip-save-btn" class="button button-primary" style="font-size: 14px; padding: 6px 24px;">
                        💾 Зберегти Правило
                    </button>
                    <button type="button" id="wcs-vip-cancel-btn" class="button" style="font-size: 14px; padding: 6px 24px; display: none;">
                        ✖ Скасувати
                    </button>
                    <span id="wcs-vip-save-status" style="margin-left: 15px; font-weight: 500;"></span>
                </div>
            </div>

            <!-- ═══ EXISTING RULES TABLE ═══ -->
            <h2 style="margin-top: 35px;">📋 Активні Правила VIP Знижок</h2>

            <table class="wp-list-table widefat fixed striped" id="wcs-vip-rules-table">
                <thead>
                    <tr>
                        <th style="width: 40px;">№</th>
                        <th>👤 Клієнти</th>
                        <th>📂 Категорії / 🛒 Товари</th>
                        <th style="width: 130px;">💰 Знижка</th>
                        <th style="width: 160px;">🏷️ Мітка</th>
                        <th style="width: 80px;">Статус</th>
                        <th style="width: 170px;">⚙️ Дії</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($rules)) : ?>
                        <?php foreach ($rules as $i => $rule) : ?>
                            <?php
                            $rule_product_ids  = isset($rule['product_ids']) ? (array)$rule['product_ids'] : array();
                            $rule_category_ids = isset($rule['category_ids']) ? (array)$rule['category_ids'] : array();
                            ?>
                            <tr data-index="<?php echo $i; ?>">
                                <td><strong><?php echo ($i + 1); ?></strong></td>
                                <td>
                                    <?php
                                    $user_names = array();
                                    foreach ((array) $rule['user_ids'] as $uid) {
                                        $u = get_userdata($uid);
                                        if ($u) {
                                            $user_names[] = '<strong>' . esc_html($u->display_name) . '</strong><br><small style="color:#666;">' . esc_html($u->user_email) . '</small>';
                                        }
                                    }
                                    echo implode('<hr style="margin:4px 0;border-color:#eee;">', $user_names);
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    // Categories
                                    if (!empty($rule_category_ids)) {
                                        $cat_names = array();
                                        foreach ($rule_category_ids as $cid) {
                                            $term = get_term($cid, 'product_cat');
                                            if ($term && !is_wp_error($term)) {
                                                $cat_names[] = esc_html($term->name);
                                            }
                                        }
                                        if (!empty($cat_names)) {
                                            echo '<strong style="color:#1d2327;">📂 Категорії:</strong><br>' . implode(', ', $cat_names);
                                        }
                                    }
                                    // Products
                                    if (!empty($rule_product_ids)) {
                                        if (!empty($rule_category_ids)) {
                                            echo '<hr style="margin:6px 0;border-color:#eee;">';
                                        }
                                        $prod_names = array();
                                        foreach ($rule_product_ids as $pid) {
                                            $p = wc_get_product($pid);
                                            if ($p) {
                                                $prod_names[] = esc_html($p->get_name());
                                            }
                                        }
                                        if (!empty($prod_names)) {
                                            echo '<strong style="color:#1d2327;">🛒 Товари:</strong><br>' . implode(', ', $prod_names);
                                        }
                                    }
                                    if (empty($rule_category_ids) && empty($rule_product_ids)) {
                                        echo '<span style="color:#999;">— не вказано —</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <strong style="font-size: 15px; color: #d63638;">
                                        <?php
                                        if ($rule['discount_type'] === 'percentage') {
                                            echo '-' . $rule['discount_value'] . '%';
                                        } else {
                                            echo '-' . wc_price($rule['discount_value']);
                                        }
                                        ?>
                                    </strong><br>
                                    <small style="color:#888;">
                                        <?php echo $rule['discount_type'] === 'percentage' ? 'відсоток' : 'фіксована'; ?>
                                    </small>
                                </td>
                                <td><?php echo esc_html($rule['label']); ?></td>
                                <td>
                                    <?php if (!empty($rule['enabled'])) : ?>
                                        <span style="color: #00a32a; font-weight: 600;">✅ Активно</span>
                                    <?php else : ?>
                                        <span style="color: #999;">⏸ Вимкнено</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="button wcs-vip-edit-btn" data-index="<?php echo $i; ?>"
                                            data-users='<?php echo esc_attr(json_encode(array_map(function($uid) {
                                                $u = get_userdata($uid);
                                                return $u ? array('id' => $uid, 'text' => $u->display_name . ' (' . $u->user_email . ')') : null;
                                            }, (array)$rule['user_ids']))); ?>'
                                            data-categories='<?php echo esc_attr(json_encode($rule_category_ids)); ?>'
                                            data-products='<?php echo esc_attr(json_encode(array_map(function($pid) {
                                                $p = wc_get_product($pid);
                                                $price_text = ($p && $p->get_price()) ? ' — ' . strip_tags(wc_price($p->get_price())) : '';
                                                return $p ? array('id' => $pid, 'text' => $p->get_name() . $price_text . ' (ID: ' . $pid . ')') : null;
                                            }, $rule_product_ids))); ?>'
                                            data-discount-type="<?php echo esc_attr($rule['discount_type']); ?>"
                                            data-discount-value="<?php echo esc_attr($rule['discount_value']); ?>"
                                            data-label="<?php echo esc_attr($rule['label']); ?>"
                                            data-enabled="<?php echo !empty($rule['enabled']) ? '1' : '0'; ?>">
                                        ✏️ Редагувати
                                    </button>
                                    <button class="button wcs-vip-delete-btn" data-index="<?php echo $i; ?>" style="color: #d63638;">
                                        🗑️ Видалити
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr id="wcs-vip-no-rules">
                            <td colspan="7" style="text-align: center; padding: 40px; color: #999;">
                                <div style="font-size: 48px;">📭</div>
                                <p style="font-size: 16px; margin: 10px 0 0 0;">
                                    Поки що немає правил VIP знижок.<br>
                                    <small>Додайте перше правило вище, щоб призначити персональну знижку клієнту.</small>
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="wcs-info-box" style="border-left-color: #4caf50; margin-top: 25px;">
                <h3>📌 Важливо знати:</h3>
                <ul style="margin-bottom: 0;">
                    <li><strong>Категорії + Товари:</strong> Можна вибрати категорії, конкретні товари, або обидва варіанти одночасно</li>
                    <li><strong>Один клієнт — кілька правил:</strong> Якщо клієнт є в кількох правилах, знижки додаються</li>
                    <li><strong>Кешбек:</strong> На товари, які отримали VIP-знижку, кешбек не нараховується</li>
                    <li><strong>Видимість:</strong> Клієнт бачить знижку в кошику як "VIP Знижка" (або вашу мітку)</li>
                    <li><strong>Пріоритет:</strong> VIP-знижка застосовується разом з кешбеком — кешбек на інші товари, знижка на VIP-товари</li>
                </ul>
            </div>
        </div>

        <!-- ═══ INLINE SCRIPT FOR VIP ADMIN (Select2 + AJAX) ═══ -->
        <script type="text/javascript">
        jQuery(document).ready(function($) {

            // ── Select2: Users (preloaded dropdown + search via AJAX) ──
            $('#wcs-vip-users').select2({
                placeholder: 'Виберіть клієнтів зі списку або шукайте...',
                allowClear: true,
                language: {
                    noResults: function() { return 'Клієнтів не знайдено'; },
                    searching: function() { return 'Пошук...'; }
                },
                // Also allow AJAX search for users beyond the preloaded 200
                ajax: {
                    url: wcs_admin.ajax_url,
                    dataType: 'json',
                    delay: 300,
                    data: function(params) {
                        return {
                            action: 'wcs_search_users',
                            nonce: wcs_admin.nonce,
                            term: params.term
                        };
                    },
                    processResults: function(data) {
                        return { results: data };
                    },
                    cache: true
                },
                minimumInputLength: 0
            });

            // ── Select2: Categories (static, preloaded) ──
            $('#wcs-vip-categories').select2({
                placeholder: 'Виберіть категорії...',
                allowClear: true,
                language: {
                    noResults: function() { return 'Категорій не знайдено'; }
                }
            });

            // ── Select2: Products (AJAX search) ──
            $('#wcs-vip-products').select2({
                ajax: {
                    url: wcs_admin.ajax_url,
                    dataType: 'json',
                    delay: 300,
                    data: function(params) {
                        return {
                            action: 'wcs_search_products',
                            nonce: wcs_admin.nonce,
                            term: params.term
                        };
                    },
                    processResults: function(data) {
                        return { results: data };
                    },
                    cache: true
                },
                minimumInputLength: 2,
                placeholder: 'Шукати товар за назвою...',
                allowClear: true,
                language: {
                    inputTooShort: function() { return 'Введіть хоча б 2 символи для пошуку товару...'; },
                    noResults:     function() { return 'Товарів не знайдено'; },
                    searching:     function() { return 'Пошук товарів...'; }
                }
            });

            // Toggle suffix for discount type
            $('#wcs-vip-discount-type').on('change', function() {
                var suffix = $(this).val() === 'percentage' ? '%' : 'грн';
                $('#wcs-vip-discount-suffix').text(suffix);
            });

            // ── Save Rule ──
            $('#wcs-vip-save-btn').on('click', function() {
                var $btn = $(this);
                var $status = $('#wcs-vip-save-status');
                var userIds    = $('#wcs-vip-users').val() || [];
                var catIds     = $('#wcs-vip-categories').val() || [];
                var productIds = $('#wcs-vip-products').val() || [];

                if (userIds.length === 0) {
                    $status.html('<span style="color:#d63638;">❌ Виберіть клієнтів</span>');
                    return;
                }
                if (catIds.length === 0 && productIds.length === 0) {
                    $status.html('<span style="color:#d63638;">❌ Виберіть хоча б одну категорію або товар</span>');
                    return;
                }

                var discountVal = parseFloat($('#wcs-vip-discount-value').val());
                if (!discountVal || discountVal <= 0) {
                    $status.html('<span style="color:#d63638;">❌ Вкажіть розмір знижки</span>');
                    return;
                }

                $btn.prop('disabled', true);
                $status.html('<span style="color:#2271b1;">⏳ Збереження...</span>');

                $.post(wcs_admin.ajax_url, {
                    action: 'wcs_save_vip_rule',
                    nonce: wcs_admin.nonce,
                    user_ids: userIds,
                    category_ids: catIds,
                    product_ids: productIds,
                    discount_type: $('#wcs-vip-discount-type').val(),
                    discount_value: discountVal,
                    label: $('#wcs-vip-label').val(),
                    enabled: 1,
                    rule_index: $('#wcs-vip-rule-index').val()
                }, function(response) {
                    if (response.success) {
                        $status.html('<span style="color:#00a32a;">' + response.data.message + '</span>');
                        setTimeout(function() { location.reload(); }, 800);
                    } else {
                        $status.html('<span style="color:#d63638;">' + response.data.message + '</span>');
                        $btn.prop('disabled', false);
                    }
                }).fail(function() {
                    $status.html('<span style="color:#d63638;">❌ Помилка сервера</span>');
                    $btn.prop('disabled', false);
                });
            });

            // ── Edit Rule ──
            $(document).on('click', '.wcs-vip-edit-btn', function() {
                var $btn = $(this);
                var index = $btn.data('index');

                // Set form title
                $('#wcs-vip-form-title').text('✏️ Редагувати Правило #' + (index + 1));
                $('#wcs-vip-rule-index').val(index);
                $('#wcs-vip-cancel-btn').show();

                // Fill users — set selected values from preloaded options
                var users = $btn.data('users');
                var $userSelect = $('#wcs-vip-users');
                // First, ensure all needed options exist
                if (users && Array.isArray(users)) {
                    var selectedIds = [];
                    users.forEach(function(u) {
                        if (u) {
                            // Check if option already exists in preloaded list
                            if ($userSelect.find('option[value="' + u.id + '"]').length === 0) {
                                $userSelect.append(new Option(u.text, u.id, false, false));
                            }
                            selectedIds.push(u.id.toString());
                        }
                    });
                    $userSelect.val(selectedIds).trigger('change');
                }

                // Fill categories
                var cats = $btn.data('categories');
                $('#wcs-vip-categories').val(cats).trigger('change');

                // Fill products
                var products = $btn.data('products');
                var $productSelect = $('#wcs-vip-products');
                $productSelect.empty();
                if (products && Array.isArray(products)) {
                    products.forEach(function(p) {
                        if (p) {
                            $productSelect.append(new Option(p.text, p.id, true, true));
                        }
                    });
                }
                $productSelect.trigger('change');

                // Fill discount
                $('#wcs-vip-discount-type').val($btn.data('discount-type')).trigger('change');
                $('#wcs-vip-discount-value').val($btn.data('discount-value'));
                $('#wcs-vip-label').val($btn.data('label'));

                // Scroll to form
                $('html, body').animate({ scrollTop: $('#wcs-vip-form-title').offset().top - 50 }, 300);
            });

            // ── Cancel Edit ──
            $('#wcs-vip-cancel-btn').on('click', function() {
                $('#wcs-vip-form-title').text('➕ Додати Нове Правило');
                $('#wcs-vip-rule-index').val('');
                $('#wcs-vip-cancel-btn').hide();
                $('#wcs-vip-users').val(null).trigger('change');
                $('#wcs-vip-categories').val(null).trigger('change');
                $('#wcs-vip-products').val(null).trigger('change');
                $('#wcs-vip-discount-value').val('');
                $('#wcs-vip-label').val('');
                $('#wcs-vip-save-status').html('');
            });

            // ── Delete Rule ──
            $(document).on('click', '.wcs-vip-delete-btn', function() {
                if (!confirm('Ви впевнені, що хочете видалити це правило?')) {
                    return;
                }

                var $btn = $(this);
                var index = $btn.data('index');
                $btn.prop('disabled', true).text('⏳...');

                $.post(wcs_admin.ajax_url, {
                    action: 'wcs_delete_vip_rule',
                    nonce: wcs_admin.nonce,
                    rule_index: index
                }, function(response) {
                    if (response.success) {
                        $btn.closest('tr').fadeOut(300, function() {
                            $(this).remove();
                            if ($('#wcs-vip-rules-table tbody tr').length === 0) {
                                location.reload();
                            }
                        });
                    } else {
                        alert(response.data.message);
                        $btn.prop('disabled', false).text('🗑️ Видалити');
                    }
                });
            });
        });
        </script>
        <?php
    }
}

