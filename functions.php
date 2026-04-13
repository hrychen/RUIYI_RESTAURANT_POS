<?php
/**
 * RUIYI Retail POS Theme Functions
 *
 * @package RUIYI_Retail_POS
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// ========================================
// 🔥 Performance: conditional logging — only log when WP_DEBUG is enabled
function ruiyi_pos_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log($message);
    }
}

// ========================================
// 🔥 POS Nonce 有效期设为1年
// POS 是认证后的内部系统，已有登录+角色权限保护，nonce 仅防 CSRF
// 采用简单可靠的方式，不依赖 HTTP_REFERER（隐私浏览器/代理会剥离 referer）
// ========================================
add_filter('nonce_life', function() {
    return YEAR_IN_SECONDS;
});

// ========================================
// 🔥 POS 登录 Cookie 有效期设为1年（避免频繁重新登录）
// 不依赖 HTTP_REFERER，直接对所有用户延长（POS 是内部系统）
// ========================================
add_filter('auth_cookie_expiration', function($expiration, $user_id, $remember) {
    return YEAR_IN_SECONDS;
}, 10, 3);

// ========================================
// 🔥 WordPress Cron: 日结单自动保存定时任务
// Server-side auto daily settlement cron job
// ========================================

/**
 * 注册自定义 Cron 间隔（每5分钟检查一次）
 */
add_filter('cron_schedules', function($schedules) {
    $schedules['ruiyi_five_minutes'] = array(
        'interval' => 300, // 5分钟 = 300秒
        'display'  => __('Every 5 Minutes', 'ruiyi-retail-pos')
    );
    return $schedules;
});

/**
 * 初始化 Cron 任务
 */
add_action('init', 'ruiyi_pos_init_auto_settlement_cron');
function ruiyi_pos_init_auto_settlement_cron() {
    // 只有启用了自动保存功能才注册 cron
    $auto_enabled = get_option('ruiyi_retail_auto_daily_settlement_enabled', false);
    if ($auto_enabled) {
        // 如果还没有安排任务，则安排
        if (!wp_next_scheduled('ruiyi_pos_auto_settlement_cron_hook')) {
            wp_schedule_event(time(), 'ruiyi_five_minutes', 'ruiyi_pos_auto_settlement_cron_hook');
            ruiyi_pos_log('[Auto Settlement Cron] Scheduled cron job (every 5 minutes)');
        }
    } else {
        // 如果功能被禁用，取消 cron 任务
        $timestamp = wp_next_scheduled('ruiyi_pos_auto_settlement_cron_hook');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'ruiyi_pos_auto_settlement_cron_hook');
            ruiyi_pos_log('[Auto Settlement Cron] Unscheduled cron job (feature disabled)');
        }
    }
}

/**
 * Cron 执行的回调函数
 * 改进逻辑：只要当前时间 >= 设定时间，且今天还没执行过，就执行
 */
add_action('ruiyi_pos_auto_settlement_cron_hook', 'ruiyi_pos_cron_auto_settlement');
function ruiyi_pos_cron_auto_settlement() {
    ruiyi_pos_log('[Auto Settlement Cron] Cron job triggered at ' . current_time('mysql'));

    // 检查是否启用
    $auto_enabled = get_option('ruiyi_retail_auto_daily_settlement_enabled', false);
    if (!$auto_enabled) {
        ruiyi_pos_log('[Auto Settlement Cron] Feature is disabled, skipping');
        return;
    }

    // 获取设定的保存时间
    $target_time = get_option('ruiyi_retail_auto_daily_settlement_time', '00:00');
    list($target_hour, $target_minute) = explode(':', $target_time);
    $target_hour = intval($target_hour);
    $target_minute = intval($target_minute);

    // 获取当前时间
    $current_hour = intval(current_time('G')); // 24小时制，无前导零
    $current_minute = intval(current_time('i'));
    $current_date = current_time('Y-m-d');

    // 将时间转换为分钟数便于比较
    $target_minutes_total = $target_hour * 60 + $target_minute;
    $current_minutes_total = $current_hour * 60 + $current_minute;

    ruiyi_pos_log("[Auto Settlement Cron] Current: {$current_hour}:{$current_minute} ({$current_minutes_total}min), Target: {$target_hour}:{$target_minute} ({$target_minutes_total}min)");

    // 检查今天是否已经执行过
    $last_cron_run = get_option('ruiyi_retail_last_cron_settlement_date', '');
    if ($last_cron_run === $current_date) {
        ruiyi_pos_log('[Auto Settlement Cron] Already ran today (' . $last_cron_run . '), skipping');
        return;
    }

    // 🔥 改进逻辑：只要当前时间 >= 设定时间，就执行（而不是只在精确时间窗口内）
    // 这样即使 cron 延迟执行，也能正常工作
    if ($current_minutes_total < $target_minutes_total) {
        ruiyi_pos_log("[Auto Settlement Cron] Current time ({$current_hour}:{$current_minute}) < target time ({$target_hour}:{$target_minute}), waiting...");
        return;
    }

    ruiyi_pos_log("[Auto Settlement Cron] Time condition met! Current >= Target, executing...");

    // 计算要保存的日期
    // 04:00-23:59 保存今天，00:00-03:59 保存昨天
    if ($target_hour >= 4) {
        $date_to_save = $current_date;
    } else {
        $date_to_save = date('Y-m-d', strtotime('-1 day', current_time('timestamp')));
    }

    ruiyi_pos_log("[Auto Settlement Cron] Will save daily settlement for: {$date_to_save}");

    // 执行保存
    $result = ruiyi_pos_cron_execute_auto_settlement($date_to_save);

    if ($result['success']) {
        // 记录今天已执行
        update_option('ruiyi_retail_last_cron_settlement_date', $current_date);
        ruiyi_pos_log('[Auto Settlement Cron] Successfully saved daily settlement for ' . $date_to_save);
        ruiyi_pos_log('[Auto Settlement Cron] Result: ' . print_r($result, true));
    } else {
        ruiyi_pos_log('[Auto Settlement Cron] Failed to save: ' . $result['message']);
    }
}

/**
 * 执行日结单自动保存（被 Cron 调用）
 */
function ruiyi_pos_cron_execute_auto_settlement($date_to_save) {
    ruiyi_pos_log("[Auto Settlement Cron] Executing auto settlement for {$date_to_save}");

    // 获取日结单数据
    $daily_data_key = 'ruiyi_retail_daily_summary_' . $date_to_save;
    $daily_data = get_option($daily_data_key, null);

    if (!$daily_data || empty($daily_data)) {
        return array('success' => true, 'message' => 'No daily data to save', 'skipped' => true);
    }

    // 检查是否有实际数据
    if (!isset($daily_data['total_amount']) || $daily_data['total_amount'] <= 0) {
        return array('success' => true, 'message' => 'Total amount is 0, skipping', 'skipped' => true);
    }

    ruiyi_pos_log("[Auto Settlement Cron] Found daily data: total=" . $daily_data['total_amount']);

    // 准备分类数据
    $categories_data = array();
    if (isset($daily_data['products']) && is_array($daily_data['products'])) {
        foreach ($daily_data['products'] as $product) {
            $category = isset($product['category']) ? $product['category'] : 'Sin categoría';
            if (!isset($categories_data[$category])) {
                $categories_data[$category] = array('name' => $category, 'total' => 0);
            }
            $categories_data[$category]['total'] += floatval($product['total']);
        }
    }

    // 生成唯一的结单ID
    $settlement_timestamp = current_time('timestamp');
    $settlement_time = date('His', $settlement_timestamp);
    $settlement_id = $date_to_save . '_' . $settlement_time;
    $monthly_record_key = 'ruiyi_retail_monthly_record_' . $settlement_id;

    // 准备月结单记录
    $monthly_record = array(
        'settlement_id' => $settlement_id,
        'business_date' => $date_to_save,
        'settlement_datetime' => current_time('mysql'),
        'settlement_timestamp' => $settlement_timestamp,
        'total_amount' => $daily_data['total_amount'],
        'cash_amount' => $daily_data['cash_amount'],
        'card_amount' => $daily_data['card_amount'],
        'transfer_amount' => isset($daily_data['transfer_amount']) ? $daily_data['transfer_amount'] : 0,
        'mixed_payment_count' => isset($daily_data['mixed_payment_count']) ? $daily_data['mixed_payment_count'] : 0,
        'categories_data' => $categories_data,
        'transactions_data' => $daily_data['transactions'],
        'transactions_count' => count($daily_data['transactions']),
        'products_data' => isset($daily_data['products']) ? $daily_data['products'] : array(),
        'saved_by' => 0, // Cron job, no user
        'saved_at' => current_time('mysql'),
        'auto_saved' => true,
        'cron_saved' => true // 标记为 Cron 保存
    );

    // 保存到 options 表
    $save_result = update_option($monthly_record_key, $monthly_record, 'no');

    if (!$save_result && get_option($monthly_record_key) !== $monthly_record) {
        return array('success' => false, 'message' => 'Failed to save monthly record');
    }

    // 维护月度索引
    $month_key = date('Y-m', strtotime($date_to_save));
    $month_index_key = 'ruiyi_retail_monthly_index_' . $month_key;
    $month_index = get_option($month_index_key, array());

    if (!in_array($settlement_id, $month_index)) {
        $month_index[] = $settlement_id;
        usort($month_index, function($a, $b) {
            return strcmp($a, $b);
        });
        update_option($month_index_key, $month_index, 'no');
    }

    // 删除日结单数据
    $deleted = delete_option($daily_data_key);

    ruiyi_pos_log("[Auto Settlement Cron] Saved to {$monthly_record_key}, daily data deleted: " . ($deleted ? 'YES' : 'NO'));

    return array(
        'success' => true,
        'message' => 'Settlement saved successfully',
        'settlement_id' => $settlement_id,
        'total_amount' => $daily_data['total_amount']
    );
}

/**
 * 当设置更新时，重新安排 Cron 任务
 */
add_action('update_option_ruiyi_retail_auto_daily_settlement_enabled', 'ruiyi_pos_reschedule_settlement_cron', 10, 2);
add_action('update_option_ruiyi_retail_auto_daily_settlement_time', 'ruiyi_pos_reschedule_settlement_cron', 10, 2);
function ruiyi_pos_reschedule_settlement_cron($old_value, $new_value) {
    $auto_enabled = get_option('ruiyi_retail_auto_daily_settlement_enabled', false);

    // 先清除现有的 cron
    wp_clear_scheduled_hook('ruiyi_pos_auto_settlement_cron_hook');

    if ($auto_enabled) {
        // 重新安排 cron
        wp_schedule_event(time(), 'ruiyi_five_minutes', 'ruiyi_pos_auto_settlement_cron_hook');
        ruiyi_pos_log('[Auto Settlement Cron] Rescheduled cron job after settings update');
    } else {
        ruiyi_pos_log('[Auto Settlement Cron] Cron job cleared (feature disabled)');
    }
}

/**
 * 主题停用时清除 Cron
 */
add_action('switch_theme', 'ruiyi_pos_clear_settlement_cron');
function ruiyi_pos_clear_settlement_cron() {
    wp_clear_scheduled_hook('ruiyi_pos_auto_settlement_cron_hook');
    ruiyi_pos_log('[Auto Settlement Cron] Cleared cron job on theme switch');
}

// ========================================
// 结束 Cron 相关代码
// ========================================

// 让所有列表页的行操作链接始终显示
add_action('admin_head', function() {
    ?>
    <style>
        .wp-list-table .row-actions {
            visibility: visible !important;
            opacity: 1 !important;
            position: relative !important;
            left: 0 !important;
        }
    </style>
    <?php
});

// 用CSS隐藏产品编辑页面的模块（保留功能）
add_action('admin_head', function() {
    global $pagenow, $typenow;
    if (($pagenow == 'post.php' || $pagenow == 'post-new.php') && $typenow == 'product') {
        ?>
        <style>
            /* 隐藏产品描述 */
            #postdivrich { display: none !important; }
            
            /* 隐藏产品相册 */
            #woocommerce-product-images { display: none !important; }
            
            /* 隐藏品牌 */
            #product_branddiv { display: none !important; }
            
            /* 隐藏产品标签 */
            #tagsdiv-product_tag { display: none !important; }
            
            /* 隐藏自定义字段 */
            #postcustom { display: none !important; }
            
            /* 隐藏别名 */
            #slugdiv,
            #edit-slug-box { display: none !important; }
            
            /* 隐藏评价 */
            #commentsdiv { display: none !important; }
        </style>
        <?php
    }
});

/**
 * Marcar WooCommerce Setup Wizard como completado
 * 自动将WooCommerce设置向导标记为已完成
 */
add_action('admin_init', function() {
    if (get_option('woocommerce_task_list_complete') !== 'yes') {
        update_option('woocommerce_task_list_complete', 'yes');
        update_option('woocommerce_task_list_hidden', 'yes');
        update_option('woocommerce_default_country', 'ES:B'); // Barcelona
        delete_transient('_wc_activation_redirect');
    }
});

/**
 * Serve service-worker.js from root URL
 * This allows the service worker to have scope '/' even though the file is in the theme directory
 * Fixes: "Failed to register a ServiceWorker... A bad HTTP response code (404) was received"
 */
function ruiyi_pos_serve_service_worker() {
    $request_uri = isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';

    if ($request_uri === '/service-worker.js') {
        $sw_file = get_template_directory() . '/service-worker.js';

        if (file_exists($sw_file)) {
            // Set proper headers for service worker
            header('Content-Type: application/javascript; charset=utf-8');
            header('Service-Worker-Allowed: /');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');

            readfile($sw_file);
            exit;
        }
    }
}
add_action('init', 'ruiyi_pos_serve_service_worker', 1);

// Define theme constants
// IMPORTANT for PWA/offline:
// Do NOT use time() here. A changing version makes JS/CSS URLs change on every load (?ver=...),
// which breaks offline caching because the cached URL never matches the next request.
if (!defined('RUIYI_POS_VERSION')) {
    $theme_version = '2.3.4-1230a';
    if (function_exists('wp_get_theme')) {
        $t = wp_get_theme();
        $v = $t ? $t->get('Version') : '';
        if (!empty($v)) {
            $theme_version = $v;
        }
    }
    define('RUIYI_POS_VERSION', $theme_version);
}
define('RUIYI_POS_DIR', get_template_directory());
define('RUIYI_POS_URI', get_template_directory_uri());

// Include barcode functions
require_once RUIYI_POS_DIR . '/inc/pos-barcode-functions.php';

// Include POS 日结单恢复工具 (super admin only)
require_once RUIYI_POS_DIR . '/inc/pos-recovery-admin.php';

/**
 * 获取CLodop配置（零售POS专用）
 */
function ruiyi_retail_get_clodop_config() {
    return array(
        'enabled' => (bool)get_option('ruiyi_retail_clodop_enabled', false),
        'default_printer' => get_option('ruiyi_retail_default_printer', ''),
        'drawer_printer' => get_option('ruiyi_retail_drawer_printer', ''),
    );
}

// Include translation generator
require_once RUIYI_POS_DIR . '/inc/generate-translations.php';

// Include user management system features
require_once RUIYI_POS_DIR . '/inc/auth.php';
require_once RUIYI_POS_DIR . '/inc/user-points-system.php';
require_once RUIYI_POS_DIR . '/inc/flutter-api-endpoints.php';
require_once RUIYI_POS_DIR . '/inc/referral-system.php';
require_once RUIYI_POS_DIR . '/inc/user-registration.php'; // 用户注册功能
require_once RUIYI_POS_DIR . '/inc/customer-display-admin.php'; // 客户显示屏管理
require_once RUIYI_POS_DIR . '/inc/product-tax-rates.php'; // 产品税率管理系统
require_once RUIYI_POS_DIR . '/inc/clodop-settings.php'; // CLodop打印机设置
require_once RUIYI_POS_DIR . '/inc/class-meilisearch-sync.php'; // Meilisearch product search

// Define unified font size constants for 80mm thermal printer optimization
define('RUIYI_POS_RECEIPT_FONT_SIZE', 14);          // Optimized 14pt font size for 80mm thermal printers
define('RUIYI_POS_RECEIPT_TITLE_FONT_SIZE', 16);    // Larger 16pt font size for receipt title
define('RUIYI_POS_RECEIPT_FOOTER_FONT_SIZE', 12);   // Medium 12pt font size for receipt footer
define('RUIYI_POS_RECEIPT_LINE_HEIGHT', 18);        // Increased line height for better readability
define('RUIYI_POS_RECEIPT_MARGIN', 8);              // Optimized margin for 80mm width

/**
 * Theme Setup
 */
function ruiyi_pos_setup() {
    // Add theme support
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', array(
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
        'script',
        'style',
    ));
    
    // Add WooCommerce support
    add_theme_support('woocommerce');
    add_theme_support('wc-product-gallery-zoom');
    add_theme_support('wc-product-gallery-lightbox');
    add_theme_support('wc-product-gallery-slider');
    
    // Register navigation menus
    register_nav_menus(array(
        'pos-main-menu' => __('POS Main Menu', 'ruiyi-retail-pos'),
        'pos-user-menu' => __('POS User Menu', 'ruiyi-retail-pos'),
    ));
    
    // Load text domain - will be handled by ruiyi_pos_init_language() function
}
add_action('after_setup_theme', 'ruiyi_pos_setup');

/**
 * Enqueue scripts and styles
 */
function ruiyi_pos_enqueue_scripts() {
    // Use version for cache busting in production
    $version = RUIYI_POS_VERSION;
    
    // Tailwind CSS - Local copy for offline reliability
    wp_enqueue_script('tailwindcss', RUIYI_POS_URI . '/assets/js/tailwind.min.js', array(), $version, false);
    
    // Main stylesheet (for theme-specific styles)
    wp_enqueue_style('ruiyi-pos-style', get_stylesheet_uri(), array(), $version);
    
    // Custom CSS (legacy styles being phased out)
    wp_enqueue_style('ruiyi-pos-main', RUIYI_POS_URI . '/assets/css/main.css', array(), $version);
    
    // POS Receipt optimized styles for 80mm thermal printers
    wp_enqueue_style('ruiyi-pos-receipt', RUIYI_POS_URI . '/assets/css/pos-receipt-styles.css', array(), $version, 'print');
    
    // Modern JavaScript (no jQuery dependency)
    wp_enqueue_script('ruiyi-pos-main', RUIYI_POS_URI . '/assets/js/main.js', array('jquery'), $version, true);
    
    // PWA Registration Script
    wp_enqueue_script('ruiyi-pos-pwa-register', RUIYI_POS_URI . '/assets/js/pwa-register.js', array(), $version, true);
    
    // Pre-cache all POS pages script
    wp_enqueue_script('ruiyi-pos-precache', RUIYI_POS_URI . '/assets/js/precache-all-pages.js', array('ruiyi-pos-pwa-register'), $version, true);
    
    // SPA Navigation - Load pages without reload
    wp_enqueue_script('ruiyi-pos-spa-nav', RUIYI_POS_URI . '/assets/js/spa-navigation.js', array('ruiyi-pos-pwa-register'), $version, true);
    
    // Force cache script - ensures pages are cached
    wp_enqueue_script('ruiyi-pos-force-cache', RUIYI_POS_URI . '/assets/js/force-cache-pages.js', array('ruiyi-pos-pwa-register'), $version, true);
    
    // Pre-cache POS functionality on initial site load (runs on ALL pages)
    wp_enqueue_script('ruiyi-pos-preload-functionality', RUIYI_POS_URI . '/assets/js/preload-pos-functionality.js', array('ruiyi-pos-pwa-register'), $version, true);

    // CRITICAL: Cache product list on first site load (so Start Selling shows products offline)
    // This script is safe to load globally; it only intercepts/handles POS product AJAX calls.
    wp_enqueue_script('ruiyi-pos-offline-products', RUIYI_POS_URI . '/assets/js/offline-products-cache.js', array('ruiyi-pos-main'), $version, true);

    // Product sync Web Worker URL (loaded by offline-products-cache.js, not executed directly)
    wp_localize_script('ruiyi-pos-offline-products', 'ruiyi_pos_worker', array(
        'worker_url' => RUIYI_POS_URI . '/assets/js/product-sync-worker.js',
    ));

    // Offline products cache - only on pos-checkout page
    if (is_page_template('page-templates/pos-checkout.php')) {
        wp_enqueue_script('ruiyi-pos-offline-shortcuts', RUIYI_POS_URI . '/assets/js/fix-offline-shortcuts.js', array('ruiyi-pos-main'), $version, true);
        // Enforcer script - highest priority, works even if POS object fails to initialize
        wp_enqueue_script('ruiyi-pos-shortcuts-enforcer', RUIYI_POS_URI . '/assets/js/offline-shortcuts-enforcer.js', array(), $version, true);
        // Cache ALL functionality (scripts, functions, buttons, shortcuts)
        wp_enqueue_script('ruiyi-pos-functionality-cache', RUIYI_POS_URI . '/assets/js/cache-pos-functionality.js', array('ruiyi-pos-main'), $version, true);
        // Offline payments: queue payments when offline, auto-sync when online
        wp_enqueue_script('ruiyi-pos-offline-payments', RUIYI_POS_URI . '/assets/js/offline-payment-queue.js', array('ruiyi-pos-main'), $version, true);
    }
    
    // Force cache bust by adding timestamp
    wp_add_inline_script('ruiyi-pos-main', 'window.RUIYI_POS_CACHE_VERSION = ' . time() . ';', 'before');
    
    // Localize script for AJAX and modern JavaScript
    $current_user = wp_get_current_user();

    // 获取快捷键设置
    $default_shortcuts = array(
        'complete_sale' => 'F4',  // 🔥 改为F4，F1专用于钱箱
        'clear_cart' => 'F2',
        'hold_order' => 'F2',
        'view_held_orders' => 'F3',
        'barcode_scanner' => 'F3',
        'cash_drawer' => 'F1',
        'payment_cash' => 'F5',
        'quick_add_product' => 'F6',  // 🔥 F6改为快速添加产品
        'supplier_management' => 'F7',
        'points_management' => 'F8',
        'search_products' => 'F9',
        'discount' => 'F10',
        'void_transaction' => 'F11'
    );
    $keyboard_shortcuts = get_option('ruiyi_pos_keyboard_shortcuts', $default_shortcuts);

    wp_localize_script('ruiyi-pos-main', 'ruiyi_pos_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ruiyi_pos_nonce'),
        'wc_api_url' => home_url('/wp-json/wc/v3/'),
        'site_url' => home_url(),
        'site_name' => get_bloginfo('name'),
        'user_name' => $current_user->display_name,
        'user_id' => $current_user->ID,
        'currency' => get_woocommerce_currency_symbol(),
        'currency_code' => get_woocommerce_currency(),
        'placeholder_image' => wc_placeholder_img_src(),
        'decimal_separator' => wc_get_price_decimal_separator(),
        'thousand_separator' => wc_get_price_thousand_separator(),
        'store_settings' => ruiyi_pos_get_store_settings(),
        'scale_integration' => ruiyi_pos_get_scale_integration_config(),
        'keyboard_shortcuts' => $keyboard_shortcuts, // 🔥 添加快捷键设置
        'default_bank' => get_option('ruiyi_pos_default_bank', array()), // 🔥 默认银行信息
        'toolbox_settings' => get_option('ruiyi_pos_toolbox_settings', array(
            'show_hold_order' => true,
            'show_view_held' => true,
            'show_clear_cart' => true,
            'show_print_last' => true
        )), // 🔥 工具箱显示设置
        'print_receipt_visible' => filter_var(get_option('ruiyi_retail_print_receipt_visible', '0'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false, // 🔥 开票模块打印小票按钮显示设置
        'meilisearch' => (function() {
            $ms = Ruiyi_Meilisearch_Sync::get_instance();
            $config = $ms->get_frontend_config();
            return $config ? $config : false;
        })(),
        'i18n' => array(
            'loading' => __('Loading...', 'ruiyi-retail-pos'),
            'processing' => __('Processing...', 'ruiyi-retail-pos'),
            'success' => __('Success!', 'ruiyi-retail-pos'),
            'error' => __('An error occurred', 'ruiyi-retail-pos'),
            'confirm_delete' => __('Are you sure you want to delete this item?', 'ruiyi-retail-pos'),
            'confirm_clear_cart' => __('Are you sure you want to clear the cart?', 'ruiyi-retail-pos'),
            'search_results' => __('Search completed', 'ruiyi-retail-pos'),
            'order_created' => __('Order created successfully', 'ruiyi-retail-pos'),
            'print_receipt' => __('Print Receipt', 'ruiyi-retail-pos'),
            'new_order' => __('New Order', 'ruiyi-retail-pos'),
            'empty_cart' => __('Cart is empty', 'ruiyi-retail-pos'),
            'invalid_quantity' => __('Invalid quantity', 'ruiyi-retail-pos'),
            'Low Stock' => __('Low Stock', 'ruiyi-retail-pos'),
            'cash_drawer_opened' => __('Cash drawer opened successfully', 'ruiyi-retail-pos'),
            
            // Receipt specific translations
            'Printing receipt...' => __('Printing receipt...', 'ruiyi-retail-pos'),
            'Print completed' => __('Print completed', 'ruiyi-retail-pos'),
            'Print initiated...' => __('Print initiated...', 'ruiyi-retail-pos'),
            'Date:' => __('Date:', 'ruiyi-retail-pos'),
            'Time:' => __('Time:', 'ruiyi-retail-pos'),
            'Order:' => __('Order:', 'ruiyi-retail-pos'),
            'Cashier:' => __('Cashier:', 'ruiyi-retail-pos'),
            'PRODUCTS' => __('PRODUCTS', 'ruiyi-retail-pos'),
            'TOTAL:' => __('TOTAL:', 'ruiyi-retail-pos'),
            'Thank you for your purchase!' => __('Thank you for your purchase!', 'ruiyi-retail-pos'),
            'Gracias por tu Pedido !' => __('Gracias por tu Pedido !', 'ruiyi-retail-pos'),
        ),
    ));
}
add_action('wp_enqueue_scripts', 'ruiyi_pos_enqueue_scripts');

/**
 * Add PWA meta tags and manifest link
 */
function ruiyi_pos_add_pwa_meta_tags() {
    $manifest_url = RUIYI_POS_URI . '/manifest.json';
    $theme_color = '#3B82F6';
    $site_name = get_bloginfo('name');
    $site_description = get_bloginfo('description');
    
    ?>
    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="<?php echo esc_attr($theme_color); ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?php echo esc_attr($site_name); ?>">
    <meta name="description" content="<?php echo esc_attr($site_description); ?>">
    <meta name="mobile-web-app-capable" content="yes">
    
    <!-- PWA Manifest -->
    <link rel="manifest" href="<?php echo esc_url($manifest_url); ?>">
    
    <!-- Apple Touch Icons -->
    <link rel="apple-touch-icon" sizes="72x72" href="<?php echo esc_url(RUIYI_POS_URI . '/pwa-icons/icon-72x72.png'); ?>">
    <link rel="apple-touch-icon" sizes="96x96" href="<?php echo esc_url(RUIYI_POS_URI . '/pwa-icons/icon-96x96.png'); ?>">
    <link rel="apple-touch-icon" sizes="128x128" href="<?php echo esc_url(RUIYI_POS_URI . '/pwa-icons/icon-128x128.png'); ?>">
    <link rel="apple-touch-icon" sizes="144x144" href="<?php echo esc_url(RUIYI_POS_URI . '/pwa-icons/icon-144x144.png'); ?>">
    <link rel="apple-touch-icon" sizes="152x152" href="<?php echo esc_url(RUIYI_POS_URI . '/pwa-icons/icon-152x152.png'); ?>">
    <link rel="apple-touch-icon" sizes="192x192" href="<?php echo esc_url(RUIYI_POS_URI . '/pwa-icons/icon-192x192.png'); ?>">
    <link rel="apple-touch-icon" sizes="384x384" href="<?php echo esc_url(RUIYI_POS_URI . '/pwa-icons/icon-384x384.png'); ?>">
    <link rel="apple-touch-icon" sizes="512x512" href="<?php echo esc_url(RUIYI_POS_URI . '/pwa-icons/icon-512x512.png'); ?>">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="192x192" href="<?php echo esc_url(RUIYI_POS_URI . '/pwa-icons/icon-192x192.png'); ?>">
    <link rel="icon" type="image/png" sizes="512x512" href="<?php echo esc_url(RUIYI_POS_URI . '/pwa-icons/icon-512x512.png'); ?>">
    <?php
}
add_action('wp_head', 'ruiyi_pos_add_pwa_meta_tags', 1);

/**
 * Add inline script to pre-cache important pages on first load
 */
function ruiyi_pos_add_pwa_precache_script() {
    // Get important pages to pre-cache - ALL POS pages
    // Note: Only include pages that actually exist to avoid 404 errors in console
    $important_pages = apply_filters('ruiyi_pos_pwa_precache_pages', array(
        home_url('/'),
        home_url('/pos-checkout/'),
    ));
    
    $pages_json = wp_json_encode($important_pages);
    ?>
    <script>
    // Pre-cache important pages when service worker is ready
    (function() {
        function preCachePages() {
            if (!('serviceWorker' in navigator)) return;
            
            var pagesToCache = <?php echo $pages_json; ?>;
            
            // Wait for service worker to be ready
            if (navigator.serviceWorker.controller) {
                cachePagesNow(pagesToCache);
            } else {
                navigator.serviceWorker.ready.then(function() {
                    cachePagesNow(pagesToCache);
                });
            }
        }
        
        function cachePagesNow(pagesToCache) {
            pagesToCache.forEach(function(url) {
                // Skip current page
                if (url === window.location.href || url === window.location.href.split('?')[0]) {
                    return;
                }

                // Pre-fetch and cache in background
                fetch(url, {
                    method: 'GET',
                    cache: 'default',
                    credentials: 'same-origin'
                })
                .then(async function(response) {
                    if (response && response.ok) {
                        // CRITICAL: Manually cache the response
                        if ('caches' in window) {
                            try {
                                var cacheNames = await caches.keys();
                                var html = await response.clone().text();

                                // Find or create pages cache
                                var pagesCacheName = null;
                                for (var i = 0; i < cacheNames.length; i++) {
                                    if (cacheNames[i].includes('pages')) {
                                        pagesCacheName = cacheNames[i];
                                        break;
                                    }
                                }

                                if (!pagesCacheName) {
                                    pagesCacheName = 'ruiyi-pos-pages-v1.6.0';
                                }

                                var cache = await caches.open(pagesCacheName);
                                var responseToCache = new Response(html, {
                                    status: response.status,
                                    statusText: response.statusText,
                                    headers: {
                                        'Content-Type': 'text/html; charset=utf-8'
                                    }
                                });

                                // Cache with multiple keys
                                await cache.put(url, responseToCache.clone());
                                await cache.put(new Request(url), responseToCache.clone());

                                var urlObj = new URL(url);
                                urlObj.search = '';
                                urlObj.hash = '';
                                await cache.put(urlObj.toString(), responseToCache.clone());

                                if (!url.endsWith('/')) {
                                    await cache.put(url + '/', responseToCache.clone());
                                }
                            } catch (e) {
                                // Silent cache error
                            }
                        }
                        
                        // Also send message to service worker
                        if (navigator.serviceWorker.controller) {
                            navigator.serviceWorker.controller.postMessage({
                                type: 'CACHE_PAGE',
                                url: url
                            });
                        }
                    }
                })
                .catch(function(error) {
                    if (navigator.onLine) {
                        console.warn('[PWA] Failed to pre-cache:', url, error);
                    }
                });
            });
        }
        
        // Cache on page load
        window.addEventListener('load', function() {
            setTimeout(preCachePages, 1000); // Wait 1 second after page load
        });
        
        // Also cache immediately if service worker is already ready
        if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
            preCachePages();
        }
    })();
    </script>
    <?php
}
add_action('wp_footer', 'ruiyi_pos_add_pwa_precache_script', 99);

/**
 * Normalised list of unit aliases that represent kilogram-based products.
 *
 * @return array
 */
function ruiyi_pos_get_weight_unit_aliases() {
    $defaults = array('kg', 'kilogram', 'kilograms', 'kilo', 'kilos', '公斤', '千克');
    $option_value = get_option('ruiyi_pos_weight_units', '');

    if (!empty($option_value)) {
        $custom_units = array_filter(array_map('trim', explode(',', $option_value)));
        if (!empty($custom_units)) {
            $custom_units = array_map('strtolower', $custom_units);
            $defaults = array_unique(array_merge($defaults, $custom_units));
        }
    }

    return array_values(array_map('strtolower', $defaults));
}

/**
 * Scale middleware configuration exposed to the POS front-end.
 *
 * @return array
 */
function ruiyi_pos_get_scale_integration_config() {
    $default_socket_url = 'https://srv985735.hstgr.cloud/scale';
    $socket_url = esc_url_raw(get_option('ruiyi_pos_scale_socket_url', $default_socket_url));
    if (empty($socket_url)) {
        $socket_url = $default_socket_url;
    }

    $config = array(
        'enabled' => (bool) get_option('ruiyi_pos_scale_enabled', false),
        'socket_url' => $socket_url,
        'socket_path' => sanitize_text_field(get_option('ruiyi_pos_scale_socket_path', '/socket.io')),
        'retry_ms' => max(500, intval(get_option('ruiyi_pos_scale_retry_ms', 2000))),
        'stale_after_ms' => max(1000, intval(get_option('ruiyi_pos_scale_stale_after_ms', 5000))),
        'precision' => max(0, intval(get_option('ruiyi_pos_scale_precision', 3))),
        'min_weight_kg' => max(0, floatval(get_option('ruiyi_pos_scale_min_weight', 0.005))),
        'weight_unit' => sanitize_text_field(get_option('ruiyi_pos_scale_weight_unit', 'kg')),
        'unit_aliases' => ruiyi_pos_get_weight_unit_aliases(),
        'debug' => (bool) get_option('ruiyi_pos_scale_debug', false),
        'location' => sanitize_text_field(get_option('ruiyi_pos_scale_location', 'store-002')),
        'auth_token' => sanitize_text_field(get_option('ruiyi_pos_scale_auth_token', '')),
        'client_type' => 'pos',
    );

    /**
     * Allow plugins to adjust the scale integration configuration before it reaches the front-end.
     *
     * @param array $config
     */
    return apply_filters('ruiyi_pos_scale_config', $config);
}

/**
 * Add "Sold by weight" checkbox to WooCommerce product settings.
 */
function ruiyi_pos_add_scale_product_field() {
    echo '<div class="options_group">';
    woocommerce_wp_checkbox(array(
        'id'          => '_is_scale_product',
        'label'       => __('Sold by weight (scale)', 'ruiyi-retail-pos'),
        'description' => __('Enable if this product price should be calculated using live scale weight in POS.', 'ruiyi-retail-pos'),
        'desc_tip'    => true,
    ));
    echo '</div>';
}
add_action('woocommerce_product_options_general_product_data', 'ruiyi_pos_add_scale_product_field');

/**
 * Add supplier field to WooCommerce product settings.
 */
function ruiyi_pos_add_supplier_field() {
    echo '<div class="options_group">';
    woocommerce_wp_text_input(array(
        'id'          => '_supplier',
        'label'       => __('供应商', 'ruiyi-retail-pos'),
        'placeholder' => __('输入供应商名称', 'ruiyi-retail-pos'),
        'desc_tip'    => true,
        'description' => __('产品的供应商名称', 'ruiyi-retail-pos'),
    ));
    echo '</div>';
}
add_action('woocommerce_product_options_general_product_data', 'ruiyi_pos_add_supplier_field');

/**
 * Save supplier field value.
 *
 * @param int $product_id
 */
function ruiyi_pos_save_supplier_field($product_id) {
    $supplier = isset($_POST['_supplier']) ? sanitize_text_field($_POST['_supplier']) : '';
    update_post_meta($product_id, '_supplier', $supplier);
}
add_action('woocommerce_process_product_meta', 'ruiyi_pos_save_supplier_field');

/**
 * Add supplier column to WooCommerce products list.
 */
function ruiyi_pos_add_supplier_column($columns) {
    // 在库存列之后添加供应商列
    $new_columns = array();
    foreach ($columns as $key => $value) {
        $new_columns[$key] = $value;
        if ($key === 'is_in_stock') {
            $new_columns['supplier'] = __('供应商', 'ruiyi-retail-pos');
        }
    }
    return $new_columns;
}
add_filter('manage_edit-product_columns', 'ruiyi_pos_add_supplier_column');

/**
 * Display supplier in products list column.
 */
function ruiyi_pos_display_supplier_column($column, $post_id) {
    if ($column === 'supplier') {
        $supplier = get_post_meta($post_id, '_supplier', true);
        // 🔥 有供应商就显示，没有就完全留空（不显示任何字符）
        if (!empty($supplier)) {
            echo '<span style="color: #2271b1; font-weight: 500;">' . esc_html($supplier) . '</span>';
        }
        // 没有供应商时不输出任何内容（完全空白）
    }
}
add_action('manage_product_posts_custom_column', 'ruiyi_pos_display_supplier_column', 10, 2);

/**
 * Make supplier column sortable.
 */
function ruiyi_pos_make_supplier_column_sortable($columns) {
    $columns['supplier'] = 'supplier';
    return $columns;
}
add_filter('manage_edit-product_sortable_columns', 'ruiyi_pos_make_supplier_column_sortable');

/**
 * Save "Sold by weight" checkbox value.
 *
 * @param int $product_id
 */
function ruiyi_pos_save_scale_product_field($product_id) {
    $is_scale = isset($_POST['_is_scale_product']) ? 'yes' : 'no';
    update_post_meta($product_id, '_is_scale_product', $is_scale);
}
add_action('woocommerce_process_product_meta', 'ruiyi_pos_save_scale_product_field');

/**
 * Register scale-related settings so they can be managed via wp-admin if needed.
 */
function ruiyi_pos_register_scale_settings() {
    if (!function_exists('register_setting')) {
        return;
    }

    register_setting('general', 'ruiyi_pos_scale_enabled', array(
        'type' => 'boolean',
        'sanitize_callback' => function($value) {
            return (bool) $value;
        },
        'default' => false,
    ));

    register_setting('general', 'ruiyi_pos_scale_socket_url', array(
        'type' => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default' => 'http://127.0.0.1:4010/scale',
    ));

    register_setting('general', 'ruiyi_pos_scale_weight_unit', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => 'kg',
    ));

    register_setting('general', 'ruiyi_pos_scale_precision', array(
        'type' => 'integer',
        'sanitize_callback' => 'absint',
        'default' => 3,
    ));

    register_setting('general', 'ruiyi_pos_scale_min_weight', array(
        'type' => 'number',
        'sanitize_callback' => 'floatval',
        'default' => 0.005,
    ));

    register_setting('general', 'ruiyi_pos_scale_location', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => 'default',
    ));

    register_setting('general', 'ruiyi_pos_scale_auth_token', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => '',
    ));

    register_setting('general', 'ruiyi_pos_weight_units', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => '',
    ));
}
add_action('admin_init', 'ruiyi_pos_register_scale_settings');

/**
 * 添加电子秤设置菜单到WordPress后台
 */
function ruiyi_pos_add_scale_settings_menu() {
    add_submenu_page(
        'woocommerce',  // 添加到WooCommerce菜单下
        '电子秤设置',    // 页面标题
        '⚖️ 电子秤设置', // 菜单标题
        'manage_woocommerce', // 权限要求
        'ruiyi-scale-settings', // 菜单slug
        'ruiyi_pos_render_scale_settings_page' // 渲染函数
    );
}
add_action('admin_menu', 'ruiyi_pos_add_scale_settings_menu');

/**
 * 渲染电子秤设置页面
 */
function ruiyi_pos_render_scale_settings_page() {
    // 检查用户权限
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('您没有权限访问此页面。'));
    }

    // 保存设置
    if (isset($_POST['ruiyi_scale_settings_nonce']) && wp_verify_nonce($_POST['ruiyi_scale_settings_nonce'], 'ruiyi_scale_settings')) {
        // 保存启用状态
        update_option('ruiyi_pos_scale_enabled', isset($_POST['scale_enabled']) ? '1' : '0');

        // 保存Socket URL
        if (isset($_POST['scale_socket_url'])) {
            update_option('ruiyi_pos_scale_socket_url', esc_url_raw($_POST['scale_socket_url']));
        }

        // 保存Socket路径
        if (isset($_POST['scale_socket_path'])) {
            update_option('ruiyi_pos_scale_socket_path', sanitize_text_field($_POST['scale_socket_path']));
        }

        // 保存位置标识
        if (isset($_POST['scale_location'])) {
            update_option('ruiyi_pos_scale_location', sanitize_text_field($_POST['scale_location']));
        }

        // 保存重量单位
        if (isset($_POST['scale_weight_unit'])) {
            update_option('ruiyi_pos_scale_weight_unit', sanitize_text_field($_POST['scale_weight_unit']));
        }

        // 保存精度
        if (isset($_POST['scale_precision'])) {
            update_option('ruiyi_pos_scale_precision', absint($_POST['scale_precision']));
        }

        // 保存最小重量
        if (isset($_POST['scale_min_weight'])) {
            update_option('ruiyi_pos_scale_min_weight', floatval($_POST['scale_min_weight']));
        }

        // 保存过期时间
        if (isset($_POST['scale_stale_after_ms'])) {
            update_option('ruiyi_pos_scale_stale_after_ms', absint($_POST['scale_stale_after_ms']));
        }

        // 保存重试间隔
        if (isset($_POST['scale_retry_ms'])) {
            update_option('ruiyi_pos_scale_retry_ms', absint($_POST['scale_retry_ms']));
        }

        // 保存认证令牌
        if (isset($_POST['scale_auth_token'])) {
            update_option('ruiyi_pos_scale_auth_token', sanitize_text_field($_POST['scale_auth_token']));
        }

        // 保存调试模式
        update_option('ruiyi_pos_scale_debug', isset($_POST['scale_debug']) ? '1' : '0');

        // 保存显示电子秤状态模块设置
        update_option('ruiyi_pos_scale_show_widget', isset($_POST['scale_show_widget']) ? '1' : '0');

        echo '<div class="notice notice-success is-dismissible"><p>设置已保存！</p></div>';
    }

    // 获取当前设置值
    $scale_enabled = get_option('ruiyi_pos_scale_enabled', '0');
    $socket_url = get_option('ruiyi_pos_scale_socket_url', 'https://srv985735.hstgr.cloud/scale');
    $socket_path = get_option('ruiyi_pos_scale_socket_path', '/socket.io');
    $location = get_option('ruiyi_pos_scale_location', 'store-002');
    $weight_unit = get_option('ruiyi_pos_scale_weight_unit', 'kg');
    $precision = get_option('ruiyi_pos_scale_precision', 3);
    $min_weight = get_option('ruiyi_pos_scale_min_weight', 0.005);
    $stale_after_ms = get_option('ruiyi_pos_scale_stale_after_ms', 5000);
    $retry_ms = get_option('ruiyi_pos_scale_retry_ms', 2000);
    $auth_token = get_option('ruiyi_pos_scale_auth_token', '');
    $debug_mode = get_option('ruiyi_pos_scale_debug', '0');
    $show_widget = get_option('ruiyi_pos_scale_show_widget', '1'); // 默认显示

    ?>
    <div class="wrap">
        <h1>⚖️ 电子秤设置</h1>
        <p class="description">配置POS系统的电子秤集成设置</p>

        <form method="post" action="">
            <?php wp_nonce_field('ruiyi_scale_settings', 'ruiyi_scale_settings_nonce'); ?>

            <table class="form-table" role="presentation">
                <tbody>
                    <!-- 启用电子秤 -->
                    <tr>
                        <th scope="row">
                            <label for="scale_enabled">启用电子秤</label>
                        </th>
                        <td>
                            <label for="scale_enabled">
                                <input type="checkbox"
                                       id="scale_enabled"
                                       name="scale_enabled"
                                       value="1"
                                       <?php checked($scale_enabled, '1'); ?>>
                                启用电子秤功能
                            </label>
                            <p class="description">勾选后，POS系统将连接到电子秤服务器</p>
                        </td>
                    </tr>

                    <!-- Socket服务器URL -->
                    <tr>
                        <th scope="row">
                            <label for="scale_socket_url">Socket服务器URL</label>
                        </th>
                        <td>
                            <input type="url"
                                   id="scale_socket_url"
                                   name="scale_socket_url"
                                   value="<?php echo esc_attr($socket_url); ?>"
                                   class="regular-text"
                                   placeholder="https://srv985735.hstgr.cloud/scale">
                            <p class="description">电子秤WebSocket服务器地址</p>
                        </td>
                    </tr>

                    <!-- Socket路径 -->
                    <tr>
                        <th scope="row">
                            <label for="scale_socket_path">Socket路径</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="scale_socket_path"
                                   name="scale_socket_path"
                                   value="<?php echo esc_attr($socket_path); ?>"
                                   class="regular-text"
                                   placeholder="/socket.io">
                            <p class="description">Socket.IO路径（通常为 /socket.io）</p>
                        </td>
                    </tr>

                    <!-- 店铺位置标识 -->
                    <tr>
                        <th scope="row">
                            <label for="scale_location">店铺位置标识</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="scale_location"
                                   name="scale_location"
                                   value="<?php echo esc_attr($location); ?>"
                                   class="regular-text"
                                   placeholder="store-002">
                            <p class="description">唯一标识此店铺的位置代码（例如：store-001, store-002）</p>
                        </td>
                    </tr>

                    <!-- 重量单位 -->
                    <tr>
                        <th scope="row">
                            <label for="scale_weight_unit">重量单位</label>
                        </th>
                        <td>
                            <select id="scale_weight_unit" name="scale_weight_unit">
                                <option value="kg" <?php selected($weight_unit, 'kg'); ?>>千克 (kg)</option>
                                <option value="g" <?php selected($weight_unit, 'g'); ?>>克 (g)</option>
                                <option value="lb" <?php selected($weight_unit, 'lb'); ?>>磅 (lb)</option>
                            </select>
                            <p class="description">电子秤使用的重量单位</p>
                        </td>
                    </tr>

                    <!-- 重量精度 -->
                    <tr>
                        <th scope="row">
                            <label for="scale_precision">重量精度（小数位）</label>
                        </th>
                        <td>
                            <input type="number"
                                   id="scale_precision"
                                   name="scale_precision"
                                   value="<?php echo esc_attr($precision); ?>"
                                   min="0"
                                   max="5"
                                   step="1"
                                   class="small-text">
                            <p class="description">重量显示的小数位数（0-5，推荐：3）</p>
                        </td>
                    </tr>

                    <!-- 最小重量 -->
                    <tr>
                        <th scope="row">
                            <label for="scale_min_weight">最小有效重量 (kg)</label>
                        </th>
                        <td>
                            <input type="number"
                                   id="scale_min_weight"
                                   name="scale_min_weight"
                                   value="<?php echo esc_attr($min_weight); ?>"
                                   min="0"
                                   max="1"
                                   step="0.001"
                                   class="small-text">
                            <p class="description">低于此重量的读数将被忽略（推荐：0.005）</p>
                        </td>
                    </tr>

                    <!-- 数据过期时间 -->
                    <tr>
                        <th scope="row">
                            <label for="scale_stale_after_ms">数据过期时间 (毫秒)</label>
                        </th>
                        <td>
                            <input type="number"
                                   id="scale_stale_after_ms"
                                   name="scale_stale_after_ms"
                                   value="<?php echo esc_attr($stale_after_ms); ?>"
                                   min="1000"
                                   max="30000"
                                   step="1000"
                                   class="small-text">
                            <p class="description">超过此时间未更新的重量数据将视为过期（推荐：5000）</p>
                        </td>
                    </tr>

                    <!-- 重连间隔 -->
                    <tr>
                        <th scope="row">
                            <label for="scale_retry_ms">重连间隔 (毫秒)</label>
                        </th>
                        <td>
                            <input type="number"
                                   id="scale_retry_ms"
                                   name="scale_retry_ms"
                                   value="<?php echo esc_attr($retry_ms); ?>"
                                   min="500"
                                   max="10000"
                                   step="500"
                                   class="small-text">
                            <p class="description">连接失败后的重试间隔（推荐：2000）</p>
                        </td>
                    </tr>

                    <!-- 认证令牌 -->
                    <tr>
                        <th scope="row">
                            <label for="scale_auth_token">认证令牌（可选）</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="scale_auth_token"
                                   name="scale_auth_token"
                                   value="<?php echo esc_attr($auth_token); ?>"
                                   class="regular-text"
                                   placeholder="留空表示不需要认证">
                            <p class="description">如果服务器需要认证，请输入令牌</p>
                        </td>
                    </tr>

                    <!-- 调试模式 -->
                    <tr>
                        <th scope="row">
                            <label for="scale_debug">调试模式</label>
                        </th>
                        <td>
                            <label for="scale_debug">
                                <input type="checkbox"
                                       id="scale_debug"
                                       name="scale_debug"
                                       value="1"
                                       <?php checked($debug_mode, '1'); ?>>
                                启用调试模式
                            </label>
                            <p class="description">启用后，浏览器控制台将显示详细的电子秤日志</p>
                        </td>
                    </tr>

                    <!-- 显示电子秤状态模块 -->
                    <tr>
                        <th scope="row">
                            <label for="scale_show_widget">显示电子秤状态模块</label>
                        </th>
                        <td>
                            <label for="scale_show_widget">
                                <input type="checkbox"
                                       id="scale_show_widget"
                                       name="scale_show_widget"
                                       value="1"
                                       <?php checked($show_widget, '1'); ?>>
                                在POS收银界面显示电子秤状态模块
                            </label>
                            <p class="description">关闭后，POS收银界面将隐藏电子秤状态卡片（电子秤功能仍可正常使用）</p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p class="submit">
                <input type="submit"
                       name="submit"
                       id="submit"
                       class="button button-primary"
                       value="保存设置">
            </p>
        </form>

        <hr>

        <h2>🔧 当前配置状态</h2>
        <table class="widefat" style="max-width: 800px;">
            <thead>
                <tr>
                    <th>配置项</th>
                    <th>当前值</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>电子秤状态</strong></td>
                    <td><?php echo $scale_enabled === '1' ? '<span style="color: green;">✓ 已启用</span>' : '<span style="color: red;">✗ 已禁用</span>'; ?></td>
                </tr>
                <tr>
                    <td><strong>Socket URL</strong></td>
                    <td><code><?php echo esc_html($socket_url); ?></code></td>
                </tr>
                <tr>
                    <td><strong>位置标识</strong></td>
                    <td><code><?php echo esc_html($location); ?></code></td>
                </tr>
                <tr>
                    <td><strong>重量单位</strong></td>
                    <td><?php echo esc_html($weight_unit); ?></td>
                </tr>
                <tr>
                    <td><strong>精度</strong></td>
                    <td><?php echo esc_html($precision); ?> 位小数</td>
                </tr>
                <tr>
                    <td><strong>最小重量</strong></td>
                    <td><?php echo esc_html($min_weight); ?> kg</td>
                </tr>
                <tr>
                    <td><strong>调试模式</strong></td>
                    <td><?php echo $debug_mode === '1' ? '<span style="color: orange;">✓ 开启</span>' : '关闭'; ?></td>
                </tr>
            </tbody>
        </table>

        <hr>

        <h2>📖 使用说明</h2>
        <div class="card" style="max-width: 800px; padding: 20px;">
            <h3>1. 基本配置</h3>
            <ul>
                <li><strong>Socket服务器URL</strong>：电子秤WebSocket服务器的完整地址</li>
                <li><strong>位置标识</strong>：用于区分不同店铺/收银台的唯一代码</li>
            </ul>

            <h3>2. 高级配置</h3>
            <ul>
                <li><strong>重量精度</strong>：显示小数位数，例如3表示显示为1.234kg</li>
                <li><strong>最小重量</strong>：低于此值的重量读数将被忽略（防止误读）</li>
                <li><strong>数据过期时间</strong>：超过此时间未更新的重量数据将标记为过期</li>
                <li><strong>重连间隔</strong>：连接断开后的自动重连间隔</li>
            </ul>

            <h3>3. 测试连接</h3>
            <p>保存设置后，前往 <a href="<?php echo home_url('/pos-checkout/'); ?>" target="_blank">POS收银页面</a> 查看电子秤连接状态。</p>
        </div>
    </div>
    <?php
}

/**
 * Get cached products for POS with performance optimization
 */
function ruiyi_pos_get_cached_products($args = array()) {
    $cache_key = 'ruiyi_pos_products_' . md5(serialize($args));
    $cached_products = wp_cache_get($cache_key, 'ruiyi_pos');
    
    if (false === $cached_products) {
        $default_args = array(
            'post_type' => 'product',
            'posts_per_page' => 60,
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_stock_status',
                    'value' => 'instock',
                ),
            ),
            'orderby' => 'menu_order title',
            'order' => 'ASC',
        );
        
        $args = wp_parse_args($args, $default_args);
        $products = get_posts($args);
        
        // Cache for 5 minutes
        wp_cache_set($cache_key, $products, 'ruiyi_pos', 300);
        $cached_products = $products;
    }
    
    return $cached_products;
}

/**
 * Clear POS cache when products are updated
 */
function ruiyi_pos_clear_cache() {
    wp_cache_flush_group('ruiyi_pos');
}
add_action('save_post', 'ruiyi_pos_clear_cache');
add_action('woocommerce_product_set_stock', 'ruiyi_pos_clear_cache');

/**
 * Custom user roles for POS system
 */
function ruiyi_pos_add_roles() {
    // POS Manager role
    add_role('pos_manager', __('POS Manager', 'ruiyi-retail-pos'), array(
        'read' => true,
        'edit_posts' => false,
        'delete_posts' => false,
        'manage_woocommerce' => true,
        'view_woocommerce_reports' => true,
        'edit_shop_orders' => true,
        'read_shop_orders' => true,
        'delete_shop_orders' => false,
        'publish_shop_orders' => true,
        'edit_published_shop_orders' => true,
        'manage_pos' => true,
    ));
    
    // Cashier role
    add_role('pos_cashier', __('Cashier', 'ruiyi-retail-pos'), array(
        'read' => true,
        'edit_posts' => false,
        'delete_posts' => false,
        'edit_shop_orders' => true,
        'read_shop_orders' => true,
        'publish_shop_orders' => true,
        'use_pos' => true,
    ));
}
add_action('init', 'ruiyi_pos_add_roles');

/**
 * Multi-language support functions
 */

/**
 * Custom translation function that bypasses WordPress MO files
 */
function ruiyi_pos_translate($string, $context = 'default') {
    // Use global cache that can be cleared - add version check to force reload
    $cache_version = filemtime(RUIYI_POS_DIR . '/languages/translations.php');
    
    if (!isset($GLOBALS['ruiyi_pos_translation_cache']) || 
        !isset($GLOBALS['ruiyi_pos_translation_cache_version']) ||
        $GLOBALS['ruiyi_pos_translation_cache_version'] !== $cache_version) {
        
        $translations_file = RUIYI_POS_DIR . '/languages/translations.php';
        if (file_exists($translations_file)) {
            $GLOBALS['ruiyi_pos_translation_cache'] = include $translations_file;
            $GLOBALS['ruiyi_pos_translation_cache_version'] = $cache_version;
        } else {
            $GLOBALS['ruiyi_pos_translation_cache'] = array();
        }
    }
    
    $translations = $GLOBALS['ruiyi_pos_translation_cache'];
    
    // Get current language
    $current_language = ruiyi_pos_get_current_language();
    
    // Return translation if available
    if (isset($translations[$current_language][$string])) {
        return $translations[$current_language][$string];
    }
    
    // Fallback to original string
    return $string;
}

/**
 * Custom translation function with echo (equivalent to _e)
 */
function ruiyi_pos_translate_echo($string, $context = 'default') {
    echo ruiyi_pos_translate($string, $context);
}

/**
 * Override WordPress translation functions for POS templates
 */
function ruiyi_pos_override_translations() {
    // Only override on POS pages
    if (is_page_template(array(
        'page-templates/pos-checkout.php',
        'page-templates/page-pos.php',
        'page-templates/login.php'
    ))) {
        
        // Add single filter to override both __() and _e() function calls
        add_filter('gettext', function($translation, $text, $domain) {
            if ($domain === 'ruiyi-retail-pos') {
                return ruiyi_pos_translate($text);
            }
            return $translation;
        }, 999, 3); // High priority to ensure it runs last
    }
}
add_action('template_redirect', 'ruiyi_pos_override_translations', 1);

/**
 * Get available languages for POS system
 */
function ruiyi_pos_get_available_languages() {
    return array(
        'en_US' => array(
            'name' => 'English',
            'native' => 'English',
            'flag' => '🇺🇸',
            'code' => 'en'
        ),
        'zh_CN' => array(
            'name' => 'Chinese (Simplified)',
            'native' => '简体中文',
            'flag' => '🇨🇳',
            'code' => 'zh'
        ),
        'es_ES' => array(
            'name' => 'Spanish',
            'native' => 'Español',
            'flag' => '🇪🇸',
            'code' => 'es'
        ),
    );
}

/**
 * Get current POS language
 */
function ruiyi_pos_get_current_language() {
    $available_languages = ruiyi_pos_get_available_languages();

    // Check for URL parameter first (for immediate switching)
    if (isset($_GET['lang'])) {
        $lang = sanitize_text_field($_GET['lang']);
        if (array_key_exists($lang, $available_languages)) {
            // Also persist this language choice so it sticks after redirect
            ruiyi_pos_persist_language($lang);
            return $lang;
        }
    }

    // Check if user is logged in and has a saved preference
    if (is_user_logged_in()) {
        $user_lang = get_user_meta(get_current_user_id(), 'ruiyi_pos_language', true);
        if (!empty($user_lang) && array_key_exists($user_lang, $available_languages)) {
            return $user_lang;
        }
    }

    // Check cookie as primary fallback (most reliable for persistence)
    if (isset($_COOKIE['ruiyi_pos_language'])) {
        $cookie_lang = sanitize_text_field($_COOKIE['ruiyi_pos_language']);
        if (array_key_exists($cookie_lang, $available_languages)) {
            return $cookie_lang;
        }
    }

    // Check session for non-logged in users
    if (session_status() == PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }

    if (isset($_SESSION['ruiyi_pos_language']) && array_key_exists($_SESSION['ruiyi_pos_language'], $available_languages)) {
        return $_SESSION['ruiyi_pos_language'];
    }

    // Fall back to WordPress locale
    $wp_locale = get_locale();

    // Check if WordPress locale is in our supported languages
    if (array_key_exists($wp_locale, $available_languages)) {
        return $wp_locale;
    }

    // Default to English
    return 'en_US';
}

/**
 * Persist language choice without full set_language overhead
 */
function ruiyi_pos_persist_language($language) {
    // Save to user meta if logged in
    if (is_user_logged_in()) {
        update_user_meta(get_current_user_id(), 'ruiyi_pos_language', $language);
    }

    // Save to session
    if (session_status() == PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
    if (session_status() == PHP_SESSION_ACTIVE) {
        $_SESSION['ruiyi_pos_language'] = $language;
    }

    // Cookie is set via setcookie, but it won't be available until next request
    // The AJAX call already sets the cookie, so this is mainly for session/user_meta
}

/**
 * Set POS language
 */
function ruiyi_pos_set_language($language) {
    $available_languages = ruiyi_pos_get_available_languages();
    
    if (!array_key_exists($language, $available_languages)) {
        return false;
    }
    
    // Save to user meta if logged in
    if (is_user_logged_in()) {
        update_user_meta(get_current_user_id(), 'ruiyi_pos_language', $language);
    }
    
    // Save to session for non-logged in users
    if (session_status() == PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
    $_SESSION['ruiyi_pos_language'] = $language;
    
    // Also save to cookie for persistence
    setcookie('ruiyi_pos_language', $language, time() + (30 * 24 * 60 * 60), '/'); // 30 days
    
    // Switch WordPress locale
    switch_to_locale($language);
    
    return true;
}

/**
 * Set locale filter very early
 */
function ruiyi_pos_set_locale_filter() {
    add_filter('locale', function($locale) {
        $pos_language = ruiyi_pos_get_current_language();
        return $pos_language;
    }, 10);
}
add_action('plugins_loaded', 'ruiyi_pos_set_locale_filter', 1);

/**
 * Initialize POS language on theme setup
 */
function ruiyi_pos_init_language() {
    $current_language = ruiyi_pos_get_current_language();
    
    // Load theme text domain
    load_theme_textdomain('ruiyi-retail-pos', RUIYI_POS_DIR . '/languages');
    
    // Also update WordPress locale
    if ($current_language !== 'en_US') {
        switch_to_locale($current_language);
    }
}
add_action('after_setup_theme', 'ruiyi_pos_init_language', 5);

/**
 * Force reload text domain after language switch
 */
function ruiyi_pos_reload_textdomain($language) {
    global $l10n;
    
    // Clear our custom translation cache
    $GLOBALS['ruiyi_pos_translation_cache'] = null;
    $GLOBALS['ruiyi_pos_translation_cache_version'] = null;
    
    // Unload current text domain
    if (isset($l10n['ruiyi-retail-pos'])) {
        unset($l10n['ruiyi-retail-pos']);
    }
    unload_textdomain('ruiyi-retail-pos');
    
    // Update locale filter
    add_filter('locale', function() use ($language) {
        return $language;
    }, 999);
    
    // Switch WordPress locale
    switch_to_locale($language);
    
    // Force reload text domain with new language
    load_theme_textdomain('ruiyi-retail-pos', RUIYI_POS_DIR . '/languages');
    
    // Also try loading from WordPress languages directory as fallback
    if (!is_textdomain_loaded('ruiyi-retail-pos')) {
        load_textdomain('ruiyi-retail-pos', WP_LANG_DIR . '/themes/ruiyi-retail-pos-' . $language . '.mo');
    }
}

/**
 * Ensure language is set very early in template loading
 */
function ruiyi_pos_template_language_setup() {
    if (is_page_template(array(
        'page-templates/pos-checkout.php',
        'page-templates/page-pos.php',
        'page-templates/login.php'
    ))) {
        $current_language = ruiyi_pos_get_current_language();
        ruiyi_pos_reload_textdomain($current_language);
    }
}
add_action('template_redirect', 'ruiyi_pos_template_language_setup', 1);

/**
 * AJAX handler for language switching
 */
function ruiyi_pos_switch_language() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'ruiyi_pos_nonce')) {
        wp_send_json_error(array('message' => __('Security check failed', 'ruiyi-retail-pos')));
    }
    
    $language = isset($_POST['language']) ? sanitize_text_field($_POST['language']) : '';
    
    if (empty($language)) {
        wp_send_json_error(array('message' => __('Invalid language', 'ruiyi-retail-pos')));
    }
    
    if (ruiyi_pos_set_language($language)) {
        // Force reload text domain for immediate effect
        ruiyi_pos_reload_textdomain($language);
        
        wp_send_json_success(array(
            'message' => __('Language switched successfully', 'ruiyi-retail-pos'),
            'language' => $language,
            'reload' => true
        ));
    } else {
        wp_send_json_error(array('message' => __('Failed to switch language', 'ruiyi-retail-pos')));
    }
}
add_action('wp_ajax_ruiyi_pos_switch_language', 'ruiyi_pos_switch_language');
add_action('wp_ajax_nopriv_ruiyi_pos_switch_language', 'ruiyi_pos_switch_language');

/**
 * Get language switcher HTML with admin button
 */
function ruiyi_pos_get_language_switcher() {
    $available_languages = ruiyi_pos_get_available_languages();
    $current_language = ruiyi_pos_get_current_language();
    $current_lang_data = $available_languages[$current_language];

    ob_start();
    ?>
    <div class="flex items-center space-x-3">
        <!-- Language Switcher -->
        <div class="relative inline-block text-left" id="language-switcher">
        <button type="button"
                class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                onclick="toggleLanguageDropdown(event)">
            <span class="mr-2"><?php echo $current_lang_data['flag']; ?></span>
            <span class="mr-2"><?php echo $current_lang_data['native']; ?></span>
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>
        
        <div id="language-dropdown" 
             class="origin-top-right absolute right-0 mt-2 w-48 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 focus:outline-none hidden z-50">
            <div class="py-1">
                <?php foreach ($available_languages as $code => $lang_data): ?>
                    <button type="button"
                            class="<?php echo $code === $current_language ? 'bg-gray-100 text-gray-900' : 'text-gray-700'; ?> hover:bg-gray-100 hover:text-gray-900 group flex items-center px-4 py-2 text-sm w-full text-left"
                            onclick="switchLanguage('<?php echo $code; ?>', event)">
                        <span class="mr-3"><?php echo $lang_data['flag']; ?></span>
                        <span class="flex-1"><?php echo $lang_data['native']; ?></span>
                        <?php if ($code === $current_language): ?>
                            <svg class="h-4 w-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <script>
    (function() {
        let dropdownOpen = false;
        let switchingLanguage = false;

        window.toggleLanguageDropdown = function(event) {
            if (event) {
                event.stopPropagation();
            }
            if (switchingLanguage) return; // Prevent interaction while switching

            const dropdown = document.getElementById('language-dropdown');
            dropdownOpen = !dropdownOpen;

            if (dropdownOpen) {
                dropdown.classList.remove('hidden');
            } else {
                dropdown.classList.add('hidden');
            }
        };

        window.switchLanguage = function(language, event) {
            if (event) {
                event.stopPropagation();
            }
            if (switchingLanguage) return; // Prevent double-click
            switchingLanguage = true;

            // Show loading state
            const button = document.querySelector('#language-switcher > button');
            if (button) {
                button.innerHTML = '<span class="mr-2">⏳</span><span><?php echo __("Switching...", "ruiyi-retail-pos"); ?></span>';
                button.disabled = true;
            }

            // Hide dropdown immediately
            const dropdown = document.getElementById('language-dropdown');
            if (dropdown) {
                dropdown.classList.add('hidden');
            }
            dropdownOpen = false;

            // Build redirect URL
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('lang', language);
            // Remove any cache-busting params
            currentUrl.searchParams.delete('_');

            // Make AJAX request to set the language preference, then redirect
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    'action': 'ruiyi_pos_switch_language',
                    'language': language,
                    'nonce': '<?php echo wp_create_nonce('ruiyi_pos_nonce'); ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                // Small delay to ensure cookie is set before redirect
                setTimeout(() => {
                    window.location.href = currentUrl.toString();
                }, 100);
            })
            .catch(error => {
                console.error('Language switch error:', error);
                // Still redirect to apply language via URL parameter
                setTimeout(() => {
                    window.location.href = currentUrl.toString();
                }, 100);
            });
        };

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            if (!dropdownOpen) return;

            const switcher = document.getElementById('language-switcher');
            const dropdown = document.getElementById('language-dropdown');

            if (switcher && dropdown && !switcher.contains(event.target)) {
                dropdown.classList.add('hidden');
                dropdownOpen = false;
            }
        });

        // Close dropdown on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && dropdownOpen) {
                const dropdown = document.getElementById('language-dropdown');
                if (dropdown) {
                    dropdown.classList.add('hidden');
                    dropdownOpen = false;
                }
            }
        });
    })();
    </script>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Check if user can access POS
 */
function ruiyi_pos_check_access() {
    if (!is_user_logged_in()) {
        return false;
    }
    
    $user = wp_get_current_user();
    $allowed_roles = array('administrator', 'shop_manager', 'pos_manager', 'pos_cashier');
    
    return array_intersect($allowed_roles, $user->roles) ? true : false;
}

/**
 * Redirect non-POS users
 */
function ruiyi_pos_template_redirect() {
    if (is_page_template('page-templates/pos-checkout.php') ||
        is_page_template('page-templates/page-pos.php')) {

        if (!ruiyi_pos_check_access()) {
            wp_redirect(home_url('/pos-login/'));
            exit;
        }
    }
}
add_action('template_redirect', 'ruiyi_pos_template_redirect');

/**
 * Custom page templates
 */
function ruiyi_pos_page_templates($templates) {
    $templates['page-templates/login.php'] = __('POS Login', 'ruiyi-retail-pos');
    $templates['page-templates/page-pos.php'] = __('Usuario POS', 'ruiyi-retail-pos');
    $templates['page-templates/pos-checkout.php'] = __('POS Checkout', 'ruiyi-retail-pos');

    return $templates;
}
add_filter('theme_page_templates', 'ruiyi_pos_page_templates');

/**
 * AJAX handler for POS operations
 */
function ruiyi_pos_ajax_handler() {
    // Nonce and permission already verified in ruiyi_pos_ajax_handler_extended()
    $action = isset($_POST['pos_action']) ? sanitize_text_field($_POST['pos_action']) : '';
    
    switch ($action) {
        case 'create_order':
            ruiyi_pos_create_order_enhanced();
            break;
        case 'get_inventory':
            ruiyi_pos_get_inventory();
            break;
        case 'get_product_details':
            ruiyi_pos_get_product_details();
            break;
        case 'update_product':
            ruiyi_pos_update_product();
            break;
        case 'delete_product':
            ruiyi_pos_delete_product();
            break;
        case 'bulk_delete_products':
            ruiyi_pos_bulk_delete_products();
            break;
        case 'generate_product_details_pdf':
            ruiyi_pos_generate_product_details_pdf();
            break;
        case 'get_sales_chart_data':
            ruiyi_pos_get_sales_chart_data();
            break;
        case 'get_payment_chart_data':
            ruiyi_pos_get_payment_chart_data();
            break;
        case 'get_top_products':
            ruiyi_pos_get_top_products();
            break;
        case 'get_statistics':
            ruiyi_pos_get_statistics();
            break;
        case 'get_refundable_orders':
            ruiyi_pos_get_refundable_orders();
            break;
        case 'get_order_details':
            ruiyi_pos_get_order_details();
            break;
        case 'get_full_order_details':
            ruiyi_pos_get_full_order_details();
            break;
        case 'get_order_receipt_data':
            ruiyi_pos_get_order_receipt_data();
            break;
        case 'save_order_customer_info':
            ruiyi_pos_save_order_customer_info();
            break;
        case 'process_refund':
            ruiyi_pos_process_refund();
            break;
        case 'search_products':
            ruiyi_pos_search_products();
            break;
        case 'get_initial_products':
            ruiyi_pos_get_initial_products();
            break;
        case 'get_all_products_paginated':
            ruiyi_pos_get_all_products_paginated();
            break;
        case 'get_products_last_modified':
            ruiyi_pos_get_products_last_modified();
            break;
        case 'get_products_modified_since':
            ruiyi_pos_get_products_modified_since();
            break;
        case 'get_products_for_edit':
            ruiyi_pos_get_products_for_edit_with_date();
            break;
        case 'create_product':
            ruiyi_pos_create_product();
            break;
        case 'search_product_by_sku':
            ruiyi_pos_search_product_by_sku();
            break;
        case 'search_product_by_number':
            ruiyi_pos_search_product_by_number();
            break;
        case 'search_by_barcode':
            ruiyi_pos_search_by_barcode();
            break;
        case 'bulk_update_stock':
            ruiyi_pos_bulk_update_stock();
            break;
        case 'bulk_update_prices':
            ruiyi_pos_bulk_update_prices();
            break;
        case 'export_inventory_csv':
            ruiyi_pos_export_inventory_csv();
            break;
        case 'import_inventory_csv':
            ruiyi_pos_import_inventory_csv();
            break;
        case 'export_products_csv':
            ruiyi_pos_export_products_csv();
            break;
        case 'import_products_csv':
            ruiyi_pos_import_products_csv();
            break;
        case 'import_products_mapped':
            ruiyi_pos_import_products_mapped();
            break;
        case 'open_cash_drawer':
            ruiyi_pos_open_cash_drawer();
            break;
        case 'update_customer_cart':
            ruiyi_pos_update_customer_cart();
            break;
        case 'get_customer_cart':
            ruiyi_pos_get_customer_cart();
            break;
        case 'get_settlement_report':
            ruiyi_pos_get_settlement_report();
            break;
        case 'get_suppliers':
            ruiyi_pos_get_suppliers();
            break;
        case 'add_supplier':
            ruiyi_pos_add_supplier();
            break;
        case 'delete_supplier':
            ruiyi_pos_delete_supplier();
            break;
        case 'get_order_history':
            ruiyi_pos_get_order_history();
            break;
        case 'get_order_customer_data':
            ruiyi_pos_get_order_customer_data();
            break;
        case 'generate_invoice':
            ruiyi_pos_generate_invoice();
            break;
        case 'generate_delivery_note':
            ruiyi_pos_generate_delivery_note();
            break;
        case 'create_albaran_order':
            ruiyi_pos_create_albaran_order();
            break;
        case 'complete_order':
            ruiyi_pos_complete_order();
            break;
        case 'void_order':
            ruiyi_pos_void_order();
            break;
        case 'void_order_with_rectificativa':
            ruiyi_pos_void_order_with_rectificativa();
            break;
        case 'get_salespersons':
            ruiyi_pos_get_salespersons();
            break;
        case 'search_customers':
            ruiyi_pos_search_customers();
            break;
        case 'search_customer_by_phone':
            ruiyi_pos_search_customer_by_phone();
            break;
        case 'get_customer_data':
            ruiyi_pos_get_customer_data();
            break;
        case 'get_all_customers':
            ruiyi_pos_get_all_customers();
            break;
        case 'add_invoice_customer':
            ruiyi_pos_add_invoice_customer();
            break;
        case 'update_invoice_customer':
            ruiyi_pos_update_invoice_customer();
            break;
        case 'delete_invoice_customer':
            ruiyi_pos_delete_invoice_customer();
            break;
        case 'check_customer_number':
            ruiyi_pos_check_customer_number();
            break;
        case 'toggle_wholesale_albaran':
            ruiyi_pos_toggle_wholesale_albaran();
            break;
        case 'toggle_transfer_payment':
            ruiyi_pos_toggle_transfer_payment();
            break;
        case 'toggle_wholesale_invoice':
            ruiyi_pos_toggle_wholesale_invoice();
            break;
        case 'toggle_payment_visibility':
            ruiyi_pos_toggle_payment_visibility();
            break;
        case 'get_rectificativa_number':
            ruiyi_pos_get_rectificativa_number();
            break;
        case 'generate_rectificativa_invoice':
            ruiyi_pos_generate_rectificativa_invoice();
            break;
        case 'save_product_number_key':
            ruiyi_pos_save_product_number_key();
            break;
        case 'save_all_backend_settings':
            ruiyi_pos_save_all_backend_settings();
            break;
        case 'get_company_settings':
            ruiyi_pos_get_company_settings();
            break;
        case 'save_company_settings':
            ruiyi_pos_save_company_settings();
            break;
        case 'get_pdf_codigo_setting':
            ruiyi_pos_get_pdf_codigo_setting();
            break;
        case 'save_pdf_codigo_setting':
            ruiyi_pos_save_pdf_codigo_setting();
            break;
        case 'get_toolbox_settings':
            ruiyi_pos_get_toolbox_settings();
            break;
        case 'save_toolbox_settings':
            ruiyi_pos_save_toolbox_settings();
            break;
        case 'auto_save_daily_settlement':
            ruiyi_pos_auto_save_daily_settlement();
            break;
        case 'save_auto_settlement_time':
            ruiyi_pos_save_auto_settlement_time();
            break;
        case 'manual_trigger_auto_settlement':
            ruiyi_pos_manual_trigger_auto_settlement();
            break;
        case 'page_close_auto_save':
            ruiyi_pos_page_close_auto_save();
            break;
        case 'get_tax_settings':
            ruiyi_pos_get_tax_settings();
            break;
        case 'check_sku_unique':
            ruiyi_pos_check_sku_unique();
            break;
        case 'create_product_category':
            ruiyi_pos_create_product_category();
            break;
        case 'save_category_tax_rates':
            ruiyi_pos_save_category_tax_rates();
            break;
        case 'save_product_tax_rate':
            ruiyi_pos_save_product_tax_rate();
            break;
        case 'search_products_for_tax':
            ruiyi_pos_search_products_for_tax();
            break;
        case 'meilisearch_save_config':
            ruiyi_pos_meilisearch_save_config();
            break;
        case 'meilisearch_initial_index':
            Ruiyi_Meilisearch_Sync::get_instance()->ajax_initial_index();
            break;
        case 'meilisearch_search':
            Ruiyi_Meilisearch_Sync::get_instance()->ajax_search();
            break;
        case 'meilisearch_mark_indexed':
            Ruiyi_Meilisearch_Sync::get_instance()->ajax_mark_indexed();
            break;
        default:
            wp_send_json_error(array('message' => __('Invalid action', 'ruiyi-retail-pos')));
    }
}
add_action('wp_ajax_ruiyi_pos_ajax', 'ruiyi_pos_ajax_handler');

/**
 * Save Meilisearch configuration (host + master key)
 */
function ruiyi_pos_meilisearch_save_config() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ruiyi_pos_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed', 'code' => 'nonce_expired'));
        return;
    }

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
    }

    $host = isset($_POST['host']) ? esc_url_raw($_POST['host']) : '';
    $admin_key = isset($_POST['admin_key']) ? sanitize_text_field($_POST['admin_key']) : '';

    if (empty($host) || empty($admin_key)) {
        wp_send_json_error(array('message' => 'Host and admin key are required'));
    }

    $result = Ruiyi_Meilisearch_Sync::save_config($host, $admin_key);

    if ($result === true) {
        wp_send_json_success(array(
            'message' => 'Meilisearch configured successfully',
            'config'  => Ruiyi_Meilisearch_Sync::get_config(),
        ));
    } else {
        wp_send_json_error(array('message' => $result));
    }
}

/**
 * Wrapper for get_products_for_edit with date_filter support
 * Adds date_query to WP_Query via pre_get_posts hook, then delegates to original function
 */
function ruiyi_pos_get_products_for_edit_with_date() {
    $date_filter = isset($_POST['date_filter']) ? sanitize_text_field($_POST['date_filter']) : '';

    if (!empty($date_filter) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_filter)) {
        // Add date_query via pre_get_posts filter before the original function runs
        add_action('pre_get_posts', function($query) use ($date_filter) {
            if ($query->get('post_type') === 'product') {
                $query->set('date_query', array(
                    array(
                        'year'  => intval(substr($date_filter, 0, 4)),
                        'month' => intval(substr($date_filter, 5, 2)),
                        'day'   => intval(substr($date_filter, 8, 2)),
                    ),
                ));
            }
        });
    }

    // Call the original function — it will pick up the date_query from pre_get_posts
    ruiyi_pos_get_products_for_edit();
}

/**
 * Open cash drawer via ESC/POS command
 */
function ruiyi_pos_open_cash_drawer() {
    // Debug log
    ruiyi_pos_log('RUIYI POS: ruiyi_pos_open_cash_drawer function called');
    
    // Check if user has permission to open cash drawer
    if (!current_user_can('manage_woocommerce') && !ruiyi_pos_check_access()) {
        ruiyi_pos_log('RUIYI POS: Permission denied for user ID ' . get_current_user_id());
        wp_send_json_error(array(
            'message' => __('You do not have permission to open the cash drawer', 'ruiyi-retail-pos')
        ));
        return;
    }
    
    // Log the cash drawer opening attempt
    ruiyi_pos_log('RUIYI POS: Cash drawer opening requested by user ID ' . get_current_user_id());
    
    try {
        // Generate ESC/POS command for cash drawer
        // Standard ESC/POS command: ESC p m t1 t2 (hex: 1B 70 00 19 19)
        $esc_pos_command = "\x1B\x70\x00\x19\x19";
        
        // Method 1: Try to send directly to default printer (if configured)
        $printer_sent = false;
        
        // Get printer settings (if any)
        $printer_name = get_option('ruiyi_pos_printer_name', '');
        $printer_type = get_option('ruiyi_pos_printer_type', 'thermal');
        
        // If printer is configured, try to send command
        if (!empty($printer_name) && $printer_type === 'thermal') {
            // For thermal printers, we can try direct printing
            if (function_exists('printer_open')) {
                // Windows printer functions (if available)
                $handle = @printer_open($printer_name);
                if ($handle) {
                    printer_write($handle, $esc_pos_command);
                    printer_close($handle);
                    $printer_sent = true;
                }
            }
        }
        
        // Method 2: Create a temporary file for manual printing
        $temp_dir = wp_upload_dir()['path'];
        $temp_file = $temp_dir . '/cash_drawer_' . time() . '.prn';
        
        if (file_put_contents($temp_file, $esc_pos_command)) {
            // File created successfully for manual printing
            $file_created = true;
            
            // Clean up old cash drawer files (older than 1 hour)
            $old_files = glob($temp_dir . '/cash_drawer_*.prn');
            $one_hour_ago = time() - 3600;
            foreach ($old_files as $file) {
                if (filemtime($file) < $one_hour_ago) {
                    @unlink($file);
                }
            }
        } else {
            $file_created = false;
        }
        
        // Method 3: JavaScript-based printer integration (for modern browsers)
        $success_message = __('Cash drawer opening command sent', 'ruiyi-retail-pos');
        
        // Provide different success messages based on method
        if ($printer_sent) {
            $success_message = __('Cash drawer opened successfully', 'ruiyi-retail-pos');
        } elseif ($file_created) {
            $success_message = __('Cash drawer command created. If using network printer, please check printer queue.', 'ruiyi-retail-pos');
        }
        
        // Log successful attempt
        ruiyi_pos_log('RUIYI POS: Cash drawer command generated successfully');
        
        wp_send_json_success(array(
            'message' => $success_message,
            'method' => $printer_sent ? 'direct' : ($file_created ? 'file' : 'command'),
            'esc_pos_command' => base64_encode($esc_pos_command), // For client-side printing
            'timestamp' => current_time('mysql')
        ));
        
    } catch (Exception $e) {
        ruiyi_pos_log('RUIYI POS: Cash drawer error - ' . $e->getMessage());
        wp_send_json_error(array(
            'message' => __('Failed to open cash drawer: ', 'ruiyi-retail-pos') . $e->getMessage()
        ));
    }
}



/**
 * Create order from POS - Updated for modern WooCommerce API
 */
function ruiyi_pos_create_order() {
    $cart_items = isset($_POST['cart_items']) ? json_decode(stripslashes($_POST['cart_items']), true) : array();
    $payment_method = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : 'cash';
    $customer_note = isset($_POST['customer_note']) ? sanitize_textarea_field($_POST['customer_note']) : '';
    
    if (empty($cart_items)) {
        wp_send_json_error(array('message' => __('Cart is empty', 'ruiyi-retail-pos')));
    }
    
    try {
        // Create order using modern WooCommerce API
        $order_data = array();
        
        
        $order = wc_create_order($order_data);
        
        if (is_wp_error($order)) {
            throw new Exception($order->get_error_message());
        }
        
        $order_total = 0;
        
        // Add products to order with validation (支持小数数量)
        foreach ($cart_items as $item) {
            $product_id = intval($item['id']);
            $quantity = floatval($item['quantity']); // 支持小数数量如1.5, 2.75

            if ($quantity <= 0) {
                continue;
            }
            
            $product = wc_get_product($product_id);
            if (!$product || !$product->is_purchasable()) {
                continue;
            }
            
            // Check stock
            if (!$product->has_enough_stock($quantity)) {
                wp_send_json_error(array(
                    'message' => sprintf(__('Not enough stock for %s', 'ruiyi-retail-pos'), $product->get_name())
                ));
            }
            
            $order->add_product($product, $quantity);
            $order_total += $product->get_price() * $quantity;
        }
        
        // Set order details
        $order->set_created_via('pos');
        $order->update_meta_data('_ruiyi_order_source', '收银系统');
        $order->set_payment_method($payment_method);

        // Set payment method title
        $payment_titles = array(
            'cash' => __('Cash', 'ruiyi-retail-pos'),
            'card' => __('Credit Card', 'ruiyi-retail-pos'),
            'mobile' => __('Mobile Payment', 'ruiyi-retail-pos'),
        );
        $order->set_payment_method_title($payment_titles[$payment_method] ?? $payment_method);
        
        // Add customer note if provided
        if (!empty($customer_note)) {
            $order->set_customer_note($customer_note);
        }
        
        // Add order note with cashier info
        $cashier = wp_get_current_user();
        $order->add_order_note(sprintf(
            __('Order created via POS by %s (Payment: %s)', 'ruiyi-retail-pos'),
            $cashier->display_name,
            $payment_titles[$payment_method] ?? $payment_method
        ));
        
        // Calculate totals
        $order->calculate_totals();
        
        // Complete order immediately for POS
        $order->update_status('completed', __('Order completed via POS', 'ruiyi-retail-pos'));
        
        // Update stock
        wc_maybe_reduce_stock_levels($order->get_id());
        
        // Calculate IVA details for receipt
        $tax_rate = 0.21; // 21% IVA
        
        // Get original subtotal (sum of all items)
        // Use the $order_total we calculated earlier
        $original_total = $order_total;
        
        $original_subtotal_without_tax = $original_total / (1 + $tax_rate);
        $iva_amount = $original_total - $original_subtotal_without_tax;
        
        wp_send_json_success(array(
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'total' => $order->get_total(),
            'formatted_total' => wc_price($order->get_total()),
            'formatted_subtotal' => wc_price($original_subtotal_without_tax),
            'formatted_tax' => wc_price($iva_amount),
            'subtotal' => $original_subtotal_without_tax,
            'tax' => $iva_amount,
            'tax_rate' => $tax_rate,
            'currency' => get_woocommerce_currency(),
            'payment_method' => $payment_method,
            'payment_method_title' => $payment_titles[$payment_method] ?? $payment_method,
            'order_date' => $order->get_date_created()->format('Y-m-d H:i:s'),
            'cashier' => $cashier->display_name,
            'items_count' => $order->get_item_count(),
            'receipt_url' => $order->get_checkout_order_received_url(),
        ));
        
    } catch (Exception $e) {
        wp_send_json_error(array(
            'message' => $e->getMessage(),
            'code' => 'order_creation_failed'
        ));
    }
}

/**
 * Get inventory data - Updated for modern WooCommerce API
 */
function ruiyi_pos_get_inventory() {
    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 50;
    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    $stock_status = isset($_POST['stock_status']) ? sanitize_text_field($_POST['stock_status']) : '';
    
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => $per_page,
        'paged' => $page,
        'post_status' => 'publish',
        'orderby' => 'title',
        'order' => 'ASC',
    );
    
    // Build meta_query for filtering
    $meta_query = array();
    
    // Add stock status filter if provided
    if (!empty($stock_status)) {
        if ($stock_status === 'lowstock') {
            // Custom logic for low stock - need to handle this after getting products
            // No meta_query needed for lowstock - we'll filter after getting products
        } else {
            // Standard WooCommerce stock status
            $meta_query[] = array(
                'key' => '_stock_status',
                'value' => $stock_status,
            );
        }
    }
    
    // Add meta_query to args if we have any conditions
    if (!empty($meta_query)) {
        $args['meta_query'] = $meta_query;
    }
    
    // Add search functionality - Custom implementation to handle both title and SKU
    if (!empty($search)) {
        // Search by title first
        $title_search_args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            's' => $search,
            'fields' => 'ids'
        );
        $title_results = get_posts($title_search_args);
        
        // Search by SKU
        $sku_search_args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_sku',
                    'value' => $search,
                    'compare' => 'LIKE'
                )
            ),
            'fields' => 'ids'
        );
        $sku_results = get_posts($sku_search_args);
        
        // Combine results and remove duplicates
        $combined_ids = array_unique(array_merge($title_results, $sku_results));
        
        if (!empty($combined_ids)) {
            // Use post__in to limit to found products
            $args['post__in'] = $combined_ids;
        } else {
            // No products found, force empty result
            $args['post__in'] = array(0); // Non-existent ID to return empty result
        }
    }
    
    $products = get_posts($args);
    
    $inventory = array();
    
    foreach ($products as $product) {
        $wc_product = wc_get_product($product->ID);
        
        if (!$wc_product) {
            continue;
        }
        
        // Get stock information
        $stock_quantity = $wc_product->get_stock_quantity();
        $product_stock_status = $wc_product->get_stock_status();
        
        // Determine stock level color
        $stock_level = 'high';
        if ($stock_quantity !== null) {
            if ($stock_quantity <= 0) {
                $stock_level = 'out';
            } elseif ($stock_quantity <= 5) {
                $stock_level = 'low';
            } elseif ($stock_quantity <= 20) {
                $stock_level = 'medium';
            }
        }
        
        // Apply lowstock filter if requested
        if ($stock_status === 'lowstock') {
            // Only include products with low stock (quantity 1-20)
            if (!($wc_product->managing_stock() && $stock_quantity !== null && $stock_quantity > 0 && $stock_quantity <= 20)) {
                continue; // Skip this product
            }
        }
        
        $inventory[] = array(
            'id' => $product->ID,
            'name' => $product->post_title,
            'sku' => $wc_product->get_sku() ?: __('N/A', 'ruiyi-retail-pos'),
            'stock' => $stock_quantity,
            'stock_status' => $product_stock_status,
            'stock_level' => $stock_level,
            'price' => $wc_product->get_price(),
            'formatted_price' => wc_price($wc_product->get_price()),
            'regular_price' => $wc_product->get_regular_price(),
            'sale_price' => $wc_product->get_sale_price(),
            'manage_stock' => $wc_product->managing_stock(),
            'type' => $wc_product->get_type(),
            'status' => $product->post_status,
            'image' => wp_get_attachment_url($wc_product->get_image_id()) ?: wc_placeholder_img_src(),
        );
    }
    
    // Get total count for pagination
    $total_args = $args;
    $total_args['posts_per_page'] = -1;
    $total_args['fields'] = 'ids';
    
    // No additional search filter needed - using same args
    
    $total_products = get_posts($total_args);
    
    // For lowstock, we need to count after filtering
    if ($stock_status === 'lowstock') {
        $total_count = count($inventory); // Use the actual filtered results
    } else {
        $total_count = count($total_products);
    }
    
    wp_send_json_success(array(
        'inventory' => $inventory,
        'total' => $total_count,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => ceil($total_count / $per_page),
        'search' => $search,
        'stock_status' => $stock_status,
    ));
}

/**
 * Custom login handler for POS - Updated with security improvements
 */
function ruiyi_pos_login_handler() {
    if (isset($_POST['pos_login'])) {
        // Verify nonce for security
        if (!isset($_POST['pos_login_nonce']) || !wp_verify_nonce($_POST['pos_login_nonce'], 'pos_login_action')) {
            wp_redirect(home_url('/pos-login/?error=security'));
            exit;
        }
        
        $username = sanitize_user($_POST['username']);
        $password = $_POST['password'];
        $remember = isset($_POST['remember']) ? true : false;
        
        // Rate limiting - check for failed login attempts
        $failed_attempts = get_transient('pos_failed_login_' . $username);
        if ($failed_attempts && $failed_attempts >= 5) {
            wp_redirect(home_url('/pos-login/?error=too_many_attempts'));
            exit;
        }
        
        $user = wp_authenticate($username, $password);
        
        if (!is_wp_error($user)) {
            // Clear failed attempts on successful login
            delete_transient('pos_failed_login_' . $username);
            
            // Set user as logged in
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID, true); // 始终使用持久 cookie（POS 是内部系统）
            
            // Check if user has POS access
            $allowed_roles = array('administrator', 'shop_manager', 'pos_manager', 'pos_cashier');
            $has_access = array_intersect($allowed_roles, $user->roles) ? true : false;
            
            if ($has_access) {
                // Log successful login
                $user->add_meta_data('pos_last_login', current_time('mysql'));

                // Redirect to POS checkout page
                wp_redirect(home_url('/pos-checkout/'));
                exit;
            } else {
                // Log out the user if no access
                wp_logout();
                wp_redirect(home_url('/pos-login/?error=no_access'));
                exit;
            }
        } else {
            // Increment failed login attempts
            $failed_attempts = $failed_attempts ? $failed_attempts + 1 : 1;
            set_transient('pos_failed_login_' . $username, $failed_attempts, 300); // 5 minutes
            
            wp_redirect(home_url('/pos-login/?error=invalid'));
            exit;
        }
    }
}
add_action('init', 'ruiyi_pos_login_handler');

/**
 * Remove admin bar for POS pages
 */
function ruiyi_pos_remove_admin_bar() {
    if (is_page_template(array(
        'page-templates/pos-checkout.php',
        'page-templates/page-pos.php'
    ))) {
        show_admin_bar(false);
    }
}
add_action('wp', 'ruiyi_pos_remove_admin_bar');

/**
 * Enhance WooCommerce receipt template for professional 80mm receipts
 */
function ruiyi_pos_enhance_receipt_data($data, $order) {
    // Set unified 12px font sizes for professional receipt appearance using constants
    $data['constants']['font_size'] = RUIYI_POS_RECEIPT_FONT_SIZE;        // Unified base font
    $data['constants']['title_font_size'] = RUIYI_POS_RECEIPT_TITLE_FONT_SIZE;  // Unified title font
    $data['constants']['footer_font_size'] = RUIYI_POS_RECEIPT_FOOTER_FONT_SIZE; // Unified footer font
    $data['constants']['line_height'] = RUIYI_POS_RECEIPT_LINE_HEIGHT;      // Consistent line spacing
    $data['constants']['margin'] = RUIYI_POS_RECEIPT_MARGIN;           // Consistent margins
    
    return $data;
}
add_filter('woocommerce_printable_order_receipt_data', 'ruiyi_pos_enhance_receipt_data', 10, 2);

/**
 * Add custom CSS to WooCommerce receipt template for professional 80mm receipts
 */
function ruiyi_pos_enhance_receipt_css($css, $order) {
    // Add compact CSS for professional 80mm receipt printing
    $enhanced_css = $css . '
    
    /* Professional styles for 80mm thermal receipt printing - unified font size from constants */
    @media print {
        html { 
            font-size: ' . RUIYI_POS_RECEIPT_FONT_SIZE . 'pt !important; 
            line-height: 1.2 !important;
            font-family: "Courier New", monospace !important;
        }
        
        h1 { 
            font-size: ' . RUIYI_POS_RECEIPT_TITLE_FONT_SIZE . 'pt !important; 
            font-weight: bold !important;
            margin: 4pt 0 6pt 0 !important;
            text-align: center !important;
        }
        
        h3 { 
            font-size: ' . RUIYI_POS_RECEIPT_FONT_SIZE . 'pt !important; 
            font-weight: bold !important;
            margin: 4pt 0 2pt 0 !important;
        }
        
        p { 
            font-size: ' . RUIYI_POS_RECEIPT_FONT_SIZE . 'pt !important; 
            line-height: 1.2 !important;
            margin: 2pt 0 !important;
        }
        
        table { 
            font-size: ' . RUIYI_POS_RECEIPT_FONT_SIZE . 'pt !important; 
            line-height: 1.2 !important;
            border: none !important;
            background: none !important;
        }
        
        table td { 
            padding: 2pt 0 !important; 
            border: none !important;
        }
        
        table tr:last-child { 
            font-size: ' . RUIYI_POS_RECEIPT_FONT_SIZE . 'pt !important; 
            font-weight: bold !important;
            border-top: 1px solid #000 !important;
        }
        
        footer { 
            font-size: ' . RUIYI_POS_RECEIPT_FOOTER_FONT_SIZE . 'pt !important; 
            text-align: center !important;
        }
        
        @page { 
            size: 80mm auto;
            margin: 0.1in 0.05in;
        }
    }
    ';
    
    return $enhanced_css;
}
add_filter('woocommerce_printable_order_receipt_css', 'ruiyi_pos_enhance_receipt_css', 10, 2);

// 获取订单的Verifactu二维码
add_action('wp_ajax_ruiyi_get_verifactu_qr', 'ruiyi_get_verifactu_qr_callback');
add_action('wp_ajax_nopriv_ruiyi_get_verifactu_qr', 'ruiyi_get_verifactu_qr_callback');

function ruiyi_get_verifactu_qr_callback() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ruiyi_pos_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed', 'code' => 'nonce_expired'));
        return;
    }

    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

    if (!$order_id) {
        wp_send_json_error(['message' => 'Invalid order ID']);
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(['message' => 'Order not found']);
        return;
    }

    // 检查是否有Verifactu二维码和发票号码
    $qr_base64 = null;
    $invoice_number = null;
    $has_verifactu = false;

    if (class_exists('WC_Verifactu_QR')) {
        $has_verifactu = true;
        $qr_base64 = $order->get_meta('_verifactu_qr_base64');
        // 优先使用完整的发票标签（如 FAC-2026-000001）
        $invoice_number = $order->get_meta('_verifactu_invoice_label');
        if (empty($invoice_number)) {
            $invoice_number = $order->get_meta('_verifactu_invoice_number');
        }

        // 如果没有二维码但订单已完成，尝试生成
        if (empty($qr_base64) && $order->get_status() === 'completed') {
            $verifactu_instance = WC_Verifactu_QR::instance();
            if (method_exists($verifactu_instance, 'generate_and_store_qr')) {
                try {
                    // 强制生成二维码
                    $reflection = new ReflectionClass($verifactu_instance);
                    $method = $reflection->getMethod('generate_and_store_qr');
                    $method->setAccessible(true);
                    $method->invoke($verifactu_instance, $order, true);

                    // 重新获取二维码和发票号码
                    $qr_base64 = $order->get_meta('_verifactu_qr_base64');
                    $invoice_number = $order->get_meta('_verifactu_invoice_label');
                    if (empty($invoice_number)) {
                        $invoice_number = $order->get_meta('_verifactu_invoice_number');
                    }
                } catch (Exception $e) {
                    ruiyi_pos_log('Error generating Verifactu QR for retail order #' . $order_id . ': ' . $e->getMessage());
                }
            }
        }
    }

    // 如果没有Verifactu发票号，使用 FAC-年份-订单号 作为备选
    if (empty($invoice_number)) {
        $year = date('Y', strtotime($order->get_date_created()));
        $invoice_number = 'FAC-' . $year . '-' . $order->get_order_number();
    }

    if ($qr_base64 || $invoice_number) {
        wp_send_json_success([
            'qr_base64' => $qr_base64,
            'invoice_number' => $invoice_number,
            'has_verifactu' => $has_verifactu,
            'order_id' => $order_id
        ]);
    } else {
        wp_send_json_error(['message' => 'QR code not available']);
    }
}

// Get last completed order for reprinting
add_action('wp_ajax_get_last_order', 'ruiyi_get_last_order_callback');

function ruiyi_get_last_order_callback() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ruiyi_pos_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed', 'code' => 'nonce_expired'));
        return;
    }

    // Get current user
    $current_user = wp_get_current_user();
    if (!$current_user || !$current_user->ID) {
        wp_send_json_error(['message' => 'User not authenticated']);
        return;
    }

    // First try to get POS orders only (including cancelled POS refund orders)
    $args = array(
        'limit' => 1,
        'orderby' => 'date',
        'order' => 'DESC',
        'status' => array('completed', 'processing', 'cancelled'),
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key' => '_pos_order',
                'value' => '1',
                'compare' => '='
            ),
        ),
        'return' => 'objects'
    );

    $orders = wc_get_orders($args);

    // Filter: only allow cancelled orders if they are POS refund orders
    if (!empty($orders)) {
        $order = $orders[0];
        $order_status = $order->get_status();
        if ($order_status === 'cancelled' && $order->get_meta('_pos_refund_order') !== 'yes') {
            // This cancelled order is NOT a POS refund, skip it and try completed/processing only
            ruiyi_pos_log('[Print Last Order] Last POS order is cancelled but not a refund, retrying with completed/processing only');
            $args['status'] = array('completed', 'processing');
            $orders = wc_get_orders($args);
        }
    }

    // If no POS orders found, get the last order regardless
    if (empty($orders)) {
        ruiyi_pos_log('[Print Last Order] No POS orders found, trying all orders');
        $args = array(
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'status' => array('completed', 'processing'),
            'return' => 'objects'
        );
        $orders = wc_get_orders($args);
    }

    if (empty($orders)) {
        ruiyi_pos_log('[Print Last Order] No orders found at all');
        wp_send_json_error(['message' => 'No order found']);
        return;
    }

    $order = $orders[0];
    $is_pos_refund_order = $order->get_meta('_pos_refund_order') === 'yes';
    ruiyi_pos_log('[Print Last Order] Found order #' . $order->get_id() . ($is_pos_refund_order ? ' (POS refund order)' : ''));

    // Prepare order data for receipt printing
    $order_data = array(
        'order_id' => $order->get_id(),
        'order_number' => $order->get_order_number(),
        'formatted_total' => wc_price($order->get_total()),
        'formatted_subtotal' => wc_price($order->get_subtotal()),
        'total' => $order->get_total(),
        'payment_method_title' => $order->get_payment_method_title(),
        'cashier' => $order->get_meta('_cashier_name') ?: $current_user->display_name,
        'items' => array(),
        'points_used' => $order->get_meta('_points_used') ?: 0,
        'formatted_points_discount' => wc_price($order->get_meta('_points_discount') ?: 0),
        'tax_data' => array(),
        'is_refund_order' => $is_pos_refund_order,
    );

    // Get order items with proper tax calculation
    $order_items_with_tax = array();
    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();

        // Get tax rate for this product
        $tax_rate = $item->get_meta('_tax_rate');
        if (!$tax_rate && $product) {
            $tax_rate = ruiyi_get_product_tax_rate($product);
        }
        if (!$tax_rate) {
            $tax_rate = 21; // Default IVA rate
        }

        // Check if this is a return item
        $is_return_item = $item->get_meta('_is_return') === 'yes';

        // Calculate prices (assuming price includes tax)
        $line_total = $item->get_total();
        $quantity = $item->get_quantity();

        // For refund orders, line_total is negative - handle division properly
        $unit_price = ($quantity != 0) ? ($line_total / $quantity) : 0;

        // Calculate without tax
        $line_subtotal = $line_total / (1 + ($tax_rate / 100));
        $line_tax = $line_total - $line_subtotal;

        $order_items_with_tax[] = array(
            'name' => $item->get_name(),
            'quantity' => $quantity,
            'price' => $unit_price,
            'formatted_price' => wc_price($unit_price),
            'subtotal' => $line_total,
            'formatted_subtotal' => wc_price($line_total),
            'tax_rate' => $tax_rate,
            'line_subtotal' => $line_subtotal,
            'line_tax' => $line_tax,
        );

        $order_data['items'][] = array(
            'name' => $item->get_name(),
            'quantity' => $is_return_item ? -$quantity : $quantity,
            'price' => $unit_price,
            'formatted_price' => wc_price($unit_price),
            'subtotal' => $line_total,
            'formatted_subtotal' => wc_price($line_total),
            'line_total' => $line_total,  // Add for receipt generation
            'tax_rate' => $tax_rate,      // Add for receipt generation
            'is_return' => $is_return_item,
        );
    }

    // Calculate tax details using the tax system
    if (function_exists('ruiyi_calculate_order_tax_details')) {
        $tax_details = ruiyi_calculate_order_tax_details($order_items_with_tax);
        $order_data['tax_details'] = $tax_details;
        $order_data['tax_breakdown'] = $tax_details['totals_by_rate'];

        // Calculate totals from tax details
        $subtotal_without_tax = 0;
        $total_tax = 0;
        foreach ($tax_details['totals_by_rate'] as $rate_key => $rate_data) {
            $subtotal_without_tax += $rate_data['base'];
            $total_tax += $rate_data['tax'];
        }

        // Override with calculated values
        $order_data['subtotal'] = $subtotal_without_tax;
        $order_data['formatted_subtotal'] = wc_price($subtotal_without_tax);
        $order_data['tax_total'] = $total_tax;
        $order_data['formatted_tax'] = wc_price($total_tax);
        $order_data['total'] = $order->get_total();
        $order_data['formatted_total'] = wc_price($order->get_total());
    } else {
        // Fallback: Get tax data from order
        foreach ($order->get_tax_totals() as $tax_code => $tax) {
            $order_data['tax_data'][] = array(
                'label' => $tax->label,
                'amount' => $tax->amount,
                'formatted_amount' => wc_price($tax->amount),
            );
        }

        // Use order totals as fallback
        $order_data['subtotal'] = $order->get_subtotal();
        $order_data['tax_total'] = $order->get_total_tax();
        $order_data['total'] = $order->get_total();
    }

    // Add customer information from order meta
    $order_data['customer_name'] = $order->get_meta('_customer_name') ?: '';
    $order_data['customer_phone'] = $order->get_meta('_customer_phone') ?: '';
    $order_data['customer_address'] = $order->get_meta('_customer_address') ?: '';
    $order_data['customer_company'] = $order->get_meta('_customer_company') ?: '';
    $order_data['customer_tax_id'] = $order->get_meta('_customer_tax_id') ?: '';

    wp_send_json_success($order_data);
}

/**
 * Get product details for editing
 */
function ruiyi_pos_get_product_details() {
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    
    if (!$product_id) {
        wp_send_json_error(array('message' => __('Invalid product ID', 'ruiyi-retail-pos')));
    }
    
    $product = wc_get_product($product_id);
    
    if (!$product) {
        wp_send_json_error(array('message' => __('Product not found', 'ruiyi-retail-pos')));
    }
    
    $product_data = array(
        'id' => $product->get_id(),
        'name' => $product->get_name(),
        'sku' => $product->get_sku(),
        'regular_price' => $product->get_regular_price(),
        'sale_price' => $product->get_sale_price(),
        'manage_stock' => $product->managing_stock(),
        'stock' => $product->get_stock_quantity(),
        'stock_status' => $product->get_stock_status(),
        'type' => $product->get_type(),
        'status' => $product->get_status(),
    );
    
    wp_send_json_success($product_data);
}

/**
 * Update product details
 */
function ruiyi_pos_update_product() {
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

    if (!$product_id) {
        wp_send_json_error(array('message' => __('Invalid product ID', 'ruiyi-retail-pos')));
    }

    $product = wc_get_product($product_id);

    if (!$product) {
        wp_send_json_error(array('message' => __('Product not found', 'ruiyi-retail-pos')));
    }

    try {
        // Update product name (支持两种字段名)
        $name = isset($_POST['product_name']) ? sanitize_text_field($_POST['product_name']) :
                (isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '');
        if (!empty($name)) {
            $product->set_name($name);
        }

        // Update SKU (支持两种字段名)
        $sku = isset($_POST['product_sku']) ? sanitize_text_field($_POST['product_sku']) :
               (isset($_POST['sku']) ? sanitize_text_field($_POST['sku']) : '');
        if (!empty($sku) && $sku !== $product->get_sku()) {
            $existing_product = wc_get_product_id_by_sku($sku);
            if ($existing_product && $existing_product != $product_id) {
                wp_send_json_error(array('message' => __('SKU already exists', 'ruiyi-retail-pos')));
            }
            $product->set_sku($sku);
        }

        // Update prices (支持两种字段名)
        $regular_price = isset($_POST['product_price']) ? floatval($_POST['product_price']) :
                        (isset($_POST['regular_price']) ? floatval($_POST['regular_price']) : null);
        if ($regular_price !== null && $regular_price > 0) {
            $product->set_regular_price($regular_price);
        }

        $sale_price = isset($_POST['product_sale_price']) ? floatval($_POST['product_sale_price']) :
                     (isset($_POST['sale_price']) ? floatval($_POST['sale_price']) : null);
        if ($sale_price !== null) {
            if ($sale_price > 0 && $sale_price < $regular_price) {
                $product->set_sale_price($sale_price);
            } else {
                $product->set_sale_price('');
            }
        }

        // Update stock management (支持两种字段名)
        $product_stock = isset($_POST['product_stock']) ? intval($_POST['product_stock']) :
                        (isset($_POST['stock']) ? intval($_POST['stock']) : null);
        if ($product_stock !== null) {
            if ($product_stock >= 0) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity($product_stock);
                $product->set_stock_status('instock');
            } elseif ($product_stock === -1) {
                $product->set_manage_stock(false);
                $product->set_stock_status('instock');
            }
        } elseif (isset($_POST['manage_stock'])) {
            $manage_stock = $_POST['manage_stock'] === 'true';
            $product->set_manage_stock($manage_stock);

            if ($manage_stock && isset($_POST['stock'])) {
                $stock = intval($_POST['stock']);
                $product->set_stock_quantity($stock);
            }
        }

        // Update stock status
        if (isset($_POST['stock_status'])) {
            $stock_status = sanitize_text_field($_POST['stock_status']);
            $product->set_stock_status($stock_status);
        }

        // Update category
        $category = isset($_POST['product_category']) ? intval($_POST['product_category']) : 0;
        if ($category > 0) {
            $product->set_category_ids(array($category));
        }

        // Update supplier meta
        if (isset($_POST['product_supplier'])) {
            update_post_meta($product_id, '_product_supplier', sanitize_text_field($_POST['product_supplier']));
        }

        // Update product number (快捷编号)
        if (isset($_POST['product_number'])) {
            update_post_meta($product_id, '_product_number', sanitize_text_field($_POST['product_number']));
        }

        // Update cost price (进货价)
        if (isset($_POST['cost_price'])) {
            $cost_price = $_POST['cost_price'];
            if ($cost_price !== '' && is_numeric($cost_price)) {
                update_post_meta($product_id, '_product_cost_price', floatval($cost_price));
            } else {
                update_post_meta($product_id, '_product_cost_price', '');
            }
        }

        // Update chinese name (中文备注)
        if (isset($_POST['chinese_name'])) {
            update_post_meta($product_id, '_product_chinese_name', sanitize_text_field($_POST['chinese_name']));
        }

        // Save the product
        $product->save();

        // Clear cache
        ruiyi_pos_clear_cache();

        // Get updated product data for response
        $image_url = wp_get_attachment_image_url($product->get_image_id(), 'thumbnail');
        if (!$image_url) {
            $image_url = wc_placeholder_img_src('thumbnail');
        }

        $product_data = array(
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'price' => floatval($product->get_price()),
            'regular_price' => floatval($product->get_regular_price()),
            'sale_price' => floatval($product->get_sale_price()),
            'stock' => $product->get_stock_quantity(),
            'image' => $image_url,
            'formatted_price' => strip_tags(wc_price($product->get_price()))
        );

        wp_send_json_success(array(
            'message' => sprintf(__('Product "%s" updated successfully', 'ruiyi-retail-pos'), $product->get_name()),
            'product' => $product_data,
            'product_id' => $product_id
        ));

    } catch (Exception $e) {
        wp_send_json_error(array(
            'message' => $e->getMessage(),
            'code' => 'product_update_failed'
        ));
    }
}

// 已编辑产品跟踪已移至独立插件 ruiyi-edited-products-tracker.php（mu-plugin）
// 确保所有子站（不论主题）都能触发 woocommerce_update_product hook

/**
 * 删除产品
 * Delete a WooCommerce product
 */
function ruiyi_pos_delete_product() {
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

    if (!$product_id) {
        wp_send_json_error(array('message' => __('Invalid product ID', 'ruiyi-retail-pos')));
        return;
    }

    $product = wc_get_product($product_id);

    if (!$product) {
        wp_send_json_error(array('message' => __('Product not found', 'ruiyi-retail-pos')));
        return;
    }

    $product_name = $product->get_name();

    try {
        // 删除产品（移动到回收站）
        // 使用 wp_trash_post 而不是 wp_delete_post，这样可以恢复
        $result = wp_trash_post($product_id);

        if ($result) {
            // 清除缓存
            ruiyi_pos_clear_cache();

            wp_send_json_success(array(
                'message' => sprintf(__('Product "%s" deleted successfully', 'ruiyi-retail-pos'), $product_name),
                'product_id' => $product_id
            ));
        } else {
            wp_send_json_error(array('message' => __('Failed to delete product', 'ruiyi-retail-pos')));
        }
    } catch (Exception $e) {
        wp_send_json_error(array(
            'message' => $e->getMessage(),
            'code' => 'product_delete_failed'
        ));
    }
}

/**
 * 批量删除产品
 * Bulk delete WooCommerce products (move to trash)
 */
function ruiyi_pos_bulk_delete_products() {
    $product_ids_json = isset($_POST['product_ids']) ? wp_unslash($_POST['product_ids']) : '[]';
    $product_ids = json_decode($product_ids_json, true);

    if (!is_array($product_ids) || empty($product_ids)) {
        wp_send_json_error(array('message' => 'No products selected'));
        return;
    }

    $deleted = 0;
    $failed = 0;

    foreach ($product_ids as $product_id) {
        $product_id = intval($product_id);
        if (!$product_id) {
            $failed++;
            continue;
        }

        $result = wp_trash_post($product_id);
        if ($result) {
            $deleted++;
        } else {
            $failed++;
        }
    }

    if ($deleted > 0) {
        ruiyi_pos_clear_cache();
    }

    wp_send_json_success(array(
        'deleted' => $deleted,
        'failed' => $failed
    ));
}

/**
 * 生成产品明细PDF
 * Generate PDF with all product details
 */
function ruiyi_pos_generate_product_details_pdf() {
    // 获取所有产品
    $args = array(
        'status' => 'publish',
        'limit' => -1,
        'orderby' => 'name',
        'order' => 'ASC'
    );

    $products = wc_get_products($args);

    if (empty($products)) {
        wp_send_json_error(array('message' => 'No se encontraron productos'));
        return;
    }

    // 使用 TCPDF 生成 PDF (正确的路径)
    $tcpdf_path = ABSPATH . 'wp-content/plugins/verifactuqr/vendor/tecnickcom/tcpdf/tcpdf.php';
    if (!file_exists($tcpdf_path)) {
        // 备用路径
        $tcpdf_path = WP_PLUGIN_DIR . '/verifactuqr/vendor/tecnickcom/tcpdf/tcpdf.php';
    }
    if (!file_exists($tcpdf_path)) {
        wp_send_json_error(array('message' => 'Biblioteca PDF no encontrada'));
        return;
    }
    require_once($tcpdf_path);

    // 获取商店信息
    $store_name = get_bloginfo('name');

    // 创建PDF (纵向A4更适合4列)
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

    // 设置文档信息
    $pdf->SetCreator('Ruiyi POS');
    $pdf->SetAuthor($store_name);
    $pdf->SetTitle('Detalles de Productos');
    $pdf->SetSubject('Catalogo de Productos');

    // 移除默认页眉页脚
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // 设置边距
    $pdf->SetMargins(10, 15, 10);
    $pdf->SetAutoPageBreak(true, 15);

    // 添加页面
    $pdf->AddPage();

    // 设置字体
    $pdf->SetFont('helvetica', 'B', 16);

    // 标题 (西班牙语)
    $pdf->Cell(0, 10, 'Catalogo de Productos', 0, 1, 'C');

    // 商店名称和日期
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, $store_name . ' - ' . date('Y-m-d H:i'), 0, 1, 'C');
    $pdf->Ln(5);

    // 表头
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(240, 240, 240);

    // 简化列宽 (纵向A4: 210mm - 20mm边距 = 190mm) - 只显示4列
    $col_widths = array(
        'sku' => 50,          // SKU
        'name' => 80,         // 名称
        'price' => 30,        // 单价
        'number' => 30        // 产品编号
    );

    // 表头文字 (西班牙语)
    $headers = array('SKU', 'Nombre del Producto', 'Precio Unitario', 'Numero');

    $i = 0;
    foreach ($col_widths as $width) {
        $pdf->Cell($width, 8, $headers[$i], 1, 0, 'C', true);
        $i++;
    }
    $pdf->Ln();

    // 产品数据
    $pdf->SetFont('helvetica', '', 9);
    $row_num = 1;

    foreach ($products as $product) {
        // 检查是否需要新页面
        if ($pdf->GetY() > 265) {
            $pdf->AddPage();
            // 重绘表头
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->SetFillColor(240, 240, 240);
            $i = 0;
            foreach ($col_widths as $width) {
                $pdf->Cell($width, 8, $headers[$i], 1, 0, 'C', true);
                $i++;
            }
            $pdf->Ln();
            $pdf->SetFont('helvetica', '', 9);
        }

        // 获取产品数据
        $sku = $product->get_sku() ?: '-';
        $name = $product->get_name();
        $product_number = get_post_meta($product->get_id(), '_product_number', true) ?: '-';

        // 获取价格 - 直接使用数字格式，避免HTML实体问题
        $regular_price = $product->get_regular_price();
        if ($regular_price !== '' && $regular_price !== null) {
            // 格式化价格: 数字 + 欧元符号
            $price = number_format((float)$regular_price, 2, ',', '.') . ' EUR';
        } else {
            $price = '-';
        }

        // 交替行背景色
        $fill = ($row_num % 2 == 0);
        if ($fill) {
            $pdf->SetFillColor(250, 250, 250);
        }

        // 绘制行 - 只显示4列
        $pdf->Cell($col_widths['sku'], 7, mb_strimwidth($sku, 0, 25, '...'), 1, 0, 'L', $fill);
        $pdf->Cell($col_widths['name'], 7, mb_strimwidth($name, 0, 40, '...'), 1, 0, 'L', $fill);
        $pdf->Cell($col_widths['price'], 7, $price, 1, 0, 'R', $fill);
        $pdf->Cell($col_widths['number'], 7, $product_number, 1, 0, 'C', $fill);
        $pdf->Ln();

        $row_num++;
    }

    // 统计信息 (西班牙语)
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'Total: ' . count($products) . ' productos', 0, 1, 'R');

    // 保存PDF到文件
    $upload_dir = wp_upload_dir();
    $pdf_dir = $upload_dir['basedir'] . '/product-pdfs';

    // 创建目录
    if (!file_exists($pdf_dir)) {
        wp_mkdir_p($pdf_dir);
    }

    // 生成文件名
    $filename = 'catalogo-productos-' . date('Y-m-d-His') . '.pdf';
    $pdf_path = $pdf_dir . '/' . $filename;
    $pdf_url = $upload_dir['baseurl'] . '/product-pdfs/' . $filename;

    // 确保HTTPS
    $pdf_url = str_replace('http://', 'https://', $pdf_url);

    // 保存PDF
    $pdf->Output($pdf_path, 'F');

    wp_send_json_success(array(
        'pdf_url' => $pdf_url,
        'filename' => $filename,
        'product_count' => count($products)
    ));
}

/**
 * Get sales chart data for reports
 */
function ruiyi_pos_get_sales_chart_data() {
    $date_range = isset($_POST['date_range']) ? sanitize_text_field($_POST['date_range']) : 'week';
    
    // Calculate date range using WordPress timezone
    $start_date = '';
    $end_date = current_time('Y-m-d H:i:s');
    
    switch ($date_range) {
        case 'today':
            $start_date = current_time('Y-m-d 00:00:00');
            break;
        case 'yesterday':
            $start_date = date('Y-m-d 00:00:00', strtotime('-1 day', current_time('timestamp')));
            $end_date = date('Y-m-d 23:59:59', strtotime('-1 day', current_time('timestamp')));
            break;
        case 'week':
            $start_date = date('Y-m-d 00:00:00', strtotime('monday this week', current_time('timestamp')));
            break;
        case 'month':
            $start_date = current_time('Y-m-01 00:00:00');
            break;
        case 'year':
            $start_date = current_time('Y-01-01 00:00:00');
            break;
        default:
            $start_date = date('Y-m-d 00:00:00', strtotime('monday this week', current_time('timestamp')));
    }
    
    // Get orders using WooCommerce's recommended method
    if (function_exists('wc_get_orders')) {
        $start_date_only = date('Y-m-d', strtotime($start_date));
        $end_date_only = date('Y-m-d', strtotime($end_date));
        
        $order_ids = wc_get_orders(array(
            'limit' => -1,
            'status' => array('completed', 'processing'),
            'date_created' => $start_date_only . '...' . $end_date_only,
            'return' => 'ids',
        ));
    } else {
        // Fallback to get_posts
        $args = array(
            'post_type' => 'shop_order',
            'post_status' => array('wc-completed', 'wc-processing'),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'date_query' => array(
                array(
                    'after' => $start_date,
                    'before' => $end_date,
                    'inclusive' => true,
                    'column' => 'post_date',
                ),
            ),
        );
        
        $order_ids = get_posts($args);
    }
    
    // Convert IDs to order objects
    $orders = array();
    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if ($order) {
            $orders[] = $order;
        }
    }
    
    // Process data based on date range
    $labels = array();
    $values = array();
    
    if ($date_range == 'week') {
        // Group by days of week
        $days = array(
            __('Mon', 'ruiyi-retail-pos'),
            __('Tue', 'ruiyi-retail-pos'),
            __('Wed', 'ruiyi-retail-pos'),
            __('Thu', 'ruiyi-retail-pos'),
            __('Fri', 'ruiyi-retail-pos'),
            __('Sat', 'ruiyi-retail-pos'),
            __('Sun', 'ruiyi-retail-pos')
        );
        $sales_by_day = array_fill(0, 7, 0);
        
        foreach ($orders as $order) {
            // Skip refund objects
            if ($order->get_type() === 'shop_order_refund') {
                continue;
            }
            $order_date = $order->get_date_created();
            $day_of_week = $order_date->format('N') - 1; // 0 = Monday
            $sales_by_day[$day_of_week] += $order->get_total();
        }
        
        $labels = $days;
        $values = $sales_by_day;
        
    } elseif ($date_range == 'month') {
        // Group by days of month
        $days_in_month = date('t');
        $sales_by_day = array_fill(1, $days_in_month, 0);
        
        foreach ($orders as $order) {
            // Skip refund objects
            if ($order->get_type() === 'shop_order_refund') {
                continue;
            }
            $order_date = $order->get_date_created();
            $day = intval($order_date->format('j'));
            $sales_by_day[$day] += $order->get_total();
        }
        
        $labels = array_keys($sales_by_day);
        $values = array_values($sales_by_day);
        
    } else {
        // For today, yesterday, or year - group by hours or months
        if ($date_range == 'today' || $date_range == 'yesterday') {
            // Create labels for each hour (00:00 to 23:00)
            $labels = array();
            $values = array_fill(0, 24, 0);
            
            for ($i = 0; $i < 24; $i++) {
                $labels[] = sprintf('%02d:00', $i);
            }
            
            foreach ($orders as $order) {
                // Skip refund objects
                if ($order->get_type() === 'shop_order_refund') {
                    continue;
                }
                $order_date = $order->get_date_created();
                $hour = intval($order_date->format('H'));
                $values[$hour] += $order->get_total();
            }
        } else {
            // Year view - group by months
            $labels = array(
                __('Jan', 'ruiyi-retail-pos'),
                __('Feb', 'ruiyi-retail-pos'),
                __('Mar', 'ruiyi-retail-pos'),
                __('Apr', 'ruiyi-retail-pos'),
                __('May', 'ruiyi-retail-pos'),
                __('Jun', 'ruiyi-retail-pos'),
                __('Jul', 'ruiyi-retail-pos'),
                __('Aug', 'ruiyi-retail-pos'),
                __('Sep', 'ruiyi-retail-pos'),
                __('Oct', 'ruiyi-retail-pos'),
                __('Nov', 'ruiyi-retail-pos'),
                __('Dec', 'ruiyi-retail-pos')
            );
            $values = array_fill(0, 12, 0);
            
            foreach ($orders as $order) {
                // Skip refund objects
                if ($order->get_type() === 'shop_order_refund') {
                    continue;
                }
                $order_date = $order->get_date_created();
                $month = intval($order_date->format('n')) - 1;
                $values[$month] += $order->get_total();
            }
        }
    }
    
    wp_send_json_success(array(
        'labels' => $labels,
        'values' => $values,
        'date_range' => $date_range
    ));
}

/**
 * Get payment method chart data for reports
 */
function ruiyi_pos_get_payment_chart_data() {
    $date_range = isset($_POST['date_range']) ? sanitize_text_field($_POST['date_range']) : 'week';
    
    // Calculate date range using WordPress timezone
    $start_date = '';
    $end_date = current_time('Y-m-d H:i:s');
    
    switch ($date_range) {
        case 'today':
            $start_date = current_time('Y-m-d 00:00:00');
            break;
        case 'yesterday':
            $start_date = date('Y-m-d 00:00:00', strtotime('-1 day', current_time('timestamp')));
            $end_date = date('Y-m-d 23:59:59', strtotime('-1 day', current_time('timestamp')));
            break;
        case 'week':
            $start_date = date('Y-m-d 00:00:00', strtotime('monday this week', current_time('timestamp')));
            break;
        case 'month':
            $start_date = current_time('Y-m-01 00:00:00');
            break;
        case 'year':
            $start_date = current_time('Y-01-01 00:00:00');
            break;
        default:
            $start_date = date('Y-m-d 00:00:00', strtotime('monday this week', current_time('timestamp')));
    }
    
    // Get orders using WooCommerce's recommended method
    if (function_exists('wc_get_orders')) {
        $start_date_only = date('Y-m-d', strtotime($start_date));
        $end_date_only = date('Y-m-d', strtotime($end_date));
        
        $order_ids = wc_get_orders(array(
            'limit' => -1,
            'status' => array('completed', 'processing'),
            'date_created' => $start_date_only . '...' . $end_date_only,
            'return' => 'ids',
        ));
    } else {
        // Fallback to get_posts
        $args = array(
            'post_type' => 'shop_order',
            'post_status' => array('wc-completed', 'wc-processing'),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'date_query' => array(
                array(
                    'after' => $start_date,
                    'before' => $end_date,
                    'inclusive' => true,
                    'column' => 'post_date',
                ),
            ),
        );
        
        $order_ids = get_posts($args);
    }
    
    // Convert IDs to order objects
    $orders = array();
    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if ($order) {
            $orders[] = $order;
        }
    }
    
    // Count payment methods
    $payment_methods = array();
    
    foreach ($orders as $order) {
        // Skip refund objects as they don't have payment method info
        if ($order->get_type() === 'shop_order_refund') {
            continue;
        }
        
        $payment_method = $order->get_payment_method();
        $payment_method_title = $order->get_payment_method_title();
        
        if (!isset($payment_methods[$payment_method_title])) {
            $payment_methods[$payment_method_title] = 0;
        }
        $payment_methods[$payment_method_title]++;
    }
    
    // If no data, show default structure
    if (empty($payment_methods)) {
        $payment_methods = array(
            'Cash' => 0,
            'Credit Card' => 0,
            'Mobile Payment' => 0
        );
    }
    
    wp_send_json_success(array(
        'labels' => array_keys($payment_methods),
        'values' => array_values($payment_methods),
        'date_range' => $date_range
    ));
}

/**
 * Get top selling products for reports
 */
function ruiyi_pos_get_top_products() {
    $date_range = isset($_POST['date_range']) ? sanitize_text_field($_POST['date_range']) : 'week';

    // 🔥 Performance: transient cache (1 hour TTL, keyed by date_range + current hour)
    $cache_key = 'ruiyi_pos_top_' . $date_range . '_' . current_time('Y-m-d-H');
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        wp_send_json_success($cached);
        return;
    }

    // Calculate date range using WordPress timezone
    $start_date = '';
    $end_date = current_time('Y-m-d H:i:s');
    
    switch ($date_range) {
        case 'today':
            $start_date = current_time('Y-m-d 00:00:00');
            break;
        case 'yesterday':
            $start_date = date('Y-m-d 00:00:00', strtotime('-1 day', current_time('timestamp')));
            $end_date = date('Y-m-d 23:59:59', strtotime('-1 day', current_time('timestamp')));
            break;
        case 'week':
            $start_date = date('Y-m-d 00:00:00', strtotime('monday this week', current_time('timestamp')));
            break;
        case 'month':
            $start_date = current_time('Y-m-01 00:00:00');
            break;
        case 'year':
            $start_date = current_time('Y-01-01 00:00:00');
            break;
        default:
            $start_date = date('Y-m-d 00:00:00', strtotime('monday this week', current_time('timestamp')));
    }
    
    // Get orders using proper method
    if (function_exists('wc_get_orders')) {
        $order_ids = wc_get_orders(array(
            'limit' => -1,
            'status' => array('completed', 'processing'),
            'date_created' => $start_date . '...' . $end_date,
            'return' => 'ids',
        ));
    } else {
        $args = array(
            'post_type' => 'shop_order',
            'post_status' => array('wc-completed', 'wc-processing'),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'date_query' => array(
                array(
                    'after' => $start_date,
                    'before' => $end_date,
                    'inclusive' => true,
                    'column' => 'post_date',
                ),
            ),
        );
        $orders = get_posts($args);
        $order_ids = $orders;
    }
    
    // Collect product sales data
    $product_sales = array();
    
    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) continue;
        
        // Skip refund objects
        if ($order->get_type() === 'shop_order_refund') {
            continue;
        }
        
        $items = $order->get_items();
        
        foreach ($items as $item) {
            $product_id = $item->get_product_id();
            $quantity = $item->get_quantity();
            $total = $item->get_total();
            
            if (!isset($product_sales[$product_id])) {
                $product_sales[$product_id] = array(
                    'quantity' => 0,
                    'total' => 0,
                    'name' => $item->get_name()
                );
            }
            
            $product_sales[$product_id]['quantity'] += $quantity;
            $product_sales[$product_id]['total'] += $total;
        }
    }
    
    // Sort by quantity sold (descending)
    uasort($product_sales, function($a, $b) {
        return $b['quantity'] - $a['quantity'];
    });
    
    // Get top 5 products
    $top_products = array_slice($product_sales, 0, 5, true);
    
    // Format for output
    $formatted_products = array();
    foreach ($top_products as $product_id => $data) {
        $formatted_products[] = array(
            'id' => $product_id,
            'name' => $data['name'],
            'quantity' => $data['quantity'],
            'total' => number_format($data['total'], 2)
        );
    }
    
    
    $result = array(
        'products' => $formatted_products,
        'date_range' => $date_range
    );
    set_transient($cache_key, $result, HOUR_IN_SECONDS);
    wp_send_json_success($result);
}

/**
 * Get store settings for POS receipts
 */
function ruiyi_pos_get_store_settings() {
    // 复用公司信息 tab 保存的 Verifactu emitter 数据作为小票抬头
    $verifactu = get_option('wc_verifactu_qr_settings', array());

    // 拼接地址: address_1, city postcode
    $addr_parts = array_filter(array(
        isset($verifactu['emitter_address_1']) ? $verifactu['emitter_address_1'] : '',
        trim(
            (isset($verifactu['emitter_city']) ? $verifactu['emitter_city'] : '') . ' ' .
            (isset($verifactu['emitter_postcode']) ? $verifactu['emitter_postcode'] : '')
        ),
    ));
    $store_address = implode(', ', $addr_parts);

    return array(
        'business_name' => isset($verifactu['emitter_name']) ? $verifactu['emitter_name'] : '',
        'store_name' => get_option('ruiyi_pos_store_name', '') ?: (isset($verifactu['emitter_name']) ? $verifactu['emitter_name'] : get_bloginfo('name')),
        'store_address' => $store_address,
        'store_phone' => isset($verifactu['emitter_phone']) ? $verifactu['emitter_phone'] : '',
        'store_email' => get_option('ruiyi_pos_store_email', get_option('admin_email')),
        'store_website' => get_option('ruiyi_pos_store_website', home_url()),
        'store_tax_number' => isset($verifactu['emitter_vat']) ? $verifactu['emitter_vat'] : '',
        'receipt_footer' => get_option('ruiyi_pos_store_receipt_footer', __('Thank you for your purchase!', 'ruiyi-retail-pos')),
        'return_policy' => get_option('ruiyi_pos_store_return_policy', __('Returns accepted within 30 days with receipt', 'ruiyi-retail-pos')),
        'auto_print_receipt' => get_option('ruiyi_pos_auto_print_receipt', '1') === '1',
    );
}

/**
 * Enhanced order creation with complete WooCommerce data
 */
function ruiyi_pos_create_order_enhanced() {
    $cart_items = isset($_POST['cart_items']) ? json_decode(stripslashes($_POST['cart_items']), true) : array();
    $payment_method = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : 'cash';
    $client_order_id = isset($_POST['client_order_id']) ? sanitize_text_field(wp_unslash($_POST['client_order_id'])) : '';
    $customer_note = isset($_POST['customer_note']) ? sanitize_textarea_field($_POST['customer_note']) : '';
    $amount_paid = isset($_POST['amount_paid']) ? floatval($_POST['amount_paid']) : 0;

    // Mixed payment details
    $cash_amount = isset($_POST['cash_amount']) ? floatval($_POST['cash_amount']) : 0;
    $card_amount = isset($_POST['card_amount']) ? floatval($_POST['card_amount']) : 0;

    // Customer information data
    $customer_name = isset($_POST['customer_name']) ? sanitize_text_field($_POST['customer_name']) : '';
    $customer_phone = isset($_POST['customer_phone']) ? sanitize_text_field($_POST['customer_phone']) : '';
    $customer_address = isset($_POST['customer_address']) ? sanitize_text_field($_POST['customer_address']) : '';
    $customer_company = isset($_POST['customer_company']) ? sanitize_text_field($_POST['customer_company']) : '';
    $customer_tax_id = isset($_POST['customer_tax_id']) ? sanitize_text_field($_POST['customer_tax_id']) : '';

    if (empty($cart_items)) {
        wp_send_json_error(array('message' => __('Cart is empty', 'ruiyi-retail-pos')));
    }

    // Dedup: if client_order_id was already processed, return existing order.
    if (!empty($client_order_id)) {
        $existing = get_posts(array(
            'post_type' => 'shop_order',
            'post_status' => array_keys(wc_get_order_statuses()),
            'numberposts' => 1,
            'meta_key' => '_pos_client_order_id',
            'meta_value' => $client_order_id,
            'fields' => 'ids',
        ));
        if (!empty($existing)) {
            $order_id = intval($existing[0]);
            $order = wc_get_order($order_id);
            if ($order) {
                wp_send_json_success(array(
                    'order_id' => $order_id,
                    'order_number' => $order->get_order_number(),
                    'status' => $order->get_status(),
                    'message' => __('Order already completed (deduped)', 'ruiyi-retail-pos'),
                ));
            }
        }
    }

    // 🔥 防重复保护：基于内容哈希+时间窗口去重
    // 计算订单内容哈希（购物车+支付方式），60秒内相同内容视为重复
    $content_hash = md5(json_encode($cart_items) . $payment_method . $cash_amount . $card_amount);
    $dedup_transient_key = 'ruiyi_pos_order_dedup_' . $content_hash;
    $existing_order_id = get_transient($dedup_transient_key);
    if ($existing_order_id) {
        $existing_order = wc_get_order($existing_order_id);
        if ($existing_order) {
            ruiyi_pos_log("[POS Dedup] Duplicate order detected within 60s window. Hash: {$content_hash}, Existing order: #{$existing_order_id}");
            wp_send_json_success(array(
                'order_id' => intval($existing_order_id),
                'order_number' => $existing_order->get_order_number(),
                'status' => $existing_order->get_status(),
                'message' => __('Order already completed (content dedup)', 'ruiyi-retail-pos'),
            ));
            return;
        }
    }

    try {
        // Create order using WooCommerce API
        $order = wc_create_order();

        if (is_wp_error($order)) {
            throw new Exception($order->get_error_message());
        }

        $order_total = 0;
        $order_items = array();
        
        // Add products to order with full WooCommerce data (支持小数数量 + 退货负数量)
        $return_items_for_stock = array(); // 退货商品，用于后续增加库存
        foreach ($cart_items as $item) {
            $product_id = intval($item['id']);
            $quantity = floatval($item['quantity']); // 支持小数数量如1.5, 2.75

            // 🔥 DEBUG: 追踪小数数量 (enhanced function)
            ruiyi_pos_log("[POS DEBUG Enhanced] Item from cart - raw quantity: " . print_r($item['quantity'], true) . ", floatval: " . $quantity);

            if ($quantity == 0) {
                continue;
            }

            $product = wc_get_product($product_id);
            if (!$product || !$product->is_purchasable()) {
                continue;
            }

            // 🔥 检测退货商品（负数量）
            $is_return = $quantity < 0;
            $abs_quantity = abs($quantity);

            // Check stock (跳过Varios产品和退货商品的库存检查)
            $is_varios = isset($item['isVarios']) && $item['isVarios'] === true;
            if (!$is_varios && !$is_return && !$product->has_enough_stock($abs_quantity)) {
                wp_send_json_error(array(
                    'message' => sprintf(__('Not enough stock for %s', 'ruiyi-retail-pos'), $product->get_name())
                ));
            }

            // 获取价格：前端传来的价格（WC税费已关闭，get_price()返回的就是含税最终价）
            $item_price = floatval($item['price']);
            $original_price = $product->get_price(); // 保存原始不含税价格

            // 检查是否有自定义价格（Varios产品或手动修改的价格）
            $has_custom_price = (isset($item['customPrice']) && $item['customPrice'] === true) ||
                                ($is_varios && isset($item['price']));

            // 如果有自定义价格，需要在添加前临时修改产品价格
            if ($has_custom_price && $item_price > 0) {
                $product->set_price($item_price);
                $product->set_regular_price($item_price);
            }

            // Add item to order（使用绝对数量添加，然后手动设置负数金额）
            $item_id = $order->add_product($product, $abs_quantity);

            // 🔥 退货商品：手动设置负数金额
            // WC税费已关闭，产品价格就是最终含税价，不需要拆分base+tax
            if ($is_return && $item_id) {
                $negative_total = -1 * $item_price * $abs_quantity;

                // 使用WooCommerce order item对象设置负数
                $order_item = $order->get_item($item_id);
                if ($order_item) {
                    $order_item->set_quantity($quantity); // 设置负数量
                    $order_item->set_subtotal($negative_total); // 完整金额，不拆分税费
                    $order_item->set_total($negative_total);    // 完整金额，不拆分税费
                    $order_item->save();
                }

                wc_update_order_item_meta($item_id, '_is_return', 'yes');
                wc_update_order_item_meta($item_id, '_return_quantity', $abs_quantity);

                // 记录退货商品用于后续增加库存
                $return_items_for_stock[] = array(
                    'product' => $product,
                    'quantity' => $abs_quantity
                );

                ruiyi_pos_log("RUIYI POS: Return item - Product: {$product->get_name()}, Qty: {$quantity}, Total: {$negative_total}");
            }

            // 如果有自定义价格，额外保存元数据
            if ($has_custom_price && $item_id) {
                wc_update_order_item_meta($item_id, '_custom_price', $item_price);
                wc_update_order_item_meta($item_id, '_original_price', $original_price);
                wc_update_order_item_meta($item_id, '_price_modified_by_cashier', 'yes');

                $price_diff = $item_price - $original_price;
                ruiyi_pos_log("RUIYI POS: Custom price - Product: {$product->get_name()}, Original: €{$original_price}, Modified: €{$item_price}, Difference: €{$price_diff}, Item ID: #{$item_id}");
            }

            // Get tax rate for this product
            $tax_rate = ruiyi_get_product_tax_rate($product);
            ruiyi_pos_log("ORDER CREATION ENHANCED: Product '{$product->get_name()}' (ID: {$product_id}) - Tax rate: {$tax_rate}%");

            // Calculate tax details (price includes tax) - 使用原始数量（含负数）
            $line_total = $item_price * $quantity;
            $line_subtotal = $line_total / (1 + ($tax_rate / 100));
            $line_tax = $line_total - $line_subtotal;

            // Store item data for receipt with tax information
            // For Varios products, force use "Varios" name to prevent product ID from showing
            $item_name = $is_varios ? 'Varios' : $product->get_name();

            // 🔥 折扣信息：从前端传来的购物车项中获取
            $discount_percent = isset($item['discountPercent']) ? floatval($item['discountPercent']) : 0;
            $original_price = isset($item['originalPrice']) ? floatval($item['originalPrice']) : $item_price;

            $order_items[] = array(
                'id' => $product_id,
                'name' => $item_name,
                'sku' => $product->get_sku(),
                'quantity' => $quantity,
                'unit_price' => $item_price,
                'line_total' => $line_total,
                'tax_rate' => $tax_rate,
                'line_subtotal' => $line_subtotal,
                'line_tax' => $line_tax,
                'tax_class' => $product->get_tax_class(),
                'weight' => $product->get_weight(),
                'categories' => wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names')),
                'is_return' => $is_return,
                'discount_percent' => $discount_percent,
                'original_price' => $original_price,
            );

            // 🔥 DEBUG: 追踪价格和数量 (enhanced function)
            ruiyi_pos_log("[POS DEBUG Enhanced] order_item: name={$item_name}, qty={$quantity}, cart_price={$item['price']}, item_price={$item_price}, unit_price_in_response={$item_price}, line_total={$line_total}, is_return=" . ($is_return ? 'yes' : 'no'));

            // Store tax rate as item meta
            if ($item_id) {
                wc_add_order_item_meta($item_id, '_tax_rate', $tax_rate);
            }

            // 使用正确的价格计算订单总计（使用原始数量含负数）
            $order_total += $item_price * $quantity;
        }
        
        // Set order details
        $order->set_created_via('pos');
        $order->update_meta_data('_ruiyi_order_source', '收银系统');
        $order->set_payment_method($payment_method);

        // Set payment method titles
        $payment_titles = array(
            'cash' => __('Cash', 'ruiyi-retail-pos'),
            'card' => __('Credit Card', 'ruiyi-retail-pos'),
            'mobile' => __('Mobile Payment', 'ruiyi-retail-pos'),
        );
        $order->set_payment_method_title($payment_titles[$payment_method] ?? $payment_method);
        
        // Add customer note
        if (!empty($customer_note)) {
            $order->set_customer_note($customer_note);
        }
        
        // Add order meta for POS
        $order->update_meta_data('_pos_order', 'yes');
        $order->update_meta_data('_pos_cashier', wp_get_current_user()->display_name);
        $order->update_meta_data('_pos_amount_paid', $amount_paid);
        if (!empty($client_order_id)) {
            $order->update_meta_data('_pos_client_order_id', $client_order_id);
        }

        // Save customer information to order meta
        if (!empty($customer_name)) {
            $order->update_meta_data('_customer_name', $customer_name);
        }
        if (!empty($customer_phone)) {
            $order->update_meta_data('_customer_phone', $customer_phone);
        }
        if (!empty($customer_address)) {
            $order->update_meta_data('_customer_address', $customer_address);
        }
        if (!empty($customer_company)) {
            $order->update_meta_data('_customer_company', $customer_company);
        }
        if (!empty($customer_tax_id)) {
            $order->update_meta_data('_customer_tax_id', $customer_tax_id);
        }

        // Calculate change for cash payments
        $change = 0;
        if ($payment_method === 'cash' && $amount_paid > 0) {
            $change = $amount_paid - $order_total;
        }

        if ($change > 0) {
            $order->update_meta_data('_pos_change', $change);
        }

        // Save mixed payment details to order meta
        if ($payment_method === 'mixed') {
            $order->update_meta_data('_pos_cash_amount', $cash_amount);
            $order->update_meta_data('_pos_card_amount', $card_amount);
            ruiyi_pos_log("RUIYI POS: Mixed payment saved - Cash: €{$cash_amount}, Card: €{$card_amount}");
        }
        
        // 🔥 标记包含退货的订单
        if (!empty($return_items_for_stock)) {
            $order->update_meta_data('_has_returns', 'yes');
            $order->update_meta_data('_return_items_count', count($return_items_for_stock));
        }

        // Add order note
        $cashier = wp_get_current_user();
        $return_note = !empty($return_items_for_stock) ? ' [含退货]' : '';
        $order->add_order_note(sprintf(
            __('Order created via POS by %s (Payment: %s)', 'ruiyi-retail-pos') . $return_note,
            $cashier->display_name,
            $payment_titles[$payment_method] ?? $payment_method
        ));
        
        // Calculate totals
        $order->calculate_totals();

        // 🔥 修正：手动设置正确的订单总额（calculate_totals可能无法正确处理退货负数）
        $order->set_total($order_total);
        $order->save();

        // 🔥 根据总额设置订单状态
        if ($order_total < 0) {
            // 纯退货订单（总额为负）→ 设为已取消，标记为POS退货订单
            $order->update_status('cancelled', __('POS return/refund order', 'ruiyi-retail-pos'));
            $order->update_meta_data('_pos_refund_order', 'yes');
            $order->save();
            ruiyi_pos_log("RUIYI POS: Refund order created - Total: {$order_total}");
        } else {
            // 正常订单或混合订单（总额为正）→ 设为已完成
            $order->update_status('completed', __('Order completed via POS', 'ruiyi-retail-pos'));
        }
        
        // Update stock
        wc_maybe_reduce_stock_levels($order->get_id());

        // 🔥 退货商品：增加库存（wc_maybe_reduce_stock_levels只处理正数量）
        if (!empty($return_items_for_stock)) {
            foreach ($return_items_for_stock as $return_item) {
                $return_product = $return_item['product'];
                $return_qty = $return_item['quantity'];
                if ($return_product->managing_stock()) {
                    wc_update_product_stock($return_product, $return_qty, 'increase');
                    ruiyi_pos_log("RUIYI POS: Return stock increase - Product: {$return_product->get_name()}, Qty: +{$return_qty}");
                }
            }
        }

        // Calculate tax details using the new tax system
        // Use the processed order_items which have tax rates
        $tax_details = ruiyi_calculate_order_tax_details($order_items);

        // Calculate totals from tax details
        $original_total = $order_total; // This is the sum of all items
        $original_subtotal_without_tax = 0;
        $total_tax = 0;

        // Sum up tax totals by rate
        foreach ($tax_details['totals_by_rate'] as $rate_key => $rate_data) {
            $original_subtotal_without_tax += $rate_data['base'];
            $total_tax += $rate_data['tax'];
        }

        // If no tax details calculated (fallback)
        if ($original_subtotal_without_tax == 0) {
            $original_subtotal_without_tax = $original_total / 1.21; // Fallback to 21% default
            $total_tax = $original_total - $original_subtotal_without_tax;
        }
        
        // Get complete order data for receipt
        $order_data = array(
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'order_key' => $order->get_order_key(),
            'date_created' => $order->get_date_created()->format('Y-m-d H:i:s'),
            'cashier' => $cashier->display_name,
            'items' => $order_items,
            'items_count' => $order->get_item_count(),
            'subtotal' => $original_subtotal_without_tax,
            'tax_total' => $total_tax,
            'tax_details' => $tax_details,
            'tax_breakdown' => $tax_details['totals_by_rate'],
            'total' => $order->get_total(),
            'formatted_subtotal' => wc_price($original_subtotal_without_tax),
            'formatted_tax' => wc_price($total_tax),
            'formatted_total' => wc_price($order->get_total()),
            'payment_method' => $payment_method,
            'payment_method_title' => $payment_titles[$payment_method] ?? $payment_method,
            'amount_paid' => $amount_paid,
            'change' => $change,
            'formatted_change' => wc_price($change),
            'customer_note' => $customer_note,
            'customer_name' => $customer_name,
            'customer_phone' => $customer_phone,
            'customer_address' => $customer_address,
            'customer_company' => $customer_company,
            'customer_tax_id' => $customer_tax_id,
            'currency' => get_woocommerce_currency(),
            'currency_symbol' => get_woocommerce_currency_symbol(),
            'store_settings' => ruiyi_pos_get_store_settings(),
        );

        // 🔥 保存去重标记（60秒有效期），防止短时间内创建相同订单
        if (!empty($content_hash)) {
            set_transient($dedup_transient_key, $order->get_id(), 60);
        }

        wp_send_json_success($order_data);

    } catch (Exception $e) {
        wp_send_json_error(array(
            'message' => $e->getMessage(),
            'code' => 'order_creation_failed'
        ));
    }
}

/**
 * AJAX handler to lookup user by shortcode
 */
function ruiyi_lookup_user_by_shortcode() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'ruiyi_pos_nonce')) {
        wp_send_json_error(array('message' => __('Security check failed', 'ruiyi-retail-pos')));
    }
    
    // Check user permission
    if (!ruiyi_pos_check_access()) {
        wp_send_json_error(array('message' => __('Unauthorized access', 'ruiyi-retail-pos')));
    }
    
    $shortcode = isset($_POST['shortcode']) ? sanitize_text_field($_POST['shortcode']) : '';
    
    if (empty($shortcode)) {
        wp_send_json_error(array('message' => __('Please enter a shortcode', 'ruiyi-retail-pos')));
    }
    
    // Get current site ID
    $site_id = get_current_blog_id();
    
    // Search for user by shortcode
    $args = array(
        'meta_query' => array(
            array(
                'key' => is_multisite() ? "site_{$site_id}_user_shortcode" : 'user_shortcode',
                'value' => $shortcode,
                'compare' => '='
            )
        ),
        'number' => 1
    );
    
    $users = get_users($args);
    
    if (empty($users)) {
        wp_send_json_error(array('message' => __('User not found', 'ruiyi-retail-pos')));
    }
    
    $user = $users[0];
    
    // Get user points
    $points = 0;
    if (function_exists('ruiyi_get_user_points_for_site')) {
        $points = ruiyi_get_user_points_for_site($user->ID, $site_id);
    }
    
    wp_send_json_success(array(
        'user_id' => $user->ID,
        'user_name' => $user->display_name,
        'user_email' => $user->user_email,
        'points' => $points,
        'shortcode' => $shortcode
    ));
}
add_action('wp_ajax_ruiyi_lookup_user_by_shortcode', 'ruiyi_lookup_user_by_shortcode');

/**
 * AJAX handler to apply points discount
 */
function ruiyi_apply_points_discount() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'ruiyi_pos_nonce')) {
        wp_send_json_error(array('message' => __('Security check failed', 'ruiyi-retail-pos')));
    }
    
    // Check user permission
    if (!ruiyi_pos_check_access()) {
        wp_send_json_error(array('message' => __('Unauthorized access', 'ruiyi-retail-pos')));
    }
    
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $points_to_use = isset($_POST['points']) ? intval($_POST['points']) : 0;
    $cart_total = isset($_POST['cart_total']) ? floatval($_POST['cart_total']) : 0;
    
    if (!$user_id || !$points_to_use || !$cart_total) {
        wp_send_json_error(array('message' => __('Invalid request data', 'ruiyi-retail-pos')));
    }
    
    // Get current site ID
    $site_id = get_current_blog_id();
    
    // Get user's available points
    $available_points = 0;
    if (function_exists('ruiyi_get_user_points_for_site')) {
        $available_points = ruiyi_get_user_points_for_site($user_id, $site_id);
    }
    
    if ($points_to_use > $available_points) {
        wp_send_json_error(array('message' => __('Insufficient points', 'ruiyi-retail-pos')));
    }
    
    // Use WordPress admin settings for points calculation
    $max_discount_percent = floatval(get_option('ruiyi_points_max_discount', 10));
    $deduction_rate = floatval(get_option('ruiyi_points_redemption_rate', 10));
    
    // Calculate maximum allowed discount
    $max_discount_amount = ($cart_total * $max_discount_percent) / 100;
    
    // Calculate discount amount based on points used and deduction rate
    // Formula: discount = points_used / (deduction_rate * 100)
    $discount_amount = $points_to_use / ($deduction_rate * 100);
    
    // Ensure discount doesn't exceed maximum allowed discount
    if ($discount_amount > $max_discount_amount) {
        $discount_amount = $max_discount_amount;
    }
    
    // Ensure discount doesn't exceed cart total
    if ($discount_amount > $cart_total) {
        $discount_amount = $cart_total;
    }
    
    wp_send_json_success(array(
        'points_used' => $points_to_use,
        'discount_amount' => $discount_amount,
        'formatted_discount' => wc_price($discount_amount),
        'remaining_points' => $available_points - $points_to_use,
        'new_total' => $cart_total - $discount_amount,
        'formatted_new_total' => wc_price($cart_total - $discount_amount)
    ));
}
add_action('wp_ajax_ruiyi_apply_points_discount', 'ruiyi_apply_points_discount');

/**
 * AJAX handler to calculate reward points
 */
function ruiyi_ajax_calculate_reward_points() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'ruiyi_pos_nonce')) {
        wp_send_json_error(array('message' => __('Security check failed', 'ruiyi-retail-pos')));
    }
    
    // Check user permission
    if (!ruiyi_pos_check_access()) {
        wp_send_json_error(array('message' => __('Unauthorized access', 'ruiyi-retail-pos')));
    }
    
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $order_total = isset($_POST['order_total']) ? floatval($_POST['order_total']) : 0;
    
    if (!$user_id || !$order_total) {
        wp_send_json_error(array('message' => __('Invalid request data', 'ruiyi-retail-pos')));
    }
    
    // Use WordPress admin settings for reward calculation
    $reward_rate = floatval(get_option('ruiyi_points_reward_rate', 15));
    
    // Formula: points_to_reward = order_total × reward_rate% × 100
    $points_to_reward = floor(($order_total * $reward_rate / 100) * 100);
    
    wp_send_json_success(array(
        'points_to_reward' => $points_to_reward,
        'order_total' => $order_total,
        'formatted_order_total' => wc_price($order_total),
        'reward_rate' => $reward_rate
    ));
}
add_action('wp_ajax_ruiyi_calculate_reward_points', 'ruiyi_ajax_calculate_reward_points');

/**
 * Enhanced order creation with points system integration
 */
function ruiyi_pos_create_order_with_points() {
    $cart_items = isset($_POST['cart_items']) ? json_decode(stripslashes($_POST['cart_items']), true) : array();
    $payment_method = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : 'cash';
    $customer_note = isset($_POST['customer_note']) ? sanitize_textarea_field($_POST['customer_note']) : '';
    $amount_paid = isset($_POST['amount_paid']) ? floatval($_POST['amount_paid']) : 0;
    
    // Points system data
    $points_user_id = isset($_POST['points_user_id']) ? intval($_POST['points_user_id']) : 0;
    $points_discount = isset($_POST['points_discount']) ? floatval($_POST['points_discount']) : 0;
    $points_used = isset($_POST['points_used']) ? intval($_POST['points_used']) : 0;
    $points_reward = isset($_POST['points_reward']) ? intval($_POST['points_reward']) : 0;
    $user_shortcode = isset($_POST['user_shortcode']) ? sanitize_text_field($_POST['user_shortcode']) : '';

    // Customer information data
    $customer_name = isset($_POST['customer_name']) ? sanitize_text_field($_POST['customer_name']) : '';
    $customer_phone = isset($_POST['customer_phone']) ? sanitize_text_field($_POST['customer_phone']) : '';
    $customer_address = isset($_POST['customer_address']) ? sanitize_text_field($_POST['customer_address']) : '';
    $customer_company = isset($_POST['customer_company']) ? sanitize_text_field($_POST['customer_company']) : '';
    $customer_tax_id = isset($_POST['customer_tax_id']) ? sanitize_text_field($_POST['customer_tax_id']) : '';

    if (empty($cart_items)) {
        wp_send_json_error(array('message' => __('Cart is empty', 'ruiyi-retail-pos')));
    }
    
    try {
        // Create order using WooCommerce API
        $order = wc_create_order();
        
        if (is_wp_error($order)) {
            throw new Exception($order->get_error_message());
        }
        
        $order_subtotal = 0;
        $order_items = array();
        
        // Add products to order (支持小数数量)
        foreach ($cart_items as $item) {
            $product_id = intval($item['id']);
            $quantity = floatval($item['quantity']); // 支持小数数量如1.5, 2.75

            // 🔥 DEBUG: 追踪小数数量
            ruiyi_pos_log("[POS DEBUG] Item from cart - raw quantity: " . print_r($item['quantity'], true) . ", floatval: " . $quantity);

            if ($quantity <= 0) {
                continue;
            }

            $product = wc_get_product($product_id);
            if (!$product || !$product->is_purchasable()) {
                continue;
            }

            // Check stock (跳过Varios产品的库存检查)
            $is_varios = isset($item['isVarios']) && $item['isVarios'] === true;
            if (!$is_varios && !$product->has_enough_stock($quantity)) {
                wp_send_json_error(array(
                    'message' => sprintf(__('Not enough stock for %s', 'ruiyi-retail-pos'), $product->get_name())
                ));
            }

            // 获取价格：前端传来的价格（WC税费已关闭，get_price()返回的就是含税最终价）
            $item_price = floatval($item['price']);
            $original_price = $product->get_price(); // 保存原始不含税价格

            // 检查是否有自定义价格（Varios产品或手动修改的价格）
            $has_custom_price = (isset($item['customPrice']) && $item['customPrice'] === true) ||
                                ($is_varios && isset($item['price']));

            // 如果有自定义价格，需要在添加前临时修改产品价格
            if ($has_custom_price && $item_price > 0) {
                $product->set_price($item_price);
                $product->set_regular_price($item_price);
            }

            // Add item to order（使用修改后的价格）
            $item_id = $order->add_product($product, $quantity);

            // 如果有自定义价格，额外保存元数据
            if ($has_custom_price && $item_id) {
                wc_update_order_item_meta($item_id, '_custom_price', $item_price);
                wc_update_order_item_meta($item_id, '_original_price', $original_price);
                wc_update_order_item_meta($item_id, '_price_modified_by_cashier', 'yes');

                $price_diff = $item_price - $original_price;
                ruiyi_pos_log("RUIYI POS: Custom price - Product: {$product->get_name()}, Original: €{$original_price}, Modified: €{$item_price}, Difference: €{$price_diff}, Item ID: #{$item_id}");
            }

            // Get tax rate for this product
            $tax_rate = ruiyi_get_product_tax_rate($product);
            ruiyi_pos_log("ORDER CREATION: Product '{$product->get_name()}' (ID: {$product_id}) - Tax rate: {$tax_rate}%");

            // Calculate tax details (price includes tax)
            $line_total = $item_price * $quantity;
            $line_subtotal = $line_total / (1 + ($tax_rate / 100));
            $line_tax = $line_total - $line_subtotal;

            // Store item data for receipt with tax information
            // For Varios products, force use "Varios" name to prevent product ID from showing
            $item_name = $is_varios ? 'Varios' : $product->get_name();

            // 🔥 折扣信息
            $discount_percent = isset($item['discountPercent']) ? floatval($item['discountPercent']) : 0;
            $original_price = isset($item['originalPrice']) ? floatval($item['originalPrice']) : $item_price;

            $order_items[] = array(
                'id' => $product_id,
                'name' => $item_name,
                'sku' => $product->get_sku(),
                'quantity' => $quantity,
                'unit_price' => $item_price,
                'line_total' => $line_total,
                'tax_rate' => $tax_rate,
                'line_subtotal' => $line_subtotal,
                'line_tax' => $line_tax,
                'discount_percent' => $discount_percent,
                'original_price' => $original_price,
            );

            // 🔥 DEBUG: 追踪 order_items 中的数量
            ruiyi_pos_log("[POS DEBUG] Added to order_items - name: {$item_name}, quantity: {$quantity}, line_total: {$line_total}");

            // Store tax rate as item meta
            if ($item_id) {
                wc_add_order_item_meta($item_id, '_tax_rate', $tax_rate);
            }

            // 使用正确的价格计算订单总计（Varios使用自定义价格）
            $order_subtotal += $item_price * $quantity;
        }
        
        // Apply points discount if any
        if ($points_discount > 0 && $points_user_id > 0) {
            // Add discount as a fee (negative amount)
            $fee = new WC_Order_Item_Fee();
            $fee->set_name(__('Points Discount', 'ruiyi-retail-pos'));
            $fee->set_amount(-$points_discount);
            $fee->set_total(-$points_discount);
            $order->add_item($fee);
            
            // Store points usage info
            $order->update_meta_data('_pos_points_discount', $points_discount);
            $order->update_meta_data('_pos_points_used', $points_used);
            $order->update_meta_data('_pos_points_user_id', $points_user_id);
        }
        
        // Set order details
        $order->set_created_via('pos');
        $order->update_meta_data('_ruiyi_order_source', '收银系统');
        $order->set_payment_method($payment_method);

        // Set payment method titles
        $payment_titles = array(
            'cash' => __('Cash', 'ruiyi-retail-pos'),
            'card' => __('Credit Card', 'ruiyi-retail-pos'),
            'mobile' => __('Mobile Payment', 'ruiyi-retail-pos'),
        );
        $order->set_payment_method_title($payment_titles[$payment_method] ?? $payment_method);
        
        // Add customer note
        if (!empty($customer_note)) {
            $order->set_customer_note($customer_note);
        }
        
        // Add order meta for POS
        $order->update_meta_data('_pos_order', 'yes');
        $order->update_meta_data('_pos_cashier', wp_get_current_user()->display_name);
        $order->update_meta_data('_pos_amount_paid', $amount_paid);

        // Add customer information meta if provided
        if (!empty($customer_name)) {
            $order->update_meta_data('_customer_name', $customer_name);
        }
        if (!empty($customer_phone)) {
            $order->update_meta_data('_customer_phone', $customer_phone);
        }
        if (!empty($customer_address)) {
            $order->update_meta_data('_customer_address', $customer_address);
        }
        if (!empty($customer_company)) {
            $order->update_meta_data('_customer_company', $customer_company);
        }
        if (!empty($customer_tax_id)) {
            $order->update_meta_data('_customer_tax_id', $customer_tax_id);
        }

        // Calculate totals
        $order->calculate_totals();
        
        // Calculate change for cash payments
        $order_total = $order->get_total();
        $change = 0;
        if ($payment_method === 'cash' && $amount_paid > 0) {
            $change = $amount_paid - $order_total;
        }
        
        if ($change > 0) {
            $order->update_meta_data('_pos_change', $change);
        }
        
        // Add order note
        $cashier = wp_get_current_user();
        $note_parts = array(
            sprintf(__('Order created via POS by %s', 'ruiyi-retail-pos'), $cashier->display_name),
            sprintf(__('Payment: %s', 'ruiyi-retail-pos'), $payment_titles[$payment_method] ?? $payment_method)
        );
        
        if ($points_used > 0) {
            $note_parts[] = sprintf(__('Points used: %d', 'ruiyi-retail-pos'), $points_used);
        }
        
        $order->add_order_note(implode(' | ', $note_parts));
        
        // Complete order
        $order->update_status('completed', __('Order completed via POS', 'ruiyi-retail-pos'));
        
        // Update stock
        wc_maybe_reduce_stock_levels($order->get_id());
        
        // Process points transactions
        $site_id = get_current_blog_id();
        
        // Deduct points if used
        if ($points_used > 0 && $points_user_id > 0 && function_exists('ruiyi_update_user_points')) {
            ruiyi_update_user_points(
                $points_user_id,
                -$points_used,
                sprintf(__('Points used for order #%s', 'ruiyi-retail-pos'), $order->get_order_number()),
                $cashier->ID,
                $site_id
            );
        }
        
        // Award points if applicable
        if ($points_reward > 0 && $points_user_id > 0 && function_exists('ruiyi_update_user_points')) {
            ruiyi_update_user_points(
                $points_user_id,
                $points_reward,
                sprintf(__('Points earned from order #%s', 'ruiyi-retail-pos'), $order->get_order_number()),
                $cashier->ID,
                $site_id
            );
            
            $order->update_meta_data('_pos_points_rewarded', $points_reward);
        }
        
        // Update user shortcode if points were used or rewarded and shortcode was provided
        if ($points_user_id > 0 && !empty($user_shortcode) && ($points_used > 0 || $points_reward > 0)) {
            if (function_exists('ruiyi_refresh_user_shortcode')) {
                // Update the shortcode for the user on the current site
                $new_shortcode = ruiyi_refresh_user_shortcode($points_user_id, $site_id);
                
                // Log the shortcode update in order meta
                $order->update_meta_data('_pos_user_shortcode_used', $user_shortcode);
                $order->update_meta_data('_pos_user_shortcode_updated', $new_shortcode);
                
                // Add order note about shortcode update
                $order->add_order_note(sprintf(
                    __('User shortcode updated from %s to %s after points transaction', 'ruiyi-retail-pos'),
                    $user_shortcode,
                    $new_shortcode
                ));
            }
        }
        
        // Calculate tax details using the new tax system
        // Use the processed order_items which have tax rates, not raw cart_items
        $tax_details = ruiyi_calculate_order_tax_details($order_items);

        // Calculate totals from tax details
        $original_total = $order_subtotal; // This is the sum of all items before any discounts
        $original_subtotal_without_tax = 0;
        $total_tax = 0;

        // Sum up tax totals by rate
        foreach ($tax_details['totals_by_rate'] as $rate_key => $rate_data) {
            $original_subtotal_without_tax += $rate_data['base'];
            $total_tax += $rate_data['tax'];
        }

        // If no tax details calculated (fallback)
        if ($original_subtotal_without_tax == 0) {
            $original_subtotal_without_tax = $original_total / 1.21; // Fallback to 21% default
            $total_tax = $original_total - $original_subtotal_without_tax;
        }

        // Final totals after discount
        $final_total = $order->get_total();
        $final_subtotal = $original_subtotal_without_tax - $points_discount;
        
        // Get complete order data for receipt
        $order_data = array(
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'order_key' => $order->get_order_key(),
            'date_created' => $order->get_date_created()->format('Y-m-d H:i:s'),
            'cashier' => $cashier->display_name,
            'items' => $order_items,
            'items_count' => $order->get_item_count(),
            'subtotal' => $original_subtotal_without_tax,
            'points_discount' => $points_discount,
            'points_used' => $points_used,
            'points_rewarded' => $points_reward,
            'tax_total' => $total_tax,
            'tax_details' => $tax_details,
            'tax_breakdown' => $tax_details['totals_by_rate'],
            'total' => $final_total,
            'formatted_subtotal' => wc_price($original_subtotal_without_tax),
            'formatted_points_discount' => wc_price($points_discount),
            'formatted_tax' => wc_price($total_tax),
            'formatted_total' => wc_price($final_total),
            'payment_method' => $payment_method,
            'payment_method_title' => $payment_titles[$payment_method] ?? $payment_method,
            'amount_paid' => $amount_paid,
            'change' => $change,
            'formatted_change' => wc_price($change),
            'customer_note' => $customer_note,
            'customer_name' => $customer_name,
            'customer_phone' => $customer_phone,
            'customer_address' => $customer_address,
            'customer_company' => $customer_company,
            'customer_tax_id' => $customer_tax_id,
            'currency' => get_woocommerce_currency(),
            'currency_symbol' => get_woocommerce_currency_symbol(),
            'store_settings' => ruiyi_pos_get_store_settings(),
        );
        
        wp_send_json_success($order_data);
        
    } catch (Exception $e) {
        wp_send_json_error(array(
            'message' => $e->getMessage(),
            'code' => 'order_creation_failed'
        ));
    }
}

/**
 * Update AJAX handler to support points
 */
function ruiyi_pos_ajax_handler_extended() {
    // Debug log
    ruiyi_pos_log('RUIYI POS: ruiyi_pos_ajax_handler_extended called with action: ' . (isset($_POST['pos_action']) ? $_POST['pos_action'] : 'no action'));

    $action = isset($_POST['pos_action']) ? sanitize_text_field($_POST['pos_action']) : '';

    // 🔥 Special case: refresh_nonce doesn't require nonce verification (for PWA cache recovery)
    if ($action === 'refresh_nonce') {
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => __('Not logged in', 'ruiyi-retail-pos'),
                'code' => 'not_logged_in'
            ));
            wp_die();
        }
        if (!ruiyi_pos_check_access()) {
            wp_send_json_error(array(
                'message' => __('Unauthorized access', 'ruiyi-retail-pos'),
                'code' => 'unauthorized'
            ));
            wp_die();
        }
        wp_send_json_success(array(
            'nonce' => wp_create_nonce('ruiyi_pos_nonce'),
            'message' => 'Nonce refreshed successfully'
        ));
        wp_die();
    }

    // Verify nonce - return JSON error instead of plain text
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'ruiyi_pos_nonce')) {
        ruiyi_pos_log('RUIYI POS: Nonce verification failed');
        wp_send_json_error(array(
            'message' => __('Security check failed - please refresh the page', 'ruiyi-retail-pos'),
            'code' => 'nonce_expired',
            'refresh_required' => true
        ));
        wp_die();
    }

    // Check user permission
    if (!ruiyi_pos_check_access()) {
        wp_send_json_error(array('message' => __('Unauthorized access', 'ruiyi-retail-pos')));
        wp_die();
    }

    // Check if this is the enhanced create order with points
    if ($action === 'create_order' && (isset($_POST['points_user_id']) || isset($_POST['points_discount']))) {
        ruiyi_pos_create_order_with_points();
        return;
    }

    // Otherwise, call the original handler
    ruiyi_pos_ajax_handler();
}

// Remove the original handler and add the extended one
remove_action('wp_ajax_ruiyi_pos_ajax', 'ruiyi_pos_ajax_handler');
add_action('wp_ajax_ruiyi_pos_ajax', 'ruiyi_pos_ajax_handler_extended');

/**
 * Return weight-pricing metadata for a specific product.
 */
function ruiyi_pos_product_weight_meta() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ruiyi_pos_nonce')) {
        wp_send_json_error(array('message' => __('Invalid request', 'ruiyi-retail-pos')), 403);
    }

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => __('Authentication required', 'ruiyi-retail-pos')), 403);
    }

    if (!current_user_can('use_pos') && !current_user_can('manage_pos') && !current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied', 'ruiyi-retail-pos')), 403);
    }

    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    if (!$product_id) {
        wp_send_json_error(array('message' => __('Invalid product ID', 'ruiyi-retail-pos')));
    }

    if (!class_exists('WooCommerce') || !function_exists('wc_get_product')) {
        wp_send_json_error(array('message' => __('WooCommerce not available', 'ruiyi-retail-pos')));
    }

    $product = wc_get_product($product_id);
    if (!$product) {
        wp_send_json_error(array('message' => __('Product not found', 'ruiyi-retail-pos')));
    }

    $unit_name_raw = get_post_meta($product_id, '_unit_name', true);
    $unit_name = $unit_name_raw ? strtolower(trim($unit_name_raw)) : '';
    $is_weight_product = get_post_meta($product_id, '_is_scale_product', true) === 'yes';

    $unit_price_override = get_post_meta($product_id, '_price_per_kg', true);
    if ($unit_price_override !== '' && $unit_price_override !== null) {
        $unit_price_per_kg = floatval($unit_price_override);
    } else {
        $unit_price_per_kg = floatval($product->get_price());
    }

    $scale_config = ruiyi_pos_get_scale_integration_config();

    $response = array(
        'product_id' => $product_id,
        'product_name' => $product->get_name(),
        'unit_name' => $unit_name ?: null,
        'is_weight_product' => (bool) $is_weight_product,
        'unit_price_per_kg' => $unit_price_per_kg,
        'currency_symbol' => get_woocommerce_currency_symbol(),
        'currency_code' => get_woocommerce_currency(),
        'weight_unit' => isset($scale_config['weight_unit']) ? $scale_config['weight_unit'] : 'kg',
        'precision' => isset($scale_config['precision']) ? intval($scale_config['precision']) : 3,
        'min_weight_kg' => isset($scale_config['min_weight_kg']) ? floatval($scale_config['min_weight_kg']) : 0.005,
    );

    /**
     * Allow custom logic to adjust the weight metadata response.
     *
     * @param array $response
     * @param WC_Product $product
     */
    $response = apply_filters('ruiyi_pos_product_weight_meta', $response, $product);

    wp_send_json_success($response);
}
add_action('wp_ajax_ruiyi_pos_product_weight_meta', 'ruiyi_pos_product_weight_meta');
add_action('wp_ajax_nopriv_ruiyi_pos_ajax', 'ruiyi_pos_ajax_handler_extended');

/**
 * Get statistics for selected date range
 */
function ruiyi_pos_get_statistics() {
    $date_range = isset($_POST['date_range']) ? sanitize_text_field($_POST['date_range']) : 'week';

    // 🔥 Performance: transient cache (1 hour TTL, keyed by date_range + current hour)
    $cache_key = 'ruiyi_pos_stats_' . $date_range . '_' . current_time('Y-m-d-H');
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        wp_send_json_success($cached);
        return;
    }

    // Calculate date range using WordPress timezone
    $start_date = '';
    $end_date = current_time('Y-m-d H:i:s');
    
    switch ($date_range) {
        case 'today':
            $start_date = current_time('Y-m-d 00:00:00');
            break;
        case 'yesterday':
            $start_date = date('Y-m-d 00:00:00', strtotime('-1 day', current_time('timestamp')));
            $end_date = date('Y-m-d 23:59:59', strtotime('-1 day', current_time('timestamp')));
            break;
        case 'week':
            $start_date = date('Y-m-d 00:00:00', strtotime('monday this week', current_time('timestamp')));
            break;
        case 'month':
            $start_date = current_time('Y-m-01 00:00:00');
            break;
        case 'year':
            $start_date = current_time('Y-01-01 00:00:00');
            break;
        default:
            $start_date = date('Y-m-d 00:00:00', strtotime('monday this week', current_time('timestamp')));
    }
    
    // Get orders using WooCommerce's recommended method
    if (function_exists('wc_get_orders')) {
        $start_date_only = date('Y-m-d', strtotime($start_date));
        $end_date_only = date('Y-m-d', strtotime($end_date));
        
        $order_ids = wc_get_orders(array(
            'limit' => -1,
            'status' => array('completed', 'processing'),
            'date_created' => $start_date_only . '...' . $end_date_only,
            'return' => 'ids',
        ));
    } else {
        // Fallback to get_posts
        $args = array(
            'post_type' => 'shop_order',
            'post_status' => array('wc-completed', 'wc-processing'),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'date_query' => array(
                array(
                    'after' => $start_date,
                    'before' => $end_date,
                    'inclusive' => true,
                    'column' => 'post_date',
                ),
            ),
        );
        
        $order_ids = get_posts($args);
    }
    
    // Calculate statistics
    $period_revenue = 0;
    $period_count = 0; // Count only valid orders, not refunds
    
    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if ($order) {
            // Skip refund objects
            if ($order->get_type() === 'shop_order_refund') {
                continue;
            }
            $period_revenue += $order->get_total();
            $period_count++; // Count only valid orders
        }
    }
    
    // Calculate average order value
    $avg_order_value = $period_count > 0 ? $period_revenue / $period_count : 0;
    
    // Always get today's stats for comparison
    $today = current_time('Y-m-d');
    if (function_exists('wc_get_orders')) {
        $today_order_ids = wc_get_orders(array(
            'limit' => -1,
            'status' => array('completed', 'processing'),
            'date_created' => $today . '...' . $today . ' 23:59:59',
            'return' => 'ids',
        ));
    } else {
        $today_args = array(
            'post_type' => 'shop_order',
            'post_status' => array('wc-completed', 'wc-processing'),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'date_query' => array(
                array(
                    'after' => current_time('Y-m-d 00:00:00'),
                    'before' => current_time('Y-m-d 23:59:59'),
                    'inclusive' => true,
                    'column' => 'post_date',
                ),
            ),
        );
        
        $today_order_ids = get_posts($today_args);
    }
    
    $today_revenue = 0;
    $today_count = 0; // Count only valid orders, not refunds
    
    foreach ($today_order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if ($order) {
            // Skip refund objects
            if ($order->get_type() === 'shop_order_refund') {
                continue;
            }
            $today_revenue += $order->get_total();
            $today_count++; // Count only valid orders
        }
    }
    
    // Get this week's stats for the second card
    $week_start = date('Y-m-d 00:00:00', strtotime('monday this week', current_time('timestamp')));
    $week_end = date('Y-m-d 23:59:59', strtotime('sunday this week'));
    
    $week_args = array(
        'post_type' => 'shop_order',
        'post_status' => array('wc-completed', 'wc-processing'),
        'posts_per_page' => -1,
        'date_query' => array(
            array(
                'after' => $week_start,
                'before' => $week_end,
                'inclusive' => true,
            ),
        ),
    );
    
    $week_orders = get_posts($week_args);
    $week_revenue = 0;
    $week_count = 0; // Count only valid orders, not refunds
    
    foreach ($week_orders as $order_post) {
        $order = wc_get_order($order_post->ID);
        if ($order) {
            // Skip refund objects
            if ($order->get_type() === 'shop_order_refund') {
                continue;
            }
            $week_revenue += $order->get_total();
            $week_count++; // Count only valid orders
        }
    }
    
    $result = array(
        'period_revenue' => $period_revenue,
        'period_revenue_formatted' => wc_price($period_revenue),
        'period_orders' => $period_count,
        'avg_order_value' => $avg_order_value,
        'avg_order_value_formatted' => wc_price($avg_order_value),
        'today_revenue' => $today_revenue,
        'today_revenue_formatted' => wc_price($today_revenue),
        'today_orders' => $today_count,
        'week_revenue' => $week_revenue,
        'week_revenue_formatted' => wc_price($week_revenue),
        'week_orders' => $week_count,
    );
    set_transient($cache_key, $result, HOUR_IN_SECONDS);
    wp_send_json_success($result);
}

/**
 * Get settlement report data (日结单/月结单/季度单)
 * Supports daily, monthly, and quarterly settlement reports with payment breakdown
 */
function ruiyi_pos_get_settlement_report() {
    $period_type = isset($_POST['period_type']) ? sanitize_text_field($_POST['period_type']) : 'daily';
    $report_date = isset($_POST['report_date']) ? sanitize_text_field($_POST['report_date']) : current_time('Y-m-d');

    $start_date = '';
    $end_date = '';
    $period_label = '';

    // Calculate date range based on period type
    switch ($period_type) {
        case 'daily':
            // 日结单：指定日期的00:00:00到23:59:59
            $start_date = $report_date . ' 00:00:00';
            $end_date = $report_date . ' 23:59:59';
            $period_label = date('Y-m-d', strtotime($report_date));
            break;

        case 'monthly':
            // 月结单：指定月份的第一天到最后一天
            $first_day = date('Y-m-01', strtotime($report_date));
            $last_day = date('Y-m-t', strtotime($report_date));
            $start_date = $first_day . ' 00:00:00';
            $end_date = $last_day . ' 23:59:59';
            $period_label = date('Y-m', strtotime($report_date));
            break;

        case 'quarterly':
            // 季度单：当前月份往前推3个月
            $end_month = date('Y-m-t', strtotime($report_date));
            $start_month = date('Y-m-01', strtotime($report_date . ' -2 months'));
            $start_date = $start_month . ' 00:00:00';
            $end_date = $end_month . ' 23:59:59';
            $period_label = date('Y-m', strtotime($start_month)) . ' ~ ' . date('Y-m', strtotime($end_month));
            break;

        default:
            $start_date = current_time('Y-m-d 00:00:00');
            $end_date = current_time('Y-m-d 23:59:59');
            $period_label = current_time('Y-m-d');
    }

    // Get orders for the period
    $start_date_only = date('Y-m-d', strtotime($start_date));
    $end_date_only = date('Y-m-d', strtotime($end_date));

    $orders = wc_get_orders(array(
        'limit' => -1,
        'status' => array('completed', 'processing'),
        'date_created' => $start_date_only . '...' . $end_date_only,
        'type' => 'shop_order', // Exclude refunds
    ));

    // Initialize statistics
    $total_revenue = 0;
    $total_orders = 0;
    $payment_breakdown = array();
    $tax_breakdown = array();
    $cashier_breakdown = array();
    $hourly_breakdown = array(); // For daily reports only

    // Process each order
    foreach ($orders as $order) {
        // Skip refunds
        if ($order->get_type() === 'shop_order_refund') {
            continue;
        }

        $order_total = $order->get_total();
        $payment_method = $order->get_payment_method_title() ?: $order->get_payment_method();
        $cashier = $order->get_meta('_pos_cashier') ?: 'Unknown';

        // Total statistics
        $total_revenue += $order_total;
        $total_orders++;

        // Payment method breakdown
        if (!isset($payment_breakdown[$payment_method])) {
            $payment_breakdown[$payment_method] = array(
                'count' => 0,
                'amount' => 0
            );
        }
        $payment_breakdown[$payment_method]['count']++;
        $payment_breakdown[$payment_method]['amount'] += $order_total;

        // Cashier breakdown
        if (!isset($cashier_breakdown[$cashier])) {
            $cashier_breakdown[$cashier] = array(
                'count' => 0,
                'amount' => 0
            );
        }
        $cashier_breakdown[$cashier]['count']++;
        $cashier_breakdown[$cashier]['amount'] += $order_total;

        // Tax breakdown (by items)
        foreach ($order->get_items() as $item) {
            $tax_rate = $item->get_meta('_tax_rate') ?: 21;
            $rate_key = number_format($tax_rate, 0) . '%';

            $line_total = $item->get_total();
            $line_subtotal = $line_total / (1 + ($tax_rate / 100));
            $line_tax = $line_total - $line_subtotal;

            if (!isset($tax_breakdown[$rate_key])) {
                $tax_breakdown[$rate_key] = 0;
            }
            $tax_breakdown[$rate_key] += $line_tax;
        }

        // Hourly breakdown (only for daily reports)
        if ($period_type === 'daily') {
            $hour = $order->get_date_created()->format('H');
            if (!isset($hourly_breakdown[$hour])) {
                $hourly_breakdown[$hour] = array(
                    'count' => 0,
                    'amount' => 0
                );
            }
            $hourly_breakdown[$hour]['count']++;
            $hourly_breakdown[$hour]['amount'] += $order_total;
        }
    }

    // Format payment breakdown for display
    $formatted_payment_breakdown = array();
    foreach ($payment_breakdown as $method => $data) {
        $formatted_payment_breakdown[] = array(
            'method' => $method,
            'count' => $data['count'],
            'amount' => $data['amount'],
            'formatted_amount' => wc_price($data['amount'])
        );
    }

    // Format tax breakdown
    $formatted_tax_breakdown = array();
    ksort($tax_breakdown); // Sort by rate
    foreach ($tax_breakdown as $rate => $amount) {
        $formatted_tax_breakdown[$rate] = array(
            'amount' => $amount,
            'formatted_amount' => wc_price($amount)
        );
    }

    // Format cashier breakdown
    $formatted_cashier_breakdown = array();
    foreach ($cashier_breakdown as $cashier => $data) {
        $formatted_cashier_breakdown[] = array(
            'cashier' => $cashier,
            'count' => $data['count'],
            'amount' => $data['amount'],
            'formatted_amount' => wc_price($data['amount'])
        );
    }

    // Response data
    $response = array(
        'period_type' => $period_type,
        'period_label' => $period_label,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'total_revenue' => $total_revenue,
        'total_revenue_formatted' => wc_price($total_revenue),
        'total_orders' => $total_orders,
        'avg_order_value' => $total_orders > 0 ? $total_revenue / $total_orders : 0,
        'avg_order_value_formatted' => wc_price($total_orders > 0 ? $total_revenue / $total_orders : 0),
        'payment_breakdown' => $formatted_payment_breakdown,
        'tax_breakdown' => $formatted_tax_breakdown,
        'cashier_breakdown' => $formatted_cashier_breakdown,
        'print_time' => current_time('Y-m-d H:i:s'),
        'print_user' => wp_get_current_user()->display_name,
        'store_settings' => ruiyi_pos_get_store_settings()
    );

    // Add hourly breakdown for daily reports
    if ($period_type === 'daily') {
        $response['hourly_breakdown'] = $hourly_breakdown;
    }

    wp_send_json_success($response);
}


/**
 * Bulk update product stock levels
 */
function ruiyi_pos_bulk_update_stock() {
    $product_ids = isset($_POST['product_ids']) ? explode(',', $_POST['product_ids']) : array();
    $operation = isset($_POST['operation']) ? sanitize_text_field($_POST['operation']) : 'set';
    $value = isset($_POST['value']) ? intval($_POST['value']) : 0;
    
    if (empty($product_ids)) {
        wp_send_json_error(array('message' => __('No products selected', 'ruiyi-retail-pos')));
    }
    
    $updated_count = 0;
    
    foreach ($product_ids as $product_id) {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            continue;
        }
        
        if ($product->managing_stock()) {
            $current_stock = $product->get_stock_quantity();
            $new_stock = $current_stock;
            
            switch ($operation) {
                case 'set':
                    $new_stock = $value;
                    break;
                case 'increase':
                    $new_stock = $current_stock + $value;
                    break;
                case 'decrease':
                    $new_stock = max(0, $current_stock - $value);
                    break;
            }
            
            $product->set_stock_quantity($new_stock);
            $product->save();
            $updated_count++;
        }
    }
    
    wp_send_json_success(array(
        'message' => sprintf(__('Updated stock for %d products', 'ruiyi-retail-pos'), $updated_count),
        'updated' => $updated_count
    ));
}

/**
 * Bulk update product prices
 */
function ruiyi_pos_bulk_update_prices() {
    $product_ids = isset($_POST['product_ids']) ? explode(',', $_POST['product_ids']) : array();
    $price_type = isset($_POST['price_type']) ? sanitize_text_field($_POST['price_type']) : 'regular';
    $operation = isset($_POST['operation']) ? sanitize_text_field($_POST['operation']) : 'set';
    $value = isset($_POST['value']) ? floatval($_POST['value']) : 0;
    
    if (empty($product_ids)) {
        wp_send_json_error(array('message' => __('No products selected', 'ruiyi-retail-pos')));
    }
    
    $updated_count = 0;
    
    foreach ($product_ids as $product_id) {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            continue;
        }
        
        $update_regular = ($price_type === 'regular' || $price_type === 'both');
        $update_sale = ($price_type === 'sale' || $price_type === 'both');
        
        if ($update_regular) {
            $current_price = $product->get_regular_price();
            $new_price = calculate_new_price($current_price, $operation, $value);
            $product->set_regular_price($new_price);
        }
        
        if ($update_sale) {
            $current_price = $product->get_sale_price();
            // If no sale price exists and we're setting both, use regular price as base
            if (!$current_price && $price_type === 'both') {
                $current_price = $product->get_regular_price();
            }
            if ($current_price || $operation === 'set') {
                $new_price = calculate_new_price($current_price, $operation, $value);
                $product->set_sale_price($new_price);
            }
        }
        
        $product->save();
        $updated_count++;
    }
    
    wp_send_json_success(array(
        'message' => sprintf(__('Updated prices for %d products', 'ruiyi-retail-pos'), $updated_count),
        'updated' => $updated_count
    ));
}

/**
 * Calculate new price based on operation
 */
function calculate_new_price($current_price, $operation, $value) {
    $current_price = floatval($current_price);
    
    switch ($operation) {
        case 'set':
            return $value;
        case 'increase-percent':
            return $current_price * (1 + $value / 100);
        case 'decrease-percent':
            return $current_price * (1 - $value / 100);
        case 'increase-amount':
            return $current_price + $value;
        case 'decrease-amount':
            return max(0, $current_price - $value);
        default:
            return $current_price;
    }
}

/**
 * Export inventory to CSV
 */
function ruiyi_pos_export_inventory_csv() {
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'orderby' => 'title',
        'order' => 'ASC'
    );
    
    $products = get_posts($args);
    
    // CSV headers
    $csv = "SKU,Product Name,Regular Price,Sale Price,Stock Quantity,Manage Stock,Stock Status\n";
    
    foreach ($products as $product_post) {
        $product = wc_get_product($product_post->ID);
        
        if (!$product) {
            continue;
        }
        
        $sku = $product->get_sku();
        $name = $product->get_name();
        $regular_price = $product->get_regular_price();
        $sale_price = $product->get_sale_price();
        $stock_quantity = $product->get_stock_quantity();
        $manage_stock = $product->managing_stock() ? 'yes' : 'no';
        $stock_status = $product->get_stock_status();
        
        // Escape CSV values
        $name = '"' . str_replace('"', '""', $name) . '"';
        
        $csv .= sprintf(
            "%s,%s,%s,%s,%s,%s,%s\n",
            $sku,
            $name,
            $regular_price,
            $sale_price,
            $stock_quantity !== null ? $stock_quantity : '',
            $manage_stock,
            $stock_status
        );
    }
    
    wp_send_json_success(array(
        'csv' => $csv,
        'count' => count($products)
    ));
}

/**
 * Import inventory from CSV
 */
function ruiyi_pos_import_inventory_csv() {
    $csv_data = isset($_POST['csv_data']) ? $_POST['csv_data'] : '';
    
    if (empty($csv_data)) {
        wp_send_json_error(array('message' => __('No CSV data provided', 'ruiyi-retail-pos')));
    }
    
    // Parse CSV
    $lines = explode("\n", $csv_data);
    $headers = str_getcsv(array_shift($lines));
    
    // Map headers to expected fields
    $header_map = array(
        'SKU' => 'sku',
        'Product Name' => 'name',
        'Regular Price' => 'regular_price',
        'Sale Price' => 'sale_price',
        'Stock Quantity' => 'stock_quantity',
        'Manage Stock' => 'manage_stock',
        'Stock Status' => 'stock_status'
    );
    
    $imported = 0;
    $errors = array();
    
    foreach ($lines as $line_num => $line) {
        if (empty(trim($line))) {
            continue;
        }
        
        $data = str_getcsv($line);
        
        if (count($data) !== count($headers)) {
            $errors[] = sprintf(__('Line %d: Invalid number of columns', 'ruiyi-retail-pos'), $line_num + 2);
            continue;
        }
        
        // Map data to fields
        $product_data = array();
        foreach ($headers as $index => $header) {
            if (isset($header_map[$header])) {
                $product_data[$header_map[$header]] = $data[$index];
            }
        }
        
        // Find product by SKU
        if (!empty($product_data['sku'])) {
            $product_id = wc_get_product_id_by_sku($product_data['sku']);
            
            if ($product_id) {
                $product = wc_get_product($product_id);
                
                // Update product data
                if (isset($product_data['regular_price']) && $product_data['regular_price'] !== '') {
                    $product->set_regular_price($product_data['regular_price']);
                }
                
                if (isset($product_data['sale_price']) && $product_data['sale_price'] !== '') {
                    $product->set_sale_price($product_data['sale_price']);
                }
                
                if (isset($product_data['manage_stock']) && $product_data['manage_stock'] === 'yes') {
                    $product->set_manage_stock(true);
                    
                    if (isset($product_data['stock_quantity']) && $product_data['stock_quantity'] !== '') {
                        $product->set_stock_quantity($product_data['stock_quantity']);
                    }
                }
                
                if (isset($product_data['stock_status']) && in_array($product_data['stock_status'], array('instock', 'outofstock'))) {
                    $product->set_stock_status($product_data['stock_status']);
                }
                
                $product->save();
                $imported++;
            } else {
                $errors[] = sprintf(__('Line %d: Product with SKU "%s" not found', 'ruiyi-retail-pos'), $line_num + 2, $product_data['sku']);
            }
        } else {
            $errors[] = sprintf(__('Line %d: SKU is required', 'ruiyi-retail-pos'), $line_num + 2);
        }
    }
    
    $response = array(
        'imported' => $imported,
        'message' => sprintf(__('Successfully imported %d products', 'ruiyi-retail-pos'), $imported)
    );
    
    if (!empty($errors)) {
        $response['errors'] = $errors;
    }
    
    wp_send_json_success($response);
}

/**
 * Export products CSV (for Edit Products tab)
 * Columns: SKU, Name, Price, Sale Price, Supplier, Stock, Product Number
 */
function ruiyi_pos_export_products_csv() {
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'post_status' => array('publish', 'draft', 'private'),
        'orderby' => 'ID',
        'order' => 'DESC'
    );

    $query = new WP_Query($args);
    $count = 0;

    // CSV headers (Spanish)
    $csv = "SKU,Nombre,Precio,Proveedor,Stock,Número de Producto\n";

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $product = wc_get_product(get_the_ID());

            if (!$product) {
                continue;
            }

            $sku = $product->get_sku();
            $name = str_replace('"', '""', $product->get_name());
            $regular_price = $product->get_regular_price();
            $stock_quantity = $product->get_stock_quantity();

            $supplier = get_post_meta($product->get_id(), '_product_supplier', true);
            if (!$supplier) {
                $supplier = get_post_meta($product->get_id(), '_supplier', true);
            }
            $supplier = str_replace('"', '""', $supplier ?: '');

            $product_number = get_post_meta($product->get_id(), '_product_number', true) ?: '';

            $csv .= sprintf(
                "%s,\"%s\",%s,\"%s\",%s,%s\n",
                $sku,
                $name,
                $regular_price,
                $supplier,
                $stock_quantity !== null ? $stock_quantity : '',
                $product_number
            );
            $count++;
        }
    }
    wp_reset_postdata();

    wp_send_json_success(array(
        'csv' => $csv,
        'count' => $count
    ));
}

/**
 * Import products CSV (for Edit Products tab)
 * Columns: SKU, Name, Price, Sale Price, Supplier, Stock, Product Number
 * If SKU exists → update; otherwise → create new product
 */
function ruiyi_pos_import_products_csv() {
    $csv_data = isset($_POST['csv_data']) ? wp_unslash($_POST['csv_data']) : '';

    // Strip BOM if present
    $csv_data = preg_replace('/^\xEF\xBB\xBF/', '', $csv_data);

    if (empty($csv_data)) {
        wp_send_json_error(array('message' => 'No CSV data provided'));
    }

    $lines = explode("\n", $csv_data);
    $header_line = array_shift($lines);
    $headers = str_getcsv($header_line);

    // Trim headers
    $headers = array_map('trim', $headers);

    // Map headers to internal field names
    $header_map = array(
        'SKU' => 'sku',
        'Name' => 'name',
        'Price' => 'regular_price',
        'Supplier' => 'supplier',
        'Stock' => 'stock_quantity',
        'Product Number' => 'product_number',
        // Chinese header aliases
        '名称' => 'name',
        '价格' => 'regular_price',
        '供应商' => 'supplier',
        '库存' => 'stock_quantity',
        '产品编号' => 'product_number',
        // Spanish header aliases
        'Nombre' => 'name',
        'Precio' => 'regular_price',
        'Proveedor' => 'supplier',
        'Número de Producto' => 'product_number',
    );

    $created = 0;
    $updated = 0;
    $errors = array();

    foreach ($lines as $line_num => $line) {
        if (empty(trim($line))) {
            continue;
        }

        $data = str_getcsv($line);

        if (count($data) !== count($headers)) {
            $errors[] = sprintf('Line %d: Invalid number of columns (expected %d, got %d)', $line_num + 2, count($headers), count($data));
            continue;
        }

        // Map data to fields
        $product_data = array();
        foreach ($headers as $index => $header) {
            $trimmed = trim($header);
            if (isset($header_map[$trimmed])) {
                $product_data[$header_map[$trimmed]] = trim($data[$index]);
            }
        }

        try {
            $product_id = 0;

            // Try to find existing product by SKU
            if (!empty($product_data['sku'])) {
                $product_id = wc_get_product_id_by_sku($product_data['sku']);
            }

            if ($product_id) {
                // Update existing product
                $product = wc_get_product($product_id);
                if (!$product) {
                    $errors[] = sprintf('Line %d: Could not load product with SKU "%s"', $line_num + 2, $product_data['sku']);
                    continue;
                }

                if (isset($product_data['name']) && $product_data['name'] !== '') {
                    $product->set_name($product_data['name']);
                }
                if (isset($product_data['regular_price']) && $product_data['regular_price'] !== '') {
                    $product->set_regular_price($product_data['regular_price']);
                }
                if (isset($product_data['stock_quantity']) && $product_data['stock_quantity'] !== '') {
                    $product->set_manage_stock(true);
                    $current_stock = $product->get_stock_quantity();
                    $current_stock = $current_stock !== null ? intval($current_stock) : 0;
                    $product->set_stock_quantity($current_stock + intval($product_data['stock_quantity']));
                }
                if (isset($product_data['supplier']) && $product_data['supplier'] !== '') {
                    update_post_meta($product_id, '_product_supplier', sanitize_text_field($product_data['supplier']));
                }
                if (isset($product_data['product_number']) && $product_data['product_number'] !== '') {
                    update_post_meta($product_id, '_product_number', sanitize_text_field($product_data['product_number']));
                }

                $product->save();
                $updated++;
            } else {
                // Create new product
                if (empty($product_data['name'])) {
                    $errors[] = sprintf('Line %d: Product name is required for new products', $line_num + 2);
                    continue;
                }

                $product = new WC_Product_Simple();
                $product->set_name($product_data['name']);

                if (!empty($product_data['sku'])) {
                    $product->set_sku($product_data['sku']);
                }
                if (!empty($product_data['regular_price'])) {
                    $product->set_regular_price($product_data['regular_price']);
                }
                if (isset($product_data['stock_quantity']) && $product_data['stock_quantity'] !== '') {
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity(intval($product_data['stock_quantity']));
                }

                $product->set_status('publish');
                $new_id = $product->save();

                if (!empty($product_data['supplier'])) {
                    update_post_meta($new_id, '_product_supplier', sanitize_text_field($product_data['supplier']));
                }
                if (!empty($product_data['product_number'])) {
                    update_post_meta($new_id, '_product_number', sanitize_text_field($product_data['product_number']));
                }

                $created++;
            }
        } catch (Exception $e) {
            $errors[] = sprintf('Line %d: %s', $line_num + 2, $e->getMessage());
        }
    }

    wp_send_json_success(array(
        'created' => $created,
        'updated' => $updated,
        'errors' => $errors
    ));
}

/**
 * 导入产品 - 带列映射 (Import products with column mapping)
 * Receives CSV with internal field name headers (name, sku, regular_price, category, stock_quantity, __skip__)
 */
function ruiyi_pos_import_products_mapped() {
    $csv_data = isset($_POST['csv_data']) ? wp_unslash($_POST['csv_data']) : '';

    // Strip BOM if present
    $csv_data = preg_replace('/^\xEF\xBB\xBF/', '', $csv_data);

    if (empty($csv_data)) {
        wp_send_json_error(array('message' => 'No CSV data provided'));
    }

    $lines = explode("\n", $csv_data);
    $header_line = array_shift($lines);
    $headers = str_getcsv($header_line);
    $headers = array_map('trim', $headers);

    $created = 0;
    $skipped = 0;
    $errors = array();
    $imported_product_ids = array(); // 收集导入的产品ID，用于排除"已保存"标记

    foreach ($lines as $line_num => $line) {
        if (empty(trim($line))) {
            continue;
        }

        $data = str_getcsv($line);

        // Map data to fields using internal headers
        $product_data = array();
        foreach ($headers as $index => $field) {
            if ($field === '__skip__' || !isset($data[$index])) {
                continue;
            }
            $product_data[$field] = trim($data[$index]);
        }

        // Skip rows with no useful data
        if (empty($product_data)) {
            continue;
        }

        try {
            $product_id = 0;

            // Try to find existing product by SKU
            if (!empty($product_data['sku'])) {
                $product_id = wc_get_product_id_by_sku($product_data['sku']);
            }

            if ($product_id) {
                // Duplicate detected — skip to protect existing data
                $skipped++;
            } else {
                // Create new product
                if (empty($product_data['name'])) {
                    $errors[] = sprintf('Line %d: Product name is required for new products', $line_num + 2);
                    continue;
                }

                $product = new WC_Product_Simple();
                $product->set_name($product_data['name']);

                if (!empty($product_data['sku'])) {
                    $product->set_sku($product_data['sku']);
                }
                if (!empty($product_data['regular_price'])) {
                    $product->set_regular_price($product_data['regular_price']);
                }
                if (isset($product_data['stock_quantity']) && $product_data['stock_quantity'] !== '') {
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity(intval($product_data['stock_quantity']));
                }

                $product->set_status('publish');
                $new_id = $product->save();

                if (!empty($product_data['cost_price']) && is_numeric($product_data['cost_price'])) {
                    update_post_meta($new_id, '_product_cost_price', floatval($product_data['cost_price']));
                }
                if (!empty($product_data['supplier'])) {
                    update_post_meta($new_id, '_product_supplier', sanitize_text_field($product_data['supplier']));
                }

                if (!empty($product_data['category'])) {
                    $cat_name = sanitize_text_field($product_data['category']);
                    $term = get_term_by('name', $cat_name, 'product_cat');
                    if (!$term) {
                        $result = wp_insert_term($cat_name, 'product_cat');
                        if (!is_wp_error($result)) {
                            $term = get_term($result['term_id'], 'product_cat');
                        }
                    }
                    if ($term && !is_wp_error($term)) {
                        $product->set_category_ids(array($term->term_id));
                        $product->save();
                    }
                }

                $imported_product_ids[] = $new_id;
                $created++;
            }
        } catch (Exception $e) {
            $errors[] = sprintf('Line %d: %s', $line_num + 2, $e->getMessage());
        }
    }

    // 从"已保存"列表中移除导入的产品ID
    // mu-plugin (ruiyi-edited-products-tracker) 会在 $product->save() 时自动添加，
    // 但导入的产品不应标记为"已保存"，只有手动创建/编辑的才需要
    if (!empty($imported_product_ids)) {
        $edited_products = get_option('ruiyi_pos_edited_products', array());
        if (is_array($edited_products) && !empty($edited_products)) {
            $edited_products = array_values(array_diff($edited_products, $imported_product_ids));
            update_option('ruiyi_pos_edited_products', $edited_products);
            ruiyi_pos_log('[IMPORT] Removed ' . count($imported_product_ids) . ' imported product IDs from edited_products list');
        }
    }

    wp_send_json_success(array(
        'created' => $created,
        'skipped' => $skipped,
        'errors' => $errors
    ));
}

// 为管理员用户移除管理栏中的特定项目（排除特定用户）
function remove_admin_bar_items_for_administrators() {
    // 获取当前用户
    $current_user = wp_get_current_user();
    
    // 检查是否为管理员且不是被排除的用户
    if (current_user_can('administrator') && $current_user->user_email !== 'jchenhan54@gmail.com') {
        global $wp_admin_bar;
        
        // 移除站点名称/查看站点链接
        $wp_admin_bar->remove_node('site-name');
        
        // 移除"我的站点"菜单（多站点环境）
        $wp_admin_bar->remove_node('my-sites');
        
        // 移除"网络管理员"相关项目
        $wp_admin_bar->remove_node('network-admin');
        
        // 移除WordPress logo
        $wp_admin_bar->remove_node('wp-logo');
        
        // 移除评论
        $wp_admin_bar->remove_node('comments');
        
        // 移除新建内容
        $wp_admin_bar->remove_node('new-content');
    }
}
add_action('wp_before_admin_bar_render', 'remove_admin_bar_items_for_administrators');

// 为管理员用户移除"已上线"按钮（排除特定用户）
function remove_online_status_for_administrators() {
    // 获取当前用户
    $current_user = wp_get_current_user();
    
    // 检查是否为管理员且不是被排除的用户
    if (current_user_can('administrator') && $current_user->user_email !== 'jchenhan54@gmail.com') {
        global $wp_admin_bar;
        
        // 移除上线状态相关节点
        $wp_admin_bar->remove_node('wp-admin-bar-updates');
        $wp_admin_bar->remove_node('updates');
        
        // 如果是自定义的上线状态插件，可能需要移除这些
        $wp_admin_bar->remove_node('online-status');
        $wp_admin_bar->remove_node('user-online');
        $wp_admin_bar->remove_node('site-status');
    }
}

/**
 * Clean WooCommerce price format for display
 * Removes HTML tags and decodes HTML entities
 */
function ruiyi_pos_clean_price($price_html) {
    return html_entity_decode(strip_tags($price_html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Get refundable orders for refund page
 */
function ruiyi_pos_get_refundable_orders() {
    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    $date_range = isset($_POST['date_range']) ? sanitize_text_field($_POST['date_range']) : 'all';
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'completed';

    global $wpdb;

    // 🔥 如果有搜索词，只返回包含该产品的订单
    if (!empty($search)) {
        $product_id = null;

        // 🔥 1. 精确匹配SKU
        $product_id = wc_get_product_id_by_sku($search);

        // 🔥 2. 如果SKU没找到，精确匹配条码
        if (!$product_id) {
            $product_id = $wpdb->get_var($wpdb->prepare("
                SELECT post_id FROM {$wpdb->postmeta}
                WHERE meta_key = '_barcode' AND meta_value = %s
                LIMIT 1
            ", $search));
        }

        // 🔥 没有找到匹配的产品，直接返回空结果
        if (!$product_id) {
            wp_send_json_success(array('orders' => array()));
            return;
        }

        $product_id = intval($product_id);

        // 🔥 直接查找包含该产品的订单，带状态和日期过滤
        $status_sql = '';
        if ($status !== 'all') {
            $status_sql = $wpdb->prepare(" AND p.post_status = %s", 'wc-' . $status);
        } else {
            $status_sql = " AND p.post_status IN ('wc-completed', 'wc-processing')";
        }

        $date_sql = '';
        if ($date_range !== 'all') {
            $start_date = '';
            $end_date = current_time('Y-m-d H:i:s');

            switch ($date_range) {
                case 'today':
                    $start_date = current_time('Y-m-d 00:00:00');
                    break;
                case 'yesterday':
                    $start_date = date('Y-m-d 00:00:00', strtotime('-1 day', current_time('timestamp')));
                    $end_date = date('Y-m-d 23:59:59', strtotime('-1 day', current_time('timestamp')));
                    break;
                case 'week':
                    $start_date = date('Y-m-d 00:00:00', strtotime('monday this week', current_time('timestamp')));
                    break;
                case 'month':
                    $start_date = current_time('Y-m-01 00:00:00');
                    break;
            }

            if ($start_date) {
                $date_sql = $wpdb->prepare(" AND p.post_date BETWEEN %s AND %s", $start_date, $end_date);
            }
        }

        // 🔥 查询包含该产品的订单ID（直接从数据库）
        $order_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT oi.order_id
            FROM {$wpdb->prefix}woocommerce_order_items AS oi
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim
                ON oi.order_item_id = oim.order_item_id
            INNER JOIN {$wpdb->posts} AS p
                ON oi.order_id = p.ID
            WHERE oi.order_item_type = 'line_item'
            AND p.post_type = 'shop_order'
            {$status_sql}
            {$date_sql}
            AND (
                (oim.meta_key = '_product_id' AND oim.meta_value = %d)
                OR (oim.meta_key = '_variation_id' AND oim.meta_value = %d)
            )
            ORDER BY p.post_date DESC
            LIMIT 50
        ", $product_id, $product_id));

        if (empty($order_ids)) {
            wp_send_json_success(array('orders' => array()));
            return;
        }

        // 🔥 获取订单详情
        $formatted_orders = array();
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order || $order->get_type() === 'shop_order_refund') {
                continue;
            }

            $formatted_orders[] = array(
                'id' => $order->get_id(),
                'number' => $order->get_order_number(),
                'date' => $order->get_date_created()->format('Y-m-d H:i'),
                'customer_email' => $order->get_billing_email(),
                'total' => ruiyi_pos_clean_price(wc_price($order->get_total())),
                'total_raw' => $order->get_total(),
                'status' => $order->get_status(),
            );
        }

        wp_send_json_success(array('orders' => $formatted_orders));
        return;
    }

    // 🔥 没有搜索词时，显示最近订单
    $args = array(
        'limit' => 50,
        'orderby' => 'date',
        'order' => 'DESC',
        'type' => 'shop_order',
    );

    // Status filter
    if ($status !== 'all') {
        $args['status'] = array($status);
    } else {
        $args['status'] = array('completed', 'processing');
    }

    // Date range filter
    if ($date_range !== 'all') {
        $start_date = '';
        $end_date = current_time('Y-m-d H:i:s');

        switch ($date_range) {
            case 'today':
                $start_date = current_time('Y-m-d 00:00:00');
                break;
            case 'yesterday':
                $start_date = date('Y-m-d 00:00:00', strtotime('-1 day', current_time('timestamp')));
                $end_date = date('Y-m-d 23:59:59', strtotime('-1 day', current_time('timestamp')));
                break;
            case 'week':
                $start_date = date('Y-m-d 00:00:00', strtotime('monday this week', current_time('timestamp')));
                break;
            case 'month':
                $start_date = current_time('Y-m-01 00:00:00');
                break;
        }

        if ($start_date) {
            $args['date_created'] = $start_date . '...' . $end_date;
        }
    }

    $orders = wc_get_orders($args);

    $formatted_orders = array();
    foreach ($orders as $order) {
        if ($order->get_type() === 'shop_order_refund') {
            continue;
        }

        $formatted_orders[] = array(
            'id' => $order->get_id(),
            'number' => $order->get_order_number(),
            'date' => $order->get_date_created()->format('Y-m-d H:i'),
            'customer_email' => $order->get_billing_email(),
            'total' => ruiyi_pos_clean_price(wc_price($order->get_total())),
            'total_raw' => $order->get_total(),
            'status' => $order->get_status(),
        );
    }

    wp_send_json_success(array('orders' => $formatted_orders));
}

/**
 * Get order details for refund modal
 */
function ruiyi_pos_get_order_details() {
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    
    if (!$order_id) {
        wp_send_json_error(array('message' => __('Invalid order ID', 'ruiyi-retail-pos')));
    }
    
    $order = wc_get_order($order_id);
    
    if (!$order) {
        wp_send_json_error(array('message' => __('Order not found', 'ruiyi-retail-pos')));
    }
    
    // Get order items
    $items = array();
    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();
        $unit_price = $item->get_subtotal() / $item->get_quantity();
        $subtotal = $item->get_subtotal();

        // 🔥 获取真正的产品SKU
        $sku = '';
        if ($product) {
            $sku = $product->get_sku();
        }

        $items[] = array(
            'id' => $item_id,
            'product_id' => $item->get_product_id(),
            'sku' => $sku,  // 🔥 添加真正的SKU
            'name' => $item->get_name(),
            'quantity' => $item->get_quantity(),
            'price' => ruiyi_pos_clean_price(wc_price($unit_price)),
            'price_raw' => $unit_price,
            'subtotal' => ruiyi_pos_clean_price(wc_price($subtotal)),
            'subtotal_raw' => $subtotal,
        );
    }
    
    $order_data = array(
        'id' => $order->get_id(),
        'number' => $order->get_order_number(),
        'date' => $order->get_date_created()->format('Y-m-d H:i'),
        'customer_email' => $order->get_billing_email(),
        'total' => ruiyi_pos_clean_price(wc_price($order->get_total())),
        'total_raw' => $order->get_total(),
        'status' => $order->get_status(),
        'items' => $items,
    );
    
    wp_send_json_success(array(
        'order' => $order_data
    ));
}

/**
 * 🔥 Get full order details for viewing in modal
 * Includes payment details and customer info
 */
function ruiyi_pos_get_full_order_details() {
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

    if (!$order_id) {
        wp_send_json_error(array('message' => __('Invalid order ID', 'ruiyi-retail-pos')));
    }

    $order = wc_get_order($order_id);

    if (!$order) {
        wp_send_json_error(array('message' => __('Order not found', 'ruiyi-retail-pos')));
    }

    // Get order items
    $items = array();
    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();
        $quantity = $item->get_quantity();

        // 使用含税价格显示
        $is_custom_price = wc_get_order_item_meta($item_id, '_price_modified_by_cashier', true) === 'yes';
        $custom_price_value = floatval(wc_get_order_item_meta($item_id, '_custom_price', true));

        if ($is_custom_price && $custom_price_value > 0) {
            // Varios/自定义价格：收银员输入的就是含税价格，直接使用
            $unit_price = $custom_price_value;
            $subtotal_incl_tax = $unit_price * abs($quantity);
            if ($quantity < 0) {
                $subtotal_incl_tax = -$subtotal_incl_tax;
            }
        } else {
            // 普通商品：get_subtotal返回不含税价格，需要加上税率
            $tax_rate = floatval(wc_get_order_item_meta($item_id, '_tax_rate', true) ?: 21);
            $subtotal_excl_tax = $item->get_subtotal();
            $subtotal_incl_tax = $subtotal_excl_tax * (1 + $tax_rate / 100);
            $unit_price = ($quantity != 0) ? ($subtotal_incl_tax / $quantity) : 0;
        }

        $sku = '';
        if ($product) {
            $sku = $product->get_sku();
        }

        $items[] = array(
            'id' => $item_id,
            'product_id' => $item->get_product_id(),
            'sku' => $sku,
            'name' => $item->get_name(),
            'quantity' => $quantity,
            'price' => ruiyi_pos_clean_price(wc_price($unit_price)),
            'price_raw' => $unit_price,
            'subtotal' => ruiyi_pos_clean_price(wc_price($subtotal_incl_tax)),
            'subtotal_raw' => $subtotal_incl_tax,
        );
    }

    // Get payment type and details
    $payment_type = $order->get_meta('_ruiyi_pos_payment_type');
    $payment_details = array();

    if ($payment_type === 'cash') {
        $payment_details = array(
            'cash_received' => $order->get_meta('_ruiyi_pos_cash_received'),
            'change' => $order->get_meta('_ruiyi_pos_change'),
        );
    } elseif ($payment_type === 'cash_card') {
        $payment_details = array(
            'cash_amount' => $order->get_meta('_ruiyi_pos_cash_amount'),
            'card_amount' => $order->get_meta('_ruiyi_pos_card_amount'),
        );
    }

    // Get invoice label if exists (优先使用完整标签 FAC-2026-000001)
    $invoice_label = $order->get_meta('_verifactu_invoice_label');
    if (!$invoice_label) {
        $invoice_label = $order->get_meta('_verifactu_invoice_number');
    }

    // Get customer info
    $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
    $customer_name = trim($customer_name);
    if (empty($customer_name)) {
        $customer_name = $order->get_meta('_ruiyi_pos_customer_name');
    }

    $order_data = array(
        'id' => $order->get_id(),
        'number' => $order->get_order_number(),
        'invoice_label' => $invoice_label,
        'date' => $order->get_date_created()->format('Y-m-d H:i'),
        'customer_name' => $customer_name,
        'customer_email' => $order->get_billing_email(),
        'total' => ruiyi_pos_clean_price(wc_price($order->get_total())),
        'total_raw' => $order->get_total(),
        'status' => $order->get_status(),
        'payment_type' => $payment_type,
        'payment_details' => $payment_details,
        'items' => $items,
    );

    wp_send_json_success(array(
        'order' => $order_data
    ));
}

/**
 * 🔥 Get order data formatted for receipt printing
 * Returns data in the same format expected by generateReceiptHTML
 */
/**
 * 保存客户信息到已有订单 (历史订单创建发票用)
 */
function ruiyi_pos_save_order_customer_info() {
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    if (!$order_id) {
        wp_send_json_error(array('message' => 'Invalid order ID'));
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => 'Order not found'));
        return;
    }

    $fields = array('customer_name', 'customer_phone', 'customer_address', 'customer_company', 'customer_tax_id');
    foreach ($fields as $field) {
        if (isset($_POST[$field])) {
            $order->update_meta_data('_' . $field, sanitize_text_field($_POST[$field]));
        }
    }
    $order->save();

    wp_send_json_success(array('message' => 'Customer info saved', 'order_id' => $order_id));
}

function ruiyi_pos_get_order_receipt_data() {
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

    if (!$order_id) {
        wp_send_json_error(array('message' => __('Invalid order ID', 'ruiyi-retail-pos')));
    }

    $order = wc_get_order($order_id);

    if (!$order) {
        wp_send_json_error(array('message' => __('Order not found', 'ruiyi-retail-pos')));
    }

    // Build items in the same format as create_order_enhanced (items[] with line_total, tax_rate)
    $items = array();
    $order_items_with_tax = array();
    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();

        // Get tax rate
        $tax_rate = $item->get_meta('_tax_rate');
        if (!$tax_rate && $product && function_exists('ruiyi_get_product_tax_rate')) {
            $tax_rate = ruiyi_get_product_tax_rate($product);
        }
        if (!$tax_rate) {
            $tax_rate = 21;
        }

        $line_total = $item->get_total();
        $quantity = $item->get_quantity();
        $unit_price = $quantity > 0 ? $line_total / $quantity : 0;
        $line_subtotal = $line_total / (1 + ($tax_rate / 100));
        $line_tax = $line_total - $line_subtotal;

        $items[] = array(
            'name' => $item->get_name(),
            'quantity' => $quantity,
            'price' => $unit_price,
            'formatted_price' => wc_price($unit_price),
            'subtotal' => $line_total,
            'formatted_subtotal' => wc_price($line_total),
            'line_total' => $line_total,
            'tax_rate' => $tax_rate,
            'sku' => $product ? $product->get_sku() : '',
        );

        $order_items_with_tax[] = array(
            'name' => $item->get_name(),
            'quantity' => $quantity,
            'price' => $unit_price,
            'subtotal' => $line_total,
            'tax_rate' => $tax_rate,
            'line_subtotal' => $line_subtotal,
            'line_tax' => $line_tax,
        );
    }

    // Get invoice number (优先使用完整标签 FAC-2026-000001)
    $invoice_number = $order->get_meta('_verifactu_invoice_label');
    if (!$invoice_number) {
        $invoice_number = $order->get_meta('_verifactu_invoice_number');
    }
    if (!$invoice_number) {
        $invoice_number = $order->get_order_number();
    }

    // Get cashier name (same field names as create_order_enhanced)
    $cashier = $order->get_meta('_cashier_name');
    if (!$cashier) {
        $cashier = $order->get_meta('_ruiyi_pos_salesperson_name');
    }
    if (!$cashier) {
        $user_id = $order->get_customer_id();
        if ($user_id) {
            $user = get_user_by('id', $user_id);
            if ($user) {
                $cashier = $user->display_name;
            }
        }
    }
    if (!$cashier) {
        $cashier = '-';
    }

    // Get customer info
    $customer_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    if (empty($customer_name)) {
        $customer_name = $order->get_meta('_ruiyi_pos_customer_name');
    }

    // Payment info
    $payment_type = $order->get_meta('_ruiyi_pos_payment_type');

    // Build order data matching the format generateReceiptHTML expects
    $order_data = array(
        'order_id' => $order->get_id(),
        'order_number' => $order->get_order_number(),
        'invoice_number' => $invoice_number,
        'date' => $order->get_date_created()->format('Y-m-d'),
        'time' => $order->get_date_created()->format('H:i:s'),
        'cashier' => $cashier,
        'items' => $items,
        'total' => floatval($order->get_total()),
        'formatted_total' => wc_price($order->get_total()),
        'formatted_subtotal' => wc_price($order->get_subtotal()),
        'formatted_tax' => wc_price($order->get_total_tax()),
        'payment_method' => $payment_type ?: 'cash',
        'customer_name' => $order->get_meta('_customer_name') ?: $customer_name,
        'customer_phone' => $order->get_meta('_customer_phone') ?: $order->get_meta('_ruiyi_pos_customer_phone') ?: $order->get_billing_phone(),
        'customer_address' => $order->get_meta('_customer_address') ?: $order->get_meta('_ruiyi_pos_customer_address'),
        'customer_company' => $order->get_meta('_customer_company') ?: $order->get_meta('_ruiyi_pos_customer_company') ?: $order->get_billing_company(),
        'customer_tax_id' => $order->get_meta('_customer_tax_id') ?: $order->get_meta('_ruiyi_pos_customer_tax_id'),
        'points_used' => $order->get_meta('_points_used') ?: 0,
        'formatted_points_discount' => wc_price($order->get_meta('_points_discount') ?: 0),
        'is_historical' => true,
    );

    // Calculate tax breakdown
    if (function_exists('ruiyi_calculate_order_tax_details')) {
        $tax_details = ruiyi_calculate_order_tax_details($order_items_with_tax);
        $order_data['tax_breakdown'] = $tax_details['totals_by_rate'];

        $subtotal_without_tax = 0;
        $total_tax = 0;
        foreach ($tax_details['totals_by_rate'] as $rate_data) {
            $subtotal_without_tax += $rate_data['base'];
            $total_tax += $rate_data['tax'];
        }
        $order_data['formatted_subtotal'] = wc_price($subtotal_without_tax);
        $order_data['formatted_tax'] = wc_price($total_tax);
    }

    wp_send_json_success(array(
        'order' => $order_data
    ));
}

/**
 * Process refund and update inventory
 */
function ruiyi_pos_process_refund() {
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $item_ids = isset($_POST['item_ids']) ? explode(',', sanitize_text_field($_POST['item_ids'])) : array();
    $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';
    
    if (!$order_id || empty($item_ids)) {
        wp_send_json_error(array('message' => __('Invalid refund data', 'ruiyi-retail-pos')));
    }
    
    $order = wc_get_order($order_id);
    
    if (!$order) {
        wp_send_json_error(array('message' => __('Order not found', 'ruiyi-retail-pos')));
    }
    
    // Calculate refund amount and restore inventory
    $refund_amount = 0;
    $line_items = array();
    $refunded_items = array(); // For receipt printing

    foreach ($item_ids as $item_id) {
        $item_id = intval($item_id); // Ensure item ID is integer
        $item = $order->get_item($item_id);

        if (!$item) {
            wp_send_json_error(array('message' => sprintf(__('Item with ID %s not found in order', 'ruiyi-retail-pos'), $item_id)));
            return;
        }

        $product = $item->get_product();
        $quantity = $item->get_quantity();
        $subtotal = $item->get_subtotal();
        $unit_price = $quantity > 0 ? ($subtotal / $quantity) : 0;

        // Add to refund amount
        $refund_amount += $subtotal;

        // Prepare line items for refund
        $line_items[$item_id] = array(
            'qty' => $quantity,
            'refund_total' => $subtotal,
            'refund_tax' => array(),
        );

        // Store item details for receipt
        $refunded_items[] = array(
            'name' => $item->get_name(),
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'subtotal' => $subtotal,
            'sku' => $product ? $product->get_sku() : '',
        );
    }

    // Create the refund - let WooCommerce handle stock restoration
    $refund = wc_create_refund(array(
        'amount' => $refund_amount,
        'reason' => $reason ?: __('POS Refund', 'ruiyi-retail-pos'),
        'order_id' => $order_id,
        'line_items' => $line_items,
        'refund_payment' => false,
        'restock_items' => true, // WooCommerce handles stock restoration
    ));
    
    if (is_wp_error($refund)) {
        wp_send_json_error(array('message' => $refund->get_error_message()));
    }
    
    // Update order status to refunded if all items are refunded
    $total_refunded = $order->get_total_refunded();
    if ($total_refunded >= $order->get_total()) {
        // Use 'refunded' status instead of 'cancelled' to prevent duplicate stock restoration
        $order->update_status('refunded', __('Order fully refunded via POS', 'ruiyi-retail-pos'));
    } else {
        $order->add_order_note(
            sprintf(
                __('Partial refund of %s processed via POS. Reason: %s', 'ruiyi-retail-pos'),
                wc_price($refund_amount),
                $reason ?: __('No reason provided', 'ruiyi-retail-pos')
            )
        );
    }
    
    // Get store settings for receipt
    $store_settings = ruiyi_pos_get_store_settings();

    // Get current user info
    $current_user = wp_get_current_user();
    $cashier_name = $current_user->display_name ?: $current_user->user_login;

    wp_send_json_success(array(
        'message' => __('Refund processed successfully', 'ruiyi-retail-pos'),
        'refund_id' => $refund->get_id(),
        'refund_amount' => $refund_amount,
        'refund_amount_formatted' => wc_price($refund_amount),
        'receipt_data' => array(
            'order_number' => $order->get_order_number(),
            'refund_reason' => $reason ?: __('Customer Return', 'ruiyi-retail-pos'),
            'items' => $refunded_items,
            'total' => $refund_amount,
            'currency' => get_woocommerce_currency_symbol(),
            'cashier' => $cashier_name,
            'store_name' => $store_settings['store_name'],
            'store_address' => $store_settings['store_address'],
            'store_phone' => $store_settings['store_phone'],
            'store_tax_number' => $store_settings['store_tax_number'],
        ),
    ));
}
add_action('wp_before_admin_bar_render', 'remove_online_status_for_administrators');

/**
 * Hide admin bar for shop managers completely
 */
function ruiyi_pos_hide_admin_bar_for_shop_managers() {
    // Hide admin bar for shop managers since they can't access wp-admin
    if (current_user_can('shop_manager') && !current_user_can('administrator') && !current_user_can('pos_manager')) {
        show_admin_bar(false);
    }
}
add_action('wp', 'ruiyi_pos_hide_admin_bar_for_shop_managers');

// 移除WordPress的核心权限检查
add_action('admin_head', 'ruiyi_remove_capability_restrictions', 1);
function ruiyi_remove_capability_restrictions() {
    if (is_user_logged_in()) {
        // 移除可能阻止访问的过滤器
        remove_all_filters('user_cannot_access_admin');
        
        // 确保当前用户有基础权限
        global $current_user;
        if (!$current_user->has_cap('read')) {
            $current_user->add_cap('read');
        }
    }
}

// 隐藏WordPress后台中的wp-first-item类元素和WooCommerce首页选项
add_action('admin_head', 'ruiyi_hide_wp_first_item');
function ruiyi_hide_wp_first_item() {
    echo '<style>
        /* 不隐藏wp-first-item，因为会影响正常菜单项 */
        
        /* 只隐藏WooCommerce首页菜单项 - 使用更精确的选择器 */
        #adminmenu li.toplevel_page_woocommerce .wp-submenu a[href*="page=wc-admin"]:not([href*="page=wc-admin&path"]) {
            display: none !important;
        }
        
        /* 隐藏WooCommerce子菜单中指向wc-admin主页的链接 */
        #adminmenu li.toplevel_page_woocommerce .wp-submenu li a[href="admin.php?page=wc-admin"] {
            display: none !important;
        }
        
        /* 隐藏包含WooCommerce首页链接的li元素 */
        #adminmenu li.toplevel_page_woocommerce .wp-submenu li:has(a[href="admin.php?page=wc-admin"]) {
            display: none !important;
        }
    </style>';
}

// 重定向WooCommerce设置向导页面到订单管理页面
add_action('admin_init', 'ruiyi_redirect_wc_setup_wizard');
function ruiyi_redirect_wc_setup_wizard() {
    // 检查是否正在访问WooCommerce设置向导或主页
    if (isset($_GET['page']) && $_GET['page'] === 'wc-admin') {
        // 检查是否是设置向导页面
        if (isset($_GET['path']) && strpos($_GET['path'], 'setup-wizard') !== false) {
            // 重定向到订单管理页面
            wp_redirect(admin_url('edit.php?post_type=shop_order'));
            exit;
        }
        
        // 如果只是访问wc-admin主页（没有具体路径），也重定向到订单页面
        if (!isset($_GET['path']) || $_GET['path'] === '' || $_GET['path'] === '/') {
            wp_redirect(admin_url('edit.php?post_type=shop_order'));
            exit;
        }
    }
}

/**
 * Update customer cart data for display screen synchronization
 */
function ruiyi_pos_update_customer_cart() {
    // Check permissions - allow broader access for customer display
    if (!ruiyi_pos_check_access() && !current_user_can('read')) {
        wp_send_json_error(array('message' => __('Unauthorized access', 'ruiyi-retail-pos')));
        return;
    }
    
    // Get cart data from POST
    $cart_data = isset($_POST['cart_data']) ? sanitize_textarea_field($_POST['cart_data']) : '';
    
    if (empty($cart_data)) {
        wp_send_json_error(array('message' => __('No cart data provided', 'ruiyi-retail-pos')));
        return;
    }
    
    // Validate JSON
    $cart_object = json_decode($cart_data, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        wp_send_json_error(array('message' => __('Invalid cart data format', 'ruiyi-retail-pos')));
        return;
    }
    
    // Store cart data temporarily (expires in 10 minutes)
    $cart_key = 'ruiyi_pos_customer_cart_' . get_current_user_id();
    set_transient($cart_key, $cart_object, 600); // 10 minutes
    
    wp_send_json_success(array(
        'message' => __('Customer cart updated successfully', 'ruiyi-retail-pos'),
        'timestamp' => time()
    ));
}

/**
 * Get customer cart data for display screen
 */
function ruiyi_pos_get_customer_cart() {
    // Check permissions - allow broader access for customer display
    if (!ruiyi_pos_check_access() && !current_user_can('read')) {
        wp_send_json_error(array('message' => __('Unauthorized access', 'ruiyi-retail-pos')));
        return;
    }
    
    // Get cart data from transient
    $cart_key = 'ruiyi_pos_customer_cart_' . get_current_user_id();
    $cart_data = get_transient($cart_key);
    
    if ($cart_data === false) {
        // No cart data or expired
        wp_send_json_success(array(
            'items' => array(),
            'subtotal' => wc_price(0),
            'tax' => wc_price(0),
            'total' => wc_price(0),
            'timestamp' => time()
        ));
        return;
    }
    
    wp_send_json_success($cart_data);
}
/**
 * 获取或创建Varios虚拟产品（零售POS专用）
 */
function ruiyi_get_or_create_varios_product() {
    // 验证权限
    if (!ruiyi_pos_check_access()) {
        wp_send_json_error(array('message' => 'Unauthorized'));
        return;
    }

    // 查找Varios产品（通过SKU）
    $varios_sku = 'VARIOS-RETAIL';
    $product_id = wc_get_product_id_by_sku($varios_sku);

    // 如果不存在，创建虚拟产品
    if (!$product_id) {
        $product = new WC_Product_Simple();
        $product->set_name('Varios');
        $product->set_sku($varios_sku);
        $product->set_regular_price(0);
        $product->set_virtual(true);
        $product->set_sold_individually(false);
        $product->set_catalog_visibility('hidden');
        $product->set_status('private');
        $product_id = $product->save();
        
        ruiyi_pos_log('RUIYI Retail POS: Created Varios product with ID ' . $product_id);
    }

    wp_send_json_success(array(
        'product_id' => $product_id,
        'message' => 'Varios product ready'
    ));
}
add_action('wp_ajax_ruiyi_get_or_create_varios_product', 'ruiyi_get_or_create_varios_product');

/**
 * Add Supplier column to WooCommerce product list
 */
add_filter('manage_edit-product_columns', 'ruiyi_add_supplier_column', 20);
function ruiyi_add_supplier_column($columns) {
    // Insert supplier column after SKU column, or before date if SKU doesn't exist
    $new_columns = array();
    $supplier_added = false;
    
    foreach ($columns as $key => $value) {
        // Add after SKU if it exists
        if ($key === 'sku') {
            $new_columns[$key] = $value;
            $new_columns['supplier'] = __('Supplier', 'ruiyi-retail-pos');
            $supplier_added = true;
        }
        // Add before date column if SKU hasn't been found yet
        elseif (!$supplier_added && $key === 'date') {
            $new_columns['supplier'] = __('Supplier', 'ruiyi-retail-pos');
            $new_columns[$key] = $value;
            $supplier_added = true;
        }
        // Add all other columns normally
        else {
            $new_columns[$key] = $value;
        }
    }
    
    // If still not added (no SKU or date columns), add at the end
    if (!$supplier_added) {
        $new_columns['supplier'] = __('Supplier', 'ruiyi-retail-pos');
    }
    
    return $new_columns;
}

/**
 * Display supplier data in the product list column
 */
add_action('manage_product_posts_custom_column', 'ruiyi_display_supplier_column', 10, 2);
function ruiyi_display_supplier_column($column, $post_id) {
    if ($column === 'supplier') {
        $supplier = get_post_meta($post_id, '_supplier_number', true);
        echo $supplier ? esc_html($supplier) : '—';
    }
}

/**
 * Make supplier column sortable
 */
add_filter('manage_edit-product_sortable_columns', 'ruiyi_make_supplier_column_sortable');
function ruiyi_make_supplier_column_sortable($columns) {
    $columns['supplier'] = 'supplier';
    return $columns;
}

/**
 * Handle sorting for supplier column
 */
add_action('pre_get_posts', 'ruiyi_handle_supplier_column_sorting');
function ruiyi_handle_supplier_column_sorting($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }
    
    $orderby = $query->get('orderby');
    if ($orderby === 'supplier') {
        $query->set('meta_key', '_supplier_number');
        $query->set('orderby', 'meta_value');
    }
}

/**
 * Add custom CSS for supplier column width
 */
add_action('admin_head', 'ruiyi_supplier_column_width');
function ruiyi_supplier_column_width() {
    global $pagenow;
    // Only load on products page
    if ($pagenow === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'product') {
        echo '<style>
            .wp-list-table .column-supplier {
                width: 150px;
            }
            @media screen and (max-width: 782px) {
                .wp-list-table .column-supplier {
                    width: 120px;
                }
            }
        </style>';
    }
}

/**
 * AJAX Handler: Update product name permanently in WooCommerce database
 */
add_action('wp_ajax_ruiyi_pos_update_cart_item_name', 'ruiyi_pos_update_cart_item_name');
function ruiyi_pos_update_cart_item_name() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ruiyi_pos_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed', 'code' => 'nonce_expired'));
        return;
    }
    
    // Get parameters
    $cart_item_id = isset($_POST['cart_item_id']) ? intval($_POST['cart_item_id']) : 0;
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $new_name = isset($_POST['new_name']) ? sanitize_text_field($_POST['new_name']) : '';
    $original_name = isset($_POST['original_name']) ? sanitize_text_field($_POST['original_name']) : '';
    
    // Validate input
    if (empty($new_name) || $product_id === 0) {
        wp_send_json_error(array(
            'message' => __('Invalid parameters', 'ruiyi-retail-pos')
        ));
    }
    
    // Get the product
    $product = wc_get_product($product_id);
    
    if (!$product) {
        wp_send_json_error(array(
            'message' => __('Product not found', 'ruiyi-retail-pos')
        ));
    }
    
    // Store original name as meta if not already stored
    $stored_original_name = $product->get_meta('_pos_original_name', true);
    if (empty($stored_original_name)) {
        $product->update_meta_data('_pos_original_name', $product->get_name());
    }
    
    // Update the product name in database
    $product->set_name($new_name);
    
    // Add audit metadata
    $product->update_meta_data('_pos_name_changed_by', wp_get_current_user()->display_name);
    $product->update_meta_data('_pos_name_changed_at', current_time('mysql'));
    
    // Save the product
    $product->save();
    
    // Log the change
    ruiyi_pos_log(sprintf(
        'RUIYI POS: Product name updated in database - ProductID: %d, Original: "%s", New: "%s", Cashier: %s',
        $product_id,
        $original_name,
        $new_name,
        wp_get_current_user()->display_name
    ));
    
    wp_send_json_success(array(
        'message' => __('Product name updated permanently in database', 'ruiyi-retail-pos'),
        'product_id' => $product_id,
        'new_name' => $new_name,
        'timestamp' => current_time('mysql')
    ));
}

/**
 * Get suppliers list
 */
function ruiyi_pos_get_suppliers() {
    // Get suppliers from options
    $suppliers = get_option('ruiyi_pos_suppliers', array());

    // Ensure it's an array
    if (!is_array($suppliers)) {
        $suppliers = array();
    }

    // Sort by created_at descending (newest first)
    usort($suppliers, function($a, $b) {
        $time_a = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
        $time_b = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
        return $time_b - $time_a;
    });

    wp_send_json_success(array(
        'suppliers' => $suppliers,
        'count' => count($suppliers)
    ));
}

/**
 * Add a new supplier
 */
function ruiyi_pos_add_supplier() {
    // Validate input
    if (!isset($_POST['supplier_name']) || empty(trim($_POST['supplier_name']))) {
        wp_send_json_error(array(
            'message' => __('Supplier name is required', 'ruiyi-retail-pos')
        ));
        return;
    }

    $supplier_name = sanitize_text_field(trim($_POST['supplier_name']));

    // Validate length
    if (strlen($supplier_name) > 100) {
        wp_send_json_error(array(
            'message' => __('Supplier name is too long (max 100 characters)', 'ruiyi-retail-pos')
        ));
        return;
    }

    // Get existing suppliers
    $suppliers = get_option('ruiyi_pos_suppliers', array());
    if (!is_array($suppliers)) {
        $suppliers = array();
    }

    // Check for duplicate name
    foreach ($suppliers as $supplier) {
        if (isset($supplier['name']) && strtolower($supplier['name']) === strtolower($supplier_name)) {
            wp_send_json_error(array(
                'message' => __('A supplier with this name already exists', 'ruiyi-retail-pos')
            ));
            return;
        }
    }

    // Generate unique ID
    $id = 'SUP-' . time() . '-' . rand(1000, 9999);

    // Create new supplier
    $new_supplier = array(
        'id' => $id,
        'name' => $supplier_name,
        'created_at' => current_time('mysql'),
        'created_by' => get_current_user_id()
    );

    // Add to array
    $suppliers[] = $new_supplier;

    // Save to options
    $saved = update_option('ruiyi_pos_suppliers', $suppliers);

    if ($saved || get_option('ruiyi_pos_suppliers') === $suppliers) {
        // Log the action
        ruiyi_pos_log('RUIYI POS: Supplier added - ' . $supplier_name . ' (ID: ' . $id . ') by user ' . get_current_user_id());

        wp_send_json_success(array(
            'message' => __('Supplier added successfully', 'ruiyi-retail-pos'),
            'supplier' => $new_supplier
        ));
    } else {
        wp_send_json_error(array(
            'message' => __('Failed to save supplier', 'ruiyi-retail-pos')
        ));
    }
}

/**
 * Delete a supplier
 */
function ruiyi_pos_delete_supplier() {
    // Validate input
    if (!isset($_POST['supplier_id']) || empty(trim($_POST['supplier_id']))) {
        wp_send_json_error(array(
            'message' => __('Supplier ID is required', 'ruiyi-retail-pos')
        ));
        return;
    }

    $supplier_id = sanitize_text_field(trim($_POST['supplier_id']));

    // Get existing suppliers
    $suppliers = get_option('ruiyi_pos_suppliers', array());
    if (!is_array($suppliers)) {
        $suppliers = array();
    }

    // Find and remove supplier
    $found = false;
    $supplier_name = '';
    $filtered_suppliers = array();

    foreach ($suppliers as $supplier) {
        if (isset($supplier['id']) && $supplier['id'] === $supplier_id) {
            $found = true;
            $supplier_name = isset($supplier['name']) ? $supplier['name'] : 'Unknown';
            // Skip this supplier (delete it)
            continue;
        }
        $filtered_suppliers[] = $supplier;
    }

    if (!$found) {
        wp_send_json_error(array(
            'message' => __('Supplier not found', 'ruiyi-retail-pos')
        ));
        return;
    }

    // Save updated list
    $saved = update_option('ruiyi_pos_suppliers', $filtered_suppliers);

    if ($saved || get_option('ruiyi_pos_suppliers') === $filtered_suppliers) {
        // Log the action
        ruiyi_pos_log('RUIYI POS: Supplier deleted - ' . $supplier_name . ' (ID: ' . $supplier_id . ') by user ' . get_current_user_id());

        wp_send_json_success(array(
            'message' => __('Supplier deleted successfully', 'ruiyi-retail-pos')
        ));
    } else {
        wp_send_json_error(array(
            'message' => __('Failed to delete supplier', 'ruiyi-retail-pos')
        ));
    }
}

// ========================================
// 🔥 历史订单功能 (ORDER HISTORY)
// ========================================

/**
 * 获取历史订单列表
 * Get order history list with filters
 */
function ruiyi_pos_get_order_history() {
    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'all';
    $date_range = isset($_POST['date_range']) ? sanitize_text_field($_POST['date_range']) : 'all';
    $custom_date = isset($_POST['custom_date']) ? sanitize_text_field($_POST['custom_date']) : '';

    // Build query args
    $args = array(
        'limit' => 100,
        'orderby' => 'date',
        'order' => 'DESC',
        'type' => 'shop_order',
    );

    // Status filter
    if ($status === 'completed') {
        $args['status'] = array('wc-completed');
    } elseif ($status === 'pending') {
        $args['status'] = array('wc-pending', 'wc-on-hold');
    } elseif ($status === 'cancelled') {
        $args['status'] = array('wc-cancelled');
    } else {
        // All - show completed, pending, and cancelled
        $args['status'] = array('wc-completed', 'wc-pending', 'wc-on-hold', 'wc-cancelled');
    }

    // Date range filter
    $now = current_time('timestamp');
    switch ($date_range) {
        case 'today':
            $args['date_created'] = '>=' . date('Y-m-d 00:00:00', $now);
            break;
        case 'yesterday':
            $yesterday = strtotime('-1 day', $now);
            $args['date_created'] = date('Y-m-d 00:00:00', $yesterday) . '...' . date('Y-m-d 23:59:59', $yesterday);
            break;
        case 'week':
            $week_start = strtotime('monday this week', $now);
            $args['date_created'] = '>=' . date('Y-m-d 00:00:00', $week_start);
            break;
        case 'month':
            $args['date_created'] = '>=' . date('Y-m-01 00:00:00', $now);
            break;
        case 'custom':
            // 🔥 Custom date picker - filter by specific date
            if (!empty($custom_date)) {
                $args['date_created'] = $custom_date . ' 00:00:00...' . $custom_date . ' 23:59:59';
            }
            break;
        default:
            // All dates - no filter
            break;
    }

    // Get orders
    $orders = wc_get_orders($args);

    // 🔥 Filter by search if provided - search by invoice_label first, then order number and customer name
    if (!empty($search)) {
        $search_lower = strtolower($search);
        $orders = array_filter($orders, function($order) use ($search_lower) {
            // 🔥 Search by Verifactu invoice label (e.g., FAC-2026-000001)
            $invoice_label = $order->get_meta('_verifactu_invoice_label');
            if ($invoice_label && strpos(strtolower($invoice_label), $search_lower) !== false) {
                return true;
            }
            // Search by order number
            if (strpos(strtolower($order->get_order_number()), $search_lower) !== false) {
                return true;
            }
            // Search by customer name
            $billing_name = strtolower($order->get_formatted_billing_full_name());
            if (strpos($billing_name, $search_lower) !== false) {
                return true;
            }
            return false;
        });
    }

    // Format orders for response
    $formatted_orders = array();
    foreach ($orders as $order) {
        // 过滤非收银系统订单（排除锐意助手等其他来源）
        $order_source = $order->get_meta('_ruiyi_order_source');
        if ($order_source && $order_source !== '收银系统') {
            continue;
        }

        $order_status = $order->get_status();

        // 🔥 获取支付方式
        $payment_type = ruiyi_get_order_payment_type($order);

        // 🔥 获取Verifactu发票号码
        $invoice_label = $order->get_meta('_verifactu_invoice_label');

        // 🔥 获取退货发票相关信息 (for cancelled orders)
        $has_rectificativa = $order->get_meta('_verifactu_rectificativa_label') ? true : false;
        $rectificativa_label = $order->get_meta('_verifactu_rectificativa_label') ?: '';
        $rectificativa_date = $order->get_meta('_verifactu_rectificativa_date') ?: '';
        $original_invoice_date = $order->get_date_created() ? $order->get_date_created()->date('d-m-Y') : '';

        // 🔥 获取PDF URLs (用于下载按钮) - 使用动态生成URL确保PDF始终反映最新设置
        $invoice_pdf_url = '';
        $rectificativa_pdf_url = '';

        // Original invoice PDF - 使用admin-post动态生成
        if ($invoice_label || $order->get_meta('_verifactu_qr_base64') || $order->get_meta('_verifactu_invoice_pdf')) {
            $invoice_pdf_url = admin_url('admin-post.php?action=verifactu_invoice_pdf&order_id=' . $order->get_id() . '&nonce=' . wp_create_nonce('verifactu_invoice_pdf_' . $order->get_id()));
        }

        // Rectificativa PDF - 使用admin-post动态生成
        if ($order->get_meta('_verifactu_rectificativa_label') || $order->get_meta('_verifactu_corrective_generated') === 'yes') {
            $rectificativa_pdf_url = admin_url('admin-post.php?action=verifactu_invoice_pdf&order_id=' . $order->get_id() . '&nonce=' . wp_create_nonce('verifactu_invoice_pdf_' . $order->get_id()));
        }

        // 🔥 获取发票客户信息 (用于退货单自动填充)
        $invoice_customer_name = $order->get_meta('_invoice_customer_name') ?: '';
        $invoice_customer_company = $order->get_meta('_invoice_customer_company') ?: $order->get_meta('_invoice_company') ?: $order->get_billing_company() ?: '';
        $invoice_customer_cif = $order->get_meta('_invoice_customer_cif') ?: $order->get_meta('_invoice_cif') ?: $order->get_meta('_billing_cif') ?: '';
        $invoice_customer_address = $order->get_meta('_invoice_customer_address') ?: $order->get_meta('_invoice_address') ?: '';
        $invoice_customer_city = $order->get_meta('_invoice_customer_city') ?: $order->get_meta('_invoice_city') ?: '';
        $invoice_customer_postcode = $order->get_meta('_invoice_customer_postcode') ?: $order->get_meta('_invoice_postcode') ?: '';

        // 🔥 POS退货订单标记
        $is_pos_refund = $order->get_meta('_pos_refund_order') === 'yes';

        // 🔥 获取退货商品列表（用于退货订单显示）
        $return_items_list = array();
        if ($is_pos_refund || floatval($order->get_total()) < 0) {
            foreach ($order->get_items() as $item) {
                if ($item->get_quantity() < 0 || $item->get_meta('_is_return') === 'yes') {
                    $return_items_list[] = array(
                        'name' => $item->get_name(),
                        'quantity' => $item->get_quantity(),
                        'total' => $item->get_total(),
                    );
                }
            }
        }

        $formatted_orders[] = array(
            'id' => $order->get_id(),
            'number' => $order->get_order_number(),
            'invoice_label' => $invoice_label ?: '', // 🔥 发票号码
            'date' => $order->get_date_created()->date('Y-m-d H:i'),
            'status' => $order_status,
            'total' => $order->get_formatted_order_total(),
            'total_raw' => $order->get_total(),
            'customer_name' => $order->get_meta('_customer_name') ?: $order->get_formatted_billing_full_name(),
            'customer_phone' => $order->get_meta('_customer_phone') ?: '',
            'customer_company' => $order->get_meta('_customer_company') ?: '',
            'customer_tax_id' => $order->get_meta('_customer_tax_id') ?: '',
            'customer_email' => $order->get_billing_email(),
            'payment_type' => $payment_type,  // 🔥 添加支付方式
            'has_rectificativa' => $has_rectificativa, // 🔥 是否已有退货发票
            'rectificativa_label' => $rectificativa_label, // 🔥 退货发票号码
            'rectificativa_date' => $rectificativa_date, // 🔥 退货发票日期
            'original_invoice_date' => $original_invoice_date, // 🔥 原发票日期
            'invoice_pdf_url' => $invoice_pdf_url, // 🔥 原发票PDF URL
            'rectificativa_pdf_url' => $rectificativa_pdf_url, // 🔥 退货发票PDF URL
            // 🔥 发票客户信息 (用于退货单自动填充)
            'invoice_customer_name' => $invoice_customer_name,
            'invoice_customer_company' => $invoice_customer_company,
            'invoice_customer_cif' => $invoice_customer_cif,
            'invoice_customer_address' => $invoice_customer_address,
            'invoice_customer_city' => $invoice_customer_city,
            'invoice_customer_postcode' => $invoice_customer_postcode,
            'is_pos_refund' => $is_pos_refund, // 🔥 POS退货订单标记
            'return_items' => $return_items_list, // 🔥 退货商品列表
        );
    }

    wp_send_json_success(array(
        'orders' => $formatted_orders,
        'total' => count($formatted_orders)
    ));
}

/**
 * 🔥 获取订单支付方式类型
 * Get payment type from order (cash, card, transfer, cash_card)
 */
function ruiyi_get_order_payment_type($order) {
    // 首先检查是否有存储的发票支付方式（用户在开票时手动选择的）
    $stored_payment_method = $order->get_meta('_invoice_payment_method');
    if ($stored_payment_method) {
        return $stored_payment_method;
    }

    // 🔥 获取WooCommerce订单的支付方式（这是结账时设置的）
    $wc_payment_method = $order->get_payment_method();

    // 检查是否有混合支付记录
    $pos_card_amount = floatval($order->get_meta('_pos_card_amount'));
    $pos_cash_amount = floatval($order->get_meta('_pos_cash_amount'));

    // 如果是混合支付或同时有现金和刷卡金额
    if ($wc_payment_method === 'mixed' || ($pos_cash_amount > 0 && $pos_card_amount > 0)) {
        return 'cash_card';
    }

    // 🔥 直接根据WooCommerce支付方式判断
    if ($wc_payment_method === 'card') {
        return 'card';
    }

    if ($wc_payment_method === 'transfer') {
        return 'transfer';
    }

    if ($wc_payment_method === 'cash') {
        return 'cash';
    }

    // 默认现金
    return 'cash';
}

/**
 * 获取订单客户数据
 * Get customer data from an order
 */
function ruiyi_pos_get_order_customer_data() {
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

    if (!$order_id) {
        wp_send_json_error(array('message' => __('Order ID is required', 'ruiyi-retail-pos')));
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => __('Order not found', 'ruiyi-retail-pos')));
        return;
    }

    // 🔥 获取订单支付方式 (cash, card, transfer, cash_card)
    $order_payment_method = ruiyi_get_order_payment_type($order);

    // 🔥 获取存储的银行信息 (如果订单没有，则使用公司设置中的默认值)
    $default_bank_settings = get_option('ruiyi_pos_default_bank', array());
    $bank_name = $order->get_meta('_invoice_bank_name') ?: ($default_bank_settings['bank_name'] ?? '');
    $bank_iban = $order->get_meta('_invoice_bank_iban') ?: ($default_bank_settings['bank_iban'] ?? '');
    $bank_bic = $order->get_meta('_invoice_bank_bic') ?: ($default_bank_settings['bank_bic'] ?? '');
    $phone_prefix = $order->get_meta('_billing_phone_prefix') ?: '+34'; // 🔥 Phone prefix

    // Check if order has linked Verifactu customer
    $verifactu_customer_id = $order->get_meta('_verifactu_customer_id');
    if ($verifactu_customer_id && class_exists('WC_Verifactu_Customer_Manager')) {
        $customer_data = WC_Verifactu_Customer_Manager::get_customer_data($verifactu_customer_id);
        if ($customer_data) {
            wp_send_json_success(array(
                'name' => $customer_data['customer_name'] ?: '',
                'company' => $customer_data['company'] ?: '',
                'cif' => $customer_data['cif'] ?: '',
                'address' => $customer_data['address'] ?: '',
                'address_2' => $customer_data['address_2'] ?: '',
                'city' => $customer_data['city'] ?: '',
                'state' => $customer_data['state'] ?: '',
                'postcode' => $customer_data['postcode'] ?: '',
                'country' => $customer_data['country'] ?: 'España',
                'phone_prefix' => $customer_data['phone_prefix'] ?: $phone_prefix, // 🔥 Phone prefix
                'phone' => $customer_data['phone'] ?: '',
                'email' => $customer_data['email'] ?: '',
                // 🔥 银行信息和支付方式
                'payment_method' => $order_payment_method,
                'bank_name' => $customer_data['bank_name'] ?: $bank_name,
                'bank_iban' => $customer_data['bank_iban'] ?: $bank_iban,
                'bank_bic' => $customer_data['bank_bic'] ?: $bank_bic,
            ));
            return;
        }
    }

    // Check if order is from Ruiyi marketplace app (锐意助手) and has invoice info
    $ruiyi_order_source = $order->get_meta('_ruiyi_order_source');
    $ruiyi_need_invoice = $order->get_meta('_ruiyi_need_invoice');

    if ($ruiyi_order_source === '锐意助手' && $ruiyi_need_invoice === 'yes') {
        // Extract Ruiyi marketplace invoice data
        $ruiyi_invoice_company = $order->get_meta('_ruiyi_invoice_company');
        $ruiyi_tax_id = $order->get_meta('_ruiyi_tax_id');
        $ruiyi_invoice_address = $order->get_meta('_ruiyi_invoice_address');
        $ruiyi_contact_person = $order->get_meta('_ruiyi_contact_person');

        // Use Ruiyi data to populate invoice fields
        // Combine with WooCommerce billing data for complete address info
        wp_send_json_success(array(
            'name' => $ruiyi_contact_person ?: $order->get_formatted_billing_full_name(),
            'company' => ($ruiyi_invoice_company && $ruiyi_invoice_company !== '空') ? $ruiyi_invoice_company : $order->get_billing_company(),
            'cif' => ($ruiyi_tax_id && $ruiyi_tax_id !== '空') ? $ruiyi_tax_id : ($order->get_meta('_billing_vat') ?: $order->get_meta('_billing_nif') ?: ''),
            'address' => ($ruiyi_invoice_address && $ruiyi_invoice_address !== '空') ? $ruiyi_invoice_address : $order->get_billing_address_1(),
            'address_2' => $order->get_billing_address_2(),
            'city' => $order->get_billing_city(),
            'state' => $order->get_billing_state(),
            'postcode' => $order->get_billing_postcode(),
            'country' => $order->get_billing_country() ?: 'ES',
            'phone_prefix' => $phone_prefix, // 🔥 Phone prefix
            'phone' => $order->get_billing_phone(),
            'email' => $order->get_billing_email(),
            'is_ruiyi_order' => true,  // Flag to indicate this is from Ruiyi app
            // 🔥 银行信息和支付方式
            'payment_method' => $order_payment_method,
            'bank_name' => $bank_name,
            'bank_iban' => $bank_iban,
            'bank_bic' => $bank_bic,
        ));
        return;
    }

    // Fall back to WooCommerce billing data
    wp_send_json_success(array(
        'name' => $order->get_formatted_billing_full_name(),
        'company' => $order->get_billing_company(),
        'cif' => $order->get_meta('_billing_vat') ?: $order->get_meta('_billing_nif') ?: '',
        'address' => $order->get_billing_address_1(),
        'address_2' => $order->get_billing_address_2(),
        'city' => $order->get_billing_city(),
        'state' => $order->get_billing_state(),
        'postcode' => $order->get_billing_postcode(),
        'country' => $order->get_billing_country() ?: 'ES',
        'phone_prefix' => $phone_prefix, // 🔥 Phone prefix
        'phone' => $order->get_billing_phone(),
        'email' => $order->get_billing_email(),
        // 🔥 银行信息和支付方式
        'payment_method' => $order_payment_method,
        'bank_name' => $bank_name,
        'bank_iban' => $bank_iban,
        'bank_bic' => $bank_bic,
    ));
}

/**
 * 生成正式发票
 * Generate invoice through Verifactu
 */
function ruiyi_pos_generate_invoice() {
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $customer_data = isset($_POST['customer_data']) ? json_decode(stripslashes($_POST['customer_data']), true) : array();

    if (!$order_id) {
        wp_send_json_error(array('message' => __('Order ID is required', 'ruiyi-retail-pos')));
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => __('Order not found', 'ruiyi-retail-pos')));
        return;
    }

    // Save customer data to WooCommerce billing fields for Verifactu PDF generation
    if (!empty($customer_data)) {
        ruiyi_pos_log("[POS Invoice] Saving customer data for order #$order_id");
        ruiyi_pos_log("[POS Invoice] Customer data received: " . json_encode($customer_data));

        // Parse full name into first and last name
        $full_name = sanitize_text_field($customer_data['name'] ?? '');
        $name_parts = explode(' ', $full_name, 2);
        $first_name = $name_parts[0] ?? '';
        $last_name = $name_parts[1] ?? '';

        // Update WooCommerce standard billing fields
        $order->set_billing_first_name($first_name);
        $order->set_billing_last_name($last_name);
        $order->set_billing_company(sanitize_text_field($customer_data['company'] ?? ''));
        $order->set_billing_address_1(sanitize_text_field($customer_data['address'] ?? ''));
        $order->set_billing_address_2(sanitize_text_field($customer_data['address_2'] ?? ''));
        $order->set_billing_city(sanitize_text_field($customer_data['city'] ?? ''));
        $order->set_billing_state(sanitize_text_field($customer_data['state'] ?? ''));
        $order->set_billing_postcode(sanitize_text_field($customer_data['postcode'] ?? ''));
        $order->set_billing_country(sanitize_text_field($customer_data['country'] ?? 'ES'));
        $order->set_billing_phone(sanitize_text_field($customer_data['phone'] ?? ''));
        $order->set_billing_email(sanitize_email($customer_data['email'] ?? ''));

        // 🔥 Save phone prefix
        $phone_prefix = sanitize_text_field($customer_data['phone_prefix'] ?? '+34');
        $order->update_meta_data('_billing_phone_prefix', $phone_prefix);

        // Save CIF/NIF to meta field that Verifactu checks
        $cif = sanitize_text_field($customer_data['cif'] ?? '');
        if ($cif) {
            $order->update_meta_data('_billing_cif', $cif);
            $order->update_meta_data('_billing_nif', $cif);
            $order->update_meta_data('_billing_vat', $cif);
        }

        // Also save to custom fields for backup/reference
        $order->update_meta_data('_invoice_customer_name', $full_name);
        $order->update_meta_data('_invoice_company', sanitize_text_field($customer_data['company'] ?? ''));
        $order->update_meta_data('_invoice_cif', $cif);

        // 🔥 保存银行信息和支付方式
        $payment_method = sanitize_text_field($customer_data['payment_method'] ?? 'cash');
        $bank_name = sanitize_text_field($customer_data['bank_name'] ?? '');
        $bank_iban = sanitize_text_field($customer_data['bank_iban'] ?? '');
        $bank_bic = sanitize_text_field($customer_data['bank_bic'] ?? '');

        $order->update_meta_data('_invoice_payment_method', $payment_method);
        $order->update_meta_data('_invoice_bank_name', $bank_name);
        $order->update_meta_data('_invoice_bank_iban', $bank_iban);
        $order->update_meta_data('_invoice_bank_bic', $bank_bic);

        // 🔥 Save notes (always save, even if empty, to allow clearing)
        $notes = sanitize_textarea_field($customer_data['notes'] ?? '');
        $order->update_meta_data('_invoice_notes', $notes);

        ruiyi_pos_log("[POS Invoice] Saved payment method: $payment_method, Bank: $bank_name, IBAN: $bank_iban, BIC: $bank_bic, Notes: $notes");

        // Save customer ID and salesperson ID to order meta (for Verifactu integration)
        $customer_id = isset($customer_data['customer_id']) ? intval($customer_data['customer_id']) : 0;
        $salesperson_id = isset($customer_data['salesperson_id']) ? intval($customer_data['salesperson_id']) : 0;

        // 🔥 If no salesperson ID but new name provided, auto-create salesperson
        $new_salesperson_name = isset($customer_data['new_salesperson_name']) ? sanitize_text_field($customer_data['new_salesperson_name']) : '';
        if (!$salesperson_id && !empty($new_salesperson_name)) {
            $salesperson_id = ruiyi_pos_create_salesperson($new_salesperson_name);
            ruiyi_pos_log("[POS Invoice] Auto-created new salesperson: $new_salesperson_name (ID: $salesperson_id)");
        }

        // If no customer_id, auto-create new customer in Verifactu
        if (!$customer_id && !empty($cif)) {
            $customer_id = ruiyi_pos_save_new_customer($customer_data);
        }

        if ($customer_id) {
            $order->update_meta_data('_verifactu_customer_id', $customer_id);
        }
        if ($salesperson_id) {
            $order->update_meta_data('_verifactu_salesperson_id', $salesperson_id);
        }

        $order->save();

        // Refresh order object to ensure all data is loaded
        $order = wc_get_order($order_id);
        ruiyi_pos_log("[POS Invoice] Customer data saved. Billing name: " . $order->get_formatted_billing_full_name());
    }

    // Check if Verifactu plugin is active and has generated invoice
    // IMPORTANT: For re-generation with new customer data, we need to delete old PDF
    $pdf_path = $order->get_meta('_verifactu_invoice_pdf');

    // If customer data was provided, we need to regenerate the invoice
    $force_regenerate = !empty($customer_data);

    if ($pdf_path && file_exists($pdf_path) && !$force_regenerate) {
        // Return existing invoice URL
        $upload_dir = wp_upload_dir();
        $pdf_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $pdf_path);
        wp_send_json_success(array(
            'pdf_url' => $pdf_url,
            'message' => __('Invoice ready', 'ruiyi-retail-pos')
        ));
        return;
    }

    // Delete old PDF to force regeneration with new customer data
    if ($pdf_path && file_exists($pdf_path) && $force_regenerate) {
        @unlink($pdf_path);
        $order->delete_meta_data('_verifactu_invoice_pdf');
        $order->save();
    }

    // Try to trigger Verifactu invoice generation
    if (class_exists('WC_Verifactu_QR')) {
        ruiyi_pos_log("[POS Invoice] WC_Verifactu_QR class exists, getting instance...");
        $verifactu = WC_Verifactu_QR::instance();
        if ($verifactu) {
            ruiyi_pos_log("[POS Invoice] Verifactu instance obtained successfully");
            try {
                // Get QR code bytes from order meta (base64 encoded)
                $qr_base64 = $order->get_meta('_verifactu_qr_base64');
                $qr_bytes = '';
                if ($qr_base64) {
                    $qr_bytes = base64_decode($qr_base64, true);
                    if ($qr_bytes === false) {
                        $qr_bytes = '';
                    }
                    ruiyi_pos_log("[POS Invoice] QR code found, bytes length: " . strlen($qr_bytes));
                } else {
                    ruiyi_pos_log("[POS Invoice] No QR code found for order, generating PDF without QR");
                }

                // Use reflection to call private generate_pdf_with_qr method
                $reflection = new ReflectionClass($verifactu);
                $method = $reflection->getMethod('generate_pdf_with_qr');
                $method->setAccessible(true);

                // 🔥 Debug: Verify notes are saved in order before PDF generation
                $notes_check = $order->get_meta('_invoice_notes');
                ruiyi_pos_log("[POS Invoice] Calling generate_pdf_with_qr method... Notes in order: " . ($notes_check ?: '(empty)'));

                $pdf_bytes = $method->invoke($verifactu, $order, $qr_bytes);

                ruiyi_pos_log("[POS Invoice] PDF generation result - bytes length: " . (is_string($pdf_bytes) ? strlen($pdf_bytes) : 'null/false'));

                if ($pdf_bytes && strlen($pdf_bytes) > 100) {
                    // Verify it's a valid PDF (starts with %PDF)
                    if (substr($pdf_bytes, 0, 4) !== '%PDF') {
                        ruiyi_pos_log("[POS Invoice] ERROR: Generated content is not a valid PDF!");
                        wp_send_json_error(array(
                            'message' => __('Generated content is not a valid PDF', 'ruiyi-retail-pos')
                        ));
                        return;
                    }

                    // Save PDF to file
                    $upload_dir = wp_upload_dir();
                    $invoice_dir = $upload_dir['basedir'] . '/verifactu-invoices';
                    if (!file_exists($invoice_dir)) {
                        wp_mkdir_p($invoice_dir);
                    }

                    // Get invoice label from order meta or generate default
                    $invoice_label = $order->get_meta('_verifactu_invoice_label');
                    if (!$invoice_label) {
                        // Try to get from get_invoice_label method
                        $label_method = $reflection->getMethod('get_invoice_label');
                        $label_method->setAccessible(true);
                        $invoice_label = $label_method->invoke($verifactu, $order);
                    }
                    if (!$invoice_label) {
                        $invoice_label = 'F-' . $order->get_order_number();
                    }
                    ruiyi_pos_log("[POS Invoice] Invoice label: $invoice_label");

                    // Add timestamp to filename to prevent caching of old PDFs (for server storage)
                    $filename_with_timestamp = strtolower($invoice_label) . '_' . time() . '.pdf';
                    $filepath = $invoice_dir . '/' . $filename_with_timestamp;

                    // Clean filename for download (without timestamp)
                    $download_filename = strtolower($invoice_label) . '.pdf';

                    $bytes_written = file_put_contents($filepath, $pdf_bytes);
                    ruiyi_pos_log("[POS Invoice] PDF saved to: $filepath, bytes written: $bytes_written");

                    if ($bytes_written === false || $bytes_written < 100) {
                        ruiyi_pos_log("[POS Invoice] ERROR: Failed to write PDF file!");
                        wp_send_json_error(array(
                            'message' => __('Failed to save PDF file', 'ruiyi-retail-pos')
                        ));
                        return;
                    }

                    // Save path to order meta
                    $order->update_meta_data('_verifactu_invoice_pdf', $filepath);
                    $order->save();

                    $pdf_url = $upload_dir['baseurl'] . '/verifactu-invoices/' . $filename_with_timestamp;
                    // Ensure HTTPS
                    $pdf_url = str_replace('http://', 'https://', $pdf_url);
                    ruiyi_pos_log("[POS Invoice] SUCCESS! PDF URL: $pdf_url");

                    wp_send_json_success(array(
                        'pdf_url' => $pdf_url,
                        'filename' => $download_filename,
                        'message' => __('Invoice generated successfully', 'ruiyi-retail-pos')
                    ));
                    return;
                } else {
                    ruiyi_pos_log("[POS Invoice] ERROR: PDF bytes empty or too small");
                }
            } catch (Exception $e) {
                ruiyi_pos_log('[POS Invoice] Exception: ' . $e->getMessage());
                ruiyi_pos_log('[POS Invoice] Stack trace: ' . $e->getTraceAsString());
            }
        } else {
            ruiyi_pos_log("[POS Invoice] ERROR: Could not get Verifactu instance");
        }
    } else {
        ruiyi_pos_log("[POS Invoice] ERROR: WC_Verifactu_QR class does not exist");
    }

    wp_send_json_error(array(
        'message' => __('Invoice generation failed. Please check Verifactu plugin settings.', 'ruiyi-retail-pos')
    ));
}

/**
 * 创建Albarán预结单订单
 * Create Albarán (pending payment) order
 */
function ruiyi_pos_create_albaran_order() {
    $cart_data = isset($_POST['cart_data']) ? json_decode(stripslashes($_POST['cart_data']), true) : array();
    $total = isset($_POST['total']) ? floatval($_POST['total']) : 0;
    $payment_method = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : 'cash';

    if (empty($cart_data)) {
        wp_send_json_error(array('message' => __('Cart is empty', 'ruiyi-retail-pos')));
        return;
    }

    try {
        // Create a new WooCommerce order with pending status
        $order = wc_create_order(array(
            'status' => 'pending',
        ));

        if (is_wp_error($order)) {
            throw new Exception($order->get_error_message());
        }

        // Add products to the order (支持小数数量)
        foreach ($cart_data as $item) {
            $product_id = intval($item['product_id']);
            $quantity = floatval($item['quantity']); // 支持小数数量如1.5, 2.75
            $price = floatval($item['price']);
            $name = sanitize_text_field($item['name']);

            if ($product_id > 0) {
                $product = wc_get_product($product_id);
                if ($product) {
                    // Add product with custom price if different from original
                    $item_id = $order->add_product($product, $quantity, array(
                        'subtotal' => $price * $quantity,
                        'total' => $price * $quantity,
                    ));
                }
            } else {
                // Add as a fee/custom line item for "Varios" products
                $item_fee = new WC_Order_Item_Fee();
                $item_fee->set_name($name);
                $item_fee->set_amount($price * $quantity);
                $item_fee->set_total($price * $quantity);
                $order->add_item($item_fee);
            }
        }

        // Mark as Albarán order
        $order->update_meta_data('_is_albaran_order', 'yes');
        $order->update_meta_data('_albaran_created_at', current_time('mysql'));
        $order->update_meta_data('_ruiyi_order_source', '收银系统');

        // 🔥 保存支付方式
        $order->set_payment_method($payment_method);
        $order->update_meta_data('_pos_payment_method', $payment_method);

        // Generate Albarán number (Al-YYYY-XXXX format)
        $year = date('Y');
        $albaran_number = ruiyi_pos_get_next_albaran_number($year);
        $order->update_meta_data('_albaran_number', $albaran_number);

        // Calculate totals
        $order->calculate_totals();

        // Add order note
        $order->add_order_note(__('Albarán order created from POS', 'ruiyi-retail-pos'));

        $order->save();

        wp_send_json_success(array(
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'albaran_number' => $albaran_number,
            'message' => __('Albarán order created successfully', 'ruiyi-retail-pos')
        ));

    } catch (Exception $e) {
        ruiyi_pos_log('[POS Albarán] Error creating order: ' . $e->getMessage());
        wp_send_json_error(array(
            'message' => __('Failed to create Albarán order: ', 'ruiyi-retail-pos') . $e->getMessage()
        ));
    }
}

/**
 * 获取下一个Albarán编号
 * Get next Albarán number for the year
 */
function ruiyi_pos_get_next_albaran_number($year) {
    $option_key = 'ruiyi_pos_albaran_counter_' . $year;
    $counter = get_option($option_key, 0);
    $counter++;
    update_option($option_key, $counter);
    return sprintf('Al-%s-%04d', $year, $counter);
}

/**
 * 完成订单 - 将待付款订单状态改为已完成
 * Complete order - change pending order status to completed
 */
function ruiyi_pos_complete_order() {
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

    if (!$order_id) {
        wp_send_json_error(array('message' => __('Order ID is required', 'ruiyi-retail-pos')));
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => __('Order not found', 'ruiyi-retail-pos')));
        return;
    }

    // Check if order is already completed
    if ($order->get_status() === 'completed') {
        wp_send_json_error(array('message' => __('Order is already completed', 'ruiyi-retail-pos')));
        return;
    }

    try {
        // Update order status to completed
        $order->set_status('completed', __('Order completed via POS', 'ruiyi-retail-pos'));
        $order->save();

        wp_send_json_success(array(
            'order_id' => $order_id,
            'message' => __('Order completed successfully', 'ruiyi-retail-pos')
        ));

    } catch (Exception $e) {
        ruiyi_pos_log('[POS Complete Order] Error: ' . $e->getMessage());
        wp_send_json_error(array(
            'message' => __('Failed to complete order: ', 'ruiyi-retail-pos') . $e->getMessage()
        ));
    }
}

/**
 * 作废订单 - 已完成订单改为取消状态，待付款订单永久删除
 * Void order - cancel completed orders, permanently delete pending orders
 */
function ruiyi_pos_void_order() {
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $is_pending = isset($_POST['is_pending']) && $_POST['is_pending'] === '1';

    if (!$order_id) {
        wp_send_json_error(array('message' => __('Order ID is required', 'ruiyi-retail-pos')));
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => __('Order not found', 'ruiyi-retail-pos')));
        return;
    }

    try {
        if ($is_pending) {
            // For pending orders: permanently delete
            // First, remove the order from WooCommerce
            $order->delete(true); // true = force delete (bypass trash)

            wp_send_json_success(array(
                'order_id' => $order_id,
                'action' => 'deleted',
                'message' => __('Pending order permanently deleted', 'ruiyi-retail-pos')
            ));
        } else {
            // For completed orders:
            // 1. Deduct from daily summary
            // 2. Change status to cancelled
            // 3. Generate rectificativa invoice (if has original invoice)

            ruiyi_pos_deduct_from_daily_summary($order);

            // 🔥 作废直接取消订单，不自动生成退货发票PDF
            $order->set_status('cancelled', __('Order voided via POS', 'ruiyi-retail-pos'));
            $order->save();

            wp_send_json_success(array(
                'order_id' => $order_id,
                'action' => 'cancelled',
                'message' => __('Order voided successfully', 'ruiyi-retail-pos')
            ));
        }

    } catch (Exception $e) {
        ruiyi_pos_log('[POS Void Order] Error: ' . $e->getMessage());
        wp_send_json_error(array(
            'message' => __('Failed to void order: ', 'ruiyi-retail-pos') . $e->getMessage()
        ));
    }
}

/**
 * 退货并生成退货发票（带客户信息）
 * Void order and generate rectificativa invoice with customer data
 */
function ruiyi_pos_void_order_with_rectificativa() {
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $new_invoice_number = isset($_POST['new_invoice_number']) ? sanitize_text_field($_POST['new_invoice_number']) : '';
    $original_invoice_number = isset($_POST['original_invoice_number']) ? sanitize_text_field($_POST['original_invoice_number']) : '';
    $original_invoice_date = isset($_POST['original_invoice_date']) ? sanitize_text_field($_POST['original_invoice_date']) : '';
    $emission_date = isset($_POST['emission_date']) ? sanitize_text_field($_POST['emission_date']) : '';

    // Customer data
    $customer_name = isset($_POST['customer_name']) ? sanitize_text_field($_POST['customer_name']) : '';
    $company = isset($_POST['company']) ? sanitize_text_field($_POST['company']) : '';
    $cif = isset($_POST['cif']) ? sanitize_text_field($_POST['cif']) : '';
    $address = isset($_POST['address']) ? sanitize_text_field($_POST['address']) : '';
    $city = isset($_POST['city']) ? sanitize_text_field($_POST['city']) : '';
    $postcode = isset($_POST['postcode']) ? sanitize_text_field($_POST['postcode']) : '';

    if (!$order_id || !$new_invoice_number) {
        wp_send_json_error(array('message' => __('Missing required parameters', 'ruiyi-retail-pos')));
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => __('Order not found', 'ruiyi-retail-pos')));
        return;
    }

    // Check if rectificativa already exists
    $existing_rectificativa = $order->get_meta('_verifactu_rectificativa_label');
    if (!empty($existing_rectificativa)) {
        wp_send_json_error(array('message' => __('Rectificativa already exists for this order', 'ruiyi-retail-pos')));
        return;
    }

    try {
        // 1. Deduct from daily summary
        ruiyi_pos_deduct_from_daily_summary($order);

        // 2. Set a flag to prevent on_order_cancelled hook from triggering old rectificativa flow
        // This flag MUST be set BEFORE changing status to cancelled
        $order->update_meta_data('_verifactu_pos_rectificativa_in_progress', 'yes');
        $order->save();

        // 3. Set order status to cancelled
        $order->set_status('cancelled', __('Order voided via POS with rectificativa', 'ruiyi-retail-pos'));

        // 4. Store customer data on order
        $order->update_meta_data('_invoice_customer_name', $customer_name);
        $order->update_meta_data('_invoice_customer_company', $company);
        $order->update_meta_data('_invoice_customer_cif', $cif);
        $order->update_meta_data('_invoice_customer_address', $address);
        $order->update_meta_data('_invoice_customer_city', $city);
        $order->update_meta_data('_invoice_customer_postcode', $postcode);

        // 5. Store rectificativa info
        $order->update_meta_data('_verifactu_rectificativa_label', $new_invoice_number);
        $order->update_meta_data('_verifactu_rectificativa_date', $emission_date);
        $order->update_meta_data('_verifactu_rectificativa_original_number', $original_invoice_number);
        $order->update_meta_data('_verifactu_rectificativa_original_date', $original_invoice_date);
        $order->save();

        ruiyi_pos_log("[POS Void+Rectificativa] Generating rectificativa {$new_invoice_number} for order #{$order_id}");

        // 6. Call verifactuqr plugin to generate the rectificativa invoice
        $pdf_url = '';

        if (class_exists('WC_Verifactu_QR')) {
            $verifactu = WC_Verifactu_QR::instance();
            if (method_exists($verifactu, 'generate_pos_rectificativa_invoice')) {
                $result = $verifactu->generate_pos_rectificativa_invoice($order_id, $new_invoice_number, $original_invoice_number, $original_invoice_date, $emission_date);
                if (!is_wp_error($result) && isset($result['pdf_url'])) {
                    $pdf_url = $result['pdf_url'];
                    // Ensure HTTPS
                    $pdf_url = str_replace('http://', 'https://', $pdf_url);
                    ruiyi_pos_log("[POS Void+Rectificativa] PDF URL: {$pdf_url}");
                } else {
                    ruiyi_pos_log("[POS Void+Rectificativa] PDF generation failed or no pdf_url in result");
                }
            }
        }

        if (!empty($pdf_url)) {
            // Increment the rectificativa counter
            $year = date('Y');
            $counter_key = 'ruiyi_rectificativa_counter_' . $year;
            $counter = get_option($counter_key, 0);
            update_option($counter_key, $counter + 1);
            ruiyi_pos_log("[POS Void+Rectificativa] Counter incremented to " . ($counter + 1));

            // Generate clean filename for download
            $download_filename = strtolower($new_invoice_number) . '.pdf';

            wp_send_json_success(array(
                'order_id' => $order_id,
                'action' => 'cancelled_with_rectificativa',
                'message' => __('Order voided and rectificativa generated', 'ruiyi-retail-pos'),
                'rectificativa_number' => $new_invoice_number,
                'pdf_url' => $pdf_url,
                'filename' => $download_filename
            ));
            return;
        }

        // If PDF generation failed, still return cancelled status
        wp_send_json_error(array(
            'message' => __('Failed to generate rectificativa PDF', 'ruiyi-retail-pos')
        ));

    } catch (Exception $e) {
        ruiyi_pos_log('[POS Void+Rectificativa] Error: ' . $e->getMessage());
        wp_send_json_error(array(
            'message' => __('Failed to void order: ', 'ruiyi-retail-pos') . $e->getMessage()
        ));
    }
}

/**
 * 从日结单中扣除作废订单的金额
 * Deduct voided order amount from daily summary
 */
function ruiyi_pos_deduct_from_daily_summary($order) {
    if (!$order) return;

    $order_id = $order->get_id();
    $order_date = $order->get_date_created();
    if (!$order_date) return;

    // 检查订单是否已记录到日结单
    $recorded = $order->get_meta('_recorded_in_daily_summary');
    if ($recorded !== 'yes') {
        ruiyi_pos_log("[POS Void] Order #{$order_id} was not recorded in daily summary, skipping deduction");
        return;
    }

    // 获取订单日期对应的日结单
    $order_date_str = $order_date->format('Y-m-d');
    $daily_data_key = 'ruiyi_retail_daily_summary_' . $order_date_str;
    $daily_data = get_option($daily_data_key);

    if (!$daily_data) {
        ruiyi_pos_log("[POS Void] No daily summary found for date: {$order_date_str}, checking monthly records...");
        // 日结单已结算归档到月结单，尝试从月结单中扣除
        ruiyi_pos_deduct_from_monthly_record($order, $order_date_str);
        return;
    }

    // 获取订单金额和支付方式
    $total_amount = floatval($order->get_total());
    $payment_method = $order->get_payment_method();
    $cash_amount = floatval($order->get_meta('_pos_cash_amount'));
    $card_amount = floatval($order->get_meta('_pos_card_amount'));

    ruiyi_pos_log("[POS Void] Deducting order #{$order_id} from daily summary: Total={$total_amount}, Payment={$payment_method}, Cash={$cash_amount}, Card={$card_amount}");

    // 扣除总金额
    $daily_data['total_amount'] = max(0, $daily_data['total_amount'] - $total_amount);

    // 根据支付方式扣除相应金额
    if ($payment_method === 'mixed' || ($cash_amount > 0 && $card_amount > 0)) {
        // 混合支付
        $daily_data['cash_amount'] = max(0, $daily_data['cash_amount'] - $cash_amount);
        $daily_data['card_amount'] = max(0, $daily_data['card_amount'] - $card_amount);
        $daily_data['mixed_payment_count'] = max(0, $daily_data['mixed_payment_count'] - 1);
        ruiyi_pos_log("[POS Void] Deducted mixed payment: Cash={$cash_amount}, Card={$card_amount}");
    } elseif ($payment_method === 'transfer') {
        // 转账支付
        if (isset($daily_data['transfer_amount'])) {
            $daily_data['transfer_amount'] = max(0, $daily_data['transfer_amount'] - $total_amount);
        }
        ruiyi_pos_log("[POS Void] Deducted transfer payment: {$total_amount}");
    } elseif ($payment_method === 'card' || $payment_method === 'bacs') {
        // 刷卡支付
        $daily_data['card_amount'] = max(0, $daily_data['card_amount'] - $total_amount);
        ruiyi_pos_log("[POS Void] Deducted card payment: {$total_amount}");
    } else {
        // 现金支付（默认）
        $daily_data['cash_amount'] = max(0, $daily_data['cash_amount'] - $total_amount);
        ruiyi_pos_log("[POS Void] Deducted cash payment: {$total_amount}");
    }

    // 扣除产品数量和金额
    foreach ($order->get_items() as $item) {
        $product_name = $item->get_name();
        $quantity = $item->get_quantity();
        $item_total = floatval($item->get_total());

        if (isset($daily_data['products'][$product_name])) {
            $daily_data['products'][$product_name]['quantity'] = max(0, $daily_data['products'][$product_name]['quantity'] - $quantity);
            $daily_data['products'][$product_name]['total'] = max(0, $daily_data['products'][$product_name]['total'] - $item_total);

            // 如果数量为0，移除该产品
            if ($daily_data['products'][$product_name]['quantity'] <= 0) {
                unset($daily_data['products'][$product_name]);
            }
        }
    }

    // 从交易记录中移除该订单
    if (isset($daily_data['transactions']) && is_array($daily_data['transactions'])) {
        $daily_data['transactions'] = array_filter($daily_data['transactions'], function($transaction) use ($order_id) {
            return isset($transaction['order_id']) && $transaction['order_id'] != $order_id;
        });
        $daily_data['transactions'] = array_values($daily_data['transactions']); // 重新索引
    }

    // 添加作废记录
    if (!isset($daily_data['voided_orders'])) {
        $daily_data['voided_orders'] = array();
    }
    $daily_data['voided_orders'][] = array(
        'order_id' => $order_id,
        'amount' => $total_amount,
        'payment_method' => $payment_method,
        'cash_amount' => $cash_amount,
        'card_amount' => $card_amount,
        'voided_at' => current_time('mysql')
    );

    // 更新最后修改时间
    $daily_data['last_updated'] = current_time('mysql');

    // 保存更新后的日结单
    update_option($daily_data_key, $daily_data);

    // 标记订单已从日结单扣除
    $order->update_meta_data('_voided_from_daily_summary', 'yes');
    $order->update_meta_data('_voided_at', current_time('mysql'));
    $order->save();

    ruiyi_pos_log("[POS Void] Successfully deducted order #{$order_id} from daily summary for {$order_date_str}");
}

/**
 * 从月结单中扣除已作废订单的金额
 * Deduct voided order from monthly record (when daily summary has already been settled)
 */
function ruiyi_pos_deduct_from_monthly_record($order, $order_date_str) {
    $order_id = $order->get_id();

    // 查找该日期对应的月结单
    $month_key = date('Y-m', strtotime($order_date_str));
    $month_index_key = 'ruiyi_retail_monthly_index_' . $month_key;
    $month_index = get_option($month_index_key, array());

    if (empty($month_index)) {
        ruiyi_pos_log("[POS Void] No monthly index found for month: {$month_key}");
        return;
    }

    // 找到包含该日期的月结单记录
    $target_settlement_id = null;
    foreach ($month_index as $settlement_id) {
        // settlement_id 格式为 YYYY-MM-DD_HHMMSS
        if (strpos($settlement_id, $order_date_str) === 0) {
            $target_settlement_id = $settlement_id;
            break;
        }
    }

    if (!$target_settlement_id) {
        ruiyi_pos_log("[POS Void] No monthly record found for date: {$order_date_str}");
        return;
    }

    $monthly_record_key = 'ruiyi_retail_monthly_record_' . $target_settlement_id;
    $monthly_record = get_option($monthly_record_key);

    if (!$monthly_record) {
        ruiyi_pos_log("[POS Void] Monthly record data not found: {$monthly_record_key}");
        return;
    }

    // 获取订单金额和支付方式
    $total_amount = floatval($order->get_total());
    $payment_method = $order->get_payment_method();
    $cash_amount = floatval($order->get_meta('_pos_cash_amount'));
    $card_amount = floatval($order->get_meta('_pos_card_amount'));

    ruiyi_pos_log("[POS Void] Deducting order #{$order_id} from monthly record {$target_settlement_id}: Total={$total_amount}, Payment={$payment_method}");

    // 扣除总金额
    $monthly_record['total_amount'] = max(0, floatval($monthly_record['total_amount']) - $total_amount);

    // 根据支付方式扣除相应金额
    if ($payment_method === 'mixed' || ($cash_amount > 0 && $card_amount > 0)) {
        $monthly_record['cash_amount'] = max(0, floatval($monthly_record['cash_amount']) - $cash_amount);
        $monthly_record['card_amount'] = max(0, floatval($monthly_record['card_amount']) - $card_amount);
        $monthly_record['mixed_payment_count'] = max(0, intval($monthly_record['mixed_payment_count']) - 1);
    } elseif ($payment_method === 'transfer') {
        if (isset($monthly_record['transfer_amount'])) {
            $monthly_record['transfer_amount'] = max(0, floatval($monthly_record['transfer_amount']) - $total_amount);
        }
    } elseif ($payment_method === 'card' || $payment_method === 'bacs') {
        $monthly_record['card_amount'] = max(0, floatval($monthly_record['card_amount']) - $total_amount);
    } else {
        $monthly_record['cash_amount'] = max(0, floatval($monthly_record['cash_amount']) - $total_amount);
    }

    // 从交易记录中移除该订单
    if (isset($monthly_record['transactions_data']) && is_array($monthly_record['transactions_data'])) {
        $monthly_record['transactions_data'] = array_filter($monthly_record['transactions_data'], function($transaction) use ($order_id) {
            return isset($transaction['order_id']) && $transaction['order_id'] != $order_id;
        });
        $monthly_record['transactions_data'] = array_values($monthly_record['transactions_data']);
        $monthly_record['transactions_count'] = count($monthly_record['transactions_data']);
    }

    // 扣除产品数据
    if (isset($monthly_record['products_data']) && is_array($monthly_record['products_data'])) {
        foreach ($order->get_items() as $item) {
            $product_name = $item->get_name();
            $quantity = $item->get_quantity();
            $item_total = floatval($item->get_total());

            if (isset($monthly_record['products_data'][$product_name])) {
                $monthly_record['products_data'][$product_name]['quantity'] = max(0, $monthly_record['products_data'][$product_name]['quantity'] - $quantity);
                $monthly_record['products_data'][$product_name]['total'] = max(0, $monthly_record['products_data'][$product_name]['total'] - $item_total);

                if ($monthly_record['products_data'][$product_name]['quantity'] <= 0) {
                    unset($monthly_record['products_data'][$product_name]);
                }
            }
        }
    }

    // 重新计算分类数据
    if (isset($monthly_record['products_data']) && is_array($monthly_record['products_data'])) {
        $categories_data = array();
        foreach ($monthly_record['products_data'] as $product) {
            $category = isset($product['category']) ? $product['category'] : 'Sin categoría';
            if (!isset($categories_data[$category])) {
                $categories_data[$category] = array('name' => $category, 'total' => 0);
            }
            $categories_data[$category]['total'] += floatval($product['total']);
        }
        $monthly_record['categories_data'] = $categories_data;
    }

    // 添加作废记录
    if (!isset($monthly_record['voided_orders'])) {
        $monthly_record['voided_orders'] = array();
    }
    $monthly_record['voided_orders'][] = array(
        'order_id' => $order_id,
        'amount' => $total_amount,
        'payment_method' => $payment_method,
        'cash_amount' => $cash_amount,
        'card_amount' => $card_amount,
        'voided_at' => current_time('mysql')
    );

    // 更新最后修改时间
    $monthly_record['last_updated'] = current_time('mysql');

    // 保存更新后的月结单
    update_option($monthly_record_key, $monthly_record, 'no');

    // 标记订单已从月结单扣除
    $order->update_meta_data('_voided_from_daily_summary', 'yes');
    $order->update_meta_data('_voided_from_monthly_record', $target_settlement_id);
    $order->update_meta_data('_voided_at', current_time('mysql'));
    $order->save();

    ruiyi_pos_log("[POS Void] Successfully deducted order #{$order_id} from monthly record {$target_settlement_id}");
}

/**
 * 获取所有活跃业务员列表
 * Get all active salespersons from Verifactu plugin
 */
function ruiyi_pos_get_salespersons() {
    $salespersons = array();

    // Get salespersons from Verifactu plugin's custom post type
    $args = array(
        'post_type' => 'verifactu_salesrep',
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
        'post_status' => 'publish',
        'meta_query' => array(
            'relation' => 'OR',
            array(
                'key' => '_salesperson_status',
                'value' => '1',
                'compare' => '='
            ),
            array(
                'key' => '_salesperson_status',
                'compare' => 'NOT EXISTS'
            )
        )
    );

    $posts = get_posts($args);

    foreach ($posts as $post) {
        $salespersons[] = array(
            'id' => $post->ID,
            'name' => $post->post_title,
            'employee_id' => get_post_meta($post->ID, '_salesperson_employee_id', true),
            'phone' => get_post_meta($post->ID, '_salesperson_phone', true),
            'email' => get_post_meta($post->ID, '_salesperson_email', true),
            'region' => get_post_meta($post->ID, '_salesperson_region', true),
        );
    }

    wp_send_json_success($salespersons);
}

/**
 * 创建新业务员 (自动创建)
 * Create a new salesperson in Verifactu
 */
function ruiyi_pos_create_salesperson($name) {
    if (empty($name)) {
        return 0;
    }

    // Check if salesperson with same name already exists
    $existing = get_posts(array(
        'post_type' => 'verifactu_salesrep',
        'title' => $name,
        'post_status' => 'publish',
        'posts_per_page' => 1,
    ));

    if (!empty($existing)) {
        return $existing[0]->ID;
    }

    // Create new salesperson post
    $post_id = wp_insert_post(array(
        'post_type' => 'verifactu_salesrep',
        'post_title' => sanitize_text_field($name),
        'post_status' => 'publish',
    ));

    if (is_wp_error($post_id)) {
        ruiyi_pos_log("[POS] Failed to create salesperson: " . $post_id->get_error_message());
        return 0;
    }

    // Set default meta values
    update_post_meta($post_id, '_salesperson_status', '1');
    update_post_meta($post_id, '_salesperson_employee_id', '');
    update_post_meta($post_id, '_salesperson_phone', '');
    update_post_meta($post_id, '_salesperson_email', '');
    update_post_meta($post_id, '_salesperson_region', '');

    return $post_id;
}

/**
 * 搜索客户 (从Verifactu客户管理)
 * Search customers from Verifactu plugin's customer management
 */
function ruiyi_pos_search_customers() {
    $query = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';

    // Allow single digit customer numbers, but require at least 2 chars for text search
    if (strlen($query) < 1 || (strlen($query) < 2 && !is_numeric($query))) {
        wp_send_json_success(array());
        return;
    }

    $customers = array();
    $is_numeric_query = is_numeric($query);

    // If query is purely numeric, search ONLY by customer number (exact match)
    if ($is_numeric_query) {
        $args = array(
            'post_type' => 'verifactu_customer',
            'posts_per_page' => 10,
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_customer_number',
                    'value' => intval($query),
                    'compare' => '=',
                    'type' => 'NUMERIC'
                )
            )
        );

        $posts = get_posts($args);

        foreach ($posts as $post) {
            $customers[] = array(
                'id' => $post->ID,
                'customer_number' => get_post_meta($post->ID, '_customer_number', true),
                'company' => $post->post_title,
                'customer_name' => get_post_meta($post->ID, '_customer_name', true),
                'cif' => get_post_meta($post->ID, '_customer_cif', true),
                'phone' => get_post_meta($post->ID, '_customer_phone', true),
                'address' => get_post_meta($post->ID, '_customer_address', true),
                'city' => get_post_meta($post->ID, '_customer_city', true),
                'state' => get_post_meta($post->ID, '_customer_state', true),
                'postcode' => get_post_meta($post->ID, '_customer_postcode', true),
            );
        }

        wp_send_json_success($customers);
        return;
    }

    // For text queries, search in title and meta fields
    $args = array(
        'post_type' => 'verifactu_customer',
        'posts_per_page' => 20,
        'post_status' => 'publish',
        's' => $query, // Search in title (company name)
    );

    $posts = get_posts($args);

    // Also search by customer name, CIF, phone in meta fields
    $meta_args = array(
        'post_type' => 'verifactu_customer',
        'posts_per_page' => 20,
        'post_status' => 'publish',
        'meta_query' => array(
            'relation' => 'OR',
            array(
                'key' => '_customer_name',
                'value' => $query,
                'compare' => 'LIKE'
            ),
            array(
                'key' => '_customer_cif',
                'value' => $query,
                'compare' => 'LIKE'
            ),
            array(
                'key' => '_customer_phone',
                'value' => $query,
                'compare' => 'LIKE'
            )
        )
    );

    $meta_posts = get_posts($meta_args);

    // Merge and deduplicate results
    $all_posts = array_merge($posts, $meta_posts);
    $seen_ids = array();

    foreach ($all_posts as $post) {
        if (in_array($post->ID, $seen_ids)) continue;
        $seen_ids[] = $post->ID;

        $customers[] = array(
            'id' => $post->ID,
            'customer_number' => get_post_meta($post->ID, '_customer_number', true),
            'company' => $post->post_title,
            'customer_name' => get_post_meta($post->ID, '_customer_name', true),
            'cif' => get_post_meta($post->ID, '_customer_cif', true),
            'phone' => get_post_meta($post->ID, '_customer_phone', true),
            'address' => get_post_meta($post->ID, '_customer_address', true),
            'city' => get_post_meta($post->ID, '_customer_city', true),
            'state' => get_post_meta($post->ID, '_customer_state', true),
            'postcode' => get_post_meta($post->ID, '_customer_postcode', true),
        );
    }

    wp_send_json_success($customers);
}

/**
 * 按手机号搜索客户（支持前4位或后4位匹配）
 */
function ruiyi_pos_search_customer_by_phone() {
    $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
    $digits = preg_replace('/\D/', '', $phone);

    if (strlen($digits) < 4) {
        wp_send_json_success(array());
        return;
    }

    global $wpdb;
    $like = '%' . $wpdb->esc_like($digits) . '%';
    $post_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_customer_phone' AND meta_value LIKE %s LIMIT 10",
        $like
    ));

    $customers = array();
    foreach ($post_ids as $pid) {
        $post = get_post($pid);
        if (!$post || $post->post_type !== 'verifactu_customer' || $post->post_status !== 'publish') continue;
        $customers[] = array(
            'id'              => $pid,
            'customer_number' => get_post_meta($pid, '_customer_number', true),
            'company'         => $post->post_title,
            'customer_name'   => get_post_meta($pid, '_customer_name', true),
            'cif'             => get_post_meta($pid, '_customer_cif', true),
            'address'         => get_post_meta($pid, '_customer_address', true),
            'city'            => get_post_meta($pid, '_customer_city', true),
            'postcode'        => get_post_meta($pid, '_customer_postcode', true),
            'phone'           => get_post_meta($pid, '_customer_phone', true),
        );
    }

    wp_send_json_success($customers);
}

/**
 * 获取客户详细信息
 * Get customer data by ID from Verifactu plugin
 */
function ruiyi_pos_get_customer_data() {
    $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;

    if (!$customer_id) {
        wp_send_json_error(array('message' => __('Customer ID is required', 'ruiyi-retail-pos')));
        return;
    }

    $post = get_post($customer_id);
    if (!$post || $post->post_type !== 'verifactu_customer') {
        wp_send_json_error(array('message' => __('Customer not found', 'ruiyi-retail-pos')));
        return;
    }

    $customer_data = array(
        'id' => $customer_id,
        'customer_number' => get_post_meta($customer_id, '_customer_number', true),
        'company' => $post->post_title,
        'customer_name' => get_post_meta($customer_id, '_customer_name', true),
        'contact_person' => get_post_meta($customer_id, '_customer_contact_person', true),
        'cif' => get_post_meta($customer_id, '_customer_cif', true),
        'address' => get_post_meta($customer_id, '_customer_address', true),
        'address_2' => get_post_meta($customer_id, '_customer_address_2', true),
        'city' => get_post_meta($customer_id, '_customer_city', true),
        'state' => get_post_meta($customer_id, '_customer_state', true),
        'country' => get_post_meta($customer_id, '_customer_country', true),
        'postcode' => get_post_meta($customer_id, '_customer_postcode', true),
        'phone' => get_post_meta($customer_id, '_customer_phone', true),
        'email' => get_post_meta($customer_id, '_customer_email', true),
        'payment_method' => get_post_meta($customer_id, '_customer_payment_method', true),
        'salesperson_id' => get_post_meta($customer_id, '_customer_salesperson_id', true),
    );

    wp_send_json_success($customer_data);
}

/**
 * 检查客户编号是否已存在
 * Check if customer number already exists
 */
function ruiyi_pos_check_customer_number() {
    $customer_number = isset($_POST['customer_number']) ? intval($_POST['customer_number']) : 0;
    $exclude_id = isset($_POST['exclude_id']) ? intval($_POST['exclude_id']) : 0;

    if (!$customer_number) {
        wp_send_json_success(array('exists' => false));
        return;
    }

    // Search for existing customer with this number
    $args = array(
        'post_type' => 'verifactu_customer',
        'posts_per_page' => 1,
        'post_status' => 'publish',
        'meta_query' => array(
            array(
                'key' => '_customer_number',
                'value' => $customer_number,
                'compare' => '='
            )
        )
    );

    // Exclude specific customer ID if provided (for edit mode)
    if ($exclude_id > 0) {
        $args['post__not_in'] = array($exclude_id);
    }

    $posts = get_posts($args);

    if (!empty($posts)) {
        $customer = $posts[0];
        wp_send_json_success(array(
            'exists' => true,
            'customer_id' => $customer->ID,
            'customer_name' => $customer->post_title,
            'customer_cif' => get_post_meta($customer->ID, '_customer_cif', true)
        ));
    } else {
        wp_send_json_success(array('exists' => false));
    }
}

/**
 * 获取所有客户（含客户编号）
 * Get all customers with customer number for customer management
 */
function ruiyi_pos_get_all_customers() {
    // Check permissions
    if (!current_user_can('manage_woocommerce') && !ruiyi_pos_check_access()) {
        wp_send_json_error(array('message' => __('Permission denied', 'ruiyi-retail-pos')));
        return;
    }

    // 🔥 修复：不按meta_key排序，避免没有customer_number的客户不显示
    $args = array(
        'post_type' => 'verifactu_customer',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'orderby' => 'title',
        'order' => 'ASC',
    );

    $posts = get_posts($args);
    $customers = array();

    foreach ($posts as $post) {
        $customers[] = array(
            'id' => $post->ID,
            'customer_number' => get_post_meta($post->ID, '_customer_number', true),
            'company' => $post->post_title,
            'customer_name' => get_post_meta($post->ID, '_customer_name', true),
            'cif' => get_post_meta($post->ID, '_customer_cif', true),
            'address' => get_post_meta($post->ID, '_customer_address', true),
            'city' => get_post_meta($post->ID, '_customer_city', true),
            'state' => get_post_meta($post->ID, '_customer_state', true),
            'postcode' => get_post_meta($post->ID, '_customer_postcode', true),
            'phone' => get_post_meta($post->ID, '_customer_phone', true),
            'email' => get_post_meta($post->ID, '_customer_email', true),
        );
    }

    // 🔥 排序：有客户编号的在前，按编号升序；没有编号的按公司名排序
    usort($customers, function($a, $b) {
        $numA = intval($a['customer_number']);
        $numB = intval($b['customer_number']);

        // 都有编号，按编号排序
        if ($numA > 0 && $numB > 0) {
            return $numA - $numB;
        }
        // 只有a有编号，a在前
        if ($numA > 0 && $numB == 0) {
            return -1;
        }
        // 只有b有编号，b在前
        if ($numA == 0 && $numB > 0) {
            return 1;
        }
        // 都没有编号，按公司名排序
        return strcasecmp($a['company'], $b['company']);
    });

    wp_send_json_success($customers);
}

/**
 * 添加新客户
 * Add a new customer
 */
function ruiyi_pos_add_invoice_customer() {
    // Check permissions
    if (!current_user_can('manage_woocommerce') && !ruiyi_pos_check_access()) {
        wp_send_json_error(array('message' => __('Permission denied', 'ruiyi-retail-pos')));
        return;
    }

    // Validate required fields
    $company = isset($_POST['company']) ? sanitize_text_field($_POST['company']) : '';
    $cif = isset($_POST['cif']) ? sanitize_text_field($_POST['cif']) : '';
    $customer_number = isset($_POST['customer_number']) ? intval($_POST['customer_number']) : 0;

    if (empty($company)) {
        wp_send_json_error(array('message' => __('Company/Customer name is required', 'ruiyi-retail-pos')));
        return;
    }

    // Check if customer number already exists (if provided)
    if ($customer_number > 0) {
        $existing = get_posts(array(
            'post_type' => 'verifactu_customer',
            'meta_key' => '_customer_number',
            'meta_value' => $customer_number,
            'posts_per_page' => 1,
        ));
        if (!empty($existing)) {
            wp_send_json_error(array('message' => __('Customer number already exists', 'ruiyi-retail-pos')));
            return;
        }
    }

    // Create new customer post
    $post_data = array(
        'post_type' => 'verifactu_customer',
        'post_title' => $company,
        'post_status' => 'publish',
    );

    $post_id = wp_insert_post($post_data);

    if (is_wp_error($post_id)) {
        wp_send_json_error(array('message' => $post_id->get_error_message()));
        return;
    }

    // Save meta fields
    if ($customer_number > 0) {
        update_post_meta($post_id, '_customer_number', $customer_number);
    }
    update_post_meta($post_id, '_customer_name', $company);
    update_post_meta($post_id, '_customer_cif', $cif);

    if (isset($_POST['address'])) {
        update_post_meta($post_id, '_customer_address', sanitize_text_field($_POST['address']));
    }
    if (isset($_POST['city'])) {
        update_post_meta($post_id, '_customer_city', sanitize_text_field($_POST['city']));
    }
    if (isset($_POST['state'])) {
        update_post_meta($post_id, '_customer_state', sanitize_text_field($_POST['state']));
    }
    if (isset($_POST['postcode'])) {
        update_post_meta($post_id, '_customer_postcode', sanitize_text_field($_POST['postcode']));
    }
    if (isset($_POST['phone'])) {
        update_post_meta($post_id, '_customer_phone', sanitize_text_field($_POST['phone']));
    }
    if (isset($_POST['email'])) {
        update_post_meta($post_id, '_customer_email', sanitize_email($_POST['email']));
    }
    if (isset($_POST['country'])) {
        update_post_meta($post_id, '_customer_country', sanitize_text_field($_POST['country']));
    }

    wp_send_json_success(array(
        'message' => __('Customer added successfully', 'ruiyi-retail-pos'),
        'customer_id' => $post_id,
    ));
}

/**
 * 更新客户信息
 * Update customer information
 */
function ruiyi_pos_update_invoice_customer() {
    // Check permissions
    if (!current_user_can('manage_woocommerce') && !ruiyi_pos_check_access()) {
        wp_send_json_error(array('message' => __('Permission denied', 'ruiyi-retail-pos')));
        return;
    }

    $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;

    if (!$customer_id) {
        wp_send_json_error(array('message' => __('Customer ID is required', 'ruiyi-retail-pos')));
        return;
    }

    // Verify customer exists
    $post = get_post($customer_id);
    if (!$post || $post->post_type !== 'verifactu_customer') {
        wp_send_json_error(array('message' => __('Customer not found', 'ruiyi-retail-pos')));
        return;
    }

    // Validate required fields
    $company = isset($_POST['company']) ? sanitize_text_field($_POST['company']) : '';
    $cif = isset($_POST['cif']) ? sanitize_text_field($_POST['cif']) : '';
    $customer_number = isset($_POST['customer_number']) ? intval($_POST['customer_number']) : 0;

    if (empty($company) || empty($cif)) {
        wp_send_json_error(array('message' => __('Company name and CIF are required', 'ruiyi-retail-pos')));
        return;
    }

    // Check if customer number already exists (excluding current customer)
    if ($customer_number > 0) {
        $existing = get_posts(array(
            'post_type' => 'verifactu_customer',
            'meta_key' => '_customer_number',
            'meta_value' => $customer_number,
            'posts_per_page' => 1,
            'exclude' => array($customer_id),
        ));
        if (!empty($existing)) {
            wp_send_json_error(array('message' => __('Customer number already exists', 'ruiyi-retail-pos')));
            return;
        }
    }

    // Update post title (company name)
    wp_update_post(array(
        'ID' => $customer_id,
        'post_title' => $company,
    ));

    // Update meta fields
    update_post_meta($customer_id, '_customer_number', $customer_number > 0 ? $customer_number : '');
    update_post_meta($customer_id, '_customer_name', $company);
    update_post_meta($customer_id, '_customer_cif', $cif);

    if (isset($_POST['address'])) {
        update_post_meta($customer_id, '_customer_address', sanitize_text_field($_POST['address']));
    }
    if (isset($_POST['city'])) {
        update_post_meta($customer_id, '_customer_city', sanitize_text_field($_POST['city']));
    }
    if (isset($_POST['state'])) {
        update_post_meta($customer_id, '_customer_state', sanitize_text_field($_POST['state']));
    }
    if (isset($_POST['postcode'])) {
        update_post_meta($customer_id, '_customer_postcode', sanitize_text_field($_POST['postcode']));
    }
    if (isset($_POST['phone'])) {
        update_post_meta($customer_id, '_customer_phone', sanitize_text_field($_POST['phone']));
    }
    if (isset($_POST['email'])) {
        update_post_meta($customer_id, '_customer_email', sanitize_email($_POST['email']));
    }
    if (isset($_POST['country'])) {
        update_post_meta($customer_id, '_customer_country', sanitize_text_field($_POST['country']));
    }

    wp_send_json_success(array(
        'message' => __('Customer updated successfully', 'ruiyi-retail-pos'),
    ));
}

/**
 * 删除客户
 * Delete a customer
 */
function ruiyi_pos_delete_invoice_customer() {
    // Check permissions - only admin can delete
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('Permission denied', 'ruiyi-retail-pos')));
        return;
    }

    $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;

    if (!$customer_id) {
        wp_send_json_error(array('message' => __('Customer ID is required', 'ruiyi-retail-pos')));
        return;
    }

    // Verify customer exists
    $post = get_post($customer_id);
    if (!$post || $post->post_type !== 'verifactu_customer') {
        wp_send_json_error(array('message' => __('Customer not found', 'ruiyi-retail-pos')));
        return;
    }

    // Delete the customer
    $result = wp_delete_post($customer_id, true);

    if ($result) {
        wp_send_json_success(array(
            'message' => __('Customer deleted successfully', 'ruiyi-retail-pos'),
        ));
    } else {
        wp_send_json_error(array('message' => __('Failed to delete customer', 'ruiyi-retail-pos')));
    }
}

/**
 * Toggle wholesale albaran feature setting
 * 切换批发商Albarán功能设置
 */
function ruiyi_pos_toggle_wholesale_albaran() {
    // Check permissions - only admin can change this setting
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('Permission denied', 'ruiyi-retail-pos')));
        return;
    }

    $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';

    // Save to WordPress options
    update_option('ruiyi_retail_wholesale_albaran_enabled', $enabled);

    wp_send_json_success(array(
        'message' => __('Setting saved', 'ruiyi-retail-pos'),
        'enabled' => $enabled
    ));
}

/**
 * Toggle transfer payment feature setting
 * 切换转账支付功能设置
 */
function ruiyi_pos_toggle_transfer_payment() {
    // Check permissions - only admin can change this setting
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('Permission denied', 'ruiyi-retail-pos')));
        return;
    }

    $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';

    // Save to WordPress options
    update_option('ruiyi_retail_transfer_payment_enabled', $enabled);

    wp_send_json_success(array(
        'message' => __('Setting saved', 'ruiyi-retail-pos'),
        'enabled' => $enabled
    ));
}

/**
 * Toggle wholesale invoice feature setting
 * 切换批发商发票功能设置
 */
function ruiyi_pos_toggle_wholesale_invoice() {
    // Check permissions - only admin can change this setting
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('Permission denied', 'ruiyi-retail-pos')));
        return;
    }

    $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';

    // Save to WordPress options
    update_option('ruiyi_retail_wholesale_invoice_enabled', $enabled);

    wp_send_json_success(array(
        'message' => __('Setting saved', 'ruiyi-retail-pos'),
        'enabled' => $enabled
    ));
}

/**
 * Toggle payment method visibility setting
 * 切换支付方式可见性设置
 */
function ruiyi_pos_toggle_payment_visibility() {
    // Check permissions - allow users who can access POS to change display settings
    if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
        wp_send_json_error(array('message' => __('Permission denied', 'ruiyi-retail-pos')));
        return;
    }

    $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
    $visible = isset($_POST['visible']) && $_POST['visible'] === '1';

    // Map type to option key
    $option_map = array(
        'cash' => 'ruiyi_retail_cash_payment_visible',
        'card' => 'ruiyi_retail_card_payment_visible',
        'points' => 'ruiyi_retail_points_payment_visible',
        'mixed' => 'ruiyi_retail_mixed_payment_visible',
        'customer_info' => 'ruiyi_retail_customer_info_visible',
        'print_receipt' => 'ruiyi_retail_print_receipt_visible',
    );

    if (!isset($option_map[$type])) {
        wp_send_json_error(array('message' => __('Invalid payment type', 'ruiyi-retail-pos')));
        return;
    }

    // Save to WordPress options as string '1' or '0' for consistent retrieval
    $save_value = $visible ? '1' : '0';
    $result = update_option($option_map[$type], $save_value);

    // Log for debugging
    ruiyi_pos_log("RUIYI POS: toggle_payment_visibility - type: $type, visible: $save_value, result: " . ($result ? 'success' : 'failed or unchanged'));

    wp_send_json_success(array(
        'message' => __('Setting saved', 'ruiyi-retail-pos'),
        'type' => $type,
        'visible' => $visible,
        'saved_value' => $save_value
    ));
}

/**
 * Get next rectificativa invoice number (preview only, does not increment counter)
 * 获取下一个退货发票号码（仅预览，不增加计数器）
 */
function ruiyi_pos_get_rectificativa_number() {
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $original_invoice = isset($_POST['original_invoice']) ? sanitize_text_field($_POST['original_invoice']) : '';

    if (!$order_id) {
        wp_send_json_error(array('message' => __('Order ID is required', 'ruiyi-retail-pos')));
        return;
    }

    // Get the current year
    $year = date('Y');

    // Get the current counter value (do NOT increment here - only preview)
    $counter_key = 'ruiyi_rectificativa_counter_' . $year;
    $counter = get_option($counter_key, 0);
    $next_counter = $counter + 1; // Preview the next number

    // Format: Fact_YYYYNNN_RT (e.g., Fact_2026001_RT)
    $rectificativa_number = sprintf('Fact_%s%03d_RT', $year, $next_counter);

    // DO NOT update counter here - only update when actually generating

    wp_send_json_success(array(
        'rectificativa_number' => $rectificativa_number,
        'counter' => $next_counter
    ));
}

/**
 * Generate rectificativa invoice
 * 生成退货发票
 */
function ruiyi_pos_generate_rectificativa_invoice() {
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $new_invoice_number = isset($_POST['new_invoice_number']) ? sanitize_text_field($_POST['new_invoice_number']) : '';
    $original_invoice_number = isset($_POST['original_invoice_number']) ? sanitize_text_field($_POST['original_invoice_number']) : '';
    $original_invoice_date = isset($_POST['original_invoice_date']) ? sanitize_text_field($_POST['original_invoice_date']) : '';
    $emission_date = isset($_POST['emission_date']) ? sanitize_text_field($_POST['emission_date']) : '';

    if (!$order_id || !$new_invoice_number) {
        wp_send_json_error(array('message' => __('Missing required parameters', 'ruiyi-retail-pos')));
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => __('Order not found', 'ruiyi-retail-pos')));
        return;
    }

    try {
        // Store rectificativa info on the order
        $order->update_meta_data('_verifactu_rectificativa_label', $new_invoice_number);
        $order->update_meta_data('_verifactu_rectificativa_date', $emission_date);
        $order->update_meta_data('_verifactu_rectificativa_original_number', $original_invoice_number);
        $order->update_meta_data('_verifactu_rectificativa_original_date', $original_invoice_date);
        $order->save();

        // Call verifactuqr plugin to generate the rectificativa invoice
        $pdf_url = '';

        // Check if verifactuqr plugin function exists and call it
        if (class_exists('WC_Verifactu_QR')) {
            $verifactu = WC_Verifactu_QR::instance();
            if (method_exists($verifactu, 'generate_pos_rectificativa_invoice')) {
                $result = $verifactu->generate_pos_rectificativa_invoice($order_id, $new_invoice_number, $original_invoice_number, $original_invoice_date, $emission_date);
                if (!is_wp_error($result) && isset($result['pdf_url'])) {
                    $pdf_url = $result['pdf_url'];
                    // Ensure HTTPS (like regular invoice)
                    $pdf_url = str_replace('http://', 'https://', $pdf_url);
                    ruiyi_pos_log("[POS Rectificativa] PDF URL: {$pdf_url}");
                } else {
                    ruiyi_pos_log("[POS Rectificativa] PDF generation failed or no pdf_url in result");
                }
            }
        }

        if (empty($pdf_url)) {
            wp_send_json_error(array(
                'message' => __('Failed to generate PDF', 'ruiyi-retail-pos')
            ));
            return;
        }

        // 🔥 NOW increment the rectificativa counter (only after successful PDF generation)
        $year = date('Y');
        $counter_key = 'ruiyi_rectificativa_counter_' . $year;
        $counter = get_option($counter_key, 0);
        update_option($counter_key, $counter + 1);

        // Log success
        ruiyi_pos_log("[POS Rectificativa] Generated rectificativa invoice {$new_invoice_number} for order #{$order_id}");
        ruiyi_pos_log("[POS Rectificativa] Counter incremented to " . ($counter + 1));

        // Generate clean filename for download (like regular invoice - without timestamp)
        $download_filename = strtolower($new_invoice_number) . '.pdf';

        wp_send_json_success(array(
            'message' => __('Rectificativa invoice generated', 'ruiyi-retail-pos'),
            'order_id' => $order_id,
            'rectificativa_number' => $new_invoice_number,
            'pdf_url' => $pdf_url,
            'filename' => $download_filename
        ));

    } catch (Exception $e) {
        ruiyi_pos_log("[POS Rectificativa] Error: " . $e->getMessage());
        wp_send_json_error(array(
            'message' => __('Failed to generate rectificativa invoice: ', 'ruiyi-retail-pos') . $e->getMessage()
        ));
    }
}

/**
 * Save product number shortcut key setting
 * 保存产品编号快捷键设置
 */
function ruiyi_pos_save_product_number_key() {
    // Check permissions - only admin can change this setting
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('Permission denied', 'ruiyi-retail-pos')));
        return;
    }

    $key = isset($_POST['key']) ? sanitize_text_field($_POST['key']) : 'm';

    // Validate: must be a single character
    if (strlen($key) !== 1) {
        wp_send_json_error(array('message' => __('Invalid key', 'ruiyi-retail-pos')));
        return;
    }

    // Save to WordPress options
    update_option('ruiyi_retail_product_number_key', strtolower($key));

    wp_send_json_success(array(
        'message' => __('Setting saved', 'ruiyi-retail-pos'),
        'key' => strtolower($key)
    ));
}

/**
 * 🔥 统一保存所有后台设置
 * Save all backend settings at once
 */
function ruiyi_pos_save_all_backend_settings() {
    // Check permissions - only admin can change these settings
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('Permission denied', 'ruiyi-retail-pos')));
        return;
    }

    // Get settings from POST
    $product_number_key = isset($_POST['product_number_key']) ? sanitize_text_field($_POST['product_number_key']) : 'm';
    $wholesale_albaran_enabled = isset($_POST['wholesale_albaran_enabled']) && $_POST['wholesale_albaran_enabled'] === '1';
    $transfer_payment_enabled = isset($_POST['transfer_payment_enabled']) && $_POST['transfer_payment_enabled'] === '1';
    $wholesale_invoice_enabled = isset($_POST['wholesale_invoice_enabled']) && $_POST['wholesale_invoice_enabled'] === '1';
    $last_order_display_enabled = isset($_POST['last_order_display_enabled']) && $_POST['last_order_display_enabled'] === '1';
    $auto_daily_settlement_enabled = isset($_POST['auto_daily_settlement_enabled']) && $_POST['auto_daily_settlement_enabled'] === '1';
    $auto_daily_settlement_time = isset($_POST['auto_daily_settlement_time']) ? sanitize_text_field($_POST['auto_daily_settlement_time']) : '00:00';

    // Validate product number key: must be a single character
    if (strlen($product_number_key) !== 1) {
        $product_number_key = 'm'; // Default to 'm' if invalid
    }

    // Validate time format (HH:MM)
    if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $auto_daily_settlement_time)) {
        $auto_daily_settlement_time = '00:00'; // Default to midnight if invalid
    }

    // Save all settings
    update_option('ruiyi_retail_product_number_key', strtolower($product_number_key));
    update_option('ruiyi_retail_wholesale_albaran_enabled', $wholesale_albaran_enabled);
    update_option('ruiyi_retail_transfer_payment_enabled', $transfer_payment_enabled);
    update_option('ruiyi_retail_wholesale_invoice_enabled', $wholesale_invoice_enabled);
    update_option('ruiyi_retail_last_order_display_enabled', $last_order_display_enabled);
    update_option('ruiyi_retail_auto_daily_settlement_enabled', $auto_daily_settlement_enabled);
    update_option('ruiyi_retail_auto_daily_settlement_time', $auto_daily_settlement_time);

    ruiyi_pos_log("RUIYI POS: Backend settings saved - Product Key: $product_number_key, Wholesale: " . ($wholesale_albaran_enabled ? 'ON' : 'OFF') . ", Transfer: " . ($transfer_payment_enabled ? 'ON' : 'OFF') . ", LastOrderDisplay: " . ($last_order_display_enabled ? 'ON' : 'OFF') . ", AutoDailySettlement: " . ($auto_daily_settlement_enabled ? 'ON' : 'OFF') . " @ " . $auto_daily_settlement_time);

    wp_send_json_success(array(
        'message' => __('All settings saved', 'ruiyi-retail-pos'),
        'product_number_key' => strtolower($product_number_key),
        'wholesale_albaran_enabled' => $wholesale_albaran_enabled,
        'transfer_payment_enabled' => $transfer_payment_enabled,
        'wholesale_invoice_enabled' => $wholesale_invoice_enabled,
        'last_order_display_enabled' => $last_order_display_enabled,
        'auto_daily_settlement_enabled' => $auto_daily_settlement_enabled,
        'auto_daily_settlement_time' => $auto_daily_settlement_time
    ));
}

/**
 * 🔥 获取公司/Emisor设置
 * Get company/emitter settings from Verifactu plugin
 */
function ruiyi_pos_get_company_settings() {
    // Check permissions
    if (!current_user_can('manage_woocommerce') && !ruiyi_pos_check_access()) {
        wp_send_json_error(array('message' => __('Permission denied', 'ruiyi-retail-pos')));
        return;
    }

    // Get Verifactu settings
    $verifactu_settings = get_option('wc_verifactu_qr_settings', array());

    // Get bank settings from POS options
    $bank_settings = get_option('ruiyi_pos_default_bank', array());

    $company_data = array(
        // Emitter info from Verifactu
        'emitter_name' => isset($verifactu_settings['emitter_name']) ? $verifactu_settings['emitter_name'] : get_bloginfo('name'),
        'emitter_vat' => isset($verifactu_settings['emitter_vat']) ? $verifactu_settings['emitter_vat'] : '',
        'emitter_phone' => isset($verifactu_settings['emitter_phone']) ? $verifactu_settings['emitter_phone'] : '',
        'emitter_address_1' => isset($verifactu_settings['emitter_address_1']) ? $verifactu_settings['emitter_address_1'] : get_option('woocommerce_store_address', ''),
        'emitter_address_2' => isset($verifactu_settings['emitter_address_2']) ? $verifactu_settings['emitter_address_2'] : get_option('woocommerce_store_address_2', ''),
        'emitter_city' => isset($verifactu_settings['emitter_city']) ? $verifactu_settings['emitter_city'] : get_option('woocommerce_store_city', ''),
        'emitter_postcode' => isset($verifactu_settings['emitter_postcode']) ? $verifactu_settings['emitter_postcode'] : get_option('woocommerce_store_postcode', ''),
        'emitter_country' => isset($verifactu_settings['emitter_country']) ? $verifactu_settings['emitter_country'] : 'ES',
        // Bank info
        'default_bank_name' => isset($bank_settings['bank_name']) ? $bank_settings['bank_name'] : '',
        'default_bank_iban' => isset($bank_settings['bank_iban']) ? $bank_settings['bank_iban'] : '',
        'default_bank_bic' => isset($bank_settings['bank_bic']) ? $bank_settings['bank_bic'] : '',
        // Receipt settings
        'receipt_store_name' => get_option('ruiyi_pos_store_name', ''),
        'receipt_footer' => get_option('ruiyi_pos_store_receipt_footer', ''),
        'auto_print_receipt' => get_option('ruiyi_pos_auto_print_receipt', '1') === '1',
    );

    wp_send_json_success(array(
        'settings' => $company_data
    ));
}

/**
 * 🔥 保存公司/Emisor设置
 * Save company/emitter settings to Verifactu plugin
 */
function ruiyi_pos_save_company_settings() {
    // Check permissions - only admin can change these settings
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('Permission denied', 'ruiyi-retail-pos')));
        return;
    }

    // Get current Verifactu settings
    $verifactu_settings = get_option('wc_verifactu_qr_settings', array());

    // Update emitter settings
    $verifactu_settings['emitter_name'] = isset($_POST['emitter_name']) ? sanitize_text_field($_POST['emitter_name']) : '';
    $verifactu_settings['emitter_vat'] = isset($_POST['emitter_vat']) ? sanitize_text_field($_POST['emitter_vat']) : '';
    $verifactu_settings['emitter_phone'] = isset($_POST['emitter_phone']) ? sanitize_text_field($_POST['emitter_phone']) : '';
    $verifactu_settings['emitter_address_1'] = isset($_POST['emitter_address_1']) ? sanitize_text_field($_POST['emitter_address_1']) : '';
    $verifactu_settings['emitter_address_2'] = isset($_POST['emitter_address_2']) ? sanitize_text_field($_POST['emitter_address_2']) : '';
    $verifactu_settings['emitter_city'] = isset($_POST['emitter_city']) ? sanitize_text_field($_POST['emitter_city']) : '';
    $verifactu_settings['emitter_postcode'] = isset($_POST['emitter_postcode']) ? sanitize_text_field($_POST['emitter_postcode']) : '';
    $verifactu_settings['emitter_country'] = isset($_POST['emitter_country']) ? strtoupper(sanitize_text_field($_POST['emitter_country'])) : 'ES';

    // Save Verifactu settings
    update_option('wc_verifactu_qr_settings', $verifactu_settings);

    // Save bank settings separately
    $bank_settings = array(
        'bank_name' => isset($_POST['default_bank_name']) ? sanitize_text_field($_POST['default_bank_name']) : '',
        'bank_iban' => isset($_POST['default_bank_iban']) ? sanitize_text_field($_POST['default_bank_iban']) : '',
        'bank_bic' => isset($_POST['default_bank_bic']) ? sanitize_text_field($_POST['default_bank_bic']) : '',
    );
    update_option('ruiyi_pos_default_bank', $bank_settings);

    // Save receipt settings
    if (isset($_POST['receipt_store_name'])) {
        update_option('ruiyi_pos_store_name', sanitize_text_field($_POST['receipt_store_name']));
    }
    if (isset($_POST['receipt_footer'])) {
        update_option('ruiyi_pos_store_receipt_footer', sanitize_text_field($_POST['receipt_footer']));
    }
    if (isset($_POST['auto_print_receipt'])) {
        update_option('ruiyi_pos_auto_print_receipt', $_POST['auto_print_receipt'] === '1' ? '1' : '0');
    }

    ruiyi_pos_log("RUIYI POS: Company settings saved - Name: " . $verifactu_settings['emitter_name'] . ", VAT: " . $verifactu_settings['emitter_vat']);

    wp_send_json_success(array(
        'message' => __('Company settings saved successfully', 'ruiyi-retail-pos')
    ));
}

/**
 * 获取PDF发票Código列显示设置
 */
function ruiyi_pos_get_pdf_codigo_setting() {
    if (!current_user_can('manage_woocommerce') && !ruiyi_pos_check_access()) {
        wp_send_json_error(array('message' => __('Permission denied', 'ruiyi-retail-pos')));
        return;
    }
    wp_send_json_success(array(
        'show_pdf_codigo' => (bool) get_option('ruiyi_pos_show_pdf_codigo', false)
    ));
}

/**
 * 保存PDF发票Código列显示设置
 */
function ruiyi_pos_save_pdf_codigo_setting() {
    if (!current_user_can('manage_woocommerce') && !ruiyi_pos_check_access()) {
        wp_send_json_error(array('message' => __('Permission denied', 'ruiyi-retail-pos')));
        return;
    }
    $value = isset($_POST['show_pdf_codigo']) && $_POST['show_pdf_codigo'] === '1';
    update_option('ruiyi_pos_show_pdf_codigo', $value);
    wp_send_json_success(array(
        'message' => __('Setting saved', 'ruiyi-retail-pos'),
        'show_pdf_codigo' => $value
    ));
}

/**
 * 🔥 获取工具箱设置
 * Get toolbox visibility settings
 */
function ruiyi_pos_get_toolbox_settings() {
    $settings = get_option('ruiyi_pos_toolbox_settings', array(
        'show_hold_order' => true,
        'show_view_held' => true,
        'show_clear_cart' => true,
        'show_print_last' => true
    ));

    wp_send_json_success(array(
        'settings' => $settings
    ));
}

/**
 * 🔥 保存工具箱设置
 * Save toolbox visibility settings
 */
function ruiyi_pos_save_toolbox_settings() {
    // Check permissions
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('Permission denied', 'ruiyi-retail-pos')));
        return;
    }

    $settings_json = isset($_POST['settings']) ? $_POST['settings'] : '{}';
    $settings = json_decode(stripslashes($settings_json), true);

    if (!is_array($settings)) {
        wp_send_json_error(array('message' => __('Invalid settings data', 'ruiyi-retail-pos')));
        return;
    }

    $toolbox_settings = array(
        'show_hold_order' => isset($settings['show_hold_order']) ? (bool)$settings['show_hold_order'] : true,
        'show_view_held' => isset($settings['show_view_held']) ? (bool)$settings['show_view_held'] : true,
        'show_clear_cart' => isset($settings['show_clear_cart']) ? (bool)$settings['show_clear_cart'] : true,
        'show_print_last' => isset($settings['show_print_last']) ? (bool)$settings['show_print_last'] : true
    );

    update_option('ruiyi_pos_toolbox_settings', $toolbox_settings);

    ruiyi_pos_log("RUIYI POS: Toolbox settings saved - " . print_r($toolbox_settings, true));

    wp_send_json_success(array(
        'message' => __('Toolbox settings saved successfully', 'ruiyi-retail-pos'),
        'settings' => $toolbox_settings
    ));
}

/**
 * 获取所有产品分类及其税率设置
 * Get all product categories with their tax rate settings
 */
function ruiyi_pos_get_tax_settings() {
    $categories = get_terms(array(
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
    ));

    $result = array();
    if (!is_wp_error($categories)) {
        foreach ($categories as $cat) {
            $option_name = 'ruiyi_tax_rate_cat_' . $cat->term_id;
            $tax_rate = get_option($option_name, '');
            $result[] = array(
                'id' => $cat->term_id,
                'name' => $cat->name,
                'slug' => $cat->slug,
                'tax_rate' => $tax_rate !== '' ? floatval($tax_rate) : '',
                'count' => $cat->count,
            );
        }
    }

    wp_send_json_success(array('categories' => $result));
}

/**
 * 批量保存分类税率
 * Save category tax rates in batch
 */
function ruiyi_pos_save_category_tax_rates() {
    $rates_json = isset($_POST['rates']) ? stripslashes($_POST['rates']) : '';
    $rates = json_decode($rates_json, true);

    if (empty($rates) || !is_array($rates)) {
        wp_send_json_error(array('message' => 'Invalid rates data'));
        return;
    }

    $saved_count = 0;
    foreach ($rates as $rate_item) {
        if (!isset($rate_item['category_id'])) continue;
        $cat_id = intval($rate_item['category_id']);
        $tax_rate = $rate_item['tax_rate'];
        $option_name = 'ruiyi_tax_rate_cat_' . $cat_id;

        if ($tax_rate === '' || $tax_rate === null) {
            delete_option($option_name);
        } else {
            update_option($option_name, floatval($tax_rate));
        }
        $saved_count++;
    }

    wp_send_json_success(array(
        'message' => "Saved $saved_count category tax rates",
        'saved_count' => $saved_count,
    ));
}

/**
 * 检查SKU是否唯一
 */
function ruiyi_pos_check_sku_unique() {
    $sku = isset($_POST['sku']) ? sanitize_text_field($_POST['sku']) : '';
    if (empty($sku)) {
        wp_send_json_error(array('message' => 'SKU is empty'));
        return;
    }
    $existing = wc_get_product_id_by_sku($sku);
    wp_send_json_success(array('unique' => empty($existing), 'sku' => $sku));
}

/**
 * 创建新产品分类
 */
function ruiyi_pos_create_product_category() {
    $name = isset($_POST['category_name']) ? sanitize_text_field($_POST['category_name']) : '';
    if (empty($name)) {
        wp_send_json_error(array('message' => 'Category name is required'));
        return;
    }
    $existing = term_exists($name, 'product_cat');
    if ($existing) {
        wp_send_json_error(array('message' => 'Category already exists'));
        return;
    }
    $result = wp_insert_term($name, 'product_cat');
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
        return;
    }
    wp_send_json_success(array('id' => $result['term_id'], 'name' => $name));
}

/**
 * 保存单个产品的税率设置
 * Save tax rate for a single product
 */
function ruiyi_pos_save_product_tax_rate() {
    if (empty($_POST['product_id'])) {
        wp_send_json_error(array('message' => 'Missing product_id'));
        return;
    }

    $product_id = intval($_POST['product_id']);
    $tax_category = sanitize_text_field($_POST['tax_category'] ?? '');
    $custom_rate = isset($_POST['custom_rate']) ? floatval($_POST['custom_rate']) : 0;

    // Validate product exists
    $product = wc_get_product($product_id);
    if (!$product) {
        wp_send_json_error(array('message' => 'Product not found'));
        return;
    }

    // Save tax category meta (sync both keys for compatibility)
    update_post_meta($product_id, '_producto_tax_category', $tax_category);
    update_post_meta($product_id, '_verifactu_tax_category', $tax_category);

    // Save custom rate if applicable
    if ($tax_category === 'custom') {
        update_post_meta($product_id, '_custom_tax_rate', $custom_rate);
    }

    // Determine the effective rate for response
    $effective_rate = 21.0;
    switch ($tax_category) {
        case 'super_reduced': $effective_rate = 4.0; break;
        case 'reduced': $effective_rate = 10.0; break;
        case 'standard': $effective_rate = 21.0; break;
        case 'custom': $effective_rate = $custom_rate; break;
    }

    wp_send_json_success(array(
        'message' => 'Product tax rate saved',
        'product_id' => $product_id,
        'tax_category' => $tax_category,
        'effective_rate' => $effective_rate,
    ));
}

/**
 * 搜索产品（用于税率设置）
 * Search products for tax rate configuration
 */
function ruiyi_pos_search_products_for_tax() {
    $query = sanitize_text_field($_POST['query'] ?? '');

    if (strlen($query) < 1) {
        wp_send_json_success(array('products' => array()));
        return;
    }

    $args = array(
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => 20,
        's' => $query,
    );

    $products_query = new WP_Query($args);
    $results = array();

    // Also search by SKU and product number
    $meta_results = get_posts(array(
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => 20,
        'meta_query' => array(
            'relation' => 'OR',
            array(
                'key' => '_sku',
                'value' => $query,
                'compare' => 'LIKE',
            ),
            array(
                'key' => '_product_number',
                'value' => $query,
                'compare' => 'LIKE',
            ),
        ),
    ));

    $seen_ids = array();

    // Merge text search results
    if ($products_query->have_posts()) {
        foreach ($products_query->posts as $post) {
            if (in_array($post->ID, $seen_ids)) continue;
            $seen_ids[] = $post->ID;
            $product = wc_get_product($post->ID);
            if (!$product) continue;

            $tax_category = get_post_meta($post->ID, '_verifactu_tax_category', true);
            if (empty($tax_category)) {
                $tax_category = get_post_meta($post->ID, '_producto_tax_category', true);
            }
            $custom_rate = get_post_meta($post->ID, '_custom_tax_rate', true);

            $results[] = array(
                'id' => $post->ID,
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'tax_category' => $tax_category ?: '',
                'custom_rate' => $custom_rate !== '' ? floatval($custom_rate) : '',
            );
        }
    }

    // Merge meta search results
    foreach ($meta_results as $post) {
        if (in_array($post->ID, $seen_ids)) continue;
        $seen_ids[] = $post->ID;
        $product = wc_get_product($post->ID);
        if (!$product) continue;

        $tax_category = get_post_meta($post->ID, '_verifactu_tax_category', true);
        if (empty($tax_category)) {
            $tax_category = get_post_meta($post->ID, '_producto_tax_category', true);
        }
        $custom_rate = get_post_meta($post->ID, '_custom_tax_rate', true);

        $results[] = array(
            'id' => $post->ID,
            'name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'tax_category' => $tax_category ?: '',
            'custom_rate' => $custom_rate !== '' ? floatval($custom_rate) : '',
        );
    }

    wp_send_json_success(array('products' => $results));
}

/**
 * 保存新客户到Verifactu客户管理
 * Save new customer to Verifactu plugin's customer management
 *
 * 重要逻辑：
 * - 如果填写了公司名称，则公司名称作为主要显示名称（发票上显示）
 * - 个人名称保存为联系人
 * - 业务员ID也会保存到客户记录
 */
function ruiyi_pos_save_new_customer($customer_data) {
    // Check if customer with same CIF already exists
    if (!empty($customer_data['cif'])) {
        $existing = get_posts(array(
            'post_type' => 'verifactu_customer',
            'posts_per_page' => 1,
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_customer_cif',
                    'value' => sanitize_text_field($customer_data['cif']),
                    'compare' => '='
                )
            )
        ));

        if (!empty($existing)) {
            // Update existing customer's data (including company name and salesperson)
            $existing_id = $existing[0]->ID;

            $has_company = !empty($customer_data['company']);
            $company_name = $has_company ? $customer_data['company'] : ($customer_data['name'] ?? '');
            $personal_name = $customer_data['name'] ?? '';

            // Update post title (company name)
            wp_update_post(array(
                'ID' => $existing_id,
                'post_title' => sanitize_text_field($company_name),
            ));

            // Update customer_name - 格式：客户名称在第一行，公司名称在第二行
            // 使用换行符让Verifactu的MultiCell能够分两行显示
            if ($has_company && $personal_name && $company_name !== $personal_name) {
                // 两行格式：客户名称\n公司名称
                $display_name = $personal_name . "\n" . $company_name;
                update_post_meta($existing_id, '_customer_name', $display_name);
                update_post_meta($existing_id, '_customer_contact_person', '');
            } elseif ($has_company) {
                update_post_meta($existing_id, '_customer_name', sanitize_text_field($company_name));
                update_post_meta($existing_id, '_customer_contact_person', '');
            } else {
                update_post_meta($existing_id, '_customer_name', sanitize_text_field($personal_name));
                update_post_meta($existing_id, '_customer_contact_person', '');
            }

            // Update other fields
            if (!empty($customer_data['address'])) {
                update_post_meta($existing_id, '_customer_address', sanitize_text_field($customer_data['address']));
            }
            if (!empty($customer_data['address_2'])) {
                update_post_meta($existing_id, '_customer_address_2', sanitize_text_field($customer_data['address_2']));
            }
            if (!empty($customer_data['city'])) {
                update_post_meta($existing_id, '_customer_city', sanitize_text_field($customer_data['city']));
            }
            if (!empty($customer_data['state'])) {
                update_post_meta($existing_id, '_customer_state', sanitize_text_field($customer_data['state']));
            }
            if (!empty($customer_data['postcode'])) {
                update_post_meta($existing_id, '_customer_postcode', sanitize_text_field($customer_data['postcode']));
            }
            if (!empty($customer_data['country'])) {
                update_post_meta($existing_id, '_customer_country', sanitize_text_field($customer_data['country']));
            }
            if (!empty($customer_data['phone'])) {
                update_post_meta($existing_id, '_customer_phone', sanitize_text_field($customer_data['phone']));
            }
            if (!empty($customer_data['email'])) {
                update_post_meta($existing_id, '_customer_email', sanitize_email($customer_data['email']));
            }

            // Update salesperson
            $salesperson_id = isset($customer_data['salesperson_id']) ? intval($customer_data['salesperson_id']) : 0;
            if ($salesperson_id > 0) {
                update_post_meta($existing_id, '_customer_salesperson_id', $salesperson_id);
            }

            // 🔥 Update customer number if provided
            if (!empty($customer_data['customer_number'])) {
                update_post_meta($existing_id, '_customer_number', intval($customer_data['customer_number']));
            }

            ruiyi_pos_log('[POS Save Customer] Updated existing customer ID: ' . $existing_id . ' with company: ' . $company_name);
            return $existing_id;
        }
    }

    // Determine display name for invoice:
    // - If company is provided, use company as post_title and _customer_name (for invoice display)
    // - Personal name goes to _customer_contact_person
    $has_company = !empty($customer_data['company']);
    $company_name = $has_company ? $customer_data['company'] : ($customer_data['name'] ?? '');
    $personal_name = $customer_data['name'] ?? '';

    $post_id = wp_insert_post(array(
        'post_type' => 'verifactu_customer',
        'post_title' => sanitize_text_field($company_name),
        'post_status' => 'publish',
    ));

    if (is_wp_error($post_id)) {
        ruiyi_pos_log('[POS Save Customer] Error creating customer: ' . $post_id->get_error_message());
        return false;
    }

    // Save meta fields
    // 重要：_customer_name 是发票上显示的名称
    // 格式：客户名称在第一行，公司名称在第二行（使用换行符）
    $display_name = '';
    if ($has_company && $personal_name && $company_name !== $personal_name) {
        // 两行格式：客户名称\n公司名称
        $display_name = $personal_name . "\n" . $company_name;
    } elseif ($has_company) {
        $display_name = $company_name;
    } else {
        $display_name = $personal_name;
    }

    $meta_fields = array(
        '_customer_name' => $display_name,
        '_customer_contact_person' => '',  // 不再使用括号格式
        '_customer_cif' => $customer_data['cif'] ?? '',
        '_customer_address' => $customer_data['address'] ?? '',
        '_customer_address_2' => $customer_data['address_2'] ?? '',
        '_customer_city' => $customer_data['city'] ?? '',
        '_customer_state' => $customer_data['state'] ?? '',
        '_customer_country' => $customer_data['country'] ?? 'ES',
        '_customer_postcode' => $customer_data['postcode'] ?? '',
        '_customer_phone' => $customer_data['phone'] ?? '',
        '_customer_email' => $customer_data['email'] ?? '',
    );

    // Save salesperson ID - ensure it's properly saved as integer
    $salesperson_id = isset($customer_data['salesperson_id']) ? intval($customer_data['salesperson_id']) : 0;
    if ($salesperson_id > 0) {
        $meta_fields['_customer_salesperson_id'] = $salesperson_id;
    }

    // 🔥 Save customer number if provided
    if (!empty($customer_data['customer_number'])) {
        $meta_fields['_customer_number'] = intval($customer_data['customer_number']);
    }

    foreach ($meta_fields as $key => $value) {
        update_post_meta($post_id, $key, sanitize_text_field($value));
    }

    ruiyi_pos_log('[POS Save Customer] New customer created with ID: ' . $post_id);
    return $post_id;
}

/**
 * 生成Albarán PDF (与Factura格式一致，但无QR码)
 * Generate Albarán PDF (same format as Factura, but without QR code)
 * Uses Verifactu plugin settings for emisor info and tax calculations
 */
function ruiyi_pos_generate_delivery_note() {
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $customer_data = isset($_POST['customer_data']) ? json_decode(stripslashes($_POST['customer_data']), true) : array();

    if (!$order_id) {
        wp_send_json_error(array('message' => __('Order ID is required', 'ruiyi-retail-pos')));
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => __('Order not found', 'ruiyi-retail-pos')));
        return;
    }

    // Save customer data to order if provided
    if (!empty($customer_data)) {
        $full_name = sanitize_text_field($customer_data['name'] ?? '');
        $name_parts = explode(' ', $full_name, 2);
        $order->set_billing_first_name($name_parts[0] ?? '');
        $order->set_billing_last_name($name_parts[1] ?? '');
        $order->set_billing_company(sanitize_text_field($customer_data['company'] ?? ''));
        $order->set_billing_address_1(sanitize_text_field($customer_data['address'] ?? ''));
        $order->set_billing_address_2(sanitize_text_field($customer_data['address_2'] ?? ''));
        $order->set_billing_city(sanitize_text_field($customer_data['city'] ?? ''));
        $order->set_billing_state(sanitize_text_field($customer_data['state'] ?? ''));
        $order->set_billing_postcode(sanitize_text_field($customer_data['postcode'] ?? ''));
        $order->set_billing_country(sanitize_text_field($customer_data['country'] ?? 'ES'));
        $order->set_billing_phone(sanitize_text_field($customer_data['phone'] ?? ''));
        $order->set_billing_email(sanitize_email($customer_data['email'] ?? ''));

        $cif = sanitize_text_field($customer_data['cif'] ?? '');
        if ($cif) {
            $order->update_meta_data('_billing_cif', $cif);
        }

        // 🔥 Save payment method and bank info
        $payment_method = sanitize_text_field($customer_data['payment_method'] ?? '');
        $bank_name = sanitize_text_field($customer_data['bank_name'] ?? '');
        $bank_iban = sanitize_text_field($customer_data['bank_iban'] ?? '');
        $bank_bic = sanitize_text_field($customer_data['bank_bic'] ?? '');
        $phone_prefix = sanitize_text_field($customer_data['phone_prefix'] ?? '');

        if ($payment_method) {
            $order->update_meta_data('_invoice_payment_method', $payment_method);
            $order->set_payment_method($payment_method);
        }
        if ($bank_name) {
            $order->update_meta_data('_invoice_bank_name', $bank_name);
        }
        if ($bank_iban) {
            $order->update_meta_data('_invoice_bank_iban', $bank_iban);
        }
        if ($bank_bic) {
            $order->update_meta_data('_invoice_bank_bic', $bank_bic);
        }
        if ($phone_prefix) {
            $order->update_meta_data('_billing_phone_prefix', $phone_prefix);
        }

        // 🔥 Save notes
        $notes = sanitize_textarea_field($customer_data['notes'] ?? '');
        if ($notes) {
            $order->update_meta_data('_invoice_notes', $notes);
        }

        ruiyi_pos_log("[POS Albarán] Saved bank info - Bank: $bank_name, IBAN: $bank_iban, Payment: $payment_method");

        // Save customer ID and salesperson ID to order meta (for Verifactu integration)
        $customer_id = isset($customer_data['customer_id']) ? intval($customer_data['customer_id']) : 0;
        $salesperson_id = isset($customer_data['salesperson_id']) ? intval($customer_data['salesperson_id']) : 0;

        // 🔥 If no salesperson ID but new name provided, auto-create salesperson
        $new_salesperson_name = isset($customer_data['new_salesperson_name']) ? sanitize_text_field($customer_data['new_salesperson_name']) : '';
        if (!$salesperson_id && !empty($new_salesperson_name)) {
            $salesperson_id = ruiyi_pos_create_salesperson($new_salesperson_name);
            ruiyi_pos_log("[POS Albarán] Auto-created new salesperson: $new_salesperson_name (ID: $salesperson_id)");
        }

        // If no customer_id, auto-create new customer in Verifactu
        if (!$customer_id && !empty($cif)) {
            $customer_id = ruiyi_pos_save_new_customer($customer_data);
        }

        if ($customer_id) {
            $order->update_meta_data('_verifactu_customer_id', $customer_id);
        }
        if ($salesperson_id) {
            $order->update_meta_data('_verifactu_salesperson_id', $salesperson_id);
        }

        $order->save();
    }

    // Check if TCPDF is available
    if (!class_exists('TCPDF')) {
        $tcpdf_paths = array(
            WP_PLUGIN_DIR . '/verifactuqr/vendor/tecnickcom/tcpdf/tcpdf.php',
            WP_PLUGIN_DIR . '/verifactuqr/includes/tcpdf/tcpdf.php',
            ABSPATH . 'wp-includes/tcpdf/tcpdf.php',
        );
        foreach ($tcpdf_paths as $path) {
            if (file_exists($path)) {
                require_once $path;
                break;
            }
        }
    }

    if (!class_exists('TCPDF')) {
        wp_send_json_error(array('message' => __('PDF library not available', 'ruiyi-retail-pos')));
        return;
    }

    // Get Albarán number (create if doesn't exist)
    $albaran_number = $order->get_meta('_albaran_number');
    if (!$albaran_number) {
        $year = date('Y');
        $albaran_number = ruiyi_pos_get_next_albaran_number($year);
        $order->update_meta_data('_albaran_number', $albaran_number);
        $order->save();
    }

    // ========================================
    // Get Verifactu settings for Emisor info
    // ========================================
    $verifactu_settings = get_option('wc_verifactu_qr_settings', array());

    // Helper function to get setting with fallback
    $get_setting = function($key, $default = '') use ($verifactu_settings) {
        return isset($verifactu_settings[$key]) && $verifactu_settings[$key] !== ''
            ? $verifactu_settings[$key]
            : $default;
    };

    // Get emisor info from Verifactu settings
    $em_nombre = $get_setting('emitter_name', get_bloginfo('name'));
    $em_vat = $get_setting('emitter_vat', '');
    $em_addr1 = $get_setting('emitter_address_1', get_option('woocommerce_store_address', ''));
    $em_addr2 = $get_setting('emitter_address_2', get_option('woocommerce_store_address_2', ''));
    $em_postcode = $get_setting('emitter_postcode', get_option('woocommerce_store_postcode', ''));
    $em_city = $get_setting('emitter_city', get_option('woocommerce_store_city', ''));

    // Get country with proper fallback
    $def_country_opt = get_option('woocommerce_default_country', 'ES');
    $def_country_cc = strpos($def_country_opt, ':') !== false ? explode(':', $def_country_opt, 2)[0] : $def_country_opt;
    $em_country_code = $get_setting('emitter_country', $def_country_cc);
    $em_phone = $get_setting('emitter_phone', ''); // 🔥 Emitter phone

    // Convert country code to Spanish name
    $em_country = ruiyi_pos_get_country_name_spanish($em_country_code);

    // Get pricing mode from Verifactu settings (tax_inclusive or tax_exclusive)
    $pricing_mode = $get_setting('pricing_mode', 'tax_inclusive');

    // Get business type from Verifactu settings
    $business_type = $get_setting('business_type', 'retail');

    // Force UTF-8 encoding for Chinese character support
    if (function_exists('mb_internal_encoding')) {
        mb_internal_encoding('UTF-8');
    }

    // Helper: detect if text contains Chinese characters
    $has_cjk = function($text) {
        return preg_match('/[\x{4e00}-\x{9fa5}]/u', $text);
    };
    // Helper: choose font based on CJK content
    $cjk_font = function($text) use ($has_cjk) {
        return $has_cjk($text) ? 'cid0cs' : 'dejavusans';
    };

    // Create PDF (same style as Verifactu invoice)
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('RUIYI Retail POS');
    $pdf->SetAuthor($em_nombre);
    $pdf->SetTitle('Albarán ' . $albaran_number);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();

    // === HEADER: ALBARÁN title ===
    $pdf->SetFont('dejavusans', 'B', 24);
    $pdf->Cell(0, 12, 'ALBARÁN', 0, 1, 'L');

    // Albarán number and date
    $pdf->SetFont('dejavusans', '', 10);
    $pdf->Cell(30, 6, 'Número:', 0, 0, 'L');
    $pdf->SetFont('dejavusans', 'B', 10);
    $pdf->Cell(60, 6, $albaran_number, 0, 1, 'L');

    $pdf->SetFont('dejavusans', '', 10);
    $pdf->Cell(30, 6, 'Fecha:', 0, 0, 'L');
    $pdf->SetFont('dejavusans', 'B', 10);
    $pdf->Cell(60, 6, $order->get_date_created()->date('d/m/Y'), 0, 1, 'L');

    $pdf->Ln(8);

    // === TWO COLUMN LAYOUT: Emisor (left) | Cliente (right) ===
    $startY = $pdf->GetY();
    $colWidth = 85;

    // Emisor (left column)
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->SetXY(15, $startY);
    $pdf->Cell($colWidth, 5, 'Emisor', 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont($cjk_font($em_nombre), 'B', 11);
    $pdf->SetX(15);
    $pdf->Cell($colWidth, 6, $em_nombre, 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 9);
    if ($em_vat) {
        $pdf->SetX(15);
        $pdf->Cell($colWidth, 5, $em_vat, 0, 1, 'L');
    }
    $pdf->SetFont($cjk_font($em_addr1), '', 9);
    $pdf->SetX(15);
    $pdf->Cell($colWidth, 5, $em_addr1, 0, 1, 'L');
    if ($em_addr2) {
        $pdf->SetFont($cjk_font($em_addr2), '', 9);
        $pdf->SetX(15);
        $pdf->Cell($colWidth, 5, $em_addr2, 0, 1, 'L');
    }
    $em_city_line = trim($em_postcode . ', ' . $em_city, ' ,');
    $pdf->SetFont($cjk_font($em_city_line), '', 9);
    $pdf->SetX(15);
    $pdf->Cell($colWidth, 5, $em_city_line, 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetX(15);
    $pdf->Cell($colWidth, 5, $em_country, 0, 1, 'L');
    // 🔥 Display emitter phone(s) if available (支持多个电话号码)
    if ($em_phone) {
        // Split by newlines or commas to support multiple phones
        $phones = preg_split('/[\n\r,]+/', $em_phone);
        $phones = array_filter(array_map('trim', $phones));
        if (count($phones) > 0) {
            foreach ($phones as $phone) {
                $pdf->SetX(15);
                $pdf->Cell($colWidth, 5, 'Tel: ' . $phone, 0, 1, 'L');
            }
        }
    }

    $emisorEndY = $pdf->GetY();

    // Cliente (right column)
    $customer_name = $customer_data['name'] ?? $order->get_formatted_billing_full_name();
    $customer_company = $customer_data['company'] ?? $order->get_billing_company();
    $customer_cif = $customer_data['cif'] ?? $order->get_meta('_billing_cif');
    $customer_address = $customer_data['address'] ?? $order->get_billing_address_1();
    $customer_address_2 = $customer_data['address_2'] ?? $order->get_billing_address_2();
    $customer_city = $customer_data['city'] ?? $order->get_billing_city();
    $customer_postcode = $customer_data['postcode'] ?? $order->get_billing_postcode();
    $customer_country_code = $customer_data['country'] ?? $order->get_billing_country();
    $customer_country = ruiyi_pos_get_country_name_spanish($customer_country_code);
    $customer_phone = $customer_data['phone'] ?? $order->get_billing_phone(); // 🔥 Client phone(s) - supports multiple
    $customer_email = $customer_data['email'] ?? $order->get_billing_email(); // 🔥 Client email

    $pdf->SetXY(110, $startY);
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell($colWidth, 5, 'Cliente', 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetX(110);
    $pdf->SetFont($cjk_font($customer_name), 'B', 11);
    // 显示格式：客户名称在第一行，公司名称在第二行
    if ($customer_company && $customer_name && $customer_company !== $customer_name) {
        $pdf->Cell($colWidth, 6, $customer_name, 0, 1, 'L');
        $pdf->SetX(110);
        $pdf->SetFont($cjk_font($customer_company), '', 10);
        $pdf->Cell($colWidth, 5, $customer_company, 0, 1, 'L');
    } else {
        $clientDisplayName = $customer_company ?: $customer_name;
        $pdf->Cell($colWidth, 6, $clientDisplayName, 0, 1, 'L');
    }
    $pdf->SetFont('dejavusans', '', 9);
    if ($customer_cif) {
        $pdf->SetX(110);
        $pdf->Cell($colWidth, 5, $customer_cif, 0, 1, 'L');
    }
    if ($customer_address) {
        $pdf->SetFont($cjk_font($customer_address), '', 9);
        $pdf->SetX(110);
        $pdf->Cell($colWidth, 5, $customer_address, 0, 1, 'L');
    }
    if ($customer_address_2) {
        $pdf->SetFont($cjk_font($customer_address_2), '', 9);
        $pdf->SetX(110);
        $pdf->Cell($colWidth, 5, $customer_address_2, 0, 1, 'L');
    }
    $customer_city_line = trim($customer_postcode . ', ' . $customer_city, ' ,');
    $pdf->SetFont($cjk_font($customer_city_line), '', 9);
    $pdf->SetX(110);
    $pdf->Cell($colWidth, 5, $customer_city_line, 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetX(110);
    $pdf->Cell($colWidth, 5, $customer_country ?: 'ESPAÑA', 0, 1, 'L');
    // 🔥 Display client phone(s) if available (支持多个电话号码)
    if ($customer_phone) {
        // Split by newlines or commas to support multiple phones
        $phones = preg_split('/[\n\r,]+/', $customer_phone);
        $phones = array_filter(array_map('trim', $phones));
        if (count($phones) > 0) {
            foreach ($phones as $phone) {
                $pdf->SetX(110);
                $pdf->Cell($colWidth, 5, 'Tel: ' . $phone, 0, 1, 'L');
            }
        }
    }
    // 🔥 Display client email if available
    if ($customer_email) {
        $pdf->SetX(110);
        $pdf->Cell($colWidth, 5, 'Email: ' . $customer_email, 0, 1, 'L');
    }

    $clienteEndY = $pdf->GetY();
    $pdf->SetY(max($emisorEndY, $clienteEndY) + 10);

    // === PRODUCTS TABLE ===
    $pdf->SetFont('dejavusans', 'B', 9);
    $pdf->SetFillColor(240, 240, 240);

    // Table header
    $descWidth = 65;
    $pdf->Cell($descWidth, 8, 'Descripción', 1, 0, 'L', true);
    $pdf->Cell(15, 8, 'Cant.', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'P. Unit.', 1, 0, 'R', true);
    $pdf->Cell(25, 8, 'Sin IVA', 1, 0, 'R', true);
    $pdf->Cell(15, 8, 'IVA', 1, 0, 'R', true);
    $pdf->Cell(30, 8, 'Total', 1, 1, 'R', true);

    // ========================================
    // Calculate line items with proper tax handling
    // ========================================
    $subtotal_ex = 0;
    $total_tax = 0;
    $tax_rates_summary = array();

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $product_name = $item->get_name();
        $quantity = (float) $item->get_quantity(); // 支持小数数量

        // Get tax rate using Verifactu logic (now reads category-level settings)
        $tax_rate = ruiyi_pos_get_product_tax_rate($item, $business_type);

        if ($pricing_mode === 'tax_exclusive') {
            $line_ex = (float) $order->get_line_subtotal($item, false, true);
            $line_tax = $line_ex * ($tax_rate / 100);
            $line_inc_tax = $line_ex + $line_tax;
        } else {
            $line_inc_tax = (float) $order->get_line_subtotal($item, true, true);
            $line_ex = $line_inc_tax / (1 + ($tax_rate / 100));
            $line_tax = $line_inc_tax - $line_ex;
        }

        $unit_price_ex = $quantity > 0 ? $line_ex / $quantity : $line_ex;

        // Use CJK font for product name if it contains Chinese characters
        $pdf->SetFont($cjk_font($product_name), '', 9);

        // Calculate dynamic row height for long product names (auto-wrap)
        $startX = $pdf->GetX();
        $startY_row = $pdf->GetY();
        $minRowHeight = 7;
        $nameHeight = $pdf->getStringHeight($descWidth, $product_name);
        $rowHeight = max($minRowHeight, $nameHeight);

        // Product name with MultiCell (supports word wrap, no truncation)
        $pdf->MultiCell($descWidth, $rowHeight, $product_name, 1, 'L', false, 0, $startX, $startY_row, true, 0, false, true, $rowHeight, 'M');

        // Numbers always use DejaVu Sans for consistent spacing
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->Cell(15, $rowHeight, $quantity, 1, 0, 'C', false, '', 0, false, 'T', 'M');
        $pdf->Cell(25, $rowHeight, number_format($unit_price_ex, 2) . ' €', 1, 0, 'R', false, '', 0, false, 'T', 'M');
        $pdf->Cell(25, $rowHeight, number_format($line_ex, 2) . ' €', 1, 0, 'R', false, '', 0, false, 'T', 'M');
        $pdf->Cell(15, $rowHeight, number_format($tax_rate, 0) . '%', 1, 0, 'R', false, '', 0, false, 'T', 'M');
        $pdf->Cell(30, $rowHeight, number_format($line_inc_tax, 2) . ' €', 1, 1, 'R', false, '', 0, false, 'T', 'M');

        $subtotal_ex += $line_ex;
        $total_tax += $line_tax;

        // Group by tax rate for summary
        $rate_key = number_format($tax_rate, 2);
        if (!isset($tax_rates_summary[$rate_key])) {
            $tax_rates_summary[$rate_key] = array('rate' => $tax_rate, 'tax_amount' => 0.0);
        }
        $tax_rates_summary[$rate_key]['tax_amount'] += $line_tax;
    }

    // Also add fee items
    foreach ($order->get_items('fee') as $item) {
        $fee_name = $item->get_name();
        $fee_total = (float) $item->get_total();
        $fee_tax = (float) $item->get_total_tax();
        $total_with_tax = $fee_total + $fee_tax;
        $iva_percent = $fee_total != 0 ? round(($fee_tax / abs($fee_total)) * 100) : 21;

        $pdf->SetFont($cjk_font($fee_name), '', 9);
        $startX = $pdf->GetX();
        $startY_row = $pdf->GetY();
        $nameHeight = $pdf->getStringHeight($descWidth, $fee_name);
        $rowHeight = max(7, $nameHeight);

        $pdf->MultiCell($descWidth, $rowHeight, $fee_name, 1, 'L', false, 0, $startX, $startY_row, true, 0, false, true, $rowHeight, 'M');
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->Cell(15, $rowHeight, '1', 1, 0, 'C', false, '', 0, false, 'T', 'M');
        $pdf->Cell(25, $rowHeight, number_format($fee_total, 2) . ' €', 1, 0, 'R', false, '', 0, false, 'T', 'M');
        $pdf->Cell(25, $rowHeight, number_format($fee_total, 2) . ' €', 1, 0, 'R', false, '', 0, false, 'T', 'M');
        $pdf->Cell(15, $rowHeight, $iva_percent . '%', 1, 0, 'R', false, '', 0, false, 'T', 'M');
        $pdf->Cell(30, $rowHeight, number_format($total_with_tax, 2) . ' €', 1, 1, 'R', false, '', 0, false, 'T', 'M');

        $subtotal_ex += $fee_total;
        $total_tax += $fee_tax;
    }

    $pdf->Ln(5);

    // === TOTALS ===
    $grand_total = $subtotal_ex + $total_tax;
    $totalsX = 125;

    $pdf->SetFont('dejavusans', '', 10);
    $pdf->SetX($totalsX);
    $pdf->Cell(35, 6, 'Subtotal:', 0, 0, 'L');
    $pdf->Cell(30, 6, number_format($subtotal_ex, 2) . ' €', 0, 1, 'R');

    // Show tax rates breakdown
    foreach ($tax_rates_summary as $rate_data) {
        if ($rate_data['tax_amount'] > 0) {
            $rate_label = $rate_data['rate'] > 0 ? 'IVA ' . number_format($rate_data['rate'], 0) . '%' : 'IVA';
            $pdf->SetX($totalsX);
            $pdf->Cell(35, 6, $rate_label . ':', 0, 0, 'L');
            $pdf->Cell(30, 6, number_format($rate_data['tax_amount'], 2) . ' €', 0, 1, 'R');
        }
    }

    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->SetX($totalsX);
    $pdf->Cell(35, 8, 'Total:', 'T', 0, 'L');
    $pdf->Cell(30, 8, number_format($grand_total, 2) . ' €', 'T', 1, 'R');

    // ===== 🔥 PAYMENT METHOD & BANK INFO (Left side) =====
    $invoice_payment_method = $order->get_meta('_invoice_payment_method');
    if (!$invoice_payment_method) {
        $invoice_payment_method = $order->get_payment_method();
    }
    $bank_name = $order->get_meta('_invoice_bank_name');
    $bank_iban = $order->get_meta('_invoice_bank_iban');
    $bank_bic = $order->get_meta('_invoice_bank_bic');

    if ($invoice_payment_method || $bank_name || $bank_iban || $bank_bic) {
        $pdf->Ln(5);
        $currentY = $pdf->GetY();

        // Payment method & Bank info on left side
        $pdf->SetXY(15, $currentY);
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(80, 5, 'Forma de Pago', 0, 1, 'L');

        $pdf->SetFont('dejavusans', '', 9);

        // Display payment method (Spanish only)
        if ($invoice_payment_method) {
            $payment_label = '';
            switch ($invoice_payment_method) {
                case 'cash':
                    $payment_label = 'Efectivo';
                    break;
                case 'card':
                    $payment_label = 'Tarjeta';
                    break;
                case 'transfer':
                    $payment_label = 'Transferencia';
                    break;
                case 'cash_card':
                case 'mixed':
                    $payment_label = 'Efectivo + Tarjeta';
                    break;
                default:
                    $payment_label = ucfirst($invoice_payment_method);
            }
            $pdf->SetX(15);
            $pdf->Cell(80, 5, $payment_label, 0, 1, 'L');
        }

        // Display bank info if available
        if ($bank_name || $bank_iban || $bank_bic) {
            $pdf->Ln(2);
            $pdf->SetFont('dejavusans', 'B', 9);
            $pdf->SetX(15);
            $pdf->Cell(80, 5, 'Datos Bancarios', 0, 1, 'L');

            $pdf->SetFont('dejavusans', '', 9);
            if ($bank_name) {
                $pdf->SetX(15);
                $pdf->Cell(25, 5, 'Banco:', 0, 0, 'L');
                $pdf->Cell(55, 5, $bank_name, 0, 1, 'L');
            }
            if ($bank_iban) {
                $pdf->SetX(15);
                $pdf->Cell(25, 5, 'IBAN:', 0, 0, 'L');
                $pdf->Cell(55, 5, $bank_iban, 0, 1, 'L');
            }
            if ($bank_bic) {
                $pdf->SetX(15);
                $pdf->Cell(25, 5, 'BIC/SWIFT:', 0, 0, 'L');
                $pdf->Cell(55, 5, $bank_bic, 0, 1, 'L');
            }
        }
    }

    // 🔥 === NOTES SECTION ===
    $invoice_notes = $order->get_meta('_invoice_notes');
    if (!empty($invoice_notes)) {
        $pdf->Ln(5);
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->SetX(15);
        $pdf->Cell(0, 5, 'Notas:', 0, 1, 'L');
        $pdf->SetFont($cjk_font($invoice_notes), '', 9);
        $pdf->SetX(15);
        $pdf->MultiCell(180, 5, $invoice_notes, 0, 'L');
    }

    $pdf->Ln(10);

    // === FOOTER NOTE ===
    $pdf->SetFont('dejavusans', 'I', 8);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->MultiCell(0, 5, 'Este documento es un albarán y no tiene validez fiscal como factura. Para obtener la factura oficial, solicítela una vez realizado el pago.', 0, 'C');
    $pdf->SetTextColor(0, 0, 0);

    // Generate filename and save
    $upload_dir = wp_upload_dir();
    $albaran_dir = $upload_dir['basedir'] . '/albaran-invoices';

    if (!file_exists($albaran_dir)) {
        wp_mkdir_p($albaran_dir);
    }

    // Use Albarán number as filename (with timestamp for server storage)
    $filename_with_timestamp = strtolower(str_replace('-', '_', $albaran_number)) . '_' . time() . '.pdf';
    $download_filename = strtolower(str_replace('-', '_', $albaran_number)) . '.pdf';
    $filepath = $albaran_dir . '/' . $filename_with_timestamp;

    $pdf->Output($filepath, 'F');

    // Return URL with HTTPS
    $pdf_url = $upload_dir['baseurl'] . '/albaran-invoices/' . $filename_with_timestamp;
    $pdf_url = str_replace('http://', 'https://', $pdf_url);

    // Save path to order meta
    $order->update_meta_data('_albaran_pdf', $filepath);
    $order->save();

    wp_send_json_success(array(
        'pdf_url' => $pdf_url,
        'filename' => $download_filename,
        'albaran_number' => $albaran_number,
        'message' => __('Albarán generated successfully', 'ruiyi-retail-pos')
    ));
}

/**
 * Helper function to convert country code to Spanish name
 */
function ruiyi_pos_get_country_name_spanish($country_code) {
    $country_code = strtoupper(trim($country_code));

    $spanish_countries = array(
        'ES' => 'ESPAÑA',
        'FR' => 'FRANCIA',
        'IT' => 'ITALIA',
        'DE' => 'ALEMANIA',
        'PT' => 'PORTUGAL',
        'GB' => 'REINO UNIDO',
        'UK' => 'REINO UNIDO',
        'US' => 'ESTADOS UNIDOS',
        'CN' => 'CHINA',
        'JP' => 'JAPÓN',
        'BR' => 'BRASIL',
        'MX' => 'MÉXICO',
        'AR' => 'ARGENTINA',
        'CO' => 'COLOMBIA',
        'CL' => 'CHILE',
        'PE' => 'PERÚ',
        'VE' => 'VENEZUELA',
        'EC' => 'ECUADOR',
        'BO' => 'BOLIVIA',
        'UY' => 'URUGUAY',
        'PY' => 'PARAGUAY',
        'NL' => 'PAÍSES BAJOS',
        'BE' => 'BÉLGICA',
        'AT' => 'AUSTRIA',
        'CH' => 'SUIZA',
        'PL' => 'POLONIA',
        'SE' => 'SUECIA',
        'NO' => 'NORUEGA',
        'DK' => 'DINAMARCA',
        'FI' => 'FINLANDIA',
        'IE' => 'IRLANDA',
        'GR' => 'GRECIA',
        'CZ' => 'REPÚBLICA CHECA',
        'RO' => 'RUMANÍA',
        'HU' => 'HUNGRÍA',
    );

    return isset($spanish_countries[$country_code]) ? $spanish_countries[$country_code] : $country_code;
}

/**
 * Helper function to get product tax rate using Verifactu logic
 */
function ruiyi_pos_get_product_tax_rate($item, $business_type = 'retail') {
    // Default tax rate depends on business type
    $default_tax_rate = ($business_type === 'restaurant') ? 10.0 : 21.0;

    $product = $item->get_product();
    if (!$product) {
        return $default_tax_rate;
    }

    $product_id = $product->get_id();
    $lookup_id = ($product->is_type('variation')) ? $product->get_parent_id() : $product_id;

    // === Priority 1: Category-level tax rate (ruiyi_tax_rate_cat_{term_id} from POS tax settings) ===
    // Always check this first - it's the most explicit user configuration from POS settings
    $categories = wp_get_post_terms($lookup_id, 'product_cat');

    if (!is_wp_error($categories) && !empty($categories)) {
        $category = $categories[0];
        $option_name = 'ruiyi_tax_rate_cat_' . $category->term_id;
        $category_tax_rate = get_option($option_name, '');

        if ($category_tax_rate !== '' && $category_tax_rate !== false) {
            return floatval($category_tax_rate);
        }
    }

    // Check uncategorized default
    if (is_wp_error($categories) || empty($categories)) {
        $default_cat_id = get_option('default_product_cat');
        if ($default_cat_id) {
            $option_name = 'ruiyi_tax_rate_cat_' . $default_cat_id;
            $cat_rate = get_option($option_name, '');
            if ($cat_rate !== '' && $cat_rate !== false) {
                return floatval($cat_rate);
            }
        }
    }

    // === Priority 2: Product-level tax meta (fallback if no category rate set) ===
    $tax_category = '';
    if ($product->is_type('variation')) {
        $tax_category = get_post_meta($product_id, '_verifactu_tax_category', true);
        if (empty($tax_category)) {
            $tax_category = get_post_meta($product_id, '_producto_tax_category', true);
        }
        if (empty($tax_category)) {
            $parent_id = $product->get_parent_id();
            $tax_category = get_post_meta($parent_id, '_verifactu_tax_category', true);
            if (empty($tax_category)) {
                $tax_category = get_post_meta($parent_id, '_producto_tax_category', true);
            }
        }
    } else {
        $tax_category = get_post_meta($product_id, '_verifactu_tax_category', true);
        if (empty($tax_category)) {
            $tax_category = get_post_meta($product_id, '_producto_tax_category', true);
        }
    }

    if ($tax_category) {
        if ($tax_category === 'custom') {
            $custom_rate = get_post_meta($product_id, '_custom_tax_rate', true);
            if ($custom_rate !== '' && $custom_rate !== false) {
                return floatval($custom_rate);
            }
        }
        switch ($tax_category) {
            case 'super_reduced': return 4.0;
            case 'reduced':       return 10.0;
            case 'standard':      return 21.0;
        }
    }

    // === Priority 3: Business type default ===
    return $default_tax_rate;
}

// ========================================
// 🔥 产品编号快速添加功能 (PRODUCT NUMBER QUICK ADD)
// ========================================
// 允许用户通过输入产品编号和按"-"键快速将产品添加到购物车

/**
 * 注册产品编号元数据字段
 * Register product number meta field for WooCommerce products
 */
add_action('woocommerce_product_options_general_product_data', function() {
    global $product_object;

    woocommerce_wp_text_input(array(
        'id'          => '_product_number',
        'label'       => __('Product Number (快速编号)', 'ruiyi-retail-pos'),
        'placeholder' => __('e.g., 01, 02, A1, B2', 'ruiyi-retail-pos'),
        'description' => __('用于POS快速查询的产品编号。在收银台输入此编号，按"-"键自动添加到购物车。', 'ruiyi-retail-pos'),
        'type'        => 'text',
        'value'       => $product_object ? $product_object->get_meta('_product_number') : '',
    ));
});

/**
 * 保存产品编号元数据
 * Save product number meta when product is saved
 */
add_action('woocommerce_process_product_meta', function($product_id) {
    if (isset($_POST['_product_number'])) {
        $product = wc_get_product($product_id);
        $product->update_meta_data('_product_number', sanitize_text_field($_POST['_product_number']));
        $product->save_meta_data();
    }
}, 10, 1);

/**
 * AJAX: 根据产品编号查找产品
 * AJAX endpoint: Find product by product number
 */
add_action('wp_ajax_ruiyi_find_product_by_number', function() {
    // 检查权限
    if (!current_user_can('manage_woocommerce') && !ruiyi_pos_check_access()) {
        wp_send_json_error(array('message' => __('Unauthorized', 'ruiyi-retail-pos')));
        return;
    }

    // 获取产品编号
    $product_number = isset($_POST['product_number']) ? sanitize_text_field($_POST['product_number']) : '';

    if (empty($product_number)) {
        wp_send_json_error(array('message' => __('Product number is required', 'ruiyi-retail-pos')));
        return;
    }

    // 查询产品
    $args = array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'meta_query'     => array(
            array(
                'key'     => '_product_number',
                'value'   => $product_number,
                'compare' => '='
            )
        ),
        'posts_per_page' => 1
    );

    $query = new WP_Query($args);

    if ($query->have_posts()) {
        $query->the_post();
        $product = wc_get_product(get_the_ID());

        // 获取产品图片
        $image_id = $product->get_image_id();
        $product_image = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '';

        // 如果没有图片，使用默认占位符
        if (empty($product_image)) {
            $product_image = wc_placeholder_img_src('thumbnail');
        }

        wp_send_json_success(array(
            'product_id'     => $product->get_id(),
            'product_name'   => $product->get_name(),
            'product_price'  => floatval($product->get_price()),
            'product_sku'    => $product->get_sku(),
            'product_image'  => $product_image,
            'in_stock'       => $product->is_in_stock(),
            'stock_qty'      => $product->get_stock_quantity(),
            'product_number' => $product->get_meta('_product_number')
        ));
    } else {
        wp_send_json_error(array('message' => __('Product not found with number: ', 'ruiyi-retail-pos') . $product_number));
    }

    wp_reset_postdata();
});

add_action('wp_ajax_nopriv_ruiyi_find_product_by_number', function() {
    // 为未登录用户也允许访问
    do_action('wp_ajax_ruiyi_find_product_by_number');
});

// 🔥 已注释：电子秤配置现在通过后台设置控制（Store Settings页面）
// 如果需要强制启用电子秤，请在后台 Store Settings → Hardware Settings 中开启
/*
// Hard-wire the Scale Hub connection for the POS front-end.
add_filter('ruiyi_pos_scale_config', function () {
    return array(
        'enabled'        => true,
        'socket_url'     => 'http://localhost:4011/scale', // ← your hub URL + namespace
        'socket_path'    => '/socket.io',
        'retry_ms'       => 2000,
        'stale_after_ms' => 5000,
        'precision'      => 3,
        'min_weight_kg'  => 0.005,
        'weight_unit'    => 'kg',
        'unit_aliases'   => array('kg', 'kilogram', 'kilo'),
        'debug'          => false,
        'location'       => 'store-001',         // must match the middleware .env
        'auth_token'     => '',                  // populate if Scale Hub AUTH_TOKEN is set
        'client_type'    => 'pos',
    );
});
*/

// ============================================================================
// 日结单和月结单系统 (Daily & Monthly Settlement System)
// ============================================================================

/**
 * 注册月结单自定义文章类型
 * Register Monthly Summary Custom Post Type
 */
function ruiyi_retail_register_monthly_summary_post_type() {
    register_post_type('retail_monthly_summary', array(
        'labels' => array(
            'name' => __('Monthly Summaries', 'ruiyi-retail-pos'),
            'singular_name' => __('Monthly Summary', 'ruiyi-retail-pos'),
            'add_new' => __('Add New', 'ruiyi-retail-pos'),
            'add_new_item' => __('Add New Monthly Summary', 'ruiyi-retail-pos'),
            'edit_item' => __('Edit Monthly Summary', 'ruiyi-retail-pos'),
            'view_item' => __('View Monthly Summary', 'ruiyi-retail-pos'),
        ),
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false,
        'capability_type' => 'post',
        'supports' => array('title'),
        'has_archive' => false,
        'rewrite' => false,
    ));
}
add_action('init', 'ruiyi_retail_register_monthly_summary_post_type');

/**
 * 获取日结单数据的默认结构
 * Get default structure for daily summary data
 */
function ruiyi_retail_get_default_daily_summary() {
    return array(
        'date' => date('Y-m-d'),
        'total_amount' => 0,
        'cash_amount' => 0,
        'card_amount' => 0,
        'transfer_amount' => 0,
        'mixed_payment_count' => 0,
        'products' => array(),
        'transactions' => array(),
        'last_updated' => current_time('mysql')
    );
}

/**
 * 记录交易到日结单
 * Record transaction to daily summary
 *
 * @param int $order_id 订单ID
 * @param string $payment_method 支付方式
 * @param float $total_amount 总金额
 * @param array $items 商品列表
 */
function ruiyi_retail_record_daily_transaction($order_id, $payment_method, $total_amount, $items) {
    global $wpdb;
    $today = date('Y-m-d');
    $daily_data_key = 'ruiyi_retail_daily_summary_' . $today;

    // 🔥 使用MySQL行级锁防止并发竞态条件（多台电脑同时下单时数据不丢失）
    $max_retries = 3;
    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        $wpdb->query('START TRANSACTION');

        // 🔥 SELECT FOR UPDATE 锁定该行，其他并发请求会等待
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE",
            $daily_data_key
        ));

        if ($row) {
            $daily_data = maybe_unserialize($row->option_value);
        } else {
            $daily_data = ruiyi_retail_get_default_daily_summary();
        }

        // 累加总金额
        $daily_data['total_amount'] += $total_amount;

        // 获取订单对象以检查详细支付信息
        $order = wc_get_order($order_id);
        $payment_detail = $order->get_meta('_payment_method_detail', true);

        // 使用正确的元数据键 _pos_cash_amount 和 _pos_card_amount
        $cash_meta = floatval($order->get_meta('_pos_cash_amount', true));
        $card_meta = floatval($order->get_meta('_pos_card_amount', true));

        // 根据支付方式分类金额
        if ($payment_method === 'mixed' || ($cash_meta > 0 && $card_meta > 0)) {
            $daily_data['cash_amount'] += $cash_meta;
            $daily_data['card_amount'] += $card_meta;
            $daily_data['mixed_payment_count']++;
            ruiyi_pos_log("RUIYI POS Daily: Mixed payment recorded - Cash: €{$cash_meta}, Card: €{$card_meta}");
        } elseif ($payment_detail === 'transfer' || $payment_method === 'transfer') {
            if (!isset($daily_data['transfer_amount'])) {
                $daily_data['transfer_amount'] = 0;
            }
            $daily_data['transfer_amount'] += $total_amount;
            ruiyi_pos_log("RUIYI POS Daily: Transfer payment recorded - €{$total_amount}");
        } elseif ($payment_detail === 'card' || $payment_method === 'card' || $payment_method === 'bacs' || $card_meta > 0) {
            $daily_data['card_amount'] += $total_amount;
            ruiyi_pos_log("RUIYI POS Daily: Card payment recorded - €{$total_amount}");
        } elseif ($payment_detail === 'cash' || $payment_method === 'cash') {
            $daily_data['cash_amount'] += $total_amount;
            ruiyi_pos_log("RUIYI POS Daily: Cash payment recorded - €{$total_amount}");
        } else {
            $daily_data['cash_amount'] += $total_amount;
            ruiyi_pos_log("RUIYI POS Daily: Default (cash) payment recorded - €{$total_amount}");
        }

        // 添加产品到汇总（按产品名聚合）
        foreach ($items as $item) {
            $product_name = $item['name'];
            $product_id = isset($item['product_id']) ? $item['product_id'] : 0;

            $category = 'Sin categoría';
            if ($product_id > 0) {
                $terms = get_the_terms($product_id, 'product_cat');
                if ($terms && !is_wp_error($terms)) {
                    $category = $terms[0]->name;
                }
            }

            if (isset($daily_data['products'][$product_name])) {
                $daily_data['products'][$product_name]['quantity'] += $item['quantity'];
                $daily_data['products'][$product_name]['total'] += $item['total'];
            } else {
                $daily_data['products'][$product_name] = array(
                    'name' => $product_name,
                    'category' => $category,
                    'product_id' => $product_id,
                    'quantity' => $item['quantity'],
                    'total' => $item['total']
                );
            }
        }

        // 添加交易记录
        $daily_data['transactions'][] = array(
            'order_id' => $order_id,
            'time' => current_time('H:i:s'),
            'amount' => $total_amount,
            'payment_method' => $payment_method,
            'payment_detail' => $payment_detail
        );

        $daily_data['last_updated'] = current_time('mysql');

        // 🔥 在事务内写入，确保原子性
        $serialized = maybe_serialize($daily_data);
        if ($row) {
            $result = $wpdb->update(
                $wpdb->options,
                array('option_value' => $serialized),
                array('option_name' => $daily_data_key)
            );
        } else {
            $result = $wpdb->insert(
                $wpdb->options,
                array(
                    'option_name' => $daily_data_key,
                    'option_value' => $serialized,
                    'autoload' => 'no'
                )
            );
        }

        if ($result !== false) {
            $wpdb->query('COMMIT');
            // 🔥 清除WordPress options缓存，确保下次get_option读到最新数据
            wp_cache_delete($daily_data_key, 'options');
            wp_cache_delete('alloptions', 'options');
            return true;
        }

        // 写入失败，回滚重试
        $wpdb->query('ROLLBACK');
        ruiyi_pos_log("RUIYI POS Daily: Transaction attempt {$attempt} failed for order #{$order_id}, retrying...");
        usleep(50000 * $attempt); // 50ms, 100ms, 150ms
    }

    // 所有重试都失败，使用原始方式兜底
    ruiyi_pos_log("RUIYI POS Daily: All lock attempts failed for order #{$order_id}, falling back to update_option");
    update_option($daily_data_key, $daily_data, 'no');
    return true;
}

/**
 * 自动记录订单到日结单
 * Automatically record order to daily summary when completed
 */
function ruiyi_retail_auto_record_to_daily_summary($order_id, $order = null) {
    if (!$order) {
        $order = wc_get_order($order_id);
    }

    if (!$order) {
        return;
    }

    // 防止重复记录检查
    $already_recorded = $order->get_meta('_recorded_in_daily_summary', true);
    if ($already_recorded === 'yes') {
        return;
    }

    // 获取支付方式和商品信息
    $payment_method = $order->get_payment_method();
    $total_amount = floatval($order->get_total());

    // 收集订单商品
    $items = array();
    foreach ($order->get_items() as $item) {
        $items[] = array(
            'name' => $item->get_name(),
            'product_id' => $item->get_product_id(),
            'quantity' => $item->get_quantity(),
            'total' => floatval($item->get_total())
        );
    }

    // 调用记录函数
    ruiyi_retail_record_daily_transaction($order_id, $payment_method, $total_amount, $items);

    // 标记已记录
    $order->update_meta_data('_recorded_in_daily_summary', 'yes');
    $order->save();
}
add_action('woocommerce_order_status_completed', 'ruiyi_retail_auto_record_to_daily_summary', 20, 2);

/**
 * WooCommerce订单状态变为已取消时，自动从日结单/月结单中扣除
 * 适用于后台手动更改订单状态的场景
 */
function ruiyi_retail_on_order_cancelled($order_id, $order = null) {
    if (!$order) {
        $order = wc_get_order($order_id);
    }
    if (!$order) return;

    // 检查是否已经从日结单中扣除过（避免POS前端作废时重复扣除）
    $already_voided = $order->get_meta('_voided_from_daily_summary');
    if ($already_voided === 'yes') {
        return;
    }

    // 检查订单是否曾记录到日结单
    $recorded = $order->get_meta('_recorded_in_daily_summary');
    if ($recorded !== 'yes') {
        return;
    }

    ruiyi_pos_log("[POS Auto Void] Order #{$order_id} cancelled via admin, deducting from daily/monthly summary");
    ruiyi_pos_deduct_from_daily_summary($order);
}
add_action('woocommerce_order_status_cancelled', 'ruiyi_retail_on_order_cancelled', 20, 2);

/**
 * AJAX: 获取今日日结单数据
 * AJAX: Get today's daily summary data
 */
function ruiyi_retail_get_daily_summary() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ruiyi_pos_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed', 'code' => 'nonce_expired'));
        return;
    }

    $today = date('Y-m-d');
    $daily_data_key = 'ruiyi_retail_daily_summary_' . $today;
    $daily_data = get_option($daily_data_key, ruiyi_retail_get_default_daily_summary());

    // 转换产品数组并排序（按销售额排序）
    $products_array = array_values($daily_data['products']);
    usort($products_array, function($a, $b) {
        return $b['total'] - $a['total'];
    });

    // 按分类汇总
    $categories_summary = array();
    foreach ($products_array as $product) {
        $category = $product['category'];
        if (!isset($categories_summary[$category])) {
            $categories_summary[$category] = array(
                'name' => $category,
                'total' => 0
            );
        }
        $categories_summary[$category]['total'] += $product['total'];
    }

    wp_send_json_success(array(
        'total_amount' => $daily_data['total_amount'],
        'card_amount' => $daily_data['card_amount'],
        'cash_amount' => $daily_data['cash_amount'],
        'transfer_amount' => isset($daily_data['transfer_amount']) ? $daily_data['transfer_amount'] : 0,
        'mixed_payment_count' => $daily_data['mixed_payment_count'],
        'products' => $products_array,
        'categories' => array_values($categories_summary),
        'date' => $today,
        'orders_count' => count($daily_data['transactions']),
        'last_updated' => $daily_data['last_updated']
    ));
}
add_action('wp_ajax_ruiyi_retail_get_daily_summary', 'ruiyi_retail_get_daily_summary');

/**
 * AJAX: 清空今日日结单数据
 * AJAX: Clear today's daily summary data
 */
function ruiyi_retail_clear_daily_summary() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ruiyi_pos_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed', 'code' => 'nonce_expired'));
        return;
    }

    // 验证权限（只有管理员可以清空）
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied', 'ruiyi-retail-pos')));
    }

    $today = date('Y-m-d');
    $daily_data_key = 'ruiyi_retail_daily_summary_' . $today;

    delete_option($daily_data_key);

    wp_send_json_success(array('message' => __('Daily summary cleared successfully', 'ruiyi-retail-pos')));
}
add_action('wp_ajax_ruiyi_retail_clear_daily_summary', 'ruiyi_retail_clear_daily_summary');

/**
 * AJAX: 保存日结单到月结单
 * AJAX: Save daily summary to monthly summary
 */
function ruiyi_retail_save_daily_to_monthly() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ruiyi_pos_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed', 'code' => 'nonce_expired'));
        return;
    }

    ruiyi_pos_log('=== 开始保存日结单到月结单 ===');

    // 计算业务日期（凌晨4点前算前一天）
    $settlement_timestamp = current_time('timestamp');
    $settlement_hour = intval(date('H', $settlement_timestamp));

    if ($settlement_hour < 4) {
        $business_date = date('Y-m-d', strtotime('-1 day', $settlement_timestamp));
    } else {
        $business_date = date('Y-m-d', $settlement_timestamp);
    }

    ruiyi_pos_log('业务日期: ' . $business_date);

    // 获取今日日结单数据
    $today = date('Y-m-d');
    $daily_data_key = 'ruiyi_retail_daily_summary_' . $today;
    $daily_data = get_option($daily_data_key, ruiyi_retail_get_default_daily_summary());

    ruiyi_pos_log('日结单数据键: ' . $daily_data_key);
    ruiyi_pos_log('日结单数据: ' . print_r($daily_data, true));

    // 检查是否有数据
    if ($daily_data['total_amount'] <= 0) {
        ruiyi_pos_log('错误: 没有数据可保存，total_amount = ' . $daily_data['total_amount']);
        wp_send_json_error(array('message' => 'No hay datos para guardar (total: €0.00)'));
        return;
    }

    // 准备分类数据
    $categories_data = array();
    foreach ($daily_data['products'] as $product) {
        $category = isset($product['category']) ? $product['category'] : 'Sin categoría';
        if (!isset($categories_data[$category])) {
            $categories_data[$category] = array('name' => $category, 'total' => 0);
        }
        $categories_data[$category]['total'] += floatval($product['total']);
    }

    ruiyi_pos_log('分类数据: ' . print_r($categories_data, true));

    // 🔥 新方案：使用时间戳确保每次结单都是独立记录
    // 生成唯一的结单ID：日期_时间戳
    $settlement_timestamp = current_time('timestamp');
    $settlement_time = date('His', $settlement_timestamp); // 格式: 235959
    $settlement_id = $business_date . '_' . $settlement_time; // 格式: 2025-11-24_235959

    // 🔥 关键修复：每次结单使用唯一键，不会覆盖
    $monthly_record_key = 'ruiyi_retail_monthly_record_' . $settlement_id;

    // 准备要保存的完整数据
    $monthly_record = array(
        'settlement_id' => $settlement_id, // 🔥 新增：结单唯一ID
        'business_date' => $business_date,
        'settlement_datetime' => current_time('mysql'),
        'settlement_timestamp' => $settlement_timestamp, // 🔥 新增：时间戳用于排序
        'total_amount' => $daily_data['total_amount'],
        'cash_amount' => $daily_data['cash_amount'],
        'card_amount' => $daily_data['card_amount'],
        'transfer_amount' => isset($daily_data['transfer_amount']) ? $daily_data['transfer_amount'] : 0,
        'mixed_payment_count' => $daily_data['mixed_payment_count'],
        'categories_data' => $categories_data,
        'transactions_data' => $daily_data['transactions'],
        'transactions_count' => count($daily_data['transactions']),
        'products_data' => $daily_data['products'],
        'saved_by' => get_current_user_id(),
        'saved_at' => current_time('mysql')
    );

    ruiyi_pos_log('准备保存月结单记录: ' . $monthly_record_key);
    ruiyi_pos_log('结单ID: ' . $settlement_id);
    ruiyi_pos_log('记录数据: ' . print_r($monthly_record, true));

    // 保存到options表
    $save_result = update_option($monthly_record_key, $monthly_record, 'no');

    if (!$save_result && get_option($monthly_record_key) !== $monthly_record) {
        ruiyi_pos_log('错误: 保存月结单记录失败');
        wp_send_json_error(array('message' => 'Error al guardar el registro mensual'));
        return;
    }

    ruiyi_pos_log('成功保存月结单记录: ' . $monthly_record_key);

    // 🔥 维护月度索引：存储所有结单ID而不只是日期
    $month_key = date('Y-m', strtotime($business_date));
    $month_index_key = 'ruiyi_retail_monthly_index_' . $month_key;
    $month_index = get_option($month_index_key, array());

    // 🔥 关键修复：索引中存储完整的settlement_id，支持同一天多次结单
    if (!in_array($settlement_id, $month_index)) {
        $month_index[] = $settlement_id;
        // 按时间戳排序（最新的在后）
        usort($month_index, function($a, $b) {
            return strcmp($a, $b); // 字符串排序即可（格式已保证可排序）
        });
        update_option($month_index_key, $month_index, 'no');
        ruiyi_pos_log('更新月度索引: ' . $month_index_key . ' - 添加结单ID: ' . $settlement_id);
    }

    // 使用时间戳作为伪post_id返回
    $pseudo_post_id = $settlement_timestamp;

    ruiyi_pos_log('Meta数据已保存:');
    ruiyi_pos_log('  - business_date: ' . $business_date);
    ruiyi_pos_log('  - total_amount: ' . $daily_data['total_amount']);
    ruiyi_pos_log('  - cash_amount: ' . $daily_data['cash_amount']);
    ruiyi_pos_log('  - card_amount: ' . $daily_data['card_amount']);

    // 删除今日临时数据
    $deleted = delete_option($daily_data_key);
    ruiyi_pos_log('临时数据删除结果: ' . ($deleted ? '成功' : '失败'));

    ruiyi_pos_log('=== 保存完成 ===');

    wp_send_json_success(array(
        'message' => 'Cierre diario guardado exitosamente',
        'post_id' => $pseudo_post_id, // 🔥 返回伪ID而不是真实post_id
        'record_key' => $monthly_record_key, // 🔥 同时返回记录键名
        'business_date' => $business_date,
        'debug' => array(
            'total_amount' => $daily_data['total_amount'],
            'cash_amount' => $daily_data['cash_amount'],
            'card_amount' => $daily_data['card_amount'],
            'transactions_count' => count($daily_data['transactions']),
            'storage_method' => 'wp_options' // 🔥 标记使用options表存储
        )
    ));
}
add_action('wp_ajax_ruiyi_retail_save_daily_to_monthly', 'ruiyi_retail_save_daily_to_monthly');

/**
 * 🔥 AJAX: 自动保存日结单到月结单 (午夜自动执行)
 * AJAX: Auto save daily settlement to monthly (midnight auto execution)
 *
 * 使用与手动保存相同的逻辑：
 * 1. 从 ruiyi_retail_daily_summary_YYYY-MM-DD 读取数据
 * 2. 保存到 ruiyi_retail_monthly_record_YYYY-MM-DD_HHMMSS
 * 3. 更新月度索引 ruiyi_retail_monthly_index_YYYY-MM
 * 4. 删除日结单数据
 */
function ruiyi_pos_auto_save_daily_settlement() {
    // Check nonce
    if (!check_ajax_referer('ruiyi_pos_nonce', 'nonce', false)) {
        ruiyi_pos_log('[Auto Settlement] ERROR: Invalid nonce');
        wp_send_json_error(array('message' => 'Invalid nonce'));
        return;
    }

    // Check permissions
    if (!current_user_can('manage_woocommerce') && !ruiyi_pos_check_access()) {
        ruiyi_pos_log('[Auto Settlement] ERROR: Permission denied');
        wp_send_json_error(array('message' => 'Permission denied'));
        return;
    }

    // Check if auto daily settlement is enabled
    $auto_enabled = get_option('ruiyi_retail_auto_daily_settlement_enabled', false);
    if (!$auto_enabled) {
        ruiyi_pos_log('[Auto Settlement] ERROR: Feature is not enabled in settings');
        wp_send_json_error(array('message' => 'Auto daily settlement is not enabled'));
        return;
    }

    ruiyi_pos_log('=== [Auto Settlement] Started ===');

    // Get the date to save (from parameter or default to yesterday)
    $date_to_save = isset($_POST['date_to_save']) ? sanitize_text_field($_POST['date_to_save']) : date('Y-m-d', strtotime('-1 day'));

    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to_save)) {
        $date_to_save = date('Y-m-d', strtotime('-1 day'));
    }

    ruiyi_pos_log('[Auto Settlement] Requested date: ' . $date_to_save);

    // 🔥 关键修复：使用与手动保存相同的逻辑
    // 从 wp_options 读取日结单数据（与手动保存一致）
    $daily_data_key = 'ruiyi_retail_daily_summary_' . $date_to_save;
    $daily_data = get_option($daily_data_key, null);

    ruiyi_pos_log('[Auto Settlement] Looking for daily data key: ' . $daily_data_key);
    ruiyi_pos_log('[Auto Settlement] Daily data found: ' . ($daily_data ? 'YES' : 'NO'));

    // 如果没有日结单数据，跳过保存
    if (!$daily_data || empty($daily_data)) {
        ruiyi_pos_log('[Auto Settlement] No daily summary data found for ' . $date_to_save . ', skipping');
        wp_send_json_success(array(
            'message' => 'No daily data to save',
            'business_date' => $date_to_save,
            'skipped' => true,
            'reason' => 'no_daily_data',
            'daily_data_key' => $daily_data_key
        ));
        return;
    }

    // 如果没有使用默认结构，创建默认值
    if (!isset($daily_data['total_amount'])) {
        $daily_data = ruiyi_retail_get_default_daily_summary();
    }

    // 检查是否有实际数据
    if ($daily_data['total_amount'] <= 0) {
        ruiyi_pos_log('[Auto Settlement] Total amount is 0, skipping save');
        wp_send_json_success(array(
            'message' => 'No transactions to save (total: 0)',
            'business_date' => $date_to_save,
            'skipped' => true,
            'reason' => 'zero_total'
        ));
        return;
    }

    ruiyi_pos_log('[Auto Settlement] Daily data: total=' . $daily_data['total_amount'] .
              ', cash=' . $daily_data['cash_amount'] .
              ', card=' . $daily_data['card_amount'] .
              ', transactions=' . count($daily_data['transactions']));

    // 准备分类数据（与手动保存一致）
    $categories_data = array();
    if (isset($daily_data['products']) && is_array($daily_data['products'])) {
        foreach ($daily_data['products'] as $product) {
            $category = isset($product['category']) ? $product['category'] : 'Sin categoría';
            if (!isset($categories_data[$category])) {
                $categories_data[$category] = array('name' => $category, 'total' => 0);
            }
            $categories_data[$category]['total'] += floatval($product['total']);
        }
    }

    // 生成唯一的结单ID：日期_时间戳
    $settlement_timestamp = current_time('timestamp');
    $settlement_time = date('His', $settlement_timestamp);
    $settlement_id = $date_to_save . '_' . $settlement_time;

    // 使用与手动保存相同的月结单记录键
    $monthly_record_key = 'ruiyi_retail_monthly_record_' . $settlement_id;

    ruiyi_pos_log('[Auto Settlement] Settlement ID: ' . $settlement_id);
    ruiyi_pos_log('[Auto Settlement] Monthly record key: ' . $monthly_record_key);

    // 准备要保存的完整数据（与手动保存格式完全一致）
    $monthly_record = array(
        'settlement_id' => $settlement_id,
        'business_date' => $date_to_save,
        'settlement_datetime' => current_time('mysql'),
        'settlement_timestamp' => $settlement_timestamp,
        'total_amount' => $daily_data['total_amount'],
        'cash_amount' => $daily_data['cash_amount'],
        'card_amount' => $daily_data['card_amount'],
        'transfer_amount' => isset($daily_data['transfer_amount']) ? $daily_data['transfer_amount'] : 0,
        'mixed_payment_count' => isset($daily_data['mixed_payment_count']) ? $daily_data['mixed_payment_count'] : 0,
        'categories_data' => $categories_data,
        'transactions_data' => $daily_data['transactions'],
        'transactions_count' => count($daily_data['transactions']),
        'products_data' => isset($daily_data['products']) ? $daily_data['products'] : array(),
        'saved_by' => get_current_user_id(),
        'saved_at' => current_time('mysql'),
        'auto_saved' => true // 标记为自动保存
    );

    // 保存到options表
    $save_result = update_option($monthly_record_key, $monthly_record, 'no');

    if (!$save_result && get_option($monthly_record_key) !== $monthly_record) {
        ruiyi_pos_log('[Auto Settlement] ERROR: Failed to save monthly record');
        wp_send_json_error(array('message' => 'Error al guardar el registro mensual'));
        return;
    }

    ruiyi_pos_log('[Auto Settlement] Monthly record saved successfully');

    // 维护月度索引（与手动保存一致）
    $month_key = date('Y-m', strtotime($date_to_save));
    $month_index_key = 'ruiyi_retail_monthly_index_' . $month_key;
    $month_index = get_option($month_index_key, array());

    if (!in_array($settlement_id, $month_index)) {
        $month_index[] = $settlement_id;
        usort($month_index, function($a, $b) {
            return strcmp($a, $b);
        });
        update_option($month_index_key, $month_index, 'no');
        ruiyi_pos_log('[Auto Settlement] Monthly index updated: ' . $month_index_key);
    }

    // 🔥 关键：删除日结单数据（与手动保存一致）
    $deleted = delete_option($daily_data_key);
    ruiyi_pos_log('[Auto Settlement] Daily data deleted: ' . ($deleted ? 'YES' : 'NO'));

    ruiyi_pos_log('=== [Auto Settlement] Completed Successfully ===');

    wp_send_json_success(array(
        'message' => 'Daily settlement auto-saved successfully',
        'business_date' => $date_to_save,
        'settlement_id' => $settlement_id,
        'total_amount' => $daily_data['total_amount'],
        'transactions_count' => count($daily_data['transactions']),
        'daily_data_deleted' => $deleted,
        'auto_saved' => true
    ));
}

/**
 * 🔥 AJAX: 保存自动日结单保存时间设置
 * AJAX: Save auto daily settlement time setting
 */
function ruiyi_pos_save_auto_settlement_time() {
    // Check nonce
    if (!check_ajax_referer('ruiyi_pos_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'Invalid nonce'));
        return;
    }

    // Check permissions
    if (!current_user_can('manage_woocommerce') && !ruiyi_pos_check_access()) {
        wp_send_json_error(array('message' => 'Permission denied'));
        return;
    }

    // Get and validate time
    $time = isset($_POST['time']) ? sanitize_text_field($_POST['time']) : '00:00';

    // Validate time format (HH:MM)
    if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
        wp_send_json_error(array('message' => 'Invalid time format'));
        return;
    }

    // Save the time setting
    update_option('ruiyi_retail_auto_daily_settlement_time', $time);

    ruiyi_pos_log('[Auto Settlement] Time setting saved: ' . $time);

    wp_send_json_success(array(
        'message' => 'Auto settlement time saved',
        'time' => $time
    ));
}

/**
 * 🔥 AJAX: 手动触发自动保存（用于测试）
 * AJAX: Manual trigger auto settlement (for testing)
 */
function ruiyi_pos_manual_trigger_auto_settlement() {
    // Check nonce
    if (!check_ajax_referer('ruiyi_pos_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'Invalid nonce'));
        return;
    }

    // Check permissions - only admin
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Permission denied - admin only'));
        return;
    }

    ruiyi_pos_log('[Auto Settlement] Manual trigger requested');

    // 获取设定时间
    $target_time = get_option('ruiyi_retail_auto_daily_settlement_time', '00:00');
    list($target_hour, $target_minute) = explode(':', $target_time);
    $target_hour = intval($target_hour);

    // 计算要保存的日期
    $current_date = current_time('Y-m-d');
    if ($target_hour >= 4) {
        $date_to_save = $current_date;
    } else {
        $date_to_save = date('Y-m-d', strtotime('-1 day', current_time('timestamp')));
    }

    ruiyi_pos_log('[Auto Settlement] Manual trigger: saving ' . $date_to_save);

    // 执行保存
    $result = ruiyi_pos_cron_execute_auto_settlement($date_to_save);

    if ($result['success']) {
        // 如果成功保存（非跳过），记录执行日期
        if (!isset($result['skipped']) || !$result['skipped']) {
            update_option('ruiyi_retail_last_cron_settlement_date', $current_date);
        }

        wp_send_json_success(array(
            'message' => $result['message'],
            'date_saved' => $date_to_save,
            'skipped' => isset($result['skipped']) ? $result['skipped'] : false,
            'settlement_id' => isset($result['settlement_id']) ? $result['settlement_id'] : null,
            'total_amount' => isset($result['total_amount']) ? $result['total_amount'] : null
        ));
    } else {
        wp_send_json_error(array(
            'message' => $result['message'],
            'date_to_save' => $date_to_save
        ));
    }
}

/**
 * 🔥 AJAX: 页面关闭时自动保存日结单
 * AJAX: Auto save daily settlement when page closes
 * 使用 sendBeacon 发送，即使页面关闭也能可靠接收
 */
function ruiyi_pos_page_close_auto_save() {
    // Check nonce - 对于 sendBeacon，nonce 验证可能失败，所以我们也检查用户权限
    $nonce_valid = check_ajax_referer('ruiyi_pos_nonce', 'nonce', false);

    // Check permissions
    if (!current_user_can('manage_woocommerce') && !ruiyi_pos_check_access()) {
        ruiyi_pos_log('[Page Close Auto Save] Permission denied');
        wp_send_json_error(array('message' => 'Permission denied'));
        return;
    }

    // Check if auto daily settlement is enabled
    $auto_enabled = get_option('ruiyi_retail_auto_daily_settlement_enabled', false);
    if (!$auto_enabled) {
        ruiyi_pos_log('[Page Close Auto Save] Feature is disabled');
        wp_send_json_error(array('message' => 'Auto daily settlement is not enabled'));
        return;
    }

    ruiyi_pos_log('[Page Close Auto Save] Triggered');

    // 获取要保存的日期
    $date_to_save = isset($_POST['date_to_save']) ? sanitize_text_field($_POST['date_to_save']) : current_time('Y-m-d');

    // 验证日期格式
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to_save)) {
        $date_to_save = current_time('Y-m-d');
    }

    ruiyi_pos_log('[Page Close Auto Save] Saving date: ' . $date_to_save);

    // 🔥 不再检查今天是否已保存，允许一天多次保存
    // 每次保存都会生成新的月结单记录（带唯一时间戳）

    // 执行保存
    $result = ruiyi_pos_cron_execute_auto_settlement($date_to_save);

    if ($result['success']) {
        if (!isset($result['skipped']) || !$result['skipped']) {
            ruiyi_pos_log('[Page Close Auto Save] Successfully saved: ' . $date_to_save . ' (settlement_id: ' . ($result['settlement_id'] ?? 'N/A') . ')');
        } else {
            ruiyi_pos_log('[Page Close Auto Save] Skipped (no data): ' . $result['message']);
        }

        wp_send_json_success(array(
            'message' => $result['message'],
            'date_saved' => $date_to_save,
            'skipped' => isset($result['skipped']) ? $result['skipped'] : false,
            'settlement_id' => isset($result['settlement_id']) ? $result['settlement_id'] : null
        ));
    } else {
        ruiyi_pos_log('[Page Close Auto Save] Failed: ' . $result['message']);
        wp_send_json_error(array(
            'message' => $result['message']
        ));
    }
}

/**
 * AJAX: 获取月结单数据
 * AJAX: Get monthly summary data
 */
function ruiyi_retail_get_monthly_summary() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ruiyi_pos_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed', 'code' => 'nonce_expired'));
        return;
    }

    ruiyi_pos_log('=== 开始查询月结单数据 ===');

    $from_date = isset($_POST['from_date']) ? sanitize_text_field($_POST['from_date']) : '';
    $to_date = isset($_POST['to_date']) ? sanitize_text_field($_POST['to_date']) : '';
    $month = isset($_POST['month']) ? sanitize_text_field($_POST['month']) : '';
    $specific_date = isset($_POST['specific_date']) ? sanitize_text_field($_POST['specific_date']) : '';

    ruiyi_pos_log('查询参数: from_date=' . $from_date . ', to_date=' . $to_date . ', month=' . $month . ', specific_date=' . $specific_date);

    // 🔥 新方案：从options表读取月结单记录
    global $wpdb;

    // 确定日期范围
    $date_start = '';
    $date_end = '';

    if ($specific_date) {
        // 🔥 按特定日期查询
        $date_start = $specific_date;
        $date_end = $specific_date;
        ruiyi_pos_log('使用特定日期查询: ' . $specific_date);
    } elseif ($month) {
        $date_start = $month . '-01';
        $date_end = date('Y-m-t', strtotime($date_start));
        ruiyi_pos_log('使用月份查询: ' . $date_start . ' 到 ' . $date_end);
    } elseif ($from_date && $to_date) {
        $date_start = $from_date;
        $date_end = $to_date;
        ruiyi_pos_log('使用日期范围查询: ' . $date_start . ' 到 ' . $date_end);
    } else {
        // 默认查询当前月
        $date_start = date('Y-m-01');
        $date_end = date('Y-m-t');
        ruiyi_pos_log('使用默认当月查询: ' . $date_start . ' 到 ' . $date_end);
    }

    // 查询options表，匹配模式 'ruiyi_retail_monthly_record_YYYY-MM-DD'
    $pattern = 'ruiyi_retail_monthly_record_%';
    $query = $wpdb->prepare(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
        $pattern
    );

    ruiyi_pos_log('SQL查询: ' . $query);

    $results = $wpdb->get_results($query);
    ruiyi_pos_log('查询到 ' . count($results) . ' 条记录');

    // 汇总数据
    $records = array();
    $total_sum = $cash_sum = $card_sum = $transfer_sum = 0;

    foreach ($results as $row) {
        $option_name = $row->option_name;
        $record_data = maybe_unserialize($row->option_value);

        // 🔥 提取结单ID（格式: 2025-11-24_235959）
        $settlement_id = str_replace('ruiyi_retail_monthly_record_', '', $option_name);

        // 🔥 从settlement_id中提取业务日期（_之前的部分）
        $parts = explode('_', $settlement_id);
        $business_date = $parts[0]; // 2025-11-24

        // 过滤日期范围
        if ($business_date < $date_start || $business_date > $date_end) {
            continue;
        }

        $total = floatval($record_data['total_amount']);
        $cash = floatval($record_data['cash_amount']);
        $card = floatval($record_data['card_amount']);
        $transfer = isset($record_data['transfer_amount']) ? floatval($record_data['transfer_amount']) : 0;
        $mixed_count = isset($record_data['mixed_payment_count']) ? intval($record_data['mixed_payment_count']) : 0;
        $transactions_count = isset($record_data['transactions_count']) ? intval($record_data['transactions_count']) : 0;
        $settlement_datetime = isset($record_data['settlement_datetime']) ? $record_data['settlement_datetime'] : '';
        $settlement_timestamp = isset($record_data['settlement_timestamp']) ? intval($record_data['settlement_timestamp']) : 0;

        $records[] = array(
            'settlement_id' => $settlement_id, // 🔥 新增：返回完整结单ID
            'date' => $business_date,
            'settlement_datetime' => $settlement_datetime,
            'settlement_time' => isset($parts[1]) ? $parts[1] : '', // 235959
            'total_amount' => $total,
            'cash_amount' => $cash,
            'card_amount' => $card,
            'transfer_amount' => $transfer,
            'mixed_payment_count' => $mixed_count,
            'transactions_count' => $transactions_count
        );

        $total_sum += $total;
        $cash_sum += $cash;
        $card_sum += $card;
        $transfer_sum += $transfer;

        ruiyi_pos_log('记录: ' . $settlement_id . ' - 业务日期: ' . $business_date . ' - 总额: €' . $total . ', 现金: €' . $cash . ', 刷卡: €' . $card . ', 转账: €' . $transfer);
    }

    // 🔥 按结单时间降序排序（最新的在前）
    usort($records, function($a, $b) {
        return strcmp($b['settlement_id'], $a['settlement_id']);
    });

    ruiyi_pos_log('=== 查询完成，共 ' . count($records) . ' 条记录 ===');
    ruiyi_pos_log('汇总: 总额 €' . $total_sum . ', 现金 €' . $cash_sum . ', 刷卡 €' . $card_sum . ', 转账: €' . $transfer_sum);

    wp_send_json_success(array(
        'records' => $records,
        'summary' => array(
            'total' => $total_sum,
            'cash' => $cash_sum,
            'card' => $card_sum,
            'transfer' => $transfer_sum,
            'count' => count($records)
        ),
        'filters' => array(
            'from_date' => $from_date,
            'to_date' => $to_date,
            'month' => $month
        )
    ));
}
add_action('wp_ajax_ruiyi_retail_get_monthly_summary', 'ruiyi_retail_get_monthly_summary');

/**
 * AJAX: 获取产品统计数据（从WC订单查询）
 * AJAX: Get product statistics from WooCommerce orders
 *
 * 支持两种模式：
 * - period=today: 查询今天的已完成订单
 * - period=month + month=YYYY-MM: 查询指定月份的已完成订单
 */
function ruiyi_retail_get_product_statistics() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ruiyi_pos_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed', 'code' => 'nonce_expired'));
        return;
    }

    $period = isset($_POST['period']) ? sanitize_text_field($_POST['period']) : 'month';
    $month = isset($_POST['month']) ? sanitize_text_field($_POST['month']) : date('Y-m');

    // 确定日期范围
    if ($period === 'today') {
        $date_start = date('Y-m-d') . ' 00:00:00';
        $date_end = date('Y-m-d') . ' 23:59:59';
        $date_label = date('Y-m-d');
    } else {
        $date_start = $month . '-01 00:00:00';
        $date_end = date('Y-m-t', strtotime($month . '-01')) . ' 23:59:59';
        $date_label = date('Y年m月', strtotime($month . '-01'));
    }

    // 查询WC已完成订单
    $orders = wc_get_orders(array(
        'status' => array('completed'),
        'date_created' => $date_start . '...' . $date_end,
        'limit' => -1,
        'return' => 'ids',
    ));

    // 聚合产品数据
    $products_aggregated = array();
    $total_orders = count($orders);

    foreach ($orders as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) continue;

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $name = $item->get_name();
            $quantity = $item->get_quantity();
            $total = floatval($item->get_total());
            $sku = '';

            // 获取SKU
            $product_obj = $item->get_product();
            if ($product_obj) {
                $sku = $product_obj->get_sku();
            }

            // 用产品名称+SKU作为聚合键，避免同名不同产品合并
            $key = $name . '|' . $sku;

            if (isset($products_aggregated[$key])) {
                $products_aggregated[$key]['quantity'] += $quantity;
                $products_aggregated[$key]['total'] += $total;
            } else {
                $products_aggregated[$key] = array(
                    'name' => $name,
                    'sku' => $sku,
                    'product_id' => $variation_id ? $variation_id : $product_id,
                    'quantity' => $quantity,
                    'total' => $total
                );
            }
        }
    }

    // 按金额降序排序
    $products_array = array_values($products_aggregated);
    usort($products_array, function($a, $b) {
        if ($b['total'] == $a['total']) return 0;
        return ($b['total'] > $a['total']) ? 1 : -1;
    });

    wp_send_json_success(array(
        'products' => $products_array,
        'date_label' => $date_label,
        'month' => $month,
        'period' => $period,
        'total_orders' => $total_orders
    ));
}
add_action('wp_ajax_ruiyi_retail_get_product_statistics', 'ruiyi_retail_get_product_statistics');

/**
 * AJAX: 清空月结单数据
 * AJAX: Clear monthly settlement data
 *
 * @param string $month 月份格式 YYYY-MM，如果为空则清空所有
 */
function ruiyi_retail_clear_monthly_summary() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ruiyi_pos_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed', 'code' => 'nonce_expired'));
        return;
    }

    // 检查用户权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Permission denied. Administrator access required.'));
        return;
    }

    $month = isset($_POST['month']) ? sanitize_text_field($_POST['month']) : '';
    $clear_all = isset($_POST['clear_all']) && $_POST['clear_all'] === 'true';

    global $wpdb;
    $deleted_count = 0;

    ruiyi_pos_log('=== 开始清空月结单数据 ===');
    ruiyi_pos_log('参数: month=' . $month . ', clear_all=' . ($clear_all ? 'true' : 'false'));

    if ($clear_all) {
        // 清空所有月结单数据
        // 删除所有月结单记录
        $records_deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ruiyi_retail_monthly_record_%'"
        );
        // 删除所有月度索引
        $index_deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ruiyi_retail_monthly_index_%'"
        );

        $deleted_count = $records_deleted + $index_deleted;
        ruiyi_pos_log('清空所有数据: 删除 ' . $records_deleted . ' 条记录, ' . $index_deleted . ' 条索引');

    } elseif (!empty($month)) {
        // 清空指定月份的数据
        // 格式验证 YYYY-MM
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            wp_send_json_error(array('message' => 'Invalid month format. Use YYYY-MM.'));
            return;
        }

        // 删除该月份的所有记录 (格式: ruiyi_retail_monthly_record_YYYY-MM-DD_HHMMSS)
        $records_deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            'ruiyi_retail_monthly_record_' . $month . '%'
        ));

        // 删除该月份的索引
        $index_key = 'ruiyi_retail_monthly_index_' . $month;
        delete_option($index_key);

        $deleted_count = $records_deleted + 1;
        ruiyi_pos_log('清空月份 ' . $month . ' 数据: 删除 ' . $records_deleted . ' 条记录');
    } else {
        wp_send_json_error(array('message' => 'Please specify a month or select clear all.'));
        return;
    }

    // 清理缓存
    wp_cache_flush();

    ruiyi_pos_log('=== 清空完成，共删除 ' . $deleted_count . ' 条数据 ===');

    wp_send_json_success(array(
        'message' => sprintf('Successfully deleted %d records.', $deleted_count),
        'deleted_count' => $deleted_count,
        'month' => $month,
        'clear_all' => $clear_all
    ));
}
add_action('wp_ajax_ruiyi_retail_clear_monthly_summary', 'ruiyi_retail_clear_monthly_summary');

/**
 * 添加WordPress后台管理菜单 - 月结单数据管理
 * Add WordPress admin menu for monthly settlement data management
 */
function ruiyi_retail_add_settlement_admin_menu() {
    add_submenu_page(
        'woocommerce',                              // 父菜单
        'POS Settlement Data',                       // 页面标题
        'POS Settlement Data',                       // 菜单标题
        'manage_options',                            // 权限
        'ruiyi-pos-settlement',                      // 菜单slug
        'ruiyi_retail_settlement_admin_page'         // 回调函数
    );
}
add_action('admin_menu', 'ruiyi_retail_add_settlement_admin_menu');

/**
 * WordPress后台月结单管理页面
 * WordPress admin page for monthly settlement management
 */
function ruiyi_retail_settlement_admin_page() {
    // 处理表单提交
    if (isset($_POST['ruiyi_clear_settlement']) && wp_verify_nonce($_POST['ruiyi_settlement_nonce'], 'ruiyi_clear_settlement_action')) {
        global $wpdb;
        $clear_type = sanitize_text_field($_POST['clear_type']);
        $clear_month = isset($_POST['clear_month']) ? sanitize_text_field($_POST['clear_month']) : '';
        $deleted_count = 0;
        $message = '';

        if ($clear_type === 'all') {
            // 清空所有数据
            $records_deleted = $wpdb->query(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ruiyi_retail_monthly_record_%'"
            );
            $index_deleted = $wpdb->query(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ruiyi_retail_monthly_index_%'"
            );
            // 同时清空日结单数据
            $daily_deleted = $wpdb->query(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ruiyi_retail_daily_summary_%'"
            );
            $deleted_count = $records_deleted + $index_deleted + $daily_deleted;
            $message = sprintf('✅ Successfully deleted all settlement data (%d records, %d indexes, %d daily summaries).', $records_deleted, $index_deleted, $daily_deleted);

        } elseif ($clear_type === 'month' && !empty($clear_month)) {
            // 清空指定月份
            $records_deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                'ruiyi_retail_monthly_record_' . $clear_month . '%'
            ));
            delete_option('ruiyi_retail_monthly_index_' . $clear_month);
            $deleted_count = $records_deleted + 1;
            $message = sprintf('✅ Successfully deleted %d records for month %s.', $records_deleted, $clear_month);
        }

        wp_cache_flush();
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    // 获取现有月份列表
    global $wpdb;
    $months = $wpdb->get_col(
        "SELECT DISTINCT SUBSTRING(option_name, 32, 7) as month
         FROM {$wpdb->options}
         WHERE option_name LIKE 'ruiyi_retail_monthly_record_%'
         ORDER BY month DESC"
    );

    // 统计数据
    $total_records = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'ruiyi_retail_monthly_record_%'"
    );
    $total_indexes = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'ruiyi_retail_monthly_index_%'"
    );
    ?>
    <div class="wrap">
        <h1>🧾 POS Settlement Data Management</h1>
        <p>Manage your POS daily and monthly settlement data.</p>

        <div class="card" style="max-width: 600px; padding: 20px; margin-bottom: 20px;">
            <h2>📊 Current Data Statistics</h2>
            <table class="widefat" style="max-width: 400px;">
                <tr>
                    <td><strong>Monthly Settlement Records:</strong></td>
                    <td><?php echo esc_html($total_records); ?></td>
                </tr>
                <tr>
                    <td><strong>Monthly Indexes:</strong></td>
                    <td><?php echo esc_html($total_indexes); ?></td>
                </tr>
                <tr>
                    <td><strong>Available Months:</strong></td>
                    <td><?php echo count($months); ?></td>
                </tr>
            </table>
        </div>

        <div class="card" style="max-width: 600px; padding: 20px; background-color: #fff3cd; border-left: 4px solid #ffc107;">
            <h2>⚠️ Clear Settlement Data</h2>
            <p><strong>Warning:</strong> This action cannot be undone. Please make sure you have a backup before proceeding.</p>

            <form method="post" action="" onsubmit="return confirm('Are you sure you want to delete this settlement data? This action cannot be undone.');">
                <?php wp_nonce_field('ruiyi_clear_settlement_action', 'ruiyi_settlement_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Clear Type</th>
                        <td>
                            <select name="clear_type" id="clear_type" onchange="toggleMonthSelect()">
                                <option value="">-- Select --</option>
                                <option value="month">Clear Specific Month</option>
                                <option value="all">Clear ALL Data</option>
                            </select>
                        </td>
                    </tr>
                    <tr id="month_row" style="display: none;">
                        <th scope="row">Select Month</th>
                        <td>
                            <select name="clear_month" id="clear_month">
                                <option value="">-- Select Month --</option>
                                <?php foreach ($months as $m): ?>
                                    <option value="<?php echo esc_attr($m); ?>"><?php echo esc_html($m); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="ruiyi_clear_settlement" class="button button-primary" value="🗑️ Clear Settlement Data" style="background-color: #dc3545; border-color: #dc3545;">
                </p>
            </form>
        </div>

        <script>
        function toggleMonthSelect() {
            var clearType = document.getElementById('clear_type').value;
            var monthRow = document.getElementById('month_row');
            monthRow.style.display = (clearType === 'month') ? 'table-row' : 'none';
        }
        </script>
    </div>
    <?php
}