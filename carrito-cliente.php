  <?php
  /**
   * Template Name: carrito-cliente 
   * Description: Plantilla para cargar la pagina de QR CODE MENU 
   */

  // 生成用于AJAX请求的安全nonce
  $ajax_nonce = wp_create_nonce('ruiyi_pos_nonce');

  // 初始化语言：优先读取客户cookie，再使用后台默认语言设置
  // 注意：客户语言与POS收银语言完全独立，互不影响
  $default_lang = get_option('pos_customer_default_language', 'es');
  $current_lang = isset($_COOKIE['ruiyi_customer_language']) ? sanitize_text_field($_COOKIE['ruiyi_customer_language']) : $default_lang;
  if (!in_array($current_lang, ['es', 'en', 'zh'])) {
      $current_lang = 'es';
  }
  // 设置页面级语言覆盖，让 ruiyi_translate() 使用客户语言而非POS语言
  global $ruiyi_page_language;
  $ruiyi_page_language = $current_lang;

  // 🔥 仅菜单浏览模式（关闭下单功能）
  $menu_only_mode = get_option('pos_customer_menu_only_mode', '0') === '1';

  // 🔥 扫码点餐页面布局风格（V3大图 / V5列表）
  $layout_style = get_option('pos_customer_layout_style', 'v3');

  // 获取URL参数中的桌号
  $table_number = isset($_GET['table']) ? sanitize_text_field($_GET['table']) : '';

  // 🔥🔥🔥 【2026-01-19 新增】获取桌位分类映射，显示完整桌位名称（如 COMEDOR1, DOMICILIO2）
  $table_display_name = $table_number; // 默认显示原始桌号
  $table_full_id = $table_number; // 用于存储和同步的完整桌位ID

  if (!empty($table_number)) {
      // 获取桌位分类映射
      if (function_exists('ruiyi_get_all_table_categories_mapping')) {
          $table_mappings = ruiyi_get_all_table_categories_mapping();

          // 提取桌号的数字部分
          $table_numeric = preg_replace('/[^0-9]/', '', $table_number);
          // 提取桌号的前缀部分
          $table_prefix = preg_replace('/[0-9]+$/', '', trim($table_number));
          $table_prefix = strtoupper(trim($table_prefix));

          // 通用前缀列表
          $generic_prefixes = array('', 'MESA', '餐桌', 'TABLE', '桌');
          $is_generic_input = in_array($table_prefix, $generic_prefixes);

          if (!empty($table_mappings) && !empty($table_numeric)) {
              foreach ($table_mappings as $mapping) {
                  $mapping_global = isset($mapping['global_table_number']) ? strval($mapping['global_table_number']) : '';
                  $mapping_display = isset($mapping['category_display_name']) ? $mapping['category_display_name'] : '';

                  // 如果URL传入的是纯数字或通用前缀，按全局桌号匹配
                  if ($is_generic_input && $mapping_global === $table_numeric) {
                      if (!empty($mapping_display)) {
                          $table_display_name = $mapping_display; // 用于界面显示
                      }
                      // 🔥 使用 "Mesa X" 格式作为数据存储ID，与 POS 页面保持一致
                      $table_full_id = 'Mesa ' . $mapping_global;
                      break;
                  }

                  // 如果URL传入的是带前缀的桌号（如 COMEDOR1），直接匹配
                  if (!$is_generic_input && !empty($mapping_display)) {
                      // 🔥 修复：规范化两边进行比较（移除空格并转大写）
                      $mapping_display_normalized = strtoupper(str_replace(' ', '', $mapping_display));
                      $input_normalized = strtoupper(str_replace(' ', '', $table_number));
                      if ($mapping_display_normalized === $input_normalized) {
                          $table_display_name = $mapping_display; // 用于界面显示
                          // 🔥 使用 "Mesa X" 格式作为数据存储ID，与 POS 页面保持一致
                          $table_full_id = 'Mesa ' . $mapping_global;
                          break;
                      }
                  }
              }
          }
      }

      // 🔥 修复：如果精确匹配失败，尝试通过数字部分查找对应的全局桌号
      if ($table_full_id === $table_number && !empty($table_numeric) && !empty($table_mappings)) {
          // 遍历所有映射，找到数字部分匹配的
          foreach ($table_mappings as $mapping) {
              $mapping_global = isset($mapping['global_table_number']) ? strval($mapping['global_table_number']) : '';
              $mapping_display = isset($mapping['category_display_name']) ? $mapping['category_display_name'] : '';

              // 从 mapping_display 提取数字部分
              $mapping_numeric = preg_replace('/[^0-9]/', '', $mapping_display);

              // 如果数字部分匹配，使用该映射的全局桌号
              if (!empty($mapping_numeric) && $mapping_numeric === $table_numeric) {
                  $table_display_name = $mapping_display;
                  $table_full_id = 'Mesa ' . $mapping_global;
                  ruiyi_debug_log("CARRITO: 数字部分匹配成功 - 输入={$table_number}, 映射={$mapping_display}, 全局桌号={$mapping_global}");
                  break;
              }
          }
      }

      // 如果没有找到映射，但桌号是纯数字，添加 Mesa 前缀
      if ($table_display_name === $table_number && preg_match('/^\d+$/', $table_number)) {
          $table_full_id = 'Mesa ' . $table_number;
      }

      // 🔥 最终兜底：如果还是没有找到映射，但有数字部分，至少使用 Mesa + 数字
      if ($table_full_id === $table_number && !empty($table_numeric)) {
          $table_full_id = 'Mesa ' . $table_numeric;
          ruiyi_debug_log("CARRITO: 使用兜底方案 - 输入={$table_number}, 转换为={$table_full_id}");
      }
  }

  $table_global_number = preg_replace('/[^0-9]/', '', $table_full_id);
  $table_zone_category_id = function_exists('ruiyi_get_table_zone_category_id_for_buffet')
      ? ruiyi_get_table_zone_category_id_for_buffet($table_display_name ?: $table_full_id, $table_global_number)
      : 0;
  $table_buffet_exempt = function_exists('ruiyi_is_buffet_exempt_table')
      ? ruiyi_is_buffet_exempt_table($table_display_name ?: $table_full_id, $table_global_number)
      : false;

  // Función para obtener categorías de WooCommerce con soporte multiidioma
  function get_woo_categories($lang = null) {
      $categories = get_terms(array(
          'taxonomy' => 'product_cat',
          'hide_empty' => true,
      ));

      $formatted_categories = array();
      // 使用传入的语言参数，而非POS语言常量
      global $ruiyi_page_language;
      $current_lang = $lang ?: $ruiyi_page_language ?: (defined('RUIYI_CURRENT_LANG') ? RUIYI_CURRENT_LANG : 'zh');
      
      if (!is_wp_error($categories)) {
          foreach ($categories as $category) {
              // 获取多语言名称（使用正确的字段名）
              $spanish_name = get_term_meta($category->term_id, 'category_name_es', true);
              $english_name = get_term_meta($category->term_id, 'category_name_en', true);
              $chinese_name = get_term_meta($category->term_id, 'category_name_zh', true);

              // 确定显示的名称
              $display_name = $category->name; // 默认使用原名称（中文）

              if ($current_lang === 'es' && !empty($spanish_name)) {
                  $display_name = $spanish_name;
              } elseif ($current_lang === 'en' && !empty($english_name)) {
                  $display_name = $english_name;
              } elseif ($current_lang === 'zh' && !empty($chinese_name)) {
                  $display_name = $chinese_name;
              }

              $formatted_categories[] = array(
                  'id' => $category->term_id,
                  'name' => $display_name,
                  'slug' => $category->slug,
                  'original_name' => $category->name, // 保留原始名称
                  'spanish_name' => $spanish_name,
                  'english_name' => $english_name,
                  'chinese_name' => $chinese_name,
              );
          }
      }
      
      return $formatted_categories;
  }

  // Función para obtener los productos de WooCommerce
  function get_woo_products($category_id = 0) {
      if (!function_exists('wc_get_products')) {
          return array();
      }
      global $table_zone_category_id;
      
      $args = array(
          'status' => 'publish',
          'limit' => -1,
          'meta_query' => array(
              array(
                  'key' => '_is_points_product',
                  'value' => 'yes',
                  'compare' => '!='
              )
          )
      );
      
      if ($category_id > 0) {
          $args['category'] = array($category_id);
      }
      
      $products = wc_get_products($args);
      $formatted_products = array();
      
      foreach ($products as $product) {
          // 双重检查确保不是积分产品
          $is_points_product = get_post_meta($product->get_id(), '_is_points_product', true);
          if ($is_points_product === 'yes') {
              continue; // 跳过积分产品
          }

          // 🔥 跳过隐藏产品
          static $hidden_products_cache = null;
          if ($hidden_products_cache === null) {
              $hidden_products_cache = json_decode(get_option('pos_customer_hidden_products', '[]'), true) ?: array();
          }
          if (in_array($product->get_id(), $hidden_products_cache)) {
              continue;
          }

          // 客户扫码点餐不展示任何称重类商品。电子称和手动称重都必须由 POS 端录入，
          // 避免客户页下单时缺少重量导致金额、厨房单或小票数据不一致。
          if (function_exists('ruiyi_pos_is_any_weight_product') && ruiyi_pos_is_any_weight_product($product->get_id())) {
              continue;
          }

          // 获取时价标记
          $market_price = get_post_meta($product->get_id(), '_ruiyi_market_price', true);

          // 获取过敏原数据
          $allergens = get_post_meta($product->get_id(), '_ruiyi_allergens', true);
          if (!is_array($allergens)) {
              $allergens = array();
          }

          $product_category_ids = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'ids'));
          if (is_wp_error($product_category_ids)) {
              $product_category_ids = array();
          }
          $base_price = floatval($product->get_price());
          $effective_price = function_exists('ruiyi_get_effective_zone_product_price')
              ? ruiyi_get_effective_zone_product_price($product->get_id(), $table_zone_category_id)
              : $base_price;

          $formatted_products[] = array(
              'id' => $product->get_id(),
              'name' => $product->get_name(),
              'price' => $effective_price,
              'base_price' => $base_price,
              'image' => wp_get_attachment_url($product->get_image_id()) ?: '',
              'market_price' => ($market_price === 'yes'),
              'allergens' => $allergens,
              'name_zh' => get_post_meta($product->get_id(), '_product_name_zh', true),
              'name_en' => get_post_meta($product->get_id(), '_product_name_en', true),
              'category_ids' => array_map('intval', $product_category_ids),
              'short_description' => $product->get_short_description(),
          );
      }

      // 读取客户点餐产品排序设置
      $saved_order = json_decode(get_option('pos_customer_product_order', '{}'), true);
      if (!empty($saved_order) && is_array($saved_order)) {
          foreach ($formatted_products as &$p) {
              $terms = get_the_terms($p['id'], 'product_cat');
              $cat_id = (!empty($terms) && !is_wp_error($terms)) ? $terms[0]->term_id : 0;
              $p['_cat_id'] = $cat_id;
              if (isset($saved_order[$cat_id])) {
                  $pos = array_search($p['id'], $saved_order[$cat_id]);
                  $p['_sort'] = ($pos !== false) ? $pos : 9999;
              } else {
                  $p['_sort'] = 9999;
              }
          }
          unset($p);
          usort($formatted_products, function($a, $b) {
              if ($a['_cat_id'] !== $b['_cat_id']) return $a['_cat_id'] - $b['_cat_id'];
              return $a['_sort'] - $b['_sort'];
          });
      }

      return $formatted_products;
  }

  // 函数检查是否有积分产品
  function has_points_products() {
      if (!function_exists('wc_get_products')) {
          return false;
      }
      
      $points_products = wc_get_products(array(
          'status' => 'publish',
          'limit' => 1, // 只需要检查是否存在，所以限制为1
          'meta_query' => array(
              array(
                  'key' => '_is_points_product',
                  'value' => 'yes',
                  'compare' => '='
              )
          )
      ));
      
      return !empty($points_products);
  }

  // Obtener categorías y productos
  $categories = get_woo_categories();
  // 🔥 过滤隐藏分类（设置中标记不在客户页显示的分类）
  $hidden_cats = json_decode(get_option('pos_customer_hidden_categories', '[]'), true) ?: array();
  if (!empty($hidden_cats)) {
      $categories = array_filter($categories, function($cat) use ($hidden_cats) {
          return !in_array($cat['id'], $hidden_cats);
      });
      $categories = array_values($categories);
  }
  // 🔥 按保存的分类排序顺序排列
  $saved_cat_order = json_decode(get_option('pos_customer_category_order', '[]'), true);
  if (!empty($saved_cat_order) && is_array($saved_cat_order)) {
      $cat_order_map = array_flip($saved_cat_order); // cat_id => position
      usort($categories, function($a, $b) use ($cat_order_map) {
          $pa = isset($cat_order_map[$a['id']]) ? $cat_order_map[$a['id']] : 9999;
          $pb = isset($cat_order_map[$b['id']]) ? $cat_order_map[$b['id']] : 9999;
          return $pa - $pb;
      });
  }
  $products = get_woo_products();
  $show_points_module = has_points_products();
  $customer_category_time_rules = function_exists('ruiyi_get_customer_category_time_rules') ? ruiyi_get_customer_category_time_rules() : array();
  ?>

  <!DOCTYPE html>
  <html lang="<?php echo esc_attr($current_lang); ?>">

  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- PWA Support -->
    <link rel="manifest" href="<?php echo get_template_directory_uri(); ?>/manifest-customer.php?table=<?php echo urlencode($table_number); ?>">
    <meta name="theme-color" content="#E84D25">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="锐意点餐">
    <link rel="apple-touch-icon" href="<?php echo get_template_directory_uri(); ?>/assets/icons/customer/icon-192x192.png">
    <!-- DNS Prefetch -->
    <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
    <link rel="dns-prefetch" href="https://fonts.googleapis.com">
    <link rel="dns-prefetch" href="https://fonts.gstatic.com">
    <link rel="dns-prefetch" href="https://cdn.tailwindcss.com">
    <link rel="dns-prefetch" href="https://cdn.socket.io">
    <!-- Professional UI/UX Design System -->
    <style>
      :root {
        /* Design System Colors - V2 */
        --primary: #E84D25;
        --primary-hover: #D4401C;
        --secondary: #0073aa;
        --success: #10b981;
        --warning: #f59e0b;
        --error: #ef4444;

        /* Warm Background Colors */
        --bg-warm: #FAFAF8;
        --bg-card: #F5F4F1;
        --border-warm: #ECEAE6;

        /* Neutral Colors */
        --gray-50: #f9fafb;
        --gray-100: #f3f4f6;
        --gray-200: #e5e7eb;
        --gray-300: #d1d5db;
        --gray-400: #9ca3af;
        --gray-500: #6b7280;
        --gray-600: #4b5563;
        --gray-700: #374151;
        --gray-800: #1f2937;
        --gray-900: #111827;

        /* Spacing System (4px base) */
        --spacing-1: 0.25rem;  /* 4px */
        --spacing-2: 0.5rem;   /* 8px */
        --spacing-3: 0.75rem;  /* 12px */
        --spacing-4: 1rem;     /* 16px */
        --spacing-5: 1.25rem;  /* 20px */
        --spacing-6: 1.5rem;   /* 24px */
        --spacing-8: 2rem;     /* 32px */
        --spacing-10: 2.5rem;  /* 40px */
        --spacing-12: 3rem;    /* 48px */

        /* Typography */
        --font-size-xs: 0.75rem;   /* 12px */
        --font-size-sm: 0.875rem;  /* 14px */
        --font-size-base: 1rem;    /* 16px */
        --font-size-lg: 1.125rem;  /* 18px */
        --font-size-xl: 1.25rem;   /* 20px */
        --font-size-2xl: 1.5rem;   /* 24px */

        /* Shadows - V2 */
        --shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.03);
        --shadow-md: 0 2px 4px -1px rgba(0, 0, 0, 0.05);
        --shadow-lg: 0 -3px 12px rgba(26, 26, 26, 0.04);
        --shadow-card: 0 2px 8px rgba(26, 26, 26, 0.03);

        /* Border Radius - V2 Rounded */
        --radius-sm: 0.5rem;      /* 8px */
        --radius-md: 0.75rem;     /* 12px */
        --radius-lg: 1rem;        /* 16px */
        --radius-xl: 1.25rem;     /* 20px */
        --radius-full: 9999px;    /* pill shape */
      }
      
      /* Reset and Base Styles */
      * {
        box-sizing: border-box;
      }
      
      body {
        font-family: 'DM Sans', system-ui, -apple-system, sans-serif;
        line-height: 1.5;
        color: #1A1A1A;
        background-color: #FAFAF8;
        margin: 0;
        padding: 0;
        overflow: hidden;
        height: 100vh;
      }
      
      /* Header Styling - V2 */
      .app-header {
        background: #FAFAF8;
        padding: 10px 16px;
        position: sticky;
        top: 0;
        z-index: 50;
        height: 56px;
        display: flex;
        align-items: center;
      }
      
      .header-content {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
      }
      
      .header-left {
        display: flex;
        align-items: center;
        gap: var(--spacing-4);
      }
      
      .header-logo {
        width: 34px;
        height: 34px;
        border-radius: 10px;
        background: #E84D25;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 16px;
        flex-shrink: 0;
      }
      .header-logo img {
        display: none;
      }

      .table-badge {
        background: transparent;
        color: #1A1A1A;
        padding: 0;
        border-radius: 0;
        font-family: 'Bricolage Grotesque', system-ui, sans-serif;
        font-size: 17px;
        font-weight: 700;
        line-height: 1.2;
      }
      
      
      .header-actions {
        display: flex;
        align-items: center;
        gap: var(--spacing-3);
      }
      
      .header-btn {
        width: 32px;
        height: 32px;
        padding: 0;
        border: none;
        border-radius: 50%;
        background: #F0F0EE;
        color: #1A1A1A;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
      }
      .header-btn span {
        display: none;
      }

      .header-btn:hover {
        background: #E5E5E3;
        color: #1A1A1A;
      }
      #search-toggle-btn.active {
        background: #E84D25;
        color: white;
      }

      .cart-btn {
        width: 36px;
        height: 36px;
        background: #E84D25;
        color: white;
        position: relative;
        font-size: 15px;
      }

      .cart-btn:hover {
        background: #D4401C;
      }

      .cart-count {
        display: none;
      }

      /* Language Dropdown */
      .lang-dropdown-wrapper {
        position: relative;
      }
      .lang-dropdown-btn {
        display: flex;
        align-items: center;
        gap: 5px;
        padding: 0 10px;
        height: 32px;
        border: none;
        border-radius: 16px;
        background: #F0F0EE;
        color: #1A1A1A;
        cursor: pointer;
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 12px;
        font-weight: 500;
        transition: background 0.2s ease;
        white-space: nowrap;
      }
      .lang-dropdown-btn:hover { background: #E5E5E3; }
      .lang-dropdown-btn .fa-globe { font-size: 13px; color: #6B7280; }
      .lang-dropdown-arrow { font-size: 9px; color: #9CA3AF; transition: transform 0.2s ease; }
      .lang-dropdown-menu {
        position: absolute;
        right: 0;
        top: calc(100% + 6px);
        min-width: 130px;
        background: #FFFFFF;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(26,26,26,0.12);
        border: 1px solid #ECEAE6;
        overflow: hidden;
        z-index: 200;
      }
      .lang-dropdown-item {
        display: block;
        padding: 10px 14px;
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 13px;
        font-weight: 500;
        color: #1A1A1A;
        text-decoration: none;
        transition: background 0.15s ease;
      }
      .lang-dropdown-item:hover { background: #F5F4F1; }
      .lang-dropdown-item.active {
        color: #E84D25;
        font-weight: 600;
        background: #FFF0EB;
      }
      
      /* Main Layout - V5: sidebar + content */
      .app-main {
        max-width: 1400px;
        margin: 0 auto;
        display: flex;
        padding: 0;
        /* 填满剩余视口高度：header(56px), 搜索栏默认隐藏，通过JS动态调整 */
        height: calc(100vh - 56px);
        transition: height 0.3s ease;
        overflow: hidden;
      }

      /* V5: Category Sidebar — 固定不滚动，固定宽度 */
      .v5-category-sidebar {
        width: 100px;
        min-width: 100px;
        max-width: 100px;
        background: #FFFFFF;
        border-right: 1px solid #ECEAE6;
        display: flex;
        flex-direction: column;
        overflow-y: auto;
        height: 100%;
        -ms-overflow-style: none;
        scrollbar-width: none;
        flex-shrink: 0;
      }
      .v5-category-sidebar::-webkit-scrollbar { display: none; }

      /* V5: Product Area — 独立滚动 */
      .v5-product-area {
        flex: 1;
        min-width: 0;
        background: #FAFAF8;
        padding: 12px;
        overflow-y: auto;
        height: 100%;
        padding-bottom: 80px;
      }

      /* Sidebar Styling - Hidden in V2 */
      .app-sidebar {
        display: none;
      }

      .sidebar-content {
        display: none;
      }
      
      .search-results-info {
        font-size: var(--font-size-sm);
        color: var(--gray-600);
        margin-bottom: var(--spacing-3);
        padding: var(--spacing-2);
        background: var(--gray-50);
        border-radius: var(--radius-md);
        border-left: 3px solid var(--primary);
      }
      
      .categories-list {
        display: flex;
        flex-direction: column;
        gap: 0;
      }
      
      .category-item {
        background: none;
        border: none;
        width: 100%;
        text-align: left;
        padding: var(--spacing-2) var(--spacing-3);
        border-radius: 0;
        font-size: var(--font-size-sm);
        font-weight: 400;
        color: var(--gray-600);
        cursor: pointer;
        transition: all 0.15s ease;
        display: flex;
        align-items: center;
        gap: var(--spacing-2);
        position: relative;
        border-left: 3px solid transparent;
      }
      
      .category-item:hover {
        background: var(--gray-50);
        color: var(--gray-800);
      }
      
      .category-item.active {
        background: var(--gray-50);
        color: var(--gray-900);
        border-left-color: var(--primary);
        font-weight: 500;
      }
      
      .category-icon {
        width: 14px;
        text-align: center;
        font-size: var(--font-size-xs);
      }
      
      /* Content Area */
      .app-content {
        flex: 1;
        min-width: 0;
      }
      
      .content-section {
        background: transparent;
        border-radius: 0;
        box-shadow: none;
        margin-bottom: 0;
        overflow: visible;
        border: none;
      }

      .content-section + .content-section {
        border-top: none;
      }

      .section-header {
        padding: 12px 4px;
        border-bottom: none;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: transparent;
      }

      .section-title {
        font-family: 'Bricolage Grotesque', system-ui, sans-serif;
        font-size: 16px;
        font-weight: 700;
        color: #1A1A1A;
        display: flex;
        align-items: center;
        gap: var(--spacing-2);
      }

      .section-title i {
        font-size: var(--font-size-sm);
        color: var(--gray-500);
      }
      
      .section-actions {
        display: flex;
        gap: var(--spacing-2);
      }
      
      .action-btn {
        padding: var(--spacing-2) var(--spacing-3);
        border: none;
        border-radius: var(--radius-sm);
        background: var(--gray-200);
        color: var(--gray-600);
        font-size: var(--font-size-xs);
        font-weight: 400;
        cursor: pointer;
        transition: all 0.15s ease;
        display: flex;
        align-items: center;
        gap: var(--spacing-1);
      }
      
      .action-btn:hover {
        background: var(--gray-300);
        color: var(--gray-700);
      }
      
      .action-btn.primary {
        background: var(--gray-700);
        color: white;
      }
      
      .action-btn.primary:hover {
        background: var(--gray-800);
      }
      
      /* Product Grid - V5: single column list */
      .products-grid {
        display: flex !important;
        flex-direction: column !important;
        gap: 12px;
        padding: 0;
      }

      /* 确保所有产品容器都使用单列布局 */
      #products-container,
      #discount-products-container,
      #popular-products-container,
      #points-products-container {
        display: flex !important;
        flex-direction: column !important;
        gap: 12px !important;
      }

      .product-card {
        background: #FFFFFF;
        border-radius: 16px;
        overflow: hidden;
        transition: all 0.2s ease;
        border: 1px solid #F0EFEC;
        box-shadow: 0 3px 12px rgba(26, 26, 26, 0.04);
        position: relative;
        display: flex !important;
        flex-direction: column !important;
      }

      .product-card:hover {
        box-shadow: 0 4px 16px rgba(26, 26, 26, 0.08);
      }

      .product-card.category-time-locked {
        opacity: 0.58;
      }

      .product-card.category-time-locked::after {
        content: attr(data-lock-label);
        position: absolute;
        top: 10px;
        right: 10px;
        z-index: 20;
        padding: 4px 8px;
        border-radius: 9999px;
        background: rgba(239, 68, 68, 0.94);
        color: #FFFFFF;
        font-size: 11px;
        font-weight: 700;
        line-height: 1;
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.24);
      }

      .product-card.category-time-locked .add-to-cart,
      .detail-add-btn.category-time-locked-btn {
        background: #9ca3af !important;
        cursor: not-allowed;
      }

      .category-time-notice {
        margin: 0 0 12px;
        padding: 10px 12px;
        border-radius: 12px;
        border: 1px solid rgba(245, 158, 11, 0.28);
        background: #fffbeb;
        color: #92400e;
        font-size: 13px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
      }

      .category-time-notice.locked {
        border-color: rgba(239, 68, 68, 0.28);
        background: #fef2f2;
        color: #991b1b;
      }

      /* 时价标识样式 */
      .market-price-badge {
        position: absolute;
        top: 6px;
        left: 6px;
        background: linear-gradient(135deg, #f59e0b, #d97706);
        color: white;
        font-size: 10px;
        font-weight: 600;
        padding: 3px 8px;
        border-radius: 4px;
        z-index: 10;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
        white-space: nowrap;
      }

      /* 过敏原图标样式 */
      .product-allergens {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        margin-bottom: 0;
      }

      .allergen-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        font-size: 16px;
        background: #fef3c7;
        border-radius: 50%;
        cursor: help;
        transition: transform 0.2s ease;
      }

      .allergen-icon:hover {
        transform: scale(1.2);
      }
      
      .product-image {
        width: 100% !important;
        height: 210px !important;
        min-width: unset;
        max-width: unset;
        object-fit: cover;
        background: var(--gray-100);
        cursor: pointer;
        flex-shrink: 0;
      }

      /* 产品详情弹窗 — 全屏 */
      .product-detail-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: #FFFFFF;
        z-index: 99999;
      }
      .product-detail-overlay.active {
        display: flex;
      }
      .product-detail-modal {
        width: 100%;
        height: 100%;
        background: #FFFFFF;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        animation: detailFadeIn 0.3s ease-out;
        position: relative;
      }
      @keyframes detailFadeIn {
        from { opacity: 0; transform: translateY(30px); }
        to { opacity: 1; transform: translateY(0); }
      }
      .product-detail-modal.closing {
        animation: detailFadeOut 0.2s ease-in forwards;
      }
      @keyframes detailFadeOut {
        from { opacity: 1; transform: translateY(0); }
        to { opacity: 0; transform: translateY(30px); }
      }
      .detail-image-wrapper {
        position: relative;
        width: 100%;
        overflow: hidden;
        background: #F5F4F1;
        flex-shrink: 0;
      }
      .detail-image-wrapper img {
        width: 100%;
        max-height: 50vh;
        object-fit: contain;
        display: block;
      }
      .detail-close-btn {
        position: absolute;
        top: max(12px, env(safe-area-inset-top));
        right: 12px;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: rgba(0,0,0,0.45);
        color: white;
        border: none;
        font-size: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        z-index: 2;
        transition: background 0.2s;
      }
      .detail-close-btn:hover {
        background: rgba(0,0,0,0.65);
      }
      .detail-body {
        flex: 1;
        overflow-y: auto;
        padding: 20px 20px 0;
      }
      .detail-product-name {
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 20px;
        font-weight: 600;
        color: #1A1A1A;
        margin: 0 0 6px;
        line-height: 1.3;
      }
      .detail-product-desc {
        font-size: 13px;
        color: #6B7280;
        line-height: 1.5;
        margin: 0 0 12px;
      }
      .detail-allergens {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-bottom: 16px;
      }
      .detail-allergens .allergen-tag {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 10px;
        background: #FFF7ED;
        border: 1px solid #FED7AA;
        border-radius: 20px;
        font-size: 12px;
        color: #9A3412;
      }
      .detail-allergens .allergen-tag .allergen-emoji {
        font-size: 14px;
      }
      /* 备注按钮行 */
      .detail-note-section {
        border-top: 1px solid #F0EFEC;
        padding: 14px 0;
      }
      .detail-note-btn {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        padding: 0;
        background: none;
        border: none;
        cursor: pointer;
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 15px;
        font-weight: 500;
        color: #1A1A1A;
      }
      .detail-note-btn .note-btn-left {
        display: flex;
        align-items: center;
        gap: 8px;
      }
      .detail-note-btn .note-preview {
        font-size: 13px;
        color: #9CA3AF;
        max-width: 180px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .detail-note-btn i {
        color: #9CA3AF;
        font-size: 14px;
      }
      /* 底部操作栏 */
      .detail-bottom-bar {
        padding: 16px 20px;
        padding-bottom: max(16px, env(safe-area-inset-bottom));
        border-top: 1px solid #F0EFEC;
        background: #FFFFFF;
        flex-shrink: 0;
      }
      .detail-price-qty-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 14px;
      }
      .detail-price {
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 24px;
        font-weight: 700;
        color: #1A1A1A;
      }
      .detail-qty-controls {
        display: flex;
        align-items: center;
        gap: 16px;
      }
      .detail-qty-btn {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 14px;
        transition: all 0.2s;
      }
      .detail-qty-btn.minus {
        background: #FFFFFF;
        border: 1.5px solid #D1D5DB;
        color: #6B7280;
      }
      .detail-qty-btn.minus:hover {
        border-color: #9CA3AF;
        color: #374151;
      }
      .detail-qty-btn.plus {
        background: #E84D25;
        border: none;
        color: #FFFFFF;
      }
      .detail-qty-btn.plus:hover {
        background: #D4401C;
      }
      .detail-qty-value {
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 17px;
        font-weight: 600;
        color: #1A1A1A;
        min-width: 20px;
        text-align: center;
      }
      .detail-add-btn {
        width: 100%;
        height: 52px;
        border: none;
        border-radius: 14px;
        background: #E84D25;
        color: #FFFFFF;
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        transition: background 0.2s;
      }
      .detail-add-btn:hover {
        background: #D4401C;
      }
      .detail-add-btn:active {
        background: #C13A19;
      }
      .detail-add-btn i {
        font-size: 15px;
      }

      /* 备注全屏页面 */
      .note-page-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: #FFFFFF;
        z-index: 100000;
        flex-direction: column;
      }
      .note-page-overlay.active {
        display: flex;
        animation: noteSlideIn 0.3s ease-out;
      }
      @keyframes noteSlideIn {
        from { transform: translateX(100%); }
        to { transform: translateX(0); }
      }
      .note-page-overlay.closing {
        animation: noteSlideOut 0.25s ease-in forwards;
      }
      @keyframes noteSlideOut {
        from { transform: translateX(0); }
        to { transform: translateX(100%); }
      }
      .note-page-header {
        display: flex;
        align-items: center;
        justify-content: center;
        height: 56px;
        padding: 0 16px;
        position: relative;
        flex-shrink: 0;
        border-bottom: 1px solid #F0EFEC;
      }
      .note-page-back {
        position: absolute;
        left: 12px;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: #F5F4F1;
        border: none;
        color: #1A1A1A;
        font-size: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: background 0.2s;
      }
      .note-page-back:hover {
        background: #ECEAE6;
      }
      .note-page-title {
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 17px;
        font-weight: 600;
        color: #1A1A1A;
      }
      .note-page-body {
        flex: 1;
        overflow-y: auto;
        padding: 20px;
      }
      .note-textarea-wrapper {
        position: relative;
        margin-bottom: 24px;
      }
      .note-textarea {
        width: 100%;
        min-height: 140px;
        padding: 16px;
        border: none;
        border-radius: 14px;
        background: #F5F4F1;
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 15px;
        color: #1A1A1A;
        outline: none;
        resize: none;
        line-height: 1.6;
      }
      .note-textarea::placeholder {
        color: #9CA3AF;
      }
      .note-char-count {
        position: absolute;
        bottom: 12px;
        left: 16px;
        font-size: 12px;
        color: #9CA3AF;
        font-family: 'DM Sans', system-ui, sans-serif;
      }
      .note-quick-title {
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 15px;
        font-weight: 600;
        color: #1A1A1A;
        margin-bottom: 14px;
      }
      .note-quick-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
      }
      .note-quick-tag {
        padding: 8px 16px;
        border-radius: 8px;
        border: none;
        background: #F5F4F1;
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 14px;
        color: #1A1A1A;
        cursor: pointer;
        transition: all 0.2s;
      }
      .note-quick-tag:hover {
        background: #ECEAE6;
      }
      .note-quick-tag.selected {
        background: #FEF2F0;
        color: #E84D25;
        box-shadow: inset 0 0 0 1.5px #E84D25;
      }
      .note-page-footer {
        padding: 16px 20px;
        padding-bottom: max(16px, env(safe-area-inset-bottom));
        flex-shrink: 0;
      }
      .note-confirm-btn {
        width: 100%;
        height: 52px;
        border: none;
        border-radius: 14px;
        background: #E84D25;
        color: #FFFFFF;
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s;
      }
      .note-confirm-btn:hover {
        background: #D4401C;
      }
      .note-confirm-btn:active {
        background: #C13A19;
      }

      .product-info {
        padding: 8px 12px 10px;
        display: flex !important;
        flex-direction: column !important;
        flex: 1 1 auto;
        min-width: 0;
        gap: 2px;
      }

      .product-title {
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 15px;
        font-weight: 600;
        color: #1A1A1A;
        margin-bottom: 0;
        line-height: 1.3;
        /* Truncate to 2 lines */
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .product-description {
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 11px;
        font-weight: 400;
        color: #9CA3AF;
        line-height: 1.3;
        margin: 0;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      /* Bottom row: price on the left, buttons on the right */
      .product-bottom-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-top: 2px;
        gap: 4px;
        position: relative;
        min-height: 34px;
      }

      .product-price {
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 17px;
        font-weight: 700;
        color: #E84D25;
        margin-bottom: 0;
        white-space: nowrap;
        flex-shrink: 0;
      }

      .add-to-cart {
        width: 34px;
        height: 34px;
        border-radius: 17px;
        background: #E84D25;
        color: white;
        border: none;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        padding: 0;
        cursor: pointer;
        flex-shrink: 0;
        transition: background 0.2s ease, opacity 0.2s ease;
      }
      .add-to-cart span {
        display: none;
      }

      .add-to-cart:hover {
        background: #D4401C;
        color: white;
      }

      .add-to-cart.adding {
        animation: addTapPulse 220ms ease-out;
      }

      @keyframes addTapPulse {
        0% { transform: scale(1); }
        45% { transform: scale(0.88); }
        100% { transform: scale(1); }
      }

      .add-to-cart.in-cart {
        background: var(--success);
        color: white;
      }

      .add-to-cart.limit-locked,
      .add-to-cart.limit-locked:hover,
      .qty-btn.limit-locked,
      .detail-qty-btn.limit-locked,
      .cart-item-qty-btn.limit-locked {
        background: #9CA3AF !important;
        color: #FFFFFF !important;
        cursor: not-allowed !important;
        opacity: 0.72;
        box-shadow: none !important;
        transform: none !important;
      }

      .add-to-cart.limit-locked {
        pointer-events: auto;
      }

      .qty-btn.limit-locked,
      .detail-qty-btn.limit-locked,
      .cart-item-qty-btn.limit-locked {
        pointer-events: none;
      }

      /* Qty controls - inline quantity controller on product cards */
      .qty-controls {
        display: flex;
        align-items: center;
        gap: 0;
        background: #E84D25;
        border-radius: 16px;
        height: 34px;
        overflow: hidden;
        transform: scale(0);
        opacity: 0;
        transform-origin: right center;
        transition: transform 0.25s cubic-bezier(0.4,0,0.2,1), opacity 0.2s ease;
        z-index: 2;
        position: absolute;
        right: 0;
        top: 0;
      }
      .qty-controls.show {
        transform: scale(1);
        opacity: 1;
      }
      .qty-controls.qty-reveal {
        animation: qtyReveal 180ms ease-out;
      }
      @keyframes qtyReveal {
        from { transform: scale(0.9); opacity: 0; }
        to { transform: scale(1); opacity: 1; }
      }
      .qty-btn {
        width: 28px; height: 28px;
        background: transparent; border: none; color: white;
        font-size: 14px; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        transition: background 0.15s;
      }
      .qty-btn:hover { background: rgba(255,255,255,0.15); }
      .qty-value {
        min-width: 20px; text-align: center;
        font-family: 'DM Sans'; font-size: 13px; font-weight: 600;
        color: white; user-select: none;
      }
      /* Hide add-to-cart button when qty-controls is visible */
      .product-bottom-row .add-to-cart.has-qty { opacity: 0; pointer-events: none; }

      /* Responsive Design - V5 */
      @media (max-width: 1024px) {
        .app-main {
          padding: 0;
        }
        .v5-product-area {
          padding: 10px;
        }
      }

      @media (max-width: 480px) {
        .header-content {
          padding: 0;
        }
        .v5-category-sidebar {
          min-width: 70px;
        }
        .category-chip {
          font-size: 12px;
          height: 40px;
          padding: 0 10px;
        }
        .v5-product-area {
          padding: 8px;
        }
        .products-grid,
        #products-container,
        #discount-products-container,
        #popular-products-container,
        #points-products-container {
          gap: 10px !important;
        }
        .product-card {
          border-radius: 12px;
        }
        .product-image {
          width: 100% !important;
          height: clamp(120px, 38vw, 160px) !important;
          object-fit: cover;
        }
        .product-info {
          padding: 8px 10px 10px !important;
        }
        .product-title {
          font-size: 14px;
          line-height: 1.25;
        }
        .product-description {
          font-size: 10px;
          -webkit-line-clamp: 1;
        }
        .product-price {
          font-size: 15px;
        }
        .bottom-cart-bar {
          min-width: 0;
          padding: 0 10px;
          gap: 8px;
        }
        .bottom-cart-info {
          min-width: 0;
        }
        .bottom-cart-cta {
          padding: 0 14px;
          white-space: nowrap;
        }
      }
      
      /* Utility Classes */
      .hidden {
        display: none !important;
      }
      
      .text-warning {
        color: var(--warning);
      }
      
      /* Smooth transitions for all interactive elements */
      button, .category-item, .product-card, .add-to-cart {
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
      }
      
      /* Focus states for accessibility */
      button:focus, 
      .category-item:focus, 
      .search-input:focus {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
      }
      
      /* Floating Category Button */
      .floating-category-btn {
        position: fixed;
        bottom: 90px;
        right: 20px;
        padding: var(--spacing-3) var(--spacing-4);
        background: var(--primary);
        color: white;
        border: none;
        border-radius: var(--radius-lg);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: var(--spacing-2);
        font-size: var(--font-size-sm);
        font-weight: 500;
        z-index: 1000;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        white-space: nowrap;
      }
      
      .floating-category-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
        background: var(--primary-hover);
      }
      
      .floating-category-btn.active {
        background: var(--gray-600);
        transform: translateY(-1px);
      }
      
      /* Category Popup Overlay */
      .category-popup-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
        z-index: 999;
      }
      
      .category-popup-overlay.active {
        opacity: 1;
        visibility: visible;
      }
      
      /* Category Popup Content */
      .category-popup {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: white;
        border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.15);
        transform: translateY(100%);
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 1001;
        max-height: 60vh;
        overflow: hidden;
      }
      
      .category-popup.active {
        transform: translateY(0);
      }
      
      .category-popup-header {
        padding: var(--spacing-4) var(--spacing-5);
        border-bottom: 1px solid var(--gray-200);
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: var(--gray-50);
      }
      
      .category-popup-title {
        font-size: var(--font-size-lg);
        font-weight: 600;
        color: var(--gray-800);
        display: flex;
        align-items: center;
        gap: var(--spacing-2);
      }
      
      .category-popup-close {
        width: 32px;
        height: 32px;
        border: none;
        background: var(--gray-200);
        color: var(--gray-600);
        border-radius: 50%;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
      }
      
      .category-popup-close:hover {
        background: var(--gray-300);
        color: var(--gray-800);
      }
      
      .category-popup-body {
        padding: var(--spacing-4);
        max-height: calc(60vh - 80px);
        overflow-y: auto;
      }
      
      .category-popup-list {
        display: flex;
        flex-direction: column;
        gap: var(--spacing-1);
      }
      
      .category-popup-item {
        padding: var(--spacing-3) var(--spacing-4);
        border: none;
        background: none;
        text-align: left;
        border-radius: var(--radius-md);
        transition: all 0.2s ease;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: var(--spacing-3);
        font-size: var(--font-size-base);
        color: var(--gray-700);
      }
      
      .category-popup-item:hover {
        background: var(--gray-100);
        color: var(--gray-800);
      }
      
      .category-popup-item.active {
        background: var(--primary);
        color: white;
      }
      
      .category-popup-item .category-icon {
        width: 20px;
        text-align: center;
      }

      /* V2: Search Bar */
      .search-bar-container {
        display: flex;
        align-items: center;
        gap: 10px;
        background: #FFFFFF;
        border-bottom: 1px solid transparent;
        position: sticky;
        top: 56px;
        z-index: 49;
        overflow: hidden;
        padding: 0 16px;
        height: 0;
        transition: height 0.3s ease, border-bottom-color 0.3s ease;
      }
      .search-bar-container.search-open {
        height: 44px;
        border-bottom-color: #ECEAE6;
      }
      .search-bar-icon { color: #9CA3AF; font-size: 18px; }
      .search-bar-input {
        flex: 1;
        border: none;
        outline: none;
        background: transparent;
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 14px;
        color: #1A1A1A;
      }
      .search-bar-input::placeholder { color: #9CA3AF; }

      /* V2: Horizontal Categories */
      /* V5: Categories as left sidebar */
      .horizontal-categories {
        display: none;
      }

      .category-chip {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 6px 8px;
        min-height: 42px;
        height: auto;
        width: 100%;
        border-radius: 0;
        border: none;
        cursor: pointer;
        white-space: normal;
        word-break: break-word;
        text-align: center;
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 13px;
        font-weight: 500;
        background: #F5F4F1;
        color: #6B7280;
        transition: all 0.2s ease;
        flex-shrink: 0;
        line-height: 1.2;
      }
      .category-chip.active {
        background: #E84D25;
        color: #FFFFFF;
        font-weight: 700;
      }
      .category-chip:hover:not(.active) {
        background: #ECEAE6;
      }
      .category-chip i { font-size: 14px; }

      /* V2: Hide old floating category button and popup */
      .floating-category-btn,
      .category-popup-overlay,
      .category-popup { display: none !important; }

      /* V2: Hide old bottom table bar and mobile bottom nav */
      .bottom-table-bar { display: none !important; }
      .mobile-bottom-nav { display: none !important; }

      /* V2: Hide old floating cart button */
      .floating-cart-button { display: none !important; }

      /* V2: Bottom Cart Bar */
      .bottom-cart-bar {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 100;
        background: #FFFFFF;
        height: 64px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 16px;
        border-top: 1px solid #ECEAE6;
        box-shadow: 0 -3px 12px rgba(26, 26, 26, 0.04);
      }
      .bottom-cart-info {
        display: flex;
        flex-direction: column;
        gap: 2px;
      }
      .bottom-cart-item-count {
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 12px;
        color: #9CA3AF;
        font-weight: 500;
      }
      .bottom-cart-total-price {
        font-family: 'Bricolage Grotesque', system-ui, sans-serif;
        font-size: 20px;
        font-weight: 700;
        color: #1A1A1A;
      }
      .bottom-cart-cta {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        background: #E84D25;
        color: white;
        border: none;
        border-radius: 21px;
        height: 42px;
        padding: 0 22px;
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s ease;
      }
      .bottom-cart-cta:hover { background: #D4401C; }

      .ruiyi-image-reference-note {
        position: fixed;
        left: 50%;
        bottom: calc(12px + env(safe-area-inset-bottom));
        transform: translateX(-50%);
        z-index: 120;
        pointer-events: none;
        padding: 6px 12px;
        border-radius: 9999px;
        background: rgba(17, 24, 39, 0.78);
        color: #FFFFFF;
        font-family: 'DM Sans', system-ui, -apple-system, sans-serif;
        font-size: 12px;
        font-weight: 600;
        line-height: 1.25;
        white-space: nowrap;
        box-shadow: 0 6px 18px rgba(17, 24, 39, 0.18);
        backdrop-filter: blur(8px);
      }

      body.has-bottom-cart .ruiyi-image-reference-note {
        bottom: calc(76px + env(safe-area-inset-bottom));
      }

      /* ============================================================
         INLINE SEARCH BAR (hidden by default, shown on tablet+)
         ============================================================ */
      .header-search-inline {
        display: none;
      }

      /* ============================================================
         SIDEBAR TITLE (hidden by default, shown on tablet+)
         ============================================================ */
      .v5-sidebar-title {
        display: none;
      }

      /* ============================================================
         CATEGORY ICON (hidden by default on mobile)
         ============================================================ */
      .category-chip .cat-icon {
        display: none;
      }

      /* ============================================================
         PRODUCT COUNT (hidden by default, shown on tablet+)
         ============================================================ */
      .section-product-count {
        display: none;
      }

      /* ============================================================
         TABLET & DESKTOP — min-width: 768px
         ============================================================ */
      @media (min-width: 768px) {

        /* --- Header --- */
        .app-header {
          height: 60px;
          padding: 0 24px;
          background: #FFFFFF;
          border-bottom: 1px solid #ECEAE6;
        }

        .header-logo {
          width: 36px;
          height: 36px;
          border-radius: 10px;
          font-size: 17px;
        }

        .table-badge {
          font-size: 18px;
        }

        .header-content {
          padding: 0;
        }

        /* Inline search bar — visible on tablet+ */
        .header-search-inline {
          display: flex;
          align-items: center;
          gap: 10px;
          background: #F5F4F1;
          border-radius: 20px;
          height: 40px;
          width: 360px;
          max-width: 40vw;
          padding: 0 16px;
          flex-shrink: 0;
        }
        .header-search-inline i {
          color: #9CA3AF;
          font-size: 14px;
          flex-shrink: 0;
        }
        .header-search-input {
          flex: 1;
          border: none;
          outline: none;
          background: transparent;
          font-family: 'DM Sans', system-ui, sans-serif;
          font-size: 14px;
          color: #1A1A1A;
        }
        .header-search-input::placeholder {
          color: #9CA3AF;
        }

        /* Hide mobile search toggle button on tablet+ */
        #search-toggle-btn {
          display: none !important;
        }
        /* Hide mobile slide-down search bar on tablet+ */
        .search-bar-container {
          display: none !important;
        }

        .header-btn {
          width: 40px;
          height: 40px;
          font-size: 15px;
        }

        .cart-btn {
          width: 40px;
          height: 40px;
          font-size: 16px;
        }

        .lang-dropdown-btn {
          height: 40px;
          padding: 0 12px;
          border-radius: 20px;
          font-size: 13px;
        }

        /* --- Main Layout --- */
        .app-main {
          height: calc(100vh - 60px);
        }

        /* --- Sidebar — 220px, list-style categories --- */
        .v5-category-sidebar {
          width: 220px;
          min-width: 220px;
          max-width: 220px;
          padding: 16px 0;
          gap: 2px;
        }

        .v5-sidebar-title {
          display: block;
          padding: 4px 20px 12px 20px;
          font-family: 'DM Sans', system-ui, sans-serif;
          font-size: 11px;
          font-weight: 600;
          color: #9CA3AF;
          letter-spacing: 1px;
          text-transform: uppercase;
        }

        .category-chip .cat-icon {
          display: inline-block;
          font-size: 20px;
          width: 20px;
          height: 20px;
          color: #9CA3AF;
          flex-shrink: 0;
        }

        .category-chip {
          justify-content: flex-start;
          text-align: left;
          padding: 0 20px;
          height: 44px;
          min-height: 44px;
          gap: 10px;
          background: transparent;
          color: #4B5563;
          font-size: 14px;
          font-weight: 500;
          border-left: 3px solid transparent;
          border-radius: 0;
          white-space: nowrap;
          word-break: normal;
        }

        .category-chip.active {
          background: #FFF0EB;
          color: #E84D25;
          font-weight: 600;
          border-left-color: #E84D25;
        }
        .category-chip.active .cat-icon {
          color: #E84D25;
        }

        .category-chip:hover:not(.active) {
          background: #F9F9F7;
        }

        /* --- Product Area --- */
        .v5-product-area {
          padding: 20px 24px;
          padding-bottom: 88px;
        }

        /* --- Section Header --- */
        .section-header {
          padding: 0 0 16px 0;
        }
        .section-title {
          font-size: 20px;
        }
        .section-title i {
          display: none;
        }
        .section-product-count {
          display: inline;
          font-family: 'DM Sans', system-ui, sans-serif;
          font-size: 13px;
          font-weight: 500;
          color: #9CA3AF;
        }

        /* --- Product Grid: 3 columns --- */
        .products-grid,
        #products-container,
        #discount-products-container,
        #popular-products-container,
        #points-products-container {
          display: grid !important;
          grid-template-columns: repeat(3, 1fr) !important;
          flex-direction: unset !important;
          gap: 14px !important;
        }

        /* --- Product Card --- */
        .product-card {
          display: flex;
          flex-direction: column;
        }
        .product-card.hidden {
          display: none !important;
        }

        .product-image {
          width: 100% !important;
          height: 140px !important;
        }

        .product-info {
          padding: 10px 14px;
        }

        .product-title {
          font-size: 13px;
          font-weight: 500;
          line-height: 1.3;
        }

        .product-description {
          font-size: 11px;
          -webkit-line-clamp: 1;
        }

        .product-price {
          font-size: 16px !important;
          font-weight: 700;
          color: #E84D25;
        }

        .add-to-cart {
          width: 30px;
          height: 30px;
          font-size: 14px;
          border-radius: 15px;
        }

        .product-bottom-row {
          margin-top: auto;
        }

        /* --- Bottom Cart Bar --- */
        .bottom-cart-bar {
          height: 68px;
          padding: 0 24px;
        }

        .bottom-cart-total-price {
          font-size: 22px;
        }

        .bottom-cart-cta {
          height: 48px;
          padding: 0 28px;
          border-radius: 25px;
          font-size: 15px;
          box-shadow: 0 4px 16px rgba(232, 77, 37, 0.25);
        }

        .bottom-cart-item-count {
          font-size: 12px;
        }
      }

      /* ============================================================
         DESKTOP — min-width: 1025px (wider search bar)
         ============================================================ */
      @media (min-width: 1025px) {
        .header-search-inline {
          width: 400px;
          max-width: 45vw;
        }
      }
    </style>
    <?php if (!empty($table_number)): ?>
    <title><?php
        // 🔥 显示完整桌位名称
        if (!empty($table_display_name) && $table_display_name !== $table_number) {
            echo esc_html($table_display_name);
        } else {
            echo ruiyi_translate('Mesa ', 'Table ', '桌号 ') . esc_html($table_number);
        }
    ?> | <?php echo ruiyi_translate('Ruiyi POS', 'Ruiyi POS', 'Ruiyi POS'); ?></title>
    <?php else: ?>
    <title><?php echo ruiyi_translate('Carrito Cliente | Ruiyi POS', 'Customer Cart | Ruiyi POS', '客户购物车 | Ruiyi POS'); ?></title>
    <?php endif; ?>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <!-- Google Fonts: Bricolage Grotesque + DM Sans + Material Symbols Rounded -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@400;600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet">

    <!-- Socket.IO Client (WebSocket 实时通知测试) -->
    <script src="https://cdn.socket.io/4.7.5/socket.io.min.js" crossorigin="anonymous" defer></script>

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- ToastifyJS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: {
              primary: '#E84D25',
              secondary: '#0073aa',
              dark: '#1A1A1A',
              light: '#FAFAF8'
            },
            fontFamily: {
              display: ['Bricolage Grotesque', 'system-ui', 'sans-serif'],
              body: ['DM Sans', 'system-ui', 'sans-serif'],
            }
          }
        }
      }
    </script>

    <!-- 增加侧边菜单样式 -->
    <style>
      /* 侧边购物车菜单 */
      .side-cart {
        position: fixed;
        top: 0;
        right: -100%;
        width: 100%;
        max-width: 402px;
        height: 100vh;
        height: 100dvh;
        background-color: #F5F4F1;
        box-shadow: -2px 0 10px rgba(0, 0, 0, 0.1);
        z-index: 9999;
        transition: right 0.3s ease;
        overflow: hidden;
        display: flex;
        flex-direction: column;
      }

      body.cart-open .side-cart {
        right: 0;
      }

      .cart-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 9998;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.3s ease, visibility 0.3s ease;
      }

      body.cart-open .cart-overlay {
        opacity: 1;
        visibility: visible;
      }

      /* Cart Header - Redesign */
      .cart-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0 20px;
        height: 60px;
        background: #FFFFFF;
        box-shadow: 0 1px 6px rgba(26, 25, 24, 0.03);
        flex-shrink: 0;
        border-bottom: none;
      }
      .cart-header-left {
        display: flex;
        align-items: center;
        gap: 12px;
      }
      .cart-header-icon {
        width: 36px; height: 36px;
        border-radius: 100px;
        background: #E84D25;
        display: flex; align-items: center; justify-content: center;
        color: white; font-size: 16px;
      }
      .cart-header-title {
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 20px; font-weight: 600;
        color: #1A1918; letter-spacing: -0.3px;
      }
      .cart-item-count-badge {
        display: flex; align-items: center; justify-content: center;
        background: #FFF0EB;
        border-radius: 100px;
        height: 24px; padding: 0 10px;
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 12px; font-weight: 600;
        color: #E84D25;
      }
      .close-cart {
        width: 36px; height: 36px;
        border-radius: 100px;
        background: #F5F4F1;
        border: none;
        display: flex; align-items: center; justify-content: center;
        cursor: pointer;
        color: #6D6C6A; font-size: 16px;
        transition: background 0.2s;
      }
      .close-cart:hover { background: #ECEAE6; }

      /* Cart Body - Redesign */
      .cart-body {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-height: 0;
        overflow: hidden;
        padding: 0;
      }

      /* Cart scrollable content area */
      .cart-scrollable-area {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        padding: 16px 20px;
        display: flex;
        flex-direction: column;
        gap: 16px;
      }

      /* Items card container — 产品列表带滚动条 */
      .cart-items-card {
        background: #FFFFFF;
        border-radius: 16px;
        box-shadow: 0 2px 12px rgba(26, 25, 24, 0.03);
        max-height: 45vh;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
      }
      /* 自定义滚动条样式 */
      .cart-items-card::-webkit-scrollbar {
        width: 4px;
      }
      .cart-items-card::-webkit-scrollbar-track {
        background: transparent;
      }
      .cart-items-card::-webkit-scrollbar-thumb {
        background: #D5D4D1;
        border-radius: 4px;
      }
      .cart-items-card::-webkit-scrollbar-thumb:hover {
        background: #9C9B99;
      }

      /* Individual cart item - Redesign */
      .cart-item-v2 {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 14px 16px;
        border-bottom: 1px solid #E5E4E1;
      }
      .cart-item-v2:last-child {
        border-bottom: none;
      }
      .cart-item-img {
        width: 56px; height: 56px;
        border-radius: 12px;
        object-fit: cover;
        background: #F5F4F1;
        flex-shrink: 0;
      }
      .cart-item-img-placeholder {
        width: 56px; height: 56px;
        border-radius: 12px;
        background: #F5F4F1;
        display: flex; align-items: center; justify-content: center;
        color: #9C9B99; font-size: 20px;
        flex-shrink: 0;
      }
      .cart-item-info {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 6px;
      }
      .cart-item-name {
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 14px; font-weight: 500;
        color: #1A1918; line-height: 1.3;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
      }
      .cart-item-bottom {
        display: flex;
        align-items: center;
        justify-content: space-between;
      }
      .cart-item-qty {
        display: flex;
        align-items: center;
        background: #F5F4F1;
        border-radius: 100px;
        height: 30px;
      }
      .cart-item-qty-btn {
        width: 30px; height: 30px;
        border-radius: 100px;
        border: none;
        display: flex; align-items: center; justify-content: center;
        cursor: pointer;
        font-size: 12px;
        transition: background 0.15s;
      }
      .cart-item-qty-btn.minus-btn {
        background: transparent;
        color: #6D6C6A;
      }
      .cart-item-qty-btn.minus-btn:hover { background: rgba(0,0,0,0.05); }
      .cart-item-qty-btn.plus-btn {
        background: #E84D25;
        color: white;
      }
      .cart-item-qty-btn.plus-btn:hover { background: #D4401C; }
      .cart-item-qty-val {
        width: 24px;
        text-align: center;
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 14px; font-weight: 600;
        color: #1A1918;
        user-select: none;
      }
      .cart-item-price {
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 16px; font-weight: 700;
        color: #E84D25;
        white-space: nowrap;
      }
      .cart-item-notes {
        font-size: 12px;
        color: #E88A25;
        margin-top: -2px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      /* Notes section - Redesign */
      .cart-notes-section {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #FFFFFF;
        border-radius: 16px;
        box-shadow: 0 2px 12px rgba(26, 25, 24, 0.03);
        padding: 16px;
        cursor: pointer;
        transition: background 0.2s;
        border: none;
        width: 100%;
      }
      .cart-notes-section:hover { background: #FAFAF8; }
      .cart-notes-left {
        display: flex;
        align-items: center;
        gap: 10px;
        color: #6D6C6A;
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 14px; font-weight: 500;
      }
      .cart-notes-left i { color: #9C9B99; font-size: 16px; }
      .cart-notes-chevron { color: #9C9B99; font-size: 14px; }

      /* Cart bottom section - Redesign */
      .cart-bottom-section {
        background: #FFFFFF;
        box-shadow: 0 -2px 12px rgba(26, 25, 24, 0.06);
        padding: 16px 20px 24px;
        flex-shrink: 0;
        display: flex;
        flex-direction: column;
        gap: 16px;
      }
      .cart-summary {
        display: flex;
        flex-direction: column;
        gap: 10px;
      }
      .cart-summary-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
      }
      .cart-summary-row.subtotal .cart-summary-label,
      .cart-summary-row.subtotal .cart-summary-value {
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 14px; color: #6D6C6A;
      }
      .cart-summary-row.subtotal .cart-summary-value { font-weight: 500; }
      .cart-summary-divider {
        height: 1px;
        background: #E5E4E1;
      }
      .cart-summary-row.total .cart-summary-label {
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 18px; font-weight: 600;
        color: #1A1918;
      }
      .cart-summary-row.total .cart-summary-value {
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 22px; font-weight: 700;
        color: #1A1918; letter-spacing: -0.5px;
      }
      /* Submit button - Redesign */
      .cart-submit-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        width: 100%;
        height: 52px;
        background: #E84D25;
        color: white;
        border: none;
        border-radius: 16px;
        font-family: 'DM Sans', system-ui, sans-serif;
        font-size: 16px; font-weight: 600;
        cursor: pointer;
        box-shadow: 0 4px 16px rgba(232, 77, 37, 0.25);
        transition: background 0.2s, box-shadow 0.2s;
        padding-bottom: env(safe-area-inset-bottom, 0px);
      }
      .cart-submit-btn:hover { background: #D4401C; box-shadow: 0 6px 20px rgba(232, 77, 37, 0.3); }
      .cart-submit-btn i { font-size: 18px; }
      /* 🔥 Cooldown disabled button state */
      .cart-submit-btn.cooldown-disabled {
        background: #D1D0CE !important;
        box-shadow: 0 4px 16px rgba(209, 208, 206, 0.25) !important;
        cursor: not-allowed;
        pointer-events: none;
      }
      .cart-submit-btn.cooldown-disabled:hover {
        background: #D1D0CE !important;
      }
      /* 🔥 Cooldown Timer Bar */
      .cooldown-timer-bar {
        display: none;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        height: 40px;
        background: #FFF0EB;
        padding: 0 16px;
        font-family: 'DM Sans', system-ui, sans-serif;
        flex-shrink: 0;
      }
      .cooldown-timer-bar.active {
        display: flex;
      }
      .cooldown-timer-bar .timer-icon {
        color: #E84D25;
        font-size: 14px;
      }
      .cooldown-timer-bar .timer-text {
        color: #E84D25;
        font-size: 12px;
        font-weight: 500;
      }
      .cooldown-timer-bar .timer-time {
        color: #E84D25;
        font-size: 13px;
        font-weight: 700;
      }
      /* Timer bar compact variant (for modals) */
      .cooldown-timer-bar.compact {
        height: 36px;
      }
      .cooldown-timer-bar.compact .timer-icon { font-size: 12px; }
      .cooldown-timer-bar.compact .timer-text { font-size: 11px; }
      .cooldown-timer-bar.compact .timer-time { font-size: 12px; }
      /* Mobile send button cooldown */
      #mobile-send-order-btn.cooldown-disabled {
        background: #D1D0CE !important;
        cursor: not-allowed;
        pointer-events: none;
      }

      /* Cart scrollbar styling */
      .cart-scrollable-area {
        scrollbar-width: thin;
        scrollbar-color: #D5D4D1 transparent;
      }
      .cart-scrollable-area::-webkit-scrollbar { width: 4px; }
      .cart-scrollable-area::-webkit-scrollbar-track { background: transparent; }
      .cart-scrollable-area::-webkit-scrollbar-thumb { background: #D5D4D1; border-radius: 2px; }
      .cart-scrollable-area::-webkit-scrollbar-thumb:hover { background: #9C9B99; }
      
      /* 移动端和平板底部导航栏 */
      .mobile-bottom-nav {
        display: none;
        position: fixed;
        bottom: 0;
        left: 0;
        width: 100%;
        background-color: white;
        box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
        z-index: 9997;
        padding: 0.75rem;
      }
      
      .mobile-cart-info {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.5rem;
      }
      
      /* iPad优化底部栏 */
      @media (min-width: 768px) and (max-width: 1024px) {
        .mobile-bottom-nav {
          padding: 1rem;
        }
        .mobile-bottom-nav .container {
          max-width: 768px;
          margin: 0 auto;
        }
      }
      
      /* 固定分类栏样式 */
      .sticky-categories {
        position: sticky;
        top: 64px;
        z-index: 40;
        background-color: white;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        transition: all 0.3s ease;
      }
      
      /* 底部固定桌号输入栏 - V2: hidden */
      .bottom-table-bar {
        display: none !important;
      }
      
      /* iPad优化 */
      @media (min-width: 768px) and (max-width: 1024px) {
        .product-grid-ipad {
          grid-template-columns: repeat(3, 1fr);
        }
        .main-cart {
          max-width: 350px;
        }
      }
      
      /* 隐藏滚动条但保持滚动功能 */
      .scrollbar-hide {
        -ms-overflow-style: none;
        scrollbar-width: none;
      }
      .scrollbar-hide::-webkit-scrollbar {
        display: none;
      }
      
      /* 分类滑动导航样式 */
      #categories-container {
        position: relative;
        cursor: grab;
        user-select: none;
        touch-action: pan-x;
      }
      
      #categories-container:active {
        cursor: grabbing;
      }
      
      /* 在移动设备上添加滑动提示 */
      @media (max-width: 768px) {
        #categories-container::after {
          content: '←→';
          position: absolute;
          right: -10px;
          top: 50%;
          transform: translateY(-50%);
          font-size: 12px;
          color: #9ca3af;
          opacity: 0.7;
          pointer-events: none;
          animation: swipeHint 2s ease-in-out infinite;
        }
      }
      
      @keyframes swipeHint {
        0%, 100% { opacity: 0.3; }
        50% { opacity: 0.7; }
      }
      
      /* Ocultar la pista después de la primera interacción */
      .categories-interacted::after {
        display: none !important;
      }
      
      /* 积分产品样式 */
      .points-product-card {
        position: relative;
        transition: all 0.3s ease;
      }
      
      .points-product-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(59, 130, 246, 0.15);
      }
      
      .points-price-badge {
        position: absolute;
        top: -8px;
        right: -8px;
        background: linear-gradient(135deg, #3b82f6, #1d4ed8);
        color: white;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: bold;
        z-index: 10;
        box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
      }
      
      .points-insufficient {
        opacity: 0.5;
        cursor: not-allowed;
      }
      
      .points-insufficient:hover {
        transform: none;
        box-shadow: none;
      }
      
      .user-shortcode-input {
        letter-spacing: 0.2em;
      }
      
      /* 短码输入动画 */
      @keyframes shortcode-success {
        0% { background-color: #10b981; }
        100% { background-color: #3b82f6; }
      }
      
      .shortcode-verified {
        animation: shortcode-success 0.6s ease-in-out;
      }
      
      /* 淡入动画 */
      @keyframes fade-in {
        from {
          opacity: 0;
          transform: translateY(10px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }
      
      .animate-fade-in {
        animation: fade-in 0.5s ease-out;
      }
      
      /* Estilos para productos con descuento */
      .discount-card {
        position: relative;
        overflow: hidden;
      }
      
      .discount-badge {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
      }
      
      .discount-price-original {
        position: relative;
      }
      
      .discount-price-original::after {
        content: '';
        position: absolute;
        top: 50%;
        left: 0;
        right: 0;
        height: 1px;
        background: #6b7280;
      }
      
      /* Estilos para productos populares */
      .popular-card {
        position: relative;
      }
      
      .popular-badge {
        background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
        box-shadow: 0 2px 8px rgba(249, 115, 22, 0.3);
      }
      
      .rank-indicator {
        background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
        border: 2px solid white;
        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
      }
      
      /* Animaciones de entrada para módulos */
      .product-module {
        animation: fadeInUp 0.6s ease-out;
      }
      
      .product-module:nth-child(1) { animation-delay: 0.1s; }
      .product-module:nth-child(2) { animation-delay: 0.2s; }
      .product-module:nth-child(3) { animation-delay: 0.3s; }
      
      @keyframes fadeInUp {
        from {
          opacity: 0;
          transform: translateY(30px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }
      
      /* 平滑滚动 */
      html {
        scroll-behavior: smooth;
      }
      
      /* 改进的产品卡片在触摸设备上的反馈 */
      @media (hover: none) {
        .product-card:active {
          transform: scale(0.98);
        }
      }
      
      /* 购物车项目优化 */
      .cart-item {
        transition: all 0.3s ease;
      }
      .cart-item:hover {
        background-color: rgba(0,0,0,0.02);
      }
      
      /* Custom scrollbar styles */
      .scrollbar-thin {
        scrollbar-width: thin;
        scrollbar-color: rgb(209 213 219) rgb(243 244 246);
      }
      
      .scrollbar-thin::-webkit-scrollbar {
        width: 8px;
      }
      
      .scrollbar-thin::-webkit-scrollbar-track {
        background: rgb(243 244 246);
        border-radius: 4px;
      }
      
      .scrollbar-thin::-webkit-scrollbar-thumb {
        background: rgb(209 213 219);
        border-radius: 4px;
      }
      
      .scrollbar-thin::-webkit-scrollbar-thumb:hover {
        background: rgb(156 163 175);
      }
      
      /* Points Unlock Popup - Module Relative */
      .points-section {
        position: relative;
        min-height: 200px; /* 确保模块有最小高度 */
      }
      
      .points-unlock-popup {
        position: fixed; /* 改为fixed定位，避免影响布局 */
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: white;
        border-radius: var(--radius-lg);
        padding: var(--spacing-6);
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        z-index: 1000; /* 提高z-index */
        min-width: 300px;
        max-width: 400px;
        opacity: 0;
        visibility: hidden;
        scale: 0.9;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border: 1px solid var(--gray-200);
      }
      
      .points-unlock-popup.show {
        opacity: 1;
        visibility: visible;
        scale: 1;
      }
      
      .points-unlock-popup::before {
        content: '';
        position: absolute;
        top: -10px;
        right: 30px;
        width: 0;
        height: 0;
        border-left: 10px solid transparent;
        border-right: 10px solid transparent;
        border-bottom: 10px solid white;
      }
      
      .points-section-overlay {
        position: fixed; /* 改为fixed定位，覆盖整个屏幕 */
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
        z-index: 999; /* 低于弹窗但高于其他内容 */
      }
      
      .points-section-overlay.show {
        opacity: 1;
        visibility: visible;
      }
      
      .popup-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: var(--spacing-4);
        padding-bottom: var(--spacing-3);
        border-bottom: 1px solid var(--gray-200);
      }
      
      .popup-title {
        font-size: var(--font-size-lg);
        font-weight: 600;
        color: var(--gray-800);
        display: flex;
        align-items: center;
        gap: var(--spacing-2);
      }
      
      .popup-close {
        width: 24px;
        height: 24px;
        border: none;
        background: none;
        color: var(--gray-400);
        cursor: pointer;
        border-radius: var(--radius-sm);
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
      }
      
      .popup-close:hover {
        background: var(--gray-100);
        color: var(--gray-600);
      }
      
      /* User Points Display */
      .user-points-display {
        margin-left: auto;
        background: #3b82f6;
        color: white;
        padding: var(--spacing-1) var(--spacing-3);
        border-radius: var(--radius-lg);
        font-size: var(--font-size-sm);
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: var(--spacing-1);
        box-shadow: var(--shadow-sm);
        transition: all 0.3s ease;
      }
      
      .user-points-display.insufficient {
        background: var(--error);
        animation: shake 0.5s ease-in-out;
      }
      
      .points-label {
        font-size: var(--font-size-xs);
        opacity: 0.9;
      }
      
      .points-value {
        font-weight: 600;
        font-size: var(--font-size-sm);
        min-width: 30px;
        text-align: center;
        transition: all 0.3s ease;
      }
      
      .points-value.updating {
        transform: scale(1.1);
        color: #fbbf24;
      }
      
      @keyframes shake {
        0%, 100% { transform: translateX(0); }
        25% { transform: translateX(-4px); }
        75% { transform: translateX(4px); }
      }
      
      @keyframes pointsChange {
        0% { transform: scale(1); }
        50% { transform: scale(1.2); }
        100% { transform: scale(1); }
      }
      
      /* 左下角淡色提示样式 */
      .bottom-left-toast {
        position: fixed;
        bottom: 20px;
        left: 20px;
        background: rgba(0, 0, 0, 0.7);
        color: white;
        padding: 10px 16px;
        border-radius: 8px;
        font-size: 14px;
        z-index: 1000;
        transform: translateY(100px);
        opacity: 0;
        transition: all 0.3s ease;
        max-width: 300px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      }
      
      .bottom-left-toast.show {
        transform: translateY(0);
        opacity: 1;
      }
      
      .bottom-left-toast.success {
        background: rgba(34, 197, 94, 0.9);
      }

      /* 悬浮购物车按钮样式 */
      .floating-cart-button {
        position: fixed;
        bottom: 20px;
        right: 20px;
        z-index: 1000;
        display: flex;
        align-items: center;
        opacity: 0;
        visibility: hidden;
        transform: translateY(20px);
        transition: all 0.3s ease;
      }
      
      .floating-cart-button.show {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
      }
      
      .floating-cart-content {
        display: flex;
        align-items: center;
        background: white;
        border-radius: 50px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        border: 2px solid #e74c3c;
        overflow: hidden;
        transition: all 0.3s ease;
      }
      
      .floating-cart-content:hover {
        box-shadow: 0 6px 25px rgba(0, 0, 0, 0.2);
        transform: translateY(-2px);
      }
      
      .floating-cart-price {
        background: #e74c3c;
        color: white;
        padding: 16px 20px;
        font-weight: bold;
        font-size: 16px;
        min-width: 80px;
        text-align: center;
      }
      
      .floating-cart-icon {
        background: white;
        border: none;
        padding: 16px 20px;
        cursor: pointer;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background-color 0.3s ease;
      }
      
      .floating-cart-icon:hover {
        background: #f8f9fa;
      }
      
      .floating-cart-icon i {
        font-size: 22px;
        color: #e74c3c;
      }
      
      
      /* 移动端适配 */
      @media (max-width: 768px) {
        .floating-cart-button {
          bottom: 15px;
          right: 15px;
        }
        
        .floating-cart-price {
          padding: 14px 18px;
          font-size: 15px;
          min-width: 70px;
        }
        
        .floating-cart-icon {
          padding: 14px 18px;
        }
        
        .floating-cart-icon i {
          font-size: 20px;
        }
        
      }
      
      @media (max-width: 1024px) {
        .side-cart {
          max-width: 100%;
        }

        /* V2: Hide inline cart on mobile/tablet */
        .main-cart {
          display: none;
        }
      }

      /* 只在大屏幕桌面版显示主页购物车区域 */
      @media (min-width: 1025px) {
        .main-cart {
          display: block;
        }
      }
    </style>
  </head>

  <!-- Splash Home -->
  <!--<section id="homeSplash" class="fixed inset-0 bg-primary flex flex-col justify-center items-center z-50 opacity-100 transition-all duration-700">-->
  <!--  <img src="https://ruiyipos.es/wp-content/uploads/2025/02/descargar-3-e1740090601800.png" alt="Logo" class="w-28 h-28 mb-6 animate-bounce">-->
  <!--  <button id="startAppBtn" class="mt-6 bg-white text-primary font-bold px-8 py-3 rounded-full shadow-lg hover:bg-gray-100 transition-all">Entrar</button>-->
  <!--</section>-->

  <!-- Capa que envuelve todo tu carrito actual -->
  <section id="carritoClienteSection" class="opacity-0 pointer-events-none transition-all duration-700">
    <!--  código del carrito  -->
  </section>

  <style>
  /* Estados iniciales */
  #homeSplash.opacity-0 {
    opacity: 0;
    pointer-events: none;
  }
  #homeSplash.opacity-100 {
    opacity: 1;
  }
  #carritoClienteSection.opacity-0 {
    opacity: 0;
    pointer-events: none;
  }
  #carritoClienteSection.opacity-100 {
    opacity: 1;
    pointer-events: auto;
  }
  <?php if ($menu_only_mode): ?>
  /* 🔥 仅菜单浏览模式：隐藏所有购物车和下单相关元素 */
  .add-to-cart,
  .qty-controls,
  .cart-btn,
  #cart-button,
  .side-cart,
  .cart-overlay,
  .floating-cart-button,
  #bottom-cart-bar,
  .bottom-cart-bar,
  #send-order-btn,
  #inline-send-order-btn,
  #mobile-send-order-btn,
  .cart-submit-btn,
  .detail-bottom-bar,
  .detail-note-section { display: none !important; }
  .product-card { cursor: default; }
  .product-image { cursor: default; }
  .product-detail-overlay,
  .product-detail-overlay.active { display: none !important; }
  <?php endif; ?>

  /* ==================== V5 列表模式样式 ==================== */
  body.layout-v5 .product-card {
    flex-direction: row !important;
    border-radius: 14px;
  }

  body.layout-v5 .product-card.hidden {
    display: none !important;
  }

  body.layout-v5 .product-image {
    width: 110px !important;
    min-width: 110px !important;
    height: 110px !important;
    border-radius: 14px 0 0 14px;
  }

  body.layout-v5 .product-info {
    padding: 10px 12px !important;
    justify-content: center;
    gap: 6px !important;
    flex: 1;
    min-width: 0;
  }

  body.layout-v5 .product-title {
    font-size: 14px !important;
    font-weight: 600;
  }

  body.layout-v5 .product-description {
    font-size: 11px !important;
    line-height: 1.3;
  }

  body.layout-v5 .market-price-badge {
    top: 4px;
    left: 4px;
    font-size: 9px;
    padding: 2px 6px;
  }

  /* V5 Tablet */
  @media (min-width: 768px) {
    body.layout-v5 .products-grid,
    body.layout-v5 #points-products-container {
      display: flex !important;
      flex-direction: column !important;
      gap: 12px !important;
    }

    body.layout-v5 .product-image {
      width: 130px !important;
      min-width: 130px !important;
      height: 130px !important;
    }

    body.layout-v5 .product-card {
      flex-direction: row !important;
    }
  }

  /* V5 Phone: keep product rows readable on narrow customer-order screens */
  @media (max-width: 520px) {
    .app-header {
      height: 64px;
      padding: 8px 10px;
    }

    .header-left {
      gap: 8px;
      min-width: 0;
      flex: 1 1 auto;
    }

    .header-logo {
      width: 42px;
      height: 42px;
      border-radius: 12px;
      font-size: 18px;
    }

    .table-badge {
      font-size: 18px;
      line-height: 1.12;
      word-break: break-word;
    }

    .header-actions {
      gap: 6px;
      flex-shrink: 0;
    }

    .lang-dropdown-btn {
      height: 36px;
      max-width: 110px;
      padding: 0 10px;
    }

    .lang-dropdown-label {
      max-width: 66px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .header-btn,
    .cart-btn {
      width: 40px;
      height: 40px;
      font-size: 17px;
    }

    .app-main {
      height: calc(100vh - 64px);
    }

    .v5-category-sidebar {
      width: 74px;
      min-width: 74px;
      max-width: 74px;
    }

    .v5-sidebar-title,
    .category-chip .cat-icon {
      display: none !important;
    }

    .category-chip {
      min-height: 52px;
      padding: 6px 6px;
      font-size: 12px;
      line-height: 1.15;
      word-break: break-word;
    }

    .v5-product-area {
      padding: 8px 8px calc(128px + env(safe-area-inset-bottom));
      scroll-padding-bottom: calc(128px + env(safe-area-inset-bottom));
    }

    body.layout-v5 .products-grid,
    body.layout-v5 #products-container,
    body.layout-v5 #discount-products-container,
    body.layout-v5 #popular-products-container,
    body.layout-v5 #points-products-container {
      gap: 8px !important;
    }

    body.layout-v5 .product-card {
      min-height: 104px;
      border-radius: 14px;
      flex-direction: row !important;
      align-items: center;
      overflow: hidden;
    }

    body.layout-v5 .product-image {
      width: 88px !important;
      min-width: 88px !important;
      height: 88px !important;
      min-height: 88px !important;
      max-height: 88px !important;
      aspect-ratio: 1 / 1;
      align-self: center;
      border-radius: 12px;
      margin-left: 8px;
      object-fit: cover;
    }

    body.layout-v5 .product-info {
      padding: 10px 10px !important;
      gap: 6px !important;
      justify-content: space-between;
      min-width: 0;
    }

    body.layout-v5 .product-title {
      font-size: 15px !important;
      line-height: 1.22;
      -webkit-line-clamp: 2;
    }

    body.layout-v5 .product-description,
    body.layout-v5 .product-allergens {
      display: none !important;
    }

    body.layout-v5 .market-price-badge {
      max-width: 74px;
      font-size: 10px;
      padding: 3px 6px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    body.layout-v5 .product-bottom-row {
      min-height: 32px;
      gap: 6px;
      width: 100%;
      position: static;
    }

    body.layout-v5 .product-price {
      font-size: 15px !important;
      min-width: 0;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    body.layout-v5 .add-to-cart {
      width: 32px;
      height: 32px;
      border-radius: 16px;
      font-size: 15px;
      transition: background 0.2s ease, opacity 0.2s ease, transform 0.18s ease;
    }

    body.layout-v5 .qty-controls {
      display: none;
      position: static;
      right: auto;
      top: auto;
      width: 92px;
      height: 32px;
      min-width: 92px;
      border-radius: 16px;
      transform: none;
      opacity: 1;
      flex-shrink: 0;
    }

    body.layout-v5 .qty-controls.show {
      display: flex;
      animation: qtyRevealMobile 180ms ease-out;
    }

    @keyframes qtyRevealMobile {
      from { transform: translateX(8px) scale(0.96); opacity: 0; }
      to { transform: translateX(0) scale(1); opacity: 1; }
    }

    body.layout-v5 .qty-btn {
      width: 30px;
      height: 32px;
      font-size: 13px;
    }

    body.layout-v5 .qty-value {
      min-width: 28px;
      font-size: 13px;
    }

    body.layout-v5 .product-bottom-row .add-to-cart.has-qty {
      display: none;
    }

    .bottom-cart-bar {
      height: calc(72px + env(safe-area-inset-bottom));
      padding: 0 10px env(safe-area-inset-bottom);
      gap: 8px;
    }

    .bottom-cart-info {
      min-width: 0;
    }

    .bottom-cart-total-price {
      font-size: 22px;
    }

    .bottom-cart-cta {
      height: 48px;
      min-width: 132px;
      padding: 0 14px;
      border-radius: 24px;
      font-size: 15px;
      white-space: nowrap;
    }
  }

  @media (max-width: 380px) {
    .v5-category-sidebar {
      width: 68px;
      min-width: 68px;
      max-width: 68px;
    }

    .category-chip {
      font-size: 11px;
      padding: 5px 4px;
    }

    body.layout-v5 .product-image {
      width: 78px !important;
      min-width: 78px !important;
      height: 78px !important;
      min-height: 78px !important;
      max-height: 78px !important;
      margin-left: 6px;
    }

    body.layout-v5 .product-info {
      padding: 8px 8px !important;
    }

    body.layout-v5 .qty-controls {
      width: 86px;
      min-width: 86px;
    }

    body.layout-v5 .qty-btn {
      width: 28px;
    }

    .bottom-cart-cta {
      min-width: 124px;
      padding: 0 12px;
      font-size: 14px;
    }
  }

  </style>

  <script>
  // Script para gestionar la transición
  document.addEventListener('DOMContentLoaded', function() {
    const homeSplash = document.getElementById('homeSplash');
    const startBtn = document.getElementById('startAppBtn');
    const carritoCliente = document.getElementById('carritoClienteSection');

    // 添加null检查防止错误
    if (startBtn && homeSplash && carritoCliente) {
      startBtn.addEventListener('click', function() {
        // Ocultar home
        homeSplash.classList.remove('opacity-100');
        homeSplash.classList.add('opacity-0');

        // Mostrar carrito cliente
        carritoCliente.classList.remove('opacity-0');
        carritoCliente.classList.add('opacity-100');

        // Opcional: eliminar splash del DOM después de animar
        setTimeout(() => {
          homeSplash.remove();
        }, 800); // Coincidir con la duración de transición
      });
    }
  });
  </script>

  <body class="layout-<?php echo esc_attr($layout_style); ?>">

  <!-- Header -->
  <header class="app-header">
    <div class="header-content">
      <!-- Left Section -->
      <div class="header-left">
        <div class="header-logo">
          <i class="fas fa-utensils"></i>
        </div>
        <?php if (!empty($table_number)): ?>
        <div class="table-badge">
          <?php
          if (!empty($table_display_name) && $table_display_name !== $table_number) {
              echo esc_html($table_display_name);
          } else {
              echo ruiyi_translate('Mesa ', 'Table ', '桌号 ') . esc_html($table_number);
          }
          ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Inline Search Bar (visible on tablet+) -->
      <div class="header-search-inline">
        <i class="fas fa-search"></i>
        <input type="text" class="header-search-input" placeholder="<?php echo ruiyi_translate('Buscar productos...', 'Search products...', '搜索产品...'); ?>">
      </div>

      <!-- Action Buttons -->
      <div class="header-actions">
        <!-- Language Switcher -->
        <div class="lang-dropdown-wrapper">
          <button id="language-menu-button" class="lang-dropdown-btn">
            <i class="fas fa-globe"></i>
            <span class="lang-dropdown-label"><?php
              if ($current_lang === 'zh') echo '简体中文';
              elseif ($current_lang === 'en') echo 'English';
              else echo 'Español';
            ?></span>
            <i class="fas fa-chevron-down lang-dropdown-arrow"></i>
          </button>
          <div id="language-menu" class="lang-dropdown-menu hidden">
            <a href="#" class="language-option lang-dropdown-item <?php echo $current_lang === 'es' ? 'active' : ''; ?>" data-lang="es">Español</a>
            <a href="#" class="language-option lang-dropdown-item <?php echo $current_lang === 'en' ? 'active' : ''; ?>" data-lang="en">English</a>
            <a href="#" class="language-option lang-dropdown-item <?php echo $current_lang === 'zh' ? 'active' : ''; ?>" data-lang="zh">简体中文</a>
          </div>
        </div>

        <!-- Search Toggle Button -->
        <button id="search-toggle-btn" class="header-btn">
          <i class="fas fa-search"></i>
        </button>

        <!-- Cart Button -->
        <button id="cart-button" class="header-btn cart-btn">
          <i class="fas fa-shopping-cart"></i>
          <span id="cart-count" class="cart-count">0</span>
        </button>

        <!-- Home Button -->
        <button id="returnHomeBtn" class="header-btn hidden">
          <i class="fas fa-home"></i>
        </button>
      </div>
    </div>
  </header>

  <!-- V2: Search Bar -->
  <div class="search-bar-container">
    <i class="fas fa-search search-bar-icon"></i>
    <input type="text" id="product-search-input" class="search-bar-input"
           placeholder="<?php echo ruiyi_translate('Buscar productos...', 'Search products...', '搜索产品...'); ?>">
  </div>

  <!-- V5: Hidden horizontal categories (preserved for JS compatibility) -->
  <div class="horizontal-categories" id="horizontal-categories" style="display:none;">
    <?php foreach ($categories as $category): ?>
    <button class="category-chip" data-category="<?php echo esc_attr($category['id']); ?>">
      <span><?php echo esc_html($category['name']); ?></span>
    </button>
    <?php endforeach; ?>
  </div>

  <!-- Main Content - V5: Sidebar + Products -->
  <main class="app-main">
    <!-- V5: Category Sidebar -->
    <div class="v5-category-sidebar" id="v5-category-sidebar">
      <div class="v5-sidebar-title"><?php echo ruiyi_translate('Categorías', 'Categories', '分类'); ?></div>
      <?php foreach ($categories as $category): ?>
      <button class="category-chip v5-cat-btn" data-category="<?php echo esc_attr($category['id']); ?>">
        <span class="material-symbols-rounded cat-icon">restaurant</span>
        <span><?php echo esc_html($category['name']); ?></span>
      </button>
      <?php endforeach; ?>
    </div>

    <!-- V5: Product Area -->
    <div class="v5-product-area">
    <div class="app-content" style="width: 100%;">
      
      
      <!-- Points Products Section (默认隐藏) -->
      <?php if ($show_points_module): ?>
      <section class="content-section points-section hidden" id="points-products-section">
        <div class="section-header">
          <div class="section-title">
            <i class="fas fa-star"></i>
            <?php echo ruiyi_translate('Productos de Puntos', 'Points Products', '积分产品'); ?>
            <!-- User Points Display -->
            <div class="user-points-display hidden" id="user-points-display">
              <span class="points-label"><?php echo ruiyi_translate('Puntos restantes:', 'Points:', '剩余积分:'); ?></span>
              <span class="points-value" id="displayed-points">0</span>
            </div>
          </div>
          <div class="section-actions">
            <button id="unlock-points-btn" class="action-btn primary">
              <i class="fas fa-lock"></i>
              <span class="unlock-text"><?php echo ruiyi_translate('Desbloquear', 'Unlock', '解锁'); ?></span>
            </button>
          </div>
        </div>
        
        <!-- Points Products Grid -->
        <div class="points-products-grid hidden" id="points-products-grid">
          <div class="products-grid" id="points-products-container">
            <!-- Points products will be loaded via JavaScript -->
          </div>
        </div>
        
        <!-- Loading State -->
        <div id="points-loading" class="text-center py-12 hidden">
          <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-500 mx-auto mb-4"></div>
          <p class="text-gray-600"><?php echo ruiyi_translate('Cargando productos...', 'Loading products...', '加载产品中...'); ?></p>
        </div>
        
        <!-- Insufficient Points Notice -->
        <div id="insufficient-points-notice" class="text-center py-12 hidden">
          <i class="fas fa-exclamation-triangle text-warning text-4xl mb-4"></i>
          <p class="text-gray-600"><?php echo ruiyi_translate('Puntos insuficientes para canjear productos', 'Insufficient points to redeem products', '积分不足，无法兑换产品'); ?></p>
        </div>
        
        <!-- No Products Notice -->
        <div id="no-points-products-notice" class="text-center py-12 hidden">
          <i class="fas fa-gift text-gray-400 text-4xl mb-4"></i>
          <p class="text-gray-600"><?php echo ruiyi_translate('No hay productos de puntos disponibles', 'No points products available', '商家暂无积分产品'); ?></p>
        </div>
        
        <!-- Points Section Overlay -->
        <div class="points-section-overlay" id="points-section-overlay"></div>
        
        <!-- Points Unlock Popup -->
        <div class="points-unlock-popup" id="points-unlock-popup">
          <div class="popup-header">
            <div class="popup-title">
              <i class="fas fa-key" style="color: #3b82f6;"></i>
              <?php echo ruiyi_translate('Desbloquear Productos de Puntos', 'Unlock Points Products', '解锁积分产品'); ?>
            </div>
            <button class="popup-close" id="points-popup-close">
              <i class="fas fa-times"></i>
            </button>
          </div>
          
          <div class="popup-body">
            <p class="text-gray-600 text-center mb-4 text-sm">
              <?php echo ruiyi_translate('Ingrese su código de 4 dígitos para acceder a productos de puntos', 'Enter your 4-digit code to access points products', '请输入您的4位短码来解锁积分产品'); ?>
            </p>
            
            <div class="mb-4">
              <div class="flex justify-center mb-3">
                <input 
                  type="text" 
                  id="popup-user-shortcode" 
                  maxlength="4" 
                  pattern="[0-9]{4}"
                  placeholder="0000"
                  class="w-32 px-4 py-3 text-center text-xl font-mono border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent bg-white"
                />
              </div>
              
              <div class="flex justify-center gap-3">
                <button 
                  id="popup-verify-btn"
                  class="action-btn primary"
                  style="background: #3b82f6; border-color: #3b82f6;"
                >
                  <i class="fas fa-unlock"></i>
                  <?php echo ruiyi_translate('Desbloquear', 'Unlock', '解锁'); ?>
                </button>
                <button 
                  id="popup-cancel-btn"
                  class="action-btn"
                >
                  <?php echo ruiyi_translate('Cancelar', 'Cancel', '取消'); ?>
                </button>
              </div>
            </div>
          </div>
        </div>
      </section>
      <?php endif; ?>

      <!-- Discount Products Section (默认隐藏) -->
      <section class="content-section hidden" id="discount-products-section">
        <div class="section-header">
          <div class="section-title">
            <i class="fas fa-tag"></i>
            <span id="discount-section-title"><?php echo ruiyi_translate('Productos con Descuento', 'Discount Products', '折扣产品'); ?></span>
          </div>
        </div>
        
        <div class="products-grid" id="discount-products-container">
          <!-- Discount products will be loaded here -->
        </div>
        
        <div id="discount-loading" class="text-center py-8 hidden">
          <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-gray-400 mx-auto"></div>
        </div>
      </section>
      
      <!-- Popular Products Section (默认隐藏) -->
      <section class="content-section hidden" id="popular-products-section">
        <div class="section-header">
          <div class="section-title">
            <i class="fas fa-thumbs-up"></i>
            <span id="popular-section-title"><?php echo ruiyi_translate('Productos Populares', 'Popular Products', '热门产品'); ?></span>
          </div>
        </div>
        
        <div class="products-grid" id="popular-products-container">
          <!-- Popular products will be loaded here -->
        </div>
        
        <div id="popular-loading" class="text-center py-8 hidden">
          <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-gray-400 mx-auto"></div>
        </div>
      </section>
      <!-- Regular Products Section -->
      <section class="content-section">
        <div class="section-header">
          <div class="section-title">
            <i class="fas fa-utensils"></i>
            <span id="regular-section-title"><?php echo ruiyi_translate('Todos los Productos', 'All Products', '所有产品'); ?></span>
          </div>
          <span class="section-product-count"><?php echo count($products) . ' ' . ruiyi_translate('productos', 'products', '个产品'); ?></span>
        </div>

        <div id="category-time-notice" class="category-time-notice hidden"></div>
        
        <div class="products-grid" id="products-container">
          <?php foreach ($products as $product): ?>
          <?php $is_buffet_product = function_exists('ruiyi_is_buffet_product') && ruiyi_is_buffet_product($product['id'], $table_display_name ?: $table_full_id, $table_global_number); ?>
          <?php
            // 🔥 多语言产品名称：根据客户页面当前语言确定显示名称
            $product_display_name = $product['name'];
            if ($current_lang === 'zh' && !empty($product['name_zh'])) {
                $product_display_name = $product['name_zh'];
            } elseif ($current_lang === 'en' && !empty($product['name_en'])) {
                $product_display_name = $product['name_en'];
            }
          ?>
          <div class="product-card relative" data-id="<?php echo esc_attr($product['id']); ?>" data-price="<?php echo esc_attr($product['price']); ?>" data-base-price="<?php echo esc_attr($product['base_price'] ?? $product['price']); ?>" data-zone-category-id="<?php echo esc_attr($table_zone_category_id); ?>" data-name="<?php echo esc_attr($product_display_name); ?>" data-name-es="<?php echo esc_attr($product['name']); ?>" data-name-zh="<?php echo esc_attr($product['name_zh'] ?? ''); ?>" data-name-en="<?php echo esc_attr($product['name_en'] ?? ''); ?>" data-image="<?php echo esc_attr($product['image']); ?>" data-market-price="<?php echo !empty($product['market_price']) ? '1' : '0'; ?>" data-is-buffet="<?php echo $is_buffet_product ? '1' : '0'; ?>" data-description="<?php echo esc_attr($product['short_description'] ?? ''); ?>" data-allergens="<?php echo !empty($product['allergens']) ? esc_attr(implode(',', $product['allergens'])) : ''; ?>" data-categories="<?php echo esc_attr(implode(' ', $product['category_ids'] ?? array())); ?>" data-category="<?php
            // Get product categories
            $terms = get_the_terms($product['id'], 'product_cat');
            echo !empty($terms) && !is_wp_error($terms) ? esc_attr($terms[0]->term_id) : '';
          ?>">
            <?php if ($is_buffet_product): ?>
            <div class="market-price-badge" style="background: linear-gradient(135deg, #f97316, #ea580c);">🍽️ <?php echo ruiyi_translate('Buffet', 'Buffet', '自助餐'); ?></div>
            <?php elseif (!empty($product['market_price'])): ?>
            <div class="market-price-badge"><?php echo ruiyi_translate('Precio de mercado', 'Market Price', '时价'); ?></div>
            <?php endif; ?>
            <?php if (!empty($product['image'])): ?>
            <img src="<?php echo esc_url($product['image']); ?>" alt="<?php echo esc_attr($product['name']); ?>" class="product-image" loading="lazy">
            <?php else: ?>
            <div class="product-image bg-gray-100 flex items-center justify-center">
              <i class="fas fa-utensils text-3xl text-gray-300"></i>
            </div>
            <?php endif; ?>
            
            <div class="product-info">
              <h3 class="product-title"><?php echo esc_html($product_display_name); ?></h3>
              <?php if (!empty($product['short_description'])): ?>
              <p class="product-description"><?php echo esc_html(wp_strip_all_tags($product['short_description'])); ?></p>
              <?php endif; ?>
              <?php if (!empty($product['allergens'])): ?>
              <div class="product-allergens">
                <?php
                $allergen_icons = array(
                  'gluten' => '🌾',
                  'crustaceans' => '🦐',
                  'eggs' => '🥚',
                  'fish' => '🐟',
                  'peanuts' => '🥜',
                  'soy' => '🫘',
                  'milk' => '🥛',
                  'nuts' => '🌰',
                  'celery' => '🥬',
                  'mustard' => '🟡',
                  'sesame' => '⚪',
                  'sulphites' => '🍷',
                  'lupin' => '🌸',
                  'molluscs' => '🦪',
                  'chili' => '🌶️',
                  'meat' => '🥩',
                );
                $allergen_names = array(
                  'gluten' => array('es' => 'Gluten', 'en' => 'Gluten', 'zh' => '麸质'),
                  'crustaceans' => array('es' => 'Crustáceos', 'en' => 'Crustaceans', 'zh' => '甲壳类'),
                  'eggs' => array('es' => 'Huevos', 'en' => 'Eggs', 'zh' => '鸡蛋'),
                  'fish' => array('es' => 'Pescado', 'en' => 'Fish', 'zh' => '鱼'),
                  'peanuts' => array('es' => 'Cacahuetes', 'en' => 'Peanuts', 'zh' => '花生'),
                  'soy' => array('es' => 'Soja', 'en' => 'Soy', 'zh' => '大豆'),
                  'milk' => array('es' => 'Lácteos', 'en' => 'Milk', 'zh' => '牛奶'),
                  'nuts' => array('es' => 'Frutos secos', 'en' => 'Nuts', 'zh' => '坚果'),
                  'celery' => array('es' => 'Apio', 'en' => 'Celery', 'zh' => '芹菜'),
                  'mustard' => array('es' => 'Mostaza', 'en' => 'Mustard', 'zh' => '芥末'),
                  'sesame' => array('es' => 'Sésamo', 'en' => 'Sesame', 'zh' => '芝麻'),
                  'sulphites' => array('es' => 'Sulfitos', 'en' => 'Sulphites', 'zh' => '亚硫酸盐'),
                  'lupin' => array('es' => 'Altramuces', 'en' => 'Lupin', 'zh' => '羽扇豆'),
                  'molluscs' => array('es' => 'Moluscos', 'en' => 'Molluscs', 'zh' => '软体动物'),
                  'chili' => array('es' => 'Chile', 'en' => 'Chili Pepper', 'zh' => '辣椒'),
                  'meat' => array('es' => 'Carne', 'en' => 'Meat', 'zh' => '肉类'),
                );
                foreach ($product['allergens'] as $allergen):
                  if (isset($allergen_icons[$allergen])):
                    $name = isset($allergen_names[$allergen]) ? ruiyi_translate($allergen_names[$allergen]['es'], $allergen_names[$allergen]['en'], $allergen_names[$allergen]['zh']) : $allergen;
                ?>
                <span class="allergen-icon" title="<?php echo esc_attr($name); ?>"><?php echo $allergen_icons[$allergen]; ?></span>
                <?php
                  endif;
                endforeach;
                ?>
              </div>
              <?php endif; ?>
              <div class="product-bottom-row">
                <div class="product-price"><?php echo $is_buffet_product ? wc_price(0) : wc_price($product['price']); ?></div>
                <button class="add-to-cart" aria-label="<?php echo ruiyi_translate('Agregar', 'Add', '添加'); ?>">
                  <i class="fas fa-plus"></i>
                </button>
                <div class="qty-controls" id="qty-controls-<?php echo esc_attr($product['id']); ?>">
                  <button class="qty-btn qty-minus" data-id="<?php echo esc_attr($product['id']); ?>"><i class="fas fa-minus"></i></button>
                  <span class="qty-value" id="qty-value-<?php echo esc_attr($product['id']); ?>">1</span>
                  <button class="qty-btn qty-plus" data-id="<?php echo esc_attr($product['id']); ?>"><i class="fas fa-plus"></i></button>
                </div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </section>
    </div>
    </div><!-- /v5-product-area -->

    <!-- Right Section: Cart (Inline version) -->
    <div class="w-full lg:w-1/4 main-cart">
      <div style="background: #F5F4F1; border-radius: 16px; overflow: hidden; position: sticky; top: 20px; max-height: calc(100vh - 2rem); display: flex; flex-direction: column; box-shadow: 0 2px 12px rgba(26,25,24,0.06);">
        <!-- Inline Cart Header -->
        <div class="cart-header">
          <div class="cart-header-left">
            <div class="cart-header-icon">
              <i class="fas fa-shopping-bag"></i>
            </div>
            <span class="cart-header-title"><?php echo ruiyi_translate('Tu Pedido', 'Your Order', '您的订单'); ?></span>
            <span class="cart-item-count-badge" id="inline-cart-count-badge">0 items</span>
          </div>
        </div>

        <!-- Hidden mesa input (preserved for functionality) -->
        <div style="display:none;">
          <?php if (!empty($table_number)): ?>
          <input type="text" id="inline-table-number" class="table-number-input" value="<?php echo esc_attr(!empty($table_display_name) ? $table_display_name : $table_number); ?>" readonly data-original-table="<?php echo esc_attr($table_number); ?>" data-full-table-id="<?php echo esc_attr($table_full_id); ?>">
          <?php else: ?>
          <input type="text" id="inline-table-number" class="table-number-input" placeholder="<?php echo ruiyi_translate('Ej: 1', 'Ex: 1', '例如: 1'); ?>">
          <?php endif; ?>
        </div>

        <!-- Inline Cart Content (scrollable) -->
        <div style="flex: 1; min-height: 0; overflow-y: auto; padding: 16px 20px; display: flex; flex-direction: column; gap: 16px; -webkit-overflow-scrolling: touch;">
          <!-- Cart Items Card -->
          <div class="cart-items-card">
            <div id="inline-cart-items"></div>
            <div class="text-center py-8" id="inline-empty-cart" style="color: #9C9B99;">
              <i class="fas fa-shopping-basket" style="font-size: 36px; margin-bottom: 8px; display: block; color: #D5D4D1;"></i>
              <p style="font-size: 14px; font-weight: 500; margin-bottom: 4px;"><?php echo ruiyi_translate('Tu carrito está vacío', 'Your cart is empty', '您的购物车是空的'); ?></p>
              <p style="font-size: 13px;"><?php echo ruiyi_translate('Añade productos para hacer tu pedido', 'Add products to place your order', '添加产品以开始下单'); ?></p>
            </div>
          </div>

          <!-- Notes Section -->
          <button type="button" id="inline-add-notes-btn" class="cart-notes-section">
            <div class="cart-notes-left">
              <i class="fas fa-pen-fancy"></i>
              <span><?php echo ruiyi_translate('Añadir notas a productos', 'Add notes to products', '添加产品备注'); ?></span>
              <span id="inline-notes-count-badge" class="hidden" style="background:#E84D25;color:white;font-size:11px;font-weight:700;border-radius:100px;width:20px;height:20px;display:flex;align-items:center;justify-content:center;">0</span>
            </div>
            <i class="fas fa-chevron-right cart-notes-chevron"></i>
          </button>
        </div>

        <!-- Inline Cart Bottom Section -->
        <div class="cart-bottom-section">
          <!-- 积分信息显示区域 -->
          <div class="user-info-display-area" id="user-info-display-area" style="display: none; background: linear-gradient(135deg, #EEF2FF, #E0E7FF); border-radius: 12px; padding: 12px; border: 1px solid #C7D2FE;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
              <div style="display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-user-circle" style="color: #6366F1; font-size: 20px;"></i>
                <div>
                  <p style="font-size: 11px; color: #6B7280;"><?php echo ruiyi_translate('Usuario', 'User', '用户'); ?></p>
                  <p style="font-weight: 500; color: #3730A3; font-size: 14px;" id="user-name-display">-</p>
                </div>
              </div>
              <div style="text-align: right;">
                <p style="font-size: 11px; color: #6B7280;"><?php echo ruiyi_translate('Puntos disponibles', 'Available Points', '可用积分'); ?></p>
                <p style="font-weight: 700; color: #4F46E5; font-size: 16px; display: flex; align-items: center; gap: 4px; justify-content: flex-end;">
                  <i class="fas fa-coins" style="color: #EAB308;"></i>
                  <span id="user-points-display">0</span>
                </p>
              </div>
            </div>
          </div>

          <!-- Cart Summary -->
          <div class="cart-summary">
            <div class="cart-summary-row subtotal">
              <span class="cart-summary-label"><?php echo ruiyi_translate('Subtotal', 'Subtotal', '小计'); ?></span>
              <span class="cart-summary-value" id="inline-cart-subtotal">€0.00</span>
            </div>
            <div class="cart-summary-divider"></div>
            <div class="cart-summary-row total">
              <span class="cart-summary-label"><?php echo ruiyi_translate('Total', 'Total', '总计'); ?></span>
              <span class="cart-summary-value" id="inline-cart-total">€0.00</span>
            </div>
          </div>

          <!-- 🔥 Cooldown Timer Bar (Inline Cart) -->
          <div id="inline-cart-timer-bar" class="cooldown-timer-bar">
            <i class="fas fa-lock timer-icon"></i>
            <span class="timer-text"><?php echo ruiyi_translate('Próximo pedido disponible en', 'Next order available in', '下次可点餐时间'); ?></span>
            <span class="timer-time">00:00</span>
          </div>

          <!-- Submit Button -->
          <button id="inline-send-order-btn" class="cart-submit-btn">
            <i class="fas fa-paper-plane"></i>
            <span><?php echo ruiyi_translate('Enviar Pedido', 'Send Order', '提交订单'); ?></span>
          </button>
        </div>
      </div>
    </div>
  </main>

  <!-- 添加侧边购物车菜单 -->
  <div class="cart-overlay" id="cart-overlay"></div>
  <div class="side-cart" id="side-cart">
    <!-- Cart Header - Redesign -->
    <div class="cart-header">
      <div class="cart-header-left">
        <div class="cart-header-icon">
          <i class="fas fa-shopping-bag"></i>
        </div>
        <span class="cart-header-title"><?php echo ruiyi_translate('Tu Pedido', 'Your Order', '您的订单'); ?></span>
        <span class="cart-item-count-badge" id="side-cart-count-badge">0 items</span>
      </div>
      <button class="close-cart" id="close-cart">
        <i class="fas fa-times"></i>
      </button>
    </div>

    <!-- 🔥 Cooldown Timer Bar (Side Cart) -->
    <div id="side-cart-timer-bar" class="cooldown-timer-bar">
      <i class="fas fa-lock timer-icon"></i>
      <span class="timer-text"><?php echo ruiyi_translate('Próximo pedido disponible en', 'Next order available in', '下次可点餐时间'); ?></span>
      <span class="timer-time">00:00</span>
    </div>

    <div class="cart-body">
      <!-- Scrollable content area -->
      <div class="cart-scrollable-area">
        <!-- Hidden mesa input (preserved for functionality) -->
        <div style="display:none;">
          <?php if (!empty($table_number)): ?>
          <input type="text" id="side-table-number" class="table-number-input" value="<?php echo esc_attr(!empty($table_display_name) ? $table_display_name : $table_number); ?>" readonly data-original-table="<?php echo esc_attr($table_number); ?>" data-full-table-id="<?php echo esc_attr($table_full_id); ?>">
          <?php else: ?>
          <input type="text" id="side-table-number" class="table-number-input" placeholder="<?php echo ruiyi_translate('Ej: 1', 'Ex: 1', '例如: 1'); ?>">
          <?php endif; ?>
        </div>

        <!-- Cart Items Card -->
        <div class="cart-items-card">
          <div id="cart-items"></div>
          <div class="text-center py-8" id="empty-cart" style="color: #9C9B99;">
            <i class="fas fa-shopping-basket" style="font-size: 36px; margin-bottom: 8px; display: block; color: #D5D4D1;"></i>
            <p style="font-size: 14px; font-weight: 500; margin-bottom: 4px;"><?php echo ruiyi_translate('Tu carrito está vacío', 'Your cart is empty', '您的购物车是空的'); ?></p>
            <p style="font-size: 13px;"><?php echo ruiyi_translate('Añade productos para hacer tu pedido', 'Add products to place your order', '添加产品以开始下单'); ?></p>
          </div>
        </div>

        <!-- Notes section - Redesign -->
        <button type="button" id="side-add-notes-btn" class="cart-notes-section">
          <div class="cart-notes-left">
            <i class="fas fa-pen-fancy"></i>
            <span><?php echo ruiyi_translate('Añadir notas a productos', 'Add notes to products', '添加产品备注'); ?></span>
            <span id="side-notes-count-badge" class="hidden" style="background:#E84D25;color:white;font-size:11px;font-weight:700;border-radius:100px;width:20px;height:20px;display:flex;align-items:center;justify-content:center;">0</span>
          </div>
          <i class="fas fa-chevron-right cart-notes-chevron"></i>
        </button>

        <!-- Hidden order notes textarea (compatibility) -->
        <textarea id="cart-order-notes" class="hidden"></textarea>
      </div>

      <!-- Bottom Section - Redesign -->
      <div class="cart-bottom-section">
        <!-- 积分信息显示区域 -->
        <div class="side-user-info-display-area" id="side-user-info-display-area" style="display: none; background: linear-gradient(135deg, #EEF2FF, #E0E7FF); border-radius: 12px; padding: 12px; border: 1px solid #C7D2FE;">
          <div style="display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 8px;">
              <i class="fas fa-user-circle" style="color: #6366F1; font-size: 20px;"></i>
              <div>
                <p style="font-size: 11px; color: #6B7280;"><?php echo ruiyi_translate('Usuario', 'User', '用户'); ?></p>
                <p style="font-weight: 500; color: #3730A3; font-size: 14px;" id="side-user-name-display">-</p>
              </div>
            </div>
            <div style="text-align: right;">
              <p style="font-size: 11px; color: #6B7280;"><?php echo ruiyi_translate('Puntos disponibles', 'Available Points', '可用积分'); ?></p>
              <p style="font-weight: 700; color: #4F46E5; font-size: 16px; display: flex; align-items: center; gap: 4px; justify-content: flex-end;">
                <i class="fas fa-coins" style="color: #EAB308;"></i>
                <span id="side-user-points-display">0</span>
              </p>
            </div>
          </div>
        </div>

        <!-- Cart Summary -->
        <div class="cart-summary">
          <div class="cart-summary-row subtotal">
            <span class="cart-summary-label"><?php echo ruiyi_translate('Subtotal', 'Subtotal', '小计'); ?></span>
            <span class="cart-summary-value" id="cart-subtotal">€0.00</span>
          </div>
          <div class="cart-summary-divider"></div>
          <div class="cart-summary-row total">
            <span class="cart-summary-label"><?php echo ruiyi_translate('Total', 'Total', '总计'); ?></span>
            <span class="cart-summary-value" id="cart-total">€0.00</span>
          </div>
        </div>

        <!-- Submit Button -->
        <button id="send-order-btn" class="cart-submit-btn">
          <i class="fas fa-paper-plane"></i>
          <span><?php echo ruiyi_translate('Enviar Pedido', 'Send Order', '提交订单'); ?></span>
        </button>
      </div>
    </div>
  </div>

  <!-- 🔥 产品备注弹窗 - Redesign -->
  <div id="product-notes-modal" class="fixed inset-0 z-[9999] hidden">
    <!-- 遮罩层 -->
    <div id="product-notes-overlay" style="position:absolute;inset:0;background:rgba(0,0,0,0.32);"></div>
    <!-- 底部抽屉面板 -->
    <div id="product-notes-panel" style="position:absolute;bottom:0;left:0;right:0;max-height:85vh;display:flex;flex-direction:column;background:#FFFFFF;border-radius:28px 28px 0 0;box-shadow:0 -8px 40px rgba(0,0,0,0.12);transform:translateY(100%);transition:transform 0.3s cubic-bezier(0.4,0,0.2,1);">
      <!-- 拖拽条 -->
      <div style="display:flex;justify-content:center;height:28px;align-items:center;flex-shrink:0;">
        <div style="width:40px;height:4px;background:#D1D0CD;border-radius:100px;"></div>
      </div>
      <!-- 标题栏 -->
      <div style="display:flex;align-items:center;justify-content:space-between;padding:0 24px 16px;flex-shrink:0;">
        <div style="display:flex;align-items:center;gap:10px;">
          <div style="width:38px;height:38px;border-radius:12px;background:#FFF0EB;display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-pen-fancy" style="color:#E84D25;font-size:16px;"></i>
          </div>
          <div>
            <div style="font-family:'DM Sans',system-ui;font-size:18px;font-weight:600;color:#1A1918;letter-spacing:-0.2px;"><?php echo ruiyi_translate('Notas de productos', 'Product Notes', '产品备注'); ?></div>
            <div style="font-family:'DM Sans',system-ui;font-size:12px;color:#9C9B99;"><?php echo ruiyi_translate('Añade instrucciones especiales', 'Add special instructions', '添加特殊说明'); ?></div>
          </div>
        </div>
        <button id="close-notes-modal" style="width:34px;height:34px;border-radius:100px;background:#F5F4F1;border:none;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#6D6C6A;font-size:14px;">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <!-- 🔥 Cooldown Timer Bar (Product Notes Modal) -->
      <div id="notes-timer-bar" class="cooldown-timer-bar compact">
        <i class="fas fa-lock timer-icon"></i>
        <span class="timer-text"><?php echo ruiyi_translate('Próximo pedido en', 'Next order in', '下次可点餐'); ?></span>
        <span class="timer-time">00:00</span>
      </div>

      <!-- 内容区 -->
      <div style="flex:1;min-height:0;overflow-y:auto;padding:0 20px;display:flex;flex-direction:column;gap:14px;-webkit-overflow-scrolling:touch;">
        <!-- 提示横幅 -->
        <div style="display:flex;align-items:center;gap:10px;background:#FFF8F0;border:1px solid #F0D8C0;border-radius:12px;padding:12px 14px;">
          <i class="fas fa-info-circle" style="color:#E84D25;font-size:14px;flex-shrink:0;"></i>
          <span style="font-family:'DM Sans',system-ui;font-size:12px;font-weight:500;color:#D08068;line-height:1.4;"><?php echo ruiyi_translate('Toca un producto para añadir una nota especial', 'Tap a product to add a special note', '点击产品以添加特别备注'); ?></span>
        </div>
        <!-- 产品列表卡片 -->
        <div id="notes-product-list" style="background:#FFFFFF;border:1px solid #E5E4E1;border-radius:16px;overflow:hidden;">
          <!-- 动态生成 -->
        </div>
      </div>
      <!-- 底部栏 -->
      <div style="background:#FFFFFF;box-shadow:0 -2px 10px rgba(26,25,24,0.03);padding:12px 20px 28px;display:flex;flex-direction:column;gap:10px;flex-shrink:0;">
        <div id="notes-summary-row" style="display:flex;align-items:center;justify-content:center;gap:6px;width:100%;">
          <i class="fas fa-comment-dots" style="color:#3D8A5A;font-size:12px;"></i>
          <span id="notes-summary-text" style="font-family:'DM Sans',system-ui;font-size:12px;font-weight:500;color:#3D8A5A;">0 <?php echo ruiyi_translate('notas añadidas', 'notes added', '条备注已添加'); ?></span>
        </div>
        <button id="done-notes-btn" style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;height:50px;background:#E84D25;color:white;border:none;border-radius:14px;font-family:'DM Sans',system-ui;font-size:15px;font-weight:600;cursor:pointer;box-shadow:0 4px 16px rgba(232,77,37,0.21);transition:background 0.2s;">
          <i class="fas fa-check" style="font-size:14px;"></i>
          <span><?php echo ruiyi_translate('Guardar Notas', 'Save Notes', '保存备注'); ?></span>
        </button>
      </div>
    </div>
  </div>

  <!-- 🔥 订单成功弹窗 - Redesign -->
  <div id="order-success-modal" class="fixed inset-0 z-[99999] hidden" style="display:none;">
    <div id="order-success-overlay" style="position:absolute;inset:0;background:rgba(0,0,0,0.38);display:flex;align-items:center;justify-content:center;padding:0 28px;">
      <div id="order-success-content" style="background:#FFFFFF;border-radius:24px;box-shadow:0 8px 40px rgba(0,0,0,0.19);padding:32px 24px 24px;width:100%;max-width:380px;display:flex;flex-direction:column;align-items:center;transform:scale(0.9);opacity:0;transition:transform 0.3s cubic-bezier(0.34,1.56,0.64,1),opacity 0.25s ease;">
        <!-- 成功图标 -->
        <div style="width:96px;height:96px;border-radius:100px;background:radial-gradient(circle,#E8F8EE,#D0F0DC);display:flex;align-items:center;justify-content:center;">
          <div style="width:68px;height:68px;border-radius:100px;background:#3D8A5A;box-shadow:0 4px 16px rgba(61,138,90,0.25);display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-check" style="color:white;font-size:28px;"></i>
          </div>
        </div>
        <div style="height:20px;"></div>
        <!-- 标题 -->
        <div style="font-family:'DM Sans',system-ui;font-size:24px;font-weight:700;color:#1A1918;letter-spacing:-0.5px;"><?php echo ruiyi_translate('¡Pedido Enviado!', 'Order Sent!', '订单已发送！'); ?></div>
        <div style="height:6px;"></div>
        <!-- 描述 -->
        <div style="font-family:'DM Sans',system-ui;font-size:14px;color:#6D6C6A;text-align:center;line-height:1.5;max-width:280px;"><?php echo ruiyi_translate('Tu pedido ha sido recibido y está siendo preparado. Te avisaremos cuando esté listo.', 'Your order has been received and is being prepared. We will notify you when it is ready.', '您的订单已收到，正在准备中。准备好后我们会通知您。'); ?></div>
        <div style="height:20px;"></div>
        <!-- 订单信息卡片 -->
        <div id="order-success-info" style="width:100%;background:#F5F4F1;border-radius:16px;padding:16px 18px;display:flex;flex-direction:column;gap:12px;">
          <div style="display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:8px;">
              <i class="fas fa-receipt" style="color:#9C9B99;font-size:14px;"></i>
              <span style="font-family:'DM Sans',system-ui;font-size:13px;color:#6D6C6A;"><?php echo ruiyi_translate('Número de pedido', 'Order number', '订单号'); ?></span>
            </div>
            <span id="success-order-id" style="font-family:'DM Sans',system-ui;font-size:13px;font-weight:600;color:#1A1918;">#0000</span>
          </div>
          <div style="height:1px;background:#E5E4E1;"></div>
          <div style="display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:8px;">
              <i class="fas fa-chair" style="color:#9C9B99;font-size:14px;"></i>
              <span style="font-family:'DM Sans',system-ui;font-size:13px;color:#6D6C6A;"><?php echo ruiyi_translate('Mesa', 'Table', '桌号'); ?></span>
            </div>
            <span id="success-table" style="font-family:'DM Sans',system-ui;font-size:13px;font-weight:600;color:#1A1918;">-</span>
          </div>
          <div style="height:1px;background:#E5E4E1;"></div>
          <div style="display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:8px;">
              <i class="fas fa-shopping-bag" style="color:#9C9B99;font-size:14px;"></i>
              <span style="font-family:'DM Sans',system-ui;font-size:13px;color:#6D6C6A;"><?php echo ruiyi_translate('Artículos', 'Items', '商品'); ?></span>
            </div>
            <span id="success-items" style="font-family:'DM Sans',system-ui;font-size:13px;font-weight:600;color:#1A1918;">0</span>
          </div>
          <div style="height:1px;background:#E5E4E1;"></div>
          <div style="display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:8px;">
              <i class="fas fa-credit-card" style="color:#9C9B99;font-size:14px;"></i>
              <span style="font-family:'DM Sans',system-ui;font-size:13px;color:#6D6C6A;">Total</span>
            </div>
            <span id="success-total" style="font-family:'DM Sans',system-ui;font-size:15px;font-weight:700;color:#E84D25;">€0.00</span>
          </div>
        </div>
        <!-- 🔥 Cooldown Timer Section (Success Modal) -->
        <div id="success-timer-section" style="display:none;width:100%;background:#FFF0EB;border-radius:16px;padding:16px;margin-top:16px;flex-direction:column;align-items:center;gap:10px;">
          <div style="display:flex;align-items:center;gap:8px;">
            <i class="fas fa-clock" style="color:#E84D25;font-size:20px;"></i>
            <span id="success-timer-time" style="font-family:'DM Sans',system-ui;font-size:22px;font-weight:700;color:#E84D25;letter-spacing:-0.5px;">00:00</span>
          </div>
          <div style="font-family:'DM Sans',system-ui;font-size:12px;color:#E84D25;text-align:center;line-height:1.4;max-width:260px;">
            <?php echo ruiyi_translate(
              'Podrás realizar tu próximo pedido cuando el temporizador llegue a cero.',
              'You can place your next order when the timer reaches zero.',
              '倒计时结束后您可以进行下一次点餐。'
            ); ?>
          </div>
        </div>
        <div style="height:24px;"></div>
        <!-- 返回菜单按钮 -->
        <button id="success-back-btn" style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;height:50px;background:#E84D25;color:white;border:none;border-radius:100px;font-family:'DM Sans',system-ui;font-size:15px;font-weight:500;cursor:pointer;box-shadow:0 4px 16px rgba(232,77,37,0.25);transition:background 0.2s;">
          <i class="fas fa-utensils" style="font-size:14px;"></i>
          <span><?php echo ruiyi_translate('Volver al Menú', 'Back to Menu', '返回菜单'); ?></span>
        </button>
      </div>
    </div>
  </div>

  <!-- Bottom Table Number Bar (Mobile Only) -->
  <div class="bottom-table-bar hidden">
    <div class="container mx-auto flex items-center justify-between gap-3">
      <div class="flex-1 max-w-xs">
        <div class="flex items-center bg-gray-50 rounded-lg border border-gray-300">
          <span class="px-3 py-2 text-gray-500">
            <i class="fas fa-utensils"></i>
          </span>
          <?php if (!empty($table_number)): ?>
          <input type="text" id="bottom-table-number" class="table-number-input flex-1 bg-transparent border-0 py-2 pr-3 focus:outline-none text-sm" value="<?php echo esc_attr(!empty($table_display_name) ? $table_display_name : $table_number); ?>" readonly data-original-table="<?php echo esc_attr($table_number); ?>" data-full-table-id="<?php echo esc_attr($table_full_id); ?>">
          <span class="text-xs text-green-600 pr-3"><?php echo ruiyi_translate('Fijo', 'Fixed', '固定'); ?></span>
          <?php else: ?>
          <input type="text" id="bottom-table-number" class="table-number-input flex-1 bg-transparent border-0 py-2 pr-3 focus:outline-none text-sm" placeholder="<?php echo ruiyi_translate('Mesa #', 'Table #', '桌号 #'); ?>">
          <?php endif; ?>
        </div>
      </div>
      
      <div class="flex items-center gap-2">
        <div class="text-right">
          <div class="text-xs text-gray-500"><?php echo ruiyi_translate('Total', 'Total', '总计'); ?></div>
          <div id="bottom-cart-total" class="font-bold text-primary">€0.00</div>
        </div>
        <button id="bottom-cart-btn" class="bg-primary text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium">
          <i class="fas fa-shopping-cart"></i>
          <span id="bottom-cart-count" class="bg-white text-primary text-xs rounded-full w-5 h-5 flex items-center justify-center">0</span>
        </button>
      </div>
    </div>
  </div>


  <!-- 🔥 Cooldown Timer Bar (Main Page / Mobile) -->
  <div id="main-page-timer-bar" class="cooldown-timer-bar" style="position:fixed;bottom:0;left:0;right:0;z-index:999;height:40px;">
    <i class="fas fa-lock timer-icon"></i>
    <span class="timer-text"><?php echo ruiyi_translate('Próximo pedido disponible en', 'Next order available in', '下次可点餐时间'); ?></span>
    <span class="timer-time">00:00</span>
  </div>

  <!-- 移动端和平板底部导航栏 -->
  <div class="mobile-bottom-nav hidden">
    <div class="mobile-cart-info">
      <div>
        <span class="text-sm font-medium"><?php echo ruiyi_translate('Carrito', 'Cart', '购物车'); ?>:</span>
        <span id="mobile-cart-count" class="bg-primary text-white text-xs rounded-full px-2 py-0.5 ml-1">0</span>
      </div>
      <span id="mobile-cart-total" class="font-bold text-primary">€0.00</span>
    </div>
    
    <!-- 手机端积分短码输入框 -->
    <?php if ($show_points_module): ?>
    <div class="mobile-shortcode-input bg-blue-50 border border-blue-200 rounded-lg p-3 mb-2">
      <div class="flex items-center space-x-2">
        <div class="flex-1">
          <label class="text-xs text-blue-600 font-medium block mb-1">
            <?php echo ruiyi_translate('Código de Puntos (4 dígitos)', 'Points Code (4 digits)', '积分短码(可选)'); ?>
          </label>
          <input 
            type="text" 
            id="mobile-user-shortcode" 
            maxlength="4" 
            pattern="[0-9]{4}"
            placeholder="0000"
            class="user-shortcode-input w-full px-3 py-1.5 text-center text-lg font-mono border border-blue-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white"
          />
        </div>
        <button 
          id="mobile-verify-shortcode-btn"
          class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-1.5 rounded-lg font-medium text-sm transition-colors duration-200 flex items-center"
        >
          <i class="fas fa-check mr-1"></i>
          <?php echo ruiyi_translate('Verificar', 'Verify', '验证'); ?>
        </button>
      </div>
      
      <!-- 移动端用户信息显示区域 -->
      <div id="mobile-user-info-area" class="mt-2 hidden">
        <div class="text-xs text-blue-700">
          <span id="mobile-user-name" class="font-medium"></span>
          <span class="mx-2">•</span>
          <span id="mobile-user-points" class="font-bold"></span> <?php echo ruiyi_translate('puntos', 'points', '积分'); ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
    
    <div class="grid grid-cols-2 gap-2">
      <button id="mobile-cart-btn" class="bg-gray-200 text-gray-800 py-2 rounded-lg font-medium flex items-center justify-center">
        <i class="fas fa-shopping-cart mr-1"></i><?php echo ruiyi_translate('Ver Carrito', 'View Cart', '查看购物车'); ?>
      </button>
      <button id="mobile-send-order-btn" class="bg-primary text-white py-2 rounded-lg font-medium">
        <?php echo ruiyi_translate('Enviar', 'Send', '发送'); ?>
      </button>
      <!--<button style="display:none;" id="mobile-pay-order-btn" class="bg-secondary text-white py-2 rounded-lg font-medium">-->
      <!--  <?php echo ruiyi_translate('Pagar', 'Pay', '支付'); ?>-->
      <!--</button>-->
    </div>
  </div>

  <!-- 悬浮购物车按钮 -->
  <div id="floating-cart-btn" class="floating-cart-button">
    <div class="floating-cart-content">
      <div class="floating-cart-price" id="floating-cart-price">€0.00</div>
      <button class="floating-cart-icon" id="floating-cart-icon-btn">
        <i class="fas fa-shopping-cart"></i>
      </button>
    </div>
  </div>

  <!-- V2: Bottom Cart Bar -->
  <div class="bottom-cart-bar" id="bottom-cart-bar" style="display: none;">
    <div class="bottom-cart-info">
      <span class="bottom-cart-item-count" id="bottom-cart-item-count">0 <?php echo ruiyi_translate('artículos', 'items', '件商品'); ?></span>
      <span class="bottom-cart-total-price" id="bottom-cart-total-price">€0.00</span>
    </div>
    <button class="bottom-cart-cta" id="bottom-cart-cta-btn">
      <i class="fas fa-shopping-cart"></i>
      <span><?php echo ruiyi_translate('Ver pedido', 'View order', '查看订单'); ?></span>
    </button>
  </div>

  <div class="ruiyi-image-reference-note" role="note">
    <?php echo esc_html(ruiyi_translate('Imágenes solo de referencia', 'Images are for reference only', '图片仅供参考')); ?>
  </div>

  <!-- 左下角淡色提示 -->
  <div id="bottom-left-toast" class="bottom-left-toast"></div>

  <script>
  // Variables globales
  const MENU_ONLY_MODE = <?php echo $menu_only_mode ? 'true' : 'false'; ?>; // 🔥 仅菜单浏览模式
  // 🔥 每次进入页面清空购物车（防止残留上次未提交的商品）
  localStorage.removeItem('cart');
  let cart = [];
  let subtotal = 0;
  let total = 0;
  
  // Points Preview System
  let realUserPoints = 0;     // 用户真实积分
  let displayedPoints = 0;    // 当前显示的积分（会被预扣除）
  // 存储AJAX nonce
  const ajaxNonce = '<?php echo esc_js($ajax_nonce); ?>';
  // WordPress AJAX URL
  const ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
  // 当前语言
  let currentLang = '<?php echo esc_js($current_lang); ?>';

  // 🔥 多语言产品名称（始终启用）
  const multilingualNames = true;

  // 🍽️ 自助餐配置
  const buffetConfig = {
      enabled: <?php echo json_encode(get_option('pos_buffet_enabled', '0') === '1'); ?>,
      categories: <?php echo get_option('pos_buffet_categories', '[]'); ?>,
      products: <?php echo get_option('pos_buffet_products', '[]'); ?>,
      excludedZones: <?php echo get_option('pos_buffet_excluded_zone_categories', '[]'); ?>,
      tableExempt: <?php echo json_encode($table_buffet_exempt); ?>
  };

  const categoryTimeRules = <?php echo wp_json_encode($customer_category_time_rules); ?> || {};
  const categoryTimeLabels = {
    available: {
      es: 'Horario disponible',
      en: 'Available time',
      zh: '可点餐时间'
    },
    locked: {
      es: 'Fuera de horario. Disponible',
      en: 'Outside ordering time. Available',
      zh: '当前不可点餐，可点餐时间'
    },
    lockedShort: {
      es: 'Bloqueado',
      en: 'Locked',
      zh: '已锁定'
    }
  };

  // 🍽️ 判断产品是否为自助餐产品
  function isBuffetProduct(productId) {
      if (!buffetConfig.enabled || buffetConfig.tableExempt) return false;
      productId = parseInt(productId);
      if (buffetConfig.products.includes(productId)) return true;
      // 检查产品卡片的分类
      const card = document.querySelector(`.product-card[data-id="${productId}"]`);
      if (card && card.dataset.isBuffet === '1') return true;
      // 检查分类
      if (card) {
          const catId = parseInt(card.dataset.category || 0);
          if (catId && buffetConfig.categories.includes(catId)) return true;
      }
      return false;
  }

  // 🔥 多语言产品名称显示辅助函数
  function getProductDisplayName(product) {
      if (!multilingualNames) return product.name;
      switch (currentLang) {
          case 'zh': return product.name_zh || product.name;
          case 'en': return product.name_en || product.name;
          default: return product.name;
      }
  }
  function getItemDisplayName(item) {
      if (!multilingualNames) return item.name;
      switch (currentLang) {
          case 'zh': return item.name_zh || item.name;
          case 'en': return item.name_en || item.name;
          default: return item.name_es || item.name;
      }
  }

  // 🍽️ 自助餐开台状态检测
  let buffetTableOpened = !buffetConfig.enabled || !!buffetConfig.tableExempt; // 非自助餐/豁免分区默认不阻拦
  let buffetTableCheckInterval = null;

  // 从服务器检查桌位是否已开台
  function checkBuffetTableStatus() {
      if (!buffetConfig.enabled || buffetConfig.tableExempt) { buffetTableOpened = true; return Promise.resolve(true); }
      if (!tableFullId && !tableParam) { buffetTableOpened = true; return Promise.resolve(true); }

      return fetch(ajaxUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
              action: 'get_tables_status',
              nonce: ajaxNonce
          })
      })
      .then(r => r.json())
      .then(data => {
          if (data.success && data.data && data.data.tables_updates) {
              const tableId = tableFullId || ('Mesa ' + tableParam);
              const tableNumeric = tableId.replace(/\D/g, '');
              const found = data.data.tables_updates.find(t => {
                  const tNum = String(t.number).replace(/\D/g, '');
                  return tNum === tableNumeric || String(t.number) === tableId;
              });
              if (found && (found.status === 'ocupada' || found.status === 'occupied')) {
                  buffetTableOpened = true;
                  hideBuffetBlockingOverlay();
                  if (buffetTableCheckInterval) { clearInterval(buffetTableCheckInterval); buffetTableCheckInterval = null; }
                  return true;
              } else {
                  buffetTableOpened = false;
                  showBuffetBlockingOverlay();
                  return false;
              }
          }
          // 请求成功但无数据 → 不阻拦（降级处理）
          buffetTableOpened = true;
          return true;
      })
      .catch(() => {
          // 网络错误 → 不阻拦（降级处理）
          buffetTableOpened = true;
          return true;
      });
  }

  // 显示阻拦遮罩
  function showBuffetBlockingOverlay() {
      let overlay = document.getElementById('buffet-table-blocking-overlay');
      if (overlay) { overlay.style.display = 'flex'; return; }
      overlay = document.createElement('div');
      overlay.id = 'buffet-table-blocking-overlay';
      overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,0.97);z-index:99999;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:32px;text-align:center;font-family:Outfit,sans-serif;';
      overlay.innerHTML = `
          <div style="width:80px;height:80px;background:#FFF5F3;border-radius:50%;display:flex;align-items:center;justify-content:center;margin-bottom:24px;">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#E84D25" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <circle cx="12" cy="12" r="10"></circle>
                  <line x1="12" y1="8" x2="12" y2="12"></line>
                  <line x1="12" y1="16" x2="12.01" y2="16"></line>
              </svg>
          </div>
          <h2 style="font-size:22px;font-weight:700;color:#1A1918;margin:0 0 12px 0;" id="buffet-block-title"></h2>
          <p style="font-size:15px;color:#6D6C6A;max-width:360px;line-height:1.6;margin:0 0 32px 0;" id="buffet-block-msg"></p>
          <button onclick="retryBuffetTableCheck()" id="buffet-block-retry-btn" style="background:#E84D25;color:#fff;border:none;padding:14px 36px;border-radius:12px;font-size:15px;font-weight:600;cursor:pointer;font-family:Outfit,sans-serif;box-shadow:0 4px 12px rgba(232,77,37,0.3);transition:all 0.2s;">
          </button>
      `;
      document.body.appendChild(overlay);
      updateBuffetBlockingOverlayText();
  }

  // 更新遮罩文字（支持语言切换）
  function updateBuffetBlockingOverlayText() {
      const title = document.getElementById('buffet-block-title');
      const msg = document.getElementById('buffet-block-msg');
      const btn = document.getElementById('buffet-block-retry-btn');
      if (title) title.textContent = t('buffetTableNotOpened');
      if (msg) msg.textContent = t('buffetTableNotOpenedMsg');
      if (btn) btn.textContent = t('buffetRetryCheck');
  }

  // 隐藏阻拦遮罩
  function hideBuffetBlockingOverlay() {
      const overlay = document.getElementById('buffet-table-blocking-overlay');
      if (overlay) overlay.style.display = 'none';
  }

  // 重试检查
  function retryBuffetTableCheck() {
      const btn = document.getElementById('buffet-block-retry-btn');
      if (btn) { btn.disabled = true; btn.textContent = t('buffetCheckingTable'); }
      checkBuffetTableStatus().finally(() => {
          if (btn) { btn.disabled = false; btn.textContent = t('buffetRetryCheck'); }
      });
  }

  // Función estandarizada para mostrar notificaciones
  function showToast(message, type = 'info') {
    const colors = {
      success: '#4caf50',
      error: '#f44336',
      warning: '#ff9800',
      info: '#0073aa'
    };
    
    Toastify({
      text: message,
      duration: 800,
      gravity: "bottom",
      position: "left",
      backgroundColor: colors[type] || colors.info,
      stopOnFocus: true,
    }).showToast();
  }

  // Points Display Management Functions
  function updatePointsDisplay(points, animate = true) {
    const pointsValueElement = document.getElementById('displayed-points');
    const userPointsDisplay = document.getElementById('user-points-display');
    const cartUserPoints = document.getElementById('user-points'); // 内联购物车中的积分显示
    const sideUserPoints = document.getElementById('side-user-points'); // 侧边栏购物车中的积分显示
    const mobileUserPoints = document.getElementById('mobile-user-points'); // 移动端购物车中的积分显示
    
    if (pointsValueElement) {
      if (animate) {
        pointsValueElement.classList.add('updating');
        setTimeout(() => {
          pointsValueElement.textContent = points;
          pointsValueElement.classList.remove('updating');
        }, 150);
      } else {
        pointsValueElement.textContent = points;
      }
    }
    
    // 同步更新所有购物车中的积分显示
    if (cartUserPoints) {
      cartUserPoints.textContent = points;
    }
    if (sideUserPoints) {
      sideUserPoints.textContent = points;
    }
    if (mobileUserPoints) {
      mobileUserPoints.textContent = points;
    }
    
    // 检查积分是否不足并更新样式
    if (userPointsDisplay) {
      if (points < 0) {
        userPointsDisplay.classList.add('insufficient');
        setTimeout(() => {
          userPointsDisplay.classList.remove('insufficient');
        }, 500);
      }
    }
    
    displayedPoints = points;
  }
  
  function initializePointsDisplay(userPoints) {
    realUserPoints = userPoints;
    
    const userPointsDisplay = document.getElementById('user-points-display');
    if (userPointsDisplay) {
      userPointsDisplay.classList.remove('hidden');
    }
    
    // 计算购物车中已有的积分产品消耗，并相应调整显示
    syncPointsWithCart();
  }
  
  function resetPointsDisplay() {
    updatePointsDisplay(realUserPoints, true);
  }
  
  function previewPointsDeduction(pointsCost) {
    const newPoints = displayedPoints - pointsCost;
    updatePointsDisplay(newPoints, true);
    return newPoints;
  }
  
  function previewPointsRefund(pointsCost) {
    const newPoints = displayedPoints + pointsCost;
    updatePointsDisplay(newPoints, true);
    return newPoints;
  }
  
  function calculateCartPointsCost() {
    return cart.reduce((total, item) => {
      if (item.is_points_product && item.points_price) {
        return total + (item.points_price * item.quantity);
      }
      return total;
    }, 0);
  }
  
  function syncPointsWithCart() {
    if (realUserPoints > 0) {
      const cartPointsCost = calculateCartPointsCost();
      const newDisplayedPoints = realUserPoints - cartPointsCost;
      updatePointsDisplay(newDisplayedPoints, false);
    }
  }

  // 多语言翻译对象
  const translations = {
    cartEmpty: {
      es: 'Carrito vacío',
      en: 'Cart Empty',
      zh: '购物车空'
    },
    addProducts: {
      es: 'Agrega productos antes de enviar tu pedido',
      en: 'Add products before placing your order',
      zh: '请先添加产品再提交订单'
    },
    sendOrder: {
      es: '¿Enviar pedido?',
      en: 'Send order?',
      zh: '发送订单？'
    },
    orderSent: {
      es: 'Tu pedido será enviado a la cocina',
      en: 'Your order will be sent to the kitchen',
      zh: '您的订单将被发送到厨房'
    },
    yesSend: {
      es: 'Sí, enviar',
      en: 'Yes, send',
      zh: '是，发送'
    },
    cancel: {
      es: 'Cancelar',
      en: 'Cancel',
      zh: '取消'
    },
    noProducts: {
      es: 'No hay productos para pagar',
      en: 'No products to pay for',
      zh: '没有要支付的产品'
    },
    processPayment: {
      es: 'Procesar Pago',
      en: 'Process Payment',
      zh: '处理付款'
    },
    selectPayment: {
      es: 'Seleccione método de pago:',
      en: 'Select payment method:',
      zh: '选择支付方式：'
    },
    cash: {
      es: 'Efectivo',
      en: 'Cash',
      zh: '现金'
    },
    card: {
      es: 'Tarjeta',
      en: 'Card',
      zh: '银行卡'
    },
    tipOptional: {
      es: 'Propina (opcional):',
      en: 'Tip (optional):',
      zh: '小费（可选）：'
    },
    confirmPayment: {
      es: 'Confirmar Pago',
      en: 'Confirm Payment',
      zh: '确认支付'
    },
    orderNotes: {
      es: 'Notas del pedido',
      en: 'Order notes',
      zh: '订单备注'
    },
    optional: {
      es: 'opcional',
      en: 'optional',
      zh: '可选'
    },
    addRemarksPlaceholder: {
      es: 'Ej: Sin picante, más salsa...',
      en: 'e.g. No spicy, extra sauce...',
      zh: '例如：不要辣，多加酱汁...'
    },
    error: {
      es: 'Error',
      en: 'Error',
      zh: '错误'
    },
    selectPayMethod: {
      es: 'Debe seleccionar un método de pago',
      en: 'You must select a payment method',
      zh: '您必须选择一种支付方式'
    },
    processingOrder: {
      es: 'Procesando pedido...',
      en: 'Processing order...',
      zh: '正在处理订单...'
    },
    pleaseWait: {
      es: 'Por favor espere mientras procesamos su pedido',
      en: 'Please wait while we process your order',
      zh: '请稍候，我们正在处理您的订单'
    },
    orderCreated: {
      es: '¡Pedido Enviado!',
      en: 'Order Sent!',
      zh: '订单发送成功！'
    },
    orderProcessed: {
      es: 'Su pedido #{{number}} ha sido enviado a la cocina. Por favor espere mientras se prepara.',
      en: 'Your order #{{number}} has been sent to the kitchen. Please wait while it is being prepared.',
      zh: '您的订单 #{{number}} 已成功发送至厨房，请耐心等待。'
    },
    connectionError: {
      es: 'Ha ocurrido un error en la conexión',
      en: 'A connection error has occurred',
      zh: '连接出错'
    },
    noTableSelected: {
      es: 'No hay mesa seleccionada',
      en: 'No table selected',
      zh: '未选择餐桌'
    },
    addedToCart: {
      es: 'añadido al carrito',
      en: 'added to cart',
      zh: '已添加到购物车'
    },
    confirmReleaseTable: {
      es: '¿Desea liberar la mesa',
      en: 'Do you want to release table',
      zh: '您要释放餐桌'
    },
    orderWillBeCancelled: {
      es: 'La orden será cancelada',
      en: 'The order will be cancelled',
      zh: '订单将被取消'
    },
    releaseTable: {
      es: 'Liberar Mesa',
      en: 'Release Table',
      zh: '释放餐桌'
    },
    yesRelease: {
      es: 'Sí, liberar',
      en: 'Yes, release',
      zh: '是，释放'
    },
    // 积分产品相关翻译
    pointsProducts: {
      es: 'Productos con Puntos',
      en: 'Points Products',
      zh: '积分产品'
    },
    enterShortcode: {
      es: 'Ingresa tu código de 4 dígitos',
      en: 'Enter your 4-digit code',
      zh: '请输入您的4位短码'
    },
    verify: {
      es: 'Verificar',
      en: 'Verify',
      zh: '验证'
    },
    pointsBalance: {
      es: 'Saldo de Puntos',
      en: 'Points Balance',
      zh: '积分余额'
    },
    redeem: {
      es: 'Canjear',
      en: 'Redeem',
      zh: '兑换'
    },
    points: {
      es: 'puntos',
      en: 'points',
      zh: '积分'
    },
    shortcodeError: {
      es: 'Por favor ingresa un código válido de 4 dígitos',
      en: 'Please enter a valid 4-digit code',
      zh: '请输入有效的4位数字短码'
    },
    redeemSuccess: {
      es: 'Producto canjeado exitosamente',
      en: 'Product redeemed successfully',
      zh: '产品兑换成功'
    },
    insufficientPoints: {
      es: 'Puntos insuficientes',
      en: 'Insufficient points',
      zh: '积分不足'
    },
    loadingProducts: {
      es: 'Cargando productos...',
      en: 'Loading products...',
      zh: '加载产品中...'
    },
    noPointsProducts: {
      es: 'No hay productos con puntos disponibles',
      en: 'No points products available',
      zh: '没有可用的积分产品'
    },
    shortcode_required: {
      es: 'Código requerido',
      en: 'Code required',
      zh: '短码必填'
    },
    verifying: {
      es: 'Verificando...',
      en: 'Verifying...',
      zh: '验证中...'
    },
    shortcode_verified: {
      es: 'Código verificado exitosamente',
      en: 'Code verified successfully',
      zh: '短码验证成功'
    },
    invalid_shortcode: {
      es: 'Código inválido',
      en: 'Invalid code',
      zh: '无效短码'
    },
    connection_error: {
      es: 'Error de conexión',
      en: 'Connection error',
      zh: '连接错误'
    },
    loading: {
      es: 'Cargando...',
      en: 'Loading...',
      zh: '加载中...'
    },
    redeeming: {
      es: 'Canjeando...',
      en: 'Redeeming...',
      zh: '兑换中...'
    },
    confirm_redeem: {
      es: 'Confirmar Canje',
      en: 'Confirm Redemption',
      zh: '确认兑换'
    },
    redeem_confirm_text: {
      es: '¿Canjear {name} por {points} puntos?',
      en: 'Redeem {name} for {points} points?',
      zh: '用{points}积分兑换{name}？'
    },
    confirm: {
      es: 'Confirmar',
      en: 'Confirm',
      zh: '确认'
    },
    yes_redeem: {
      es: 'Sí, canjear',
      en: 'Yes, redeem',
      zh: '是，兑换'
    },
    processing: {
      es: 'Procesando',
      en: 'Processing',
      zh: '处理中'
    },
    please_wait: {
      es: 'Por favor espere...',
      en: 'Please wait...',
      zh: '请稍候...'
    },
    add_to_cart: {
      es: 'Agregar al Carrito',
      en: 'Add to Cart',
      zh: '添加到购物车'
    },
    points_product_added: {
      es: 'Producto con puntos agregado al carrito',
      en: 'Points product added to cart',
      zh: '积分产品已添加到购物车'
    },
    // 新增翻译项
    verifying_text: {
      es: 'Verificando...',
      en: 'Verifying...',
      zh: '验证中...'
    },
    unlocked: {
      es: 'Desbloqueado',
      en: 'Unlocked',
      zh: '已解锁'
    },
    unlock: {
      es: 'Desbloquear',
      en: 'Unlock',
      zh: '解锁'
    },
    points_locked: {
      es: 'Productos de puntos bloqueados',
      en: 'Points products locked',
      zh: '积分产品已锁定'
    },
    insufficient_points_text: {
      es: 'Puntos insuficientes',
      en: 'Insufficient points',
      zh: '积分不足'
    },
    add_btn: {
      es: 'Agregar',
      en: 'Add',
      zh: '添加'
    },
    verification_error: {
      es: 'Error de verificación',
      en: 'Verification error',
      zh: '验证错误'
    },
    no_products_found: {
      es: 'No se encontraron productos que contengan',
      en: 'No products found containing',
      zh: '未找到包含'
    },
    products_found: {
      es: 'productos encontrados que contienen',
      en: 'products found containing',
      zh: '个包含'
    },
    products_found_suffix: {
      es: '',
      en: '',
      zh: '的产品'
    },
    product_added_success: {
      es: 'añadido al carrito exitosamente',
      en: 'added to cart successfully',
      zh: '已成功添加到购物车'
    },
    minimumOrderTitle: {
      es: 'Pedido mínimo',
      en: 'Minimum order',
      zh: '最低点餐数量'
    },
    minimumOrderMessage: {
      es: 'Debes agregar al menos {min} productos para enviar el pedido.',
      en: 'You must add at least {min} products to place your order.',
      zh: '您至少需要添加 {min} 件商品才能提交订单。'
    },
    maximumOrderTitle: {
      es: 'Límite de pedido',
      en: 'Order limit',
      zh: '最高点餐数量'
    },
    maximumOrderMessage: {
      es: 'Solo puedes agregar un máximo de {max} productos por pedido.',
      en: 'You can only add a maximum of {max} products per order.',
      zh: '每次点餐最多只能添加 {max} 件商品。'
    },
    maxItemsReached: {
      es: 'Máximo {max} productos por pedido',
      en: 'Maximum {max} products per order',
      zh: '每次最多点 {max} 件商品'
    }
  };

  // 获取翻译
  function t(key, placeholders = {}) {
    if (!translations[key]) {
      return key;
    }
    
    let text = translations[key][currentLang] || translations[key]['es'];
    
    // 替换占位符
    for (const [placeholder, value] of Object.entries(placeholders)) {
      text = text.replace(`{{${placeholder}}}`, value);
    }
    
    return text;
  }

  // 添加更多翻译
  function addMoreTranslations() {
    // 添加额外的翻译项
    translations.subtotal = {
      es: 'Subtotal',
      en: 'Subtotal',
      zh: '小计'
    };
    
    translations.tip = {
      es: 'Propina',
      en: 'Tip',
      zh: '小费'
    };
    
    translations.total = {
      es: 'Total',
      en: 'Total',
      zh: '总计'
    };
    
    translations.ahorro = {
      es: 'Ahorro',
      en: 'Save',
      zh: '节省'
    };
    
    translations.vendidos = {
      es: 'vendidos',
      en: 'sold',
      zh: '已售'
    };

    // 🍽️ 自助餐开台检测翻译
    translations.buffetTableNotOpened = {
      es: 'Mesa no abierta',
      en: 'Table not opened',
      zh: '桌位未开台'
    };
    translations.buffetTableNotOpenedMsg = {
      es: 'Esta mesa aún no ha sido abierta por el cajero. Por favor, solicite al personal que abra la mesa antes de realizar su pedido.',
      en: 'This table has not been opened by the cashier yet. Please ask staff to open the table before placing your order.',
      zh: '该桌位尚未由收银员开台，请联系服务人员开台后再进行点餐。'
    };
    translations.buffetCheckingTable = {
      es: 'Verificando mesa...',
      en: 'Checking table...',
      zh: '正在检查桌位...'
    };
    translations.buffetRetryCheck = {
      es: 'Reintentar',
      en: 'Retry',
      zh: '重新检查'
    };
  }

  // DOM Elements
  const productsContainer = document.getElementById('products-container');
  const sideCartItems = document.getElementById('cart-items');
  const inlineCartItems = document.getElementById('inline-cart-items');
  const sideEmptyCart = document.getElementById('empty-cart');
  const inlineEmptyCart = document.getElementById('inline-empty-cart');
  const sideCartSubtotal = document.getElementById('cart-subtotal');
  const inlineCartSubtotal = document.getElementById('inline-cart-subtotal');
  const sideCartTotal = document.getElementById('cart-total');
  const inlineCartTotal = document.getElementById('inline-cart-total');
  const cartCount = document.getElementById('cart-count');
  const mobileCartCount = document.getElementById('mobile-cart-count');
  const mobileCartTotal = document.getElementById('mobile-cart-total');
  const sideSendOrderBtn = document.getElementById('send-order-btn');
  const inlineSendOrderBtn = document.getElementById('inline-send-order-btn');
  const mobileSendOrderBtn = document.getElementById('mobile-send-order-btn');
  // const sidePayOrderBtn = document.getElementById('pay-order-btn');
  // const inlinePayOrderBtn = document.getElementById('inline-pay-order-btn');
  // const mobilePayOrderBtn = document.getElementById('mobile-pay-order-btn');
  const mobileCartBtn = document.getElementById('mobile-cart-btn');
  const categoryBtns = document.querySelectorAll('.category-btn');
  
  // Floating Category Button Elements - Initialize after DOM is ready
  let floatingCategoryBtn, categoryPopupOverlay, categoryPopup, categoryPopupClose, categoryPopupItems;
  // const themeToggle = document.getElementById('theme-toggle'); // Removido
  const languageMenuButton = document.getElementById('language-menu-button');
  const languageMenu = document.getElementById('language-menu');
  const languageOptions = document.querySelectorAll('.language-option');
  const tableNumberInputs = document.querySelectorAll('.table-number-input');
  const bottomCartBtn = document.getElementById('bottom-cart-btn');
  const bottomCartCount = document.getElementById('bottom-cart-count');
  const bottomCartTotal = document.getElementById('bottom-cart-total');
  const cartButton = document.getElementById('cart-button');
  const sideCart = document.getElementById('side-cart');
  const cartOverlay = document.getElementById('cart-overlay');
  const closeCart = document.getElementById('close-cart');

  // 从URL获取桌号
  const urlParams = new URLSearchParams(window.location.search);
  const tableParam = urlParams.get('table');

  // 🔥🔥🔥 【2026-01-19 新增】完整桌位ID（包含区域前缀，如 COMEDOR1, DOMICILIO2）
  // 由 PHP 端根据桌位分类映射计算得出
  const tableFullId = '<?php echo esc_js($table_full_id); ?>';
  const tableDisplayName = '<?php echo esc_js($table_display_name); ?>';
  const tableGlobalNumber = '<?php echo esc_js($table_global_number); ?>';
  const tableZoneCategoryId = <?php echo intval($table_zone_category_id); ?>;
  const tableUid = tableGlobalNumber ? `table:${tableGlobalNumber}` : '';
  const minOrderQty = <?php echo intval(get_option('pos_min_order_qty', '0')); ?>;
  const maxOrderQty = <?php echo intval(get_option('pos_max_order_qty', '0')); ?>;
  const orderCooldownMinutes = <?php echo intval(get_option('pos_order_cooldown_minutes', '0')); ?>;

  function getCartTotalQuantity() {
    return cart.reduce((sum, item) => sum + (parseInt(item.quantity, 10) || 0), 0);
  }

  function getRemainingOrderQuantity() {
    if (maxOrderQty <= 0) return Infinity;
    return Math.max(0, maxOrderQty - getCartTotalQuantity());
  }

  function isOrderLimitReached() {
    return maxOrderQty > 0 && getCartTotalQuantity() >= maxOrderQty;
  }

  function showMaxOrderLimitToast() {
    Toastify({
      text: (t('maxItemsReached') || 'Máximo {max} productos por pedido').replace('{max}', maxOrderQty),
      duration: 2000,
      gravity: 'top',
      position: 'center',
      style: { background: '#ef4444' }
    }).showToast();
  }

  function getCategoryTimeRule(categoryId) {
    const key = String(categoryId || '');
    const rule = categoryTimeRules[key];
    if (!rule || !rule.enabled || !rule.start || !rule.end) return null;
    return rule;
  }

  function timeStringToMinutes(timeValue) {
    if (!/^\d{2}:\d{2}$/.test(timeValue || '')) return null;
    const parts = timeValue.split(':').map(Number);
    if (parts[0] > 23 || parts[1] > 59) return null;
    return parts[0] * 60 + parts[1];
  }

  function isCategoryRuleOpen(rule) {
    if (!rule) return true;
    const start = timeStringToMinutes(rule.start);
    const end = timeStringToMinutes(rule.end);
    if (start === null || end === null || start === end) return true;
    const now = new Date();
    const current = now.getHours() * 60 + now.getMinutes();
    if (start < end) {
      return current >= start && current < end;
    }
    return current >= start || current < end;
  }

  function isCategoryAvailable(categoryId) {
    return isCategoryRuleOpen(getCategoryTimeRule(categoryId));
  }

  function categoryTimeText(type, rule) {
    const labels = categoryTimeLabels[type] || categoryTimeLabels.available;
    const label = labels[currentLang] || labels.es;
    return `${label}: ${rule.start} - ${rule.end}`;
  }

  function showCategoryTimeLockedToast(categoryId) {
    const rule = getCategoryTimeRule(categoryId);
    if (!rule) return;
    Toastify({
      text: categoryTimeText('locked', rule),
      duration: 2600,
      gravity: 'top',
      position: 'center',
      style: { background: '#ef4444' }
    }).showToast();
  }

  function isProductCardCategoryLocked(card) {
    if (!card) return false;
    const categoryId = card.dataset.category || '';
    return !!getCategoryTimeRule(categoryId) && !isCategoryAvailable(categoryId);
  }

  function setCategoryTimeLockedButton(button, locked, categoryId) {
    if (!button) return;
    if (locked) {
      if (!button.dataset.categoryUnlockedHtml) {
        button.dataset.categoryUnlockedHtml = button.dataset.unlockedHtml || button.innerHTML;
      }
      button.disabled = true;
      button.setAttribute('aria-disabled', 'true');
      button.classList.add('category-time-locked-btn');
      button.innerHTML = '<i class="fas fa-lock"></i>';
      button.dataset.lockedCategoryId = String(categoryId || '');
    } else {
      button.classList.remove('category-time-locked-btn');
      delete button.dataset.lockedCategoryId;
      if (!button.classList.contains('limit-locked')) {
        button.disabled = false;
        button.removeAttribute('aria-disabled');
        if (button.dataset.categoryUnlockedHtml) {
          button.innerHTML = button.dataset.categoryUnlockedHtml;
        }
      }
    }
  }

  function updateCategoryTimeNotice(categoryId) {
    const notice = document.getElementById('category-time-notice');
    if (!notice) return;
    const rule = getCategoryTimeRule(categoryId);
    if (!rule) {
      notice.classList.add('hidden');
      notice.classList.remove('locked');
      notice.innerHTML = '';
      return;
    }
    const open = isCategoryRuleOpen(rule);
    notice.classList.remove('hidden');
    notice.classList.toggle('locked', !open);
    notice.innerHTML = `<i class="fas ${open ? 'fa-clock' : 'fa-lock'}"></i><span>${categoryTimeText(open ? 'available' : 'locked', rule)}</span>`;
  }

  function applyCategoryTimeLocks() {
    document.querySelectorAll('#products-container .product-card').forEach(card => {
      const categoryId = card.dataset.category || '';
      const rule = getCategoryTimeRule(categoryId);
      const locked = !!rule && !isCategoryRuleOpen(rule);
      const shortLabels = categoryTimeLabels.lockedShort;
      card.classList.toggle('category-time-locked', locked);
      card.dataset.lockLabel = locked ? (shortLabels[currentLang] || shortLabels.es) : '';
      card.title = locked ? categoryTimeText('locked', rule) : '';
      setCategoryTimeLockedButton(card.querySelector('.add-to-cart'), locked, categoryId);
      const plusBtn = card.querySelector('.qty-plus');
      if (plusBtn) {
        plusBtn.disabled = locked || plusBtn.classList.contains('limit-locked');
        plusBtn.setAttribute('aria-disabled', plusBtn.disabled ? 'true' : 'false');
        plusBtn.classList.toggle('category-time-locked-btn', locked);
      }
    });
  }

  function getProductCategoryId(productId) {
    const safeId = (window.CSS && CSS.escape) ? CSS.escape(String(productId)) : String(productId).replace(/"/g, '\\"');
    const card = document.querySelector(`#products-container .product-card[data-id="${safeId}"]`);
    return card ? (card.dataset.category || '') : '';
  }

  function getLockedCartItems() {
    return cart.filter(item => {
      if (item.is_points_product) return false;
      const categoryId = item.category_id || getProductCategoryId(item.id);
      return !!getCategoryTimeRule(categoryId) && !isCategoryAvailable(categoryId);
    });
  }

  function setLimitLockedButton(button, locked) {
    if (!button) return;
    if (locked) {
      if (!button.dataset.unlockedHtml) {
        button.dataset.unlockedHtml = button.innerHTML;
      }
      button.disabled = true;
      button.setAttribute('aria-disabled', 'true');
      button.classList.add('limit-locked');
      button.innerHTML = '<i class="fas fa-lock"></i>';
    } else {
      button.disabled = false;
      button.removeAttribute('aria-disabled');
      button.classList.remove('limit-locked');
      if (button.dataset.unlockedHtml) {
        button.innerHTML = button.dataset.unlockedHtml;
      }
    }
  }

  function updateOrderLimitUI() {
    const limitReached = isOrderLimitReached();
    document.body.classList.toggle('order-limit-reached', limitReached);

    document.querySelectorAll('.product-card, .points-product-card').forEach(card => {
      const productId = card.dataset.id;
      const cartItem = cart.find(item => item.id === productId);
      const productQty = cartItem ? (parseInt(cartItem.quantity, 10) || 0) : 0;

      const addBtn = card.querySelector('.add-to-cart');
      setLimitLockedButton(addBtn, limitReached && productQty <= 0);

      const plusBtn = card.querySelector('.qty-plus');
      if (plusBtn) {
        plusBtn.disabled = limitReached;
        plusBtn.classList.toggle('limit-locked', limitReached);
        plusBtn.setAttribute('aria-disabled', limitReached ? 'true' : 'false');
      }
    });

    document.querySelectorAll('.increase-btn').forEach(btn => {
      btn.disabled = limitReached;
      btn.classList.toggle('limit-locked', limitReached);
      btn.setAttribute('aria-disabled', limitReached ? 'true' : 'false');
    });

    const detailPlus = document.getElementById('detailQtyPlus');
    if (detailPlus) {
      const detailLocked = maxOrderQty > 0 && getCartTotalQuantity() + detailQty >= maxOrderQty;
      detailPlus.disabled = detailLocked;
      detailPlus.classList.toggle('limit-locked', detailLocked);
      detailPlus.setAttribute('aria-disabled', detailLocked ? 'true' : 'false');
    }

    applyCategoryTimeLocks();
  }

  // 积分产品相关元素
  // 主要(内联)短码输入元素
  const userShortcodeInput = document.getElementById('user-shortcode');
  const verifyShortcodeBtn = document.getElementById('verify-shortcode-btn');
  const userInfoArea = document.getElementById('user-info-area');
  const userNameDisplay = document.getElementById('user-name');
  const userPointsDisplay = document.getElementById('user-points');

  // 侧边栏短码输入元素
  const sideUserShortcodeInput = document.getElementById('side-user-shortcode');
  const sideVerifyShortcodeBtn = document.getElementById('side-verify-shortcode-btn');
  const sideUserInfoArea = document.getElementById('side-user-info-area');
  const sideUserNameDisplay = document.getElementById('side-user-name');
  const sideUserPointsDisplay = document.getElementById('side-user-points');

  // 手机端短码输入元素
  const mobileUserShortcodeInput = document.getElementById('mobile-user-shortcode');
  const mobileVerifyShortcodeBtn = document.getElementById('mobile-verify-shortcode-btn');
  const mobileUserInfoArea = document.getElementById('mobile-user-info-area');
  const mobileUserNameDisplay = document.getElementById('mobile-user-name');
  const mobileUserPointsDisplay = document.getElementById('mobile-user-points');
  const pointsProductsGrid = document.getElementById('points-products-grid');
  const pointsProductsContainer = document.getElementById('points-products-container');
  const pointsLoading = document.getElementById('points-loading');
  const insufficientPointsNotice = document.getElementById('insufficient-points-notice');
  const noPointsProductsNotice = document.getElementById('no-points-products-notice');

  // 积分产品相关变量
  let currentUser = null;
  let pointsProducts = [];

  // 同步桌号输入
  function syncTableNumbers() {
    // 监听桌号输入框变化，并同步值
    tableNumberInputs.forEach(input => {
      input.addEventListener('input', function() {
        const value = this.value;
        tableNumberInputs.forEach(inp => {
          if (inp !== this) {
            inp.value = value;
          }
        });
      });
    });
  }

  // Inicializar carrito
  function initCart() {
    updateCartUI();
    setupEventListeners();
    checkDarkMode();
    syncTableNumbers(); // 添加同步桌号功能
    
    // 初始化产品数量显示
    updateAllProductQuantityDisplays();
    
    // 同步积分显示（如果用户已登录）
    if (currentUser && realUserPoints > 0) {
      syncPointsWithCart();
    }
    
    // 如果URL中有桌号参数，锁定桌号输入框
    if (tableParam) {
      tableNumberInputs.forEach(input => {
        input.value = tableParam;
        input.readOnly = true;
        input.classList.add('bg-gray-50');
      });
    }
  }

  // Configurar controles de cantidad para productos
  function setupProductQuantityControls() {
    // Botones "Añadir" (primera vez)
    document.querySelectorAll('[class*="add-first-btn-"]').forEach(btn => {
      btn.addEventListener('click', function(e) {
        e.stopPropagation();
        const productId = this.dataset.productId;
        const productName = this.dataset.productName;
        const productPrice = parseFloat(this.dataset.productPrice);
        
        // Añadir al carrito
        const added = addToCart(productId, productName, productPrice);
        if (!added) return;
        
        // Mostrar controles de cantidad y ocultar botón "Añadir"
        const quantityControls = document.querySelector(`.quantity-controls-${productId}`);
        const addFirstBtn = document.querySelector(`.add-first-btn-${productId}`);
        
        if (quantityControls && addFirstBtn) {
          quantityControls.classList.remove('hidden');
          addFirstBtn.classList.add('hidden');
          updateProductQuantityDisplay(productId);
        }
      });
    });
    
    // Botones de incremento (+)
    document.querySelectorAll('.increase-btn').forEach(btn => {
      btn.addEventListener('click', function(e) {
        e.stopPropagation();
        const productId = this.dataset.productId;

        if (maxOrderQty > 0 && getRemainingOrderQuantity() <= 0) {
          showMaxOrderLimitToast();
          updateOrderLimitUI();
          return;
        }
        
        // Buscar el producto en el carrito
        const cartItem = cart.find(item => item.id === productId);
        if (cartItem) {
          cartItem.quantity++;
          updateProductQuantityDisplay(productId);
          
          // Guardar carrito y actualizar UI
          saveCart();
          renderCart();
          updateOrderLimitUI();
        }
      });
    });
    
    // Botones de decremento (-)
    document.querySelectorAll('.decrease-btn').forEach(btn => {
      btn.addEventListener('click', function(e) {
        e.stopPropagation();
        const productId = this.dataset.productId;
        
        // Buscar el ítem en el carrito y reducir cantidad
        const cartItemIndex = cart.findIndex(item => item.id === productId);
        if (cartItemIndex !== -1) {
          if (cart[cartItemIndex].quantity > 1) {
            cart[cartItemIndex].quantity--;
            updateProductQuantityDisplay(productId);
          } else {
            // Si cantidad es 1, remover del carrito y mostrar botón "Añadir"
            cart.splice(cartItemIndex, 1);
            const quantityControls = document.querySelector(`.quantity-controls-${productId}`);
            const addFirstBtn = document.querySelector(`.add-first-btn-${productId}`);
            
            if (quantityControls && addFirstBtn) {
              quantityControls.classList.add('hidden');
              addFirstBtn.classList.remove('hidden');
            }
          }
          
          // Guardar carrito y actualizar UI
          saveCart();
          renderCart();
        }
      });
    });
  }

  // Actualizar la visualización de cantidad en el producto
  function updateProductQuantityDisplay(productId) {
    const cartItem = cart.find(item => item.id === productId);
    const quantity = cartItem ? cartItem.quantity : 0;
    const quantityDisplay = document.querySelector(`.quantity-controls-${productId} .quantity-display`);

    if (quantityDisplay) {
      quantityDisplay.textContent = quantity;
    }

    // 更新卡片内嵌数量控制器
    const qtyValueEl = document.getElementById(`qty-value-${productId}`);
    if (qtyValueEl) {
      qtyValueEl.textContent = quantity > 0 ? quantity : 1;
    }
    if (quantity > 0) {
      showQtyControls(productId);
    } else {
      hideQtyControls(productId);
    }
  }


  // ============================================================
  // 🌟 积分产品功能
  // ============================================================

  /**
   * 配置积分产品事件监听器
   */
  function setupPointsProductEvents() {
    // 初始化主要(内联)短码输入
    if (userShortcodeInput && verifyShortcodeBtn) {
      setupShortcodeInput(userShortcodeInput, verifyShortcodeBtn, 'main');
    }
    
    // 初始化侧边栏短码输入
    if (sideUserShortcodeInput && sideVerifyShortcodeBtn) {
      setupShortcodeInput(sideUserShortcodeInput, sideVerifyShortcodeBtn, 'side');
    }
    
    // 初始化手机端短码输入
    if (mobileUserShortcodeInput && mobileVerifyShortcodeBtn) {
      setupShortcodeInput(mobileUserShortcodeInput, mobileVerifyShortcodeBtn, 'mobile');
    }
    
    // 初始化积分解锁弹窗
    setupPointsUnlockModal();
  }

  function setupShortcodeInput(inputElement, buttonElement, type) {
    // 短码输入框事件
    inputElement.addEventListener('input', function(e) {
      // 只允许数字输入
      this.value = this.value.replace(/[^0-9]/g, '');
      
      // 限制4位数字
      if (this.value.length > 4) {
        this.value = this.value.slice(0, 4);
      }
      
      // 同步到其他输入框
      const currentValue = this.value;
      if (type === 'main') {
        if (sideUserShortcodeInput) sideUserShortcodeInput.value = currentValue;
        if (mobileUserShortcodeInput) mobileUserShortcodeInput.value = currentValue;
      } else if (type === 'side') {
        if (userShortcodeInput) userShortcodeInput.value = currentValue;
        if (mobileUserShortcodeInput) mobileUserShortcodeInput.value = currentValue;
      } else if (type === 'mobile') {
        if (userShortcodeInput) userShortcodeInput.value = currentValue;
        if (sideUserShortcodeInput) sideUserShortcodeInput.value = currentValue;
      }
      
      // 重置用户信息显示
      if (currentUser) {
        resetPointsProductsDisplay();
      }
    });
    
    // 按回车键验证
    inputElement.addEventListener('keypress', function(e) {
      if (e.key === 'Enter' && this.value.length === 4) {
        verifyUserShortcode();
      }
    });
    
    // 验证按钮点击事件
    buttonElement.addEventListener('click', verifyUserShortcode);
  }

  /**
   * 验证用户短码
   */
  function verifyUserShortcode() {
    // 获取任一输入框的值（它们应该同步）
    const shortcode = userShortcodeInput?.value?.trim() || sideUserShortcodeInput?.value?.trim() || mobileUserShortcodeInput?.value?.trim() || '';
    
    if (!shortcode || shortcode.length !== 4) {
      showToast(t('shortcode_required'), 'warning');
      return;
    }
    
    // 禁用所有验证按钮并显示加载状态
    if (verifyShortcodeBtn) {
      verifyShortcodeBtn.disabled = true;
      verifyShortcodeBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>' + t('verifying');
    }
    if (sideVerifyShortcodeBtn) {
      sideVerifyShortcodeBtn.disabled = true;
      sideVerifyShortcodeBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    }
    
    // AJAX请求验证短码
    const formData = new URLSearchParams();
    formData.append('action', 'ruiyi_verify_shortcode');
    formData.append('shortcode', shortcode);
    formData.append('nonce', ajaxNonce);
    
    fetch(ajaxUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        currentUser = data.data;
        displayUserInfo(currentUser);
        // 如果积分产品模块存在，则加载或显示积分产品
        const pointsSection = document.getElementById('points-products-section');
        if (pointsSection) {
          if (pointsProducts.length > 0) {
            displayPointsProducts(pointsProducts, false);
          } else {
            loadPointsProducts();
          }
        }
        
        // 添加成功动画到所有验证按钮
        if (verifyShortcodeBtn) {
          verifyShortcodeBtn.classList.add('shortcode-verified');
          setTimeout(() => {
            verifyShortcodeBtn.classList.remove('shortcode-verified');
          }, 600);
        }
        if (sideVerifyShortcodeBtn) {
          sideVerifyShortcodeBtn.classList.add('shortcode-verified');
          setTimeout(() => {
            sideVerifyShortcodeBtn.classList.remove('shortcode-verified');
          }, 600);
        }
        if (mobileVerifyShortcodeBtn) {
          mobileVerifyShortcodeBtn.classList.add('shortcode-verified');
          setTimeout(() => {
            mobileVerifyShortcodeBtn.classList.remove('shortcode-verified');
          }, 600);
        }
        
        showToast(t('shortcode_verified'), 'success');
      } else {
        showToast(data.data.message || t('invalid_shortcode'), 'error');
        resetPointsProductsDisplay();
      }
    })
    .catch(error => {
      console.error('Error:', error);
      showToast(t('connection_error'), 'error');
      resetPointsProductsDisplay();
    })
    .finally(() => {
      // 重置所有按钮状态
      if (verifyShortcodeBtn) {
        verifyShortcodeBtn.disabled = false;
        verifyShortcodeBtn.innerHTML = '<i class="fas fa-search mr-2"></i>' + t('verify');
      }
      if (sideVerifyShortcodeBtn) {
        sideVerifyShortcodeBtn.disabled = false;
        sideVerifyShortcodeBtn.innerHTML = '<i class="fas fa-search"></i>';
      }
      if (mobileVerifyShortcodeBtn) {
        mobileVerifyShortcodeBtn.disabled = false;
        mobileVerifyShortcodeBtn.innerHTML = '<i class="fas fa-check mr-1"></i>' + t('verify');
      }
    });
  }

  /**
   * 显示用户信息
   */
  function displayUserInfo(user) {
    // 显示新的仅查看模式的积分信息区域（主要/内联）
    const userInfoDisplayArea = document.getElementById('user-info-display-area');
    const userNameDisplayElement = document.getElementById('user-name-display');
    const userPointsDisplayElement = document.getElementById('user-points-display');
    
    if (userInfoDisplayArea && userNameDisplayElement && userPointsDisplayElement) {
      userNameDisplayElement.textContent = user.user_name || user.name || '-';
      userPointsDisplayElement.textContent = user.points_balance || user.points || '0';
      userInfoDisplayArea.style.display = 'block';
      userInfoDisplayArea.classList.add('animate-fade-in');
    }
    
    // 显示侧边栏的仅查看模式积分信息区域
    const sideUserInfoDisplayArea = document.getElementById('side-user-info-display-area');
    const sideUserNameDisplayElement = document.getElementById('side-user-name-display');
    const sideUserPointsDisplayElement = document.getElementById('side-user-points-display');
    
    if (sideUserInfoDisplayArea && sideUserNameDisplayElement && sideUserPointsDisplayElement) {
      sideUserNameDisplayElement.textContent = user.user_name || user.name || '-';
      sideUserPointsDisplayElement.textContent = user.points_balance || user.points || '0';
      sideUserInfoDisplayArea.style.display = 'block';
      sideUserInfoDisplayArea.classList.add('animate-fade-in');
    }
    
    // 更新手机端显示区域（保持原有逻辑）
    if (mobileUserInfoArea && mobileUserNameDisplay && mobileUserPointsDisplay) {
      mobileUserNameDisplay.textContent = user.user_name || user.name || '-';
      mobileUserPointsDisplay.textContent = user.points_balance || user.points || '0';
      mobileUserInfoArea.classList.remove('hidden');
      mobileUserInfoArea.classList.add('animate-fade-in');
    }
    
    // 初始化积分显示组件
    initializePointsDisplay(parseInt(user.points_balance || user.points) || 0);
  }

  /**
   * 设置积分解锁弹窗
   */
  function setupPointsUnlockModal() {
    const unlockBtn = document.getElementById('unlock-points-btn');
    const popup = document.getElementById('points-unlock-popup');
    const overlay = document.getElementById('points-section-overlay');
    const closeBtn = document.getElementById('points-popup-close');
    const cancelBtn = document.getElementById('popup-cancel-btn');
    const verifyBtn = document.getElementById('popup-verify-btn');
    const popupInput = document.getElementById('popup-user-shortcode');
    
    if (!popup || !unlockBtn) return;
    
    // 打开弹窗
    unlockBtn.addEventListener('click', function() {
      popup.classList.add('show');
      overlay.classList.add('show');
      popupInput.focus();
    });
    
    // 关闭弹窗
    function closePopup() {
      popup.classList.remove('show');
      overlay.classList.remove('show');
      setTimeout(() => {
        popupInput.value = '';
      }, 300);
    }
    
    closeBtn?.addEventListener('click', closePopup);
    cancelBtn?.addEventListener('click', closePopup);
    
    // 点击遮罩关闭
    overlay?.addEventListener('click', closePopup);
    
    // 输入框限制
    popupInput?.addEventListener('input', function(e) {
      this.value = this.value.replace(/[^0-9]/g, '');
      if (this.value.length > 4) {
        this.value = this.value.slice(0, 4);
      }
    });
    
    // 验证按钮
    verifyBtn?.addEventListener('click', function() {
      const shortcode = popupInput?.value?.trim();
      if (!shortcode || shortcode.length !== 4) {
        showToast('请输入4位数字短码', 'warning');
        return;
      }
      
      // 同步短码到其他输入框，这样verifyUserShortcode函数可以获取到值
      if (userShortcodeInput) userShortcodeInput.value = shortcode;
      if (sideUserShortcodeInput) sideUserShortcodeInput.value = shortcode;
      if (mobileUserShortcodeInput) mobileUserShortcodeInput.value = shortcode;
      
      // 调试信息
      console.log('Popup shortcode:', shortcode);
      console.log('Synced to inputs:', {
        main: userShortcodeInput?.value,
        side: sideUserShortcodeInput?.value,
        mobile: mobileUserShortcodeInput?.value
      });
      
      // 禁用按钮并显示加载状态
      this.disabled = true;
      const originalText = this.innerHTML;
      this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 验证中...';
      
      // 调用现有的验证函数
      verifyUserShortcode();
      
      // 立即关闭popup，让用户看到验证结果
      closePopup();
      
      // 恢复按钮状态（延迟恢复，给验证过程时间）
      setTimeout(() => {
        this.disabled = false;
        this.innerHTML = originalText;
      }, 2000);
    });
    
    // 回车键验证
    popupInput?.addEventListener('keypress', function(e) {
      if (e.key === 'Enter' && this.value.length === 4) {
        verifyBtn?.click();
      }
    });
    
    // ESC键关闭
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && popup.classList.contains('show')) {
        closePopup();
      }
    });
  }

  /**
   * 弹窗验证短码
   */
  function verifyModalShortcode(shortcode, button, closeCallback) {
    const formData = new URLSearchParams();
    formData.append('action', 'ruiyi_verify_shortcode');
    formData.append('shortcode', shortcode);
    formData.append('nonce', ajaxNonce);
    
    fetch(ajaxUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        currentUser = data.data;
        
        // 显示用户信息
        document.getElementById('modal-user-name').textContent = currentUser.name;
        document.getElementById('modal-user-points').textContent = currentUser.points;
        document.getElementById('modal-user-info-area').classList.remove('hidden');
        
        // 更新解锁按钮状态
        const unlockBtn = document.getElementById('unlock-points-btn');
        unlockBtn.innerHTML = '<i class="fas fa-check text-xs"></i><span class="unlock-text">' + t('unlocked') + '</span>';
        unlockBtn.classList.remove('bg-blue-600', 'hover:bg-blue-700');
        unlockBtn.classList.add('bg-green-500', 'hover:bg-green-600');
        
        // 更新其他界面
        displayUserInfo(currentUser);
        
        // 加载积分产品
        const pointsSection = document.getElementById('points-products-section');
        if (pointsSection) {
          if (pointsProducts.length > 0) {
            displayPointsProducts(pointsProducts, false);
          } else {
            loadPointsProducts();
          }
        }
        
        showToast(t('shortcode_success'), 'success');
        
        // 2秒后自动关闭弹窗
        setTimeout(() => {
          closeCallback();
        }, 2000);
        
      } else {
        showToast(data.data?.message || t('invalid_shortcode'), 'error');
      }
    })
    .catch(error => {
      console.error(t('verification_error') + ':', error);
      showToast(t('verification_error'), 'error');
    })
    .finally(() => {
      // 恢复按钮状态
      button.disabled = false;
      button.innerHTML = '<i class="fas fa-unlock"></i> ' + t('unlock');
    });
  }

  /**
   * 加载积分产品（页面加载时调用，显示锁定状态）
   */
  function loadPointsProducts() {
    // 检查积分产品模块是否存在
    const pointsSection = document.getElementById('points-products-section');
    if (!pointsSection || !pointsLoading) return;
    
    // 显示加载状态
    pointsLoading.classList.remove('hidden');
    hideAllPointsNotices();
    
    // AJAX请求获取积分产品
    const formData = new URLSearchParams();
    formData.append('action', 'ruiyi_get_points_products');
    formData.append('nonce', ajaxNonce);
    
    fetch(ajaxUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.success && data.data.length > 0) {
        pointsProducts = data.data;
        displayPointsProducts(pointsProducts, !currentUser); // 如果没有用户则锁定
      } else {
        showNoPointsProductsNotice();
      }
    })
    .catch(error => {
      console.error('Error:', error);
      showToast(t('failed_to_load_products'), 'error');
      showNoPointsProductsNotice();
    })
    .finally(() => {
      pointsLoading.classList.add('hidden');
    });
  }

  // 防止重复调用的时间戳
  let lastDisplayPointsProductsCall = 0;

  /**
   * 显示积分产品
   */
  function displayPointsProducts(products, forceDisabled = false) {
    if (!pointsProductsContainer) return;
    
    // 防止在500毫秒内重复调用
    const now = Date.now();
    if (now - lastDisplayPointsProductsCall < 500) {
      return;
    }
    lastDisplayPointsProductsCall = now;
    
    pointsProductsContainer.innerHTML = '';
    // 不重置事件监听器标记，因为我们现在有更好的重复检测机制
    
    if (products.length === 0) {
      showNoPointsProductsNotice();
      return;
    }
    
    let hasAffordableProducts = false;
    
    products.forEach(product => {
      let canAfford = false;
      let isLocked = forceDisabled;
      
      if (currentUser && !forceDisabled) {
        canAfford = currentUser.points_balance >= product.points_price;
        if (canAfford) hasAffordableProducts = true;
      }
      
      const productElement = createPointsProductCard(product, canAfford, isLocked);
      pointsProductsContainer.appendChild(productElement);
    });
    
    // 添加事件监听器
    attachProductEventListeners(pointsProductsContainer);
    
    // 显示积分产品网格
    pointsProductsGrid.classList.remove('hidden');
    
    // 如果没有用户或积分不足，显示相应提示
    if (forceDisabled) {
      showUserNotLoggedInNotice();
    } else if (currentUser && !hasAffordableProducts) {
      showInsufficientPointsNotice();
    }
  }

  /**
   * 创建积分产品卡片
   */
  function createPointsProductCard(product, canAfford, isLocked = false) {
    const card = document.createElement('div');
    card.className = `points-product-card relative cursor-pointer ${canAfford && !isLocked ? '' : 'points-insufficient'}`;
    card.dataset.id = product.id;
    card.dataset.pointsPrice = product.points_price;
    card.dataset.name = product.name;
    card.dataset.image = product.image || '';
    
    // 确定卡片状态
    let buttonState = '';
    let cardOpacity = '';
    let needsLogin = false;
    
    if (isLocked) {
      // 需要登录状态 - 显示锁定状态
      buttonState = `<div class="w-full bg-gray-100 text-gray-500 py-2 px-3 rounded-lg text-sm font-medium border border-gray-300">
        <i class="fas fa-lock mr-1"></i>${t('points_locked')}
      </div>`;
      cardOpacity = 'opacity-50';
      needsLogin = true;
    } else if (canAfford) {
      // 可兑换状态 - 移除按钮，直接点击卡片添加
      buttonState = ``;
      cardOpacity = '';
    } else {
      // 积分不足状态
      buttonState = `<div class="w-full bg-gray-200 text-gray-500 py-2 px-3 rounded-lg text-sm font-medium">
        <i class="fas fa-lock mr-1"></i>${t('insufficient_points_text')}
      </div>`;
      cardOpacity = 'opacity-60';
    }
    
    card.innerHTML = `
      <div class="bg-white rounded-lg p-3 text-center border border-gray-200 transition-all duration-300 ${cardOpacity} ${canAfford && !isLocked ? 'hover:border-blue-300 cursor-pointer hover:shadow-md' : ''}">
        <div class="relative overflow-hidden rounded-lg mb-2">
          ${product.image ? 
            `<img src="${product.image}" alt="${product.name}" class="w-full h-24 sm:h-28 md:h-32 object-cover object-center">` :
            `<div class="w-full h-24 sm:h-28 md:h-32 bg-gray-100 flex items-center justify-center">
              <i class="fas fa-star text-3xl md:text-4xl text-blue-300"></i>
            </div>`
          }
        </div>
        <h3 class="font-medium text-sm text-gray-800 line-clamp-2 min-h-[2.5rem] mb-2">${getProductDisplayName(product)}</h3>
        ${product.description ? `<p class="text-xs text-gray-500 mb-2">${product.description}</p>` : ''}
        
        <!-- 积分价格再次强调显示 -->
        <div class="mb-2 p-2 bg-blue-50 rounded-lg">
          <div class="text-blue-600 font-bold text-base">
            <i class="fas fa-coins mr-1"></i>${product.points_price} ${t('points')}
          </div>
        </div>
        
        <div class="mt-2">
          ${buttonState}
        </div>
      </div>
    `;
    
    // 事件监听器由统一的attachProductEventListeners处理
    
    return card;
  }

  /**
   * 将积分产品添加到购物车
   */
  function redeemPointsProduct(product) {
    if (!currentUser || currentUser.points_balance < product.points_price) {
      showToast(t('insufficientPoints'), 'error');
      return;
    }
    
    // 直接添加产品到购物车，积分在订单创建时扣除
    addPointsProductToCart(product);
    
    // 不显示任何提示，保持静默添加体验
  }

  /**
   * 将积分产品添加到购物车
   */
  function addPointsProductToCart(product) {
    if (maxOrderQty > 0 && getRemainingOrderQuantity() <= 0) {
      showMaxOrderLimitToast();
      updateOrderLimitUI();
      return false;
    }

    // 验证用户积分是否足够（基于显示的积分）
    if (!currentUser || displayedPoints < product.points_price) {
      showToast(t('insufficientPoints'), 'error');
      return false;
    }
    
    // 检查购物车中是否已有该积分产品
    const existingItem = cart.find(item => item.id === product.id && item.is_points_product);
    
    if (existingItem) {
      // 如果已存在，增加数量
      existingItem.quantity += 1;
    } else {
      // 添加新的积分产品到购物车
      const cartItem = {
        id: product.id,
        name: product.name,
        price: 0, // 积分产品价格为0
        points_price: product.points_price,
        quantity: 1,
        is_points_product: true,
        user_id: currentUser.user_id
      };
      cart.push(cartItem);
    }
    
    // 预览扣除积分
    previewPointsDeduction(product.points_price);
    
    // 重新渲染购物车
    renderCartItems();
    calculateTotals();
    updateCartUI();

    // 🔥 移除积分产品添加提示消息
    return true;
  }



  /**
   * 重置积分产品显示
   */
  function resetPointsProductsDisplay() {
    currentUser = null;
    
    // 隐藏主要(内联)用户信息
    if (userInfoArea) userInfoArea.classList.add('hidden');
    
    // 隐藏侧边栏用户信息
    if (sideUserInfoArea) sideUserInfoArea.classList.add('hidden');
    
    // 隐藏积分产品网格
    if (pointsProductsGrid) pointsProductsGrid.classList.add('hidden');
    
    hideAllPointsNotices();
  }

  /**
   * 隐藏所有积分提示
   */
  function hideAllPointsNotices() {
    if (insufficientPointsNotice) insufficientPointsNotice.classList.add('hidden');
    if (noPointsProductsNotice) noPointsProductsNotice.classList.add('hidden');
  }

  /**
   * 显示用户未登录提示
   */
  function showUserNotLoggedInNotice() {
    // 可以创建一个提示或者不显示任何提示，因为产品卡片本身已经显示了"请输入积分短码"
    hideAllPointsNotices();
  }

  /**
   * 显示积分不足提示
   */
  function showInsufficientPointsNotice() {
    hideAllPointsNotices();
    if (insufficientPointsNotice) insufficientPointsNotice.classList.remove('hidden');
  }

  /**
   * 显示无积分产品提示
   */
  function showNoPointsProductsNotice() {
    hideAllPointsNotices();
    if (noPointsProductsNotice) noPointsProductsNotice.classList.remove('hidden');
  }

  // Configurar event listeners
  function setupEventListeners() {
    // 旧的按钮控制逻辑已停用，现在使用卡片点击方式
    // setupProductQuantityControls();
    
    // 旧的产品点击事件已移除，现在统一使用 attachProductEventListeners 函数
    
    // 购物车按钮点击时打开侧边菜单
    cartButton.addEventListener('click', function() {
      document.body.classList.add('cart-open');
    });
    
    // 移动端底部导航栏的购物车按钮
    mobileCartBtn.addEventListener('click', function() {
      document.body.classList.add('cart-open');
    });
    
    // 关闭购物车菜单
    closeCart.addEventListener('click', function() {
      document.body.classList.remove('cart-open');
    });
    
    // 点击遮罩层关闭购物车
    cartOverlay.addEventListener('click', function() {
      document.body.classList.remove('cart-open');
    });
    
    // Bottom cart button para abrir el carrito
    if (bottomCartBtn) {
      bottomCartBtn.addEventListener('click', function() {
        document.body.classList.add('cart-open');
      });
    }
    
    // Sincronizar inputs de mesa
    tableNumberInputs.forEach(input => {
      input.addEventListener('input', function() {
        const value = this.value;
        tableNumberInputs.forEach(otherInput => {
          if (otherInput !== this && !otherInput.readOnly) {
            otherInput.value = value;
          }
        });
      });
    });
    
    // Categorías
    categoryBtns.forEach(btn => {
      btn.addEventListener('click', function() {
        const categoryId = this.dataset.category;
        filterProducts(categoryId);
        
        // Actualizar categoría activa
        categoryBtns.forEach(b => b.classList.remove('active'));
        this.classList.add('active');
      });
    });
    
    // Initialize Floating Category Button Elements
    floatingCategoryBtn = document.getElementById('floating-category-btn');
    categoryPopupOverlay = document.getElementById('category-popup-overlay');
    categoryPopup = document.getElementById('category-popup');
    categoryPopupClose = document.getElementById('category-popup-close');
    categoryPopupItems = document.querySelectorAll('.category-popup-item');
    
    // Floating Category Button Functionality
    function toggleCategoryPopup() {
      if (!floatingCategoryBtn) return;
      
      const isActive = floatingCategoryBtn.classList.contains('active');
      
      if (isActive) {
        // Close popup
        floatingCategoryBtn.classList.remove('active');
        categoryPopupOverlay?.classList.remove('active');
        categoryPopup?.classList.remove('active');
        document.body.style.overflow = '';
      } else {
        // Open popup
        floatingCategoryBtn.classList.add('active');
        categoryPopupOverlay?.classList.add('active');
        categoryPopup?.classList.add('active');
        document.body.style.overflow = 'hidden';
      }
    }
    
    // Event listeners for floating category button
    if (floatingCategoryBtn) {
      floatingCategoryBtn.addEventListener('click', toggleCategoryPopup);
    }
    
    if (categoryPopupOverlay) {
      categoryPopupOverlay.addEventListener('click', toggleCategoryPopup);
    }
    
    if (categoryPopupClose) {
      categoryPopupClose.addEventListener('click', toggleCategoryPopup);
    }
    
    // 分类筛选状态变量（必须在所有使用它的事件处理之前声明）
    let currentCategoryFilter = '0';

    // Category popup item selection - 重新获取所有分类按钮（包括新添加的）
    function initializeCategoryEventListeners() {
      const allCategoryItems = document.querySelectorAll('.category-popup-item');
      
      allCategoryItems.forEach(item => {
        // 移除可能存在的旧事件监听器
        item.removeEventListener('click', handleCategoryClick);
        // 添加新的事件监听器
        item.addEventListener('click', handleCategoryClick);
      });
    }
    
    function handleCategoryClick() {
      const categoryId = this.dataset.category;
      const categoryName = this.querySelector('span').textContent;
      
      // Update active state in popup
      document.querySelectorAll('.category-popup-item').forEach(i => i.classList.remove('active'));
      this.classList.add('active');
      
      // Filter products with category name
      filterProducts(categoryId, categoryName);
      currentCategoryFilter = categoryId;
      
      // Update main category buttons active state
      categoryBtns.forEach(b => b.classList.remove('active'));
      const mainCategoryBtn = document.querySelector(`.category-btn[data-category="${categoryId}"]`);
      if (mainCategoryBtn) {
        mainCategoryBtn.classList.add('active');
      }
      
      // Close popup
      toggleCategoryPopup();
      
      // Show toast with selected category
      showToast(`已选择分类: ${categoryName}`, 'info');
    }
    
    // 初始化分类事件监听器
    initializeCategoryEventListeners();
    
    // Escape key to close popup
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && floatingCategoryBtn.classList.contains('active')) {
        toggleCategoryPopup();
      }
    });
    
    // Configurar eventos de积分产品
    setupPointsProductEvents();

    // ===== V2: Horizontal Category Chips =====
    document.querySelectorAll('.category-chip').forEach(chip => {
      chip.addEventListener('click', function() {
        const categoryId = this.dataset.category;
        const categoryName = this.querySelector('span:not(.cat-icon)') ? this.querySelector('span:not(.cat-icon)').textContent : '';

        // Update active state
        document.querySelectorAll('.category-chip').forEach(c => c.classList.remove('active'));
        this.classList.add('active');

        // Also sync with popup items if they exist
        document.querySelectorAll('.category-popup-item').forEach(i => i.classList.remove('active'));
        const matchingPopupItem = document.querySelector('.category-popup-item[data-category="' + categoryId + '"]');
        if (matchingPopupItem) matchingPopupItem.classList.add('active');

        // Filter products
        filterProducts(categoryId, categoryName);
        currentCategoryFilter = categoryId;

        // Clear search when changing category
        const searchInput = document.getElementById('product-search-input');
        if (searchInput) searchInput.value = '';
      });
    });

    // 页面加载时自动选中第一个分类（优先侧边栏）
    const firstChip = document.querySelector('.v5-cat-btn') || document.querySelector('.category-chip');
    if (firstChip) {
      firstChip.click();
    }
    applyCategoryTimeLocks();
    setInterval(function() {
      updateCategoryTimeNotice(currentCategoryFilter === '0' ? null : currentCategoryFilter);
      updateOrderLimitUI();
    }, 60000);

    // ===== 产品详情弹窗事件绑定 =====
    initProductDetailEvents();

    // ===== Search Toggle Button =====
    const searchToggleBtn = document.getElementById('search-toggle-btn');
    const searchBarContainer = document.querySelector('.search-bar-container');
    const appMain = document.querySelector('.app-main');
    if (searchToggleBtn && searchBarContainer) {
      searchToggleBtn.addEventListener('click', function() {
        const isOpen = searchBarContainer.classList.toggle('search-open');
        searchToggleBtn.classList.toggle('active', isOpen);
        // 调整主区域高度
        if (appMain) {
          appMain.style.height = isOpen ? 'calc(100vh - 100px)' : 'calc(100vh - 56px)';
        }
        // 打开时自动聚焦输入框
        if (isOpen) {
          const input = searchBarContainer.querySelector('input');
          if (input) input.focus();
        } else {
          // 关闭时清空搜索并恢复分类过滤
          const input = searchBarContainer.querySelector('input');
          if (input) {
            input.value = '';
            filterProducts(currentCategoryFilter);
          }
        }
      });
    }

    // ===== V2: Search Bar =====
    const productSearchInput = document.getElementById('product-search-input');
    if (productSearchInput) {
      productSearchInput.addEventListener('input', function() {
        const query = this.value.toLowerCase().trim();
        const regularSection = document.querySelector('.content-section:has(#products-container)');

        if (query === '') {
          // Reset to current category filter
          filterProducts(currentCategoryFilter);
          return;
        }

        // Show regular section, hide special sections
        const discountSection = document.getElementById('discount-products-section');
        const popularSection = document.getElementById('popular-products-section');
        const pointsSection = document.getElementById('points-products-section');
        if (discountSection) discountSection.classList.add('hidden');
        if (popularSection) popularSection.classList.add('hidden');
        if (pointsSection) pointsSection.classList.add('hidden');
        if (regularSection) regularSection.classList.remove('hidden');

        // Filter products by name
        document.querySelectorAll('#products-container .product-card').forEach(card => {
          const name = (card.dataset.name || card.querySelector('.product-title').textContent).toLowerCase();
          if (name.includes(query)) {
            card.classList.remove('hidden');
          } else {
            card.classList.add('hidden');
          }
        });
      });
    }

    // ===== Tablet: Sync inline header search with main search =====
    const headerSearchInput = document.querySelector('.header-search-input');
    if (headerSearchInput && productSearchInput) {
      headerSearchInput.addEventListener('input', function() {
        productSearchInput.value = this.value;
        productSearchInput.dispatchEvent(new Event('input'));
      });
      // Also sync in reverse (when mobile search is used)
      productSearchInput.addEventListener('input', function() {
        if (headerSearchInput.value !== this.value) {
          headerSearchInput.value = this.value;
        }
      });
    }

    // ===== V2: Bottom Cart Bar =====
    const bottomCartBar = document.getElementById('bottom-cart-bar');
    const bottomCartCtaBtn = document.getElementById('bottom-cart-cta-btn');
    if (bottomCartCtaBtn) {
      bottomCartCtaBtn.addEventListener('click', function() {
        document.body.classList.add('cart-open');
      });
    }

    // Update category filter variable when category changes
    categoryBtns.forEach(btn => {
      btn.addEventListener('click', function() {
        currentCategoryFilter = this.dataset.category;
        
        if (currentSearchTerm) {
          searchProducts(currentSearchTerm, currentCategoryFilter);
        }
      });
    });
    
    // Botones de acción
    sideSendOrderBtn.addEventListener('click', sendOrder);
    inlineSendOrderBtn.addEventListener('click', sendOrder);
    mobileSendOrderBtn.addEventListener('click', sendOrder);
  //   sidePayOrderBtn.addEventListener('click', payOrder);
  //   inlinePayOrderBtn.addEventListener('click', payOrder);
  //   mobilePayOrderBtn.addEventListener('click', payOrder);
    
    // Remover evento de modo oscuro ya que se eliminó el botón
    // themeToggle.addEventListener('click', toggleDarkMode);
    
    // Menú de idiomas
    languageMenuButton.addEventListener('click', function() {
      languageMenu.classList.toggle('hidden');
    });
    
    // Opción de idioma
    languageOptions.forEach(option => {
      option.addEventListener('click', function(e) {
        e.preventDefault();
        const lang = this.dataset.lang;
        changeLanguage(lang);
      });
    });
    
    // Ocultar menú de idiomas al hacer clic fuera
    document.addEventListener('click', function(e) {
      if (!languageMenuButton.contains(e.target) && !languageMenu.contains(e.target)) {
        languageMenu.classList.add('hidden');
      }
    });
    
    // 添加按键支持
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && document.body.classList.contains('cart-open')) {
        document.body.classList.remove('cart-open');
      }
    });
  }

  // Cambiar idioma (独立于POS收银系统，仅设置客户专用cookie)
  function changeLanguage(lang) {
    if (lang === currentLang) return;

    // Cerrar menú de idiomas
    languageMenu.classList.add('hidden');

    // Mostrar indicador de carga
    const changingMsg = lang === 'es' ? 'Cambiando idioma...' : lang === 'en' ? 'Changing language...' : '正在切换语言...';
    showToast(changingMsg, 'info');

    // 使用客户专用AJAX action，不影响POS收银系统语言
    const formData = new URLSearchParams();
    formData.append('action', 'change_customer_language');
    formData.append('lang', lang);

    fetch(ajaxUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        // Recargar la página
        window.location.reload();
      }
    })
    .catch(error => {
      console.error('Error cambiando idioma:', error);
    });
  }

  // Añadir producto al carrito
  function addToCart(id, name, price, image, nameEs, nameZh, nameEn, categoryId, basePrice = null, zoneCategoryId = null) {
    if (MENU_ONLY_MODE) return false; // 🔥 仅菜单浏览模式

    categoryId = categoryId || getProductCategoryId(id);
    if (getCategoryTimeRule(categoryId) && !isCategoryAvailable(categoryId)) {
      showCategoryTimeLockedToast(categoryId);
      updateOrderLimitUI();
      return false;
    }

    // 🔥 最高商品数量限制：添加前检查
    if (maxOrderQty > 0 && getRemainingOrderQuantity() <= 0) {
      showMaxOrderLimitToast();
      updateOrderLimitUI();
      return false;
    }

    // 确保价格是有效数字
    const validPrice = parseFloat(price);
    if (isNaN(validPrice)) {
      console.error('Invalid price for product:', name, price);
      return false;
    }
    const parsedBasePrice = parseFloat(basePrice);
    const originalUnitPrice = !isNaN(parsedBasePrice) && parsedBasePrice > 0 ? parsedBasePrice : validPrice;
    const effectiveZoneCategoryId = parseInt(zoneCategoryId || tableZoneCategoryId || 0, 10) || 0;
    const hasZoneAdjustedPrice = effectiveZoneCategoryId > 0 && Math.abs(validPrice - originalUnitPrice) > 0.004;

    // 🍽️ 检查是否自助餐产品
    const isBuffet = isBuffetProduct(id);

    // Comprobar si ya existe en el carrito
    const existingItemIndex = cart.findIndex(item => item.id === id);

    if (existingItemIndex >= 0) {
      cart[existingItemIndex].quantity += 1;
      cart[existingItemIndex].category_id = cart[existingItemIndex].category_id || categoryId || '';
      cart[existingItemIndex].originalPrice = cart[existingItemIndex].originalPrice || originalUnitPrice;
      cart[existingItemIndex].basePrice = cart[existingItemIndex].basePrice || originalUnitPrice;
      if (effectiveZoneCategoryId) {
        cart[existingItemIndex].zoneCategoryId = cart[existingItemIndex].zoneCategoryId || effectiveZoneCategoryId;
        cart[existingItemIndex].zone_category_id = cart[existingItemIndex].zone_category_id || effectiveZoneCategoryId;
      }
      if (!isBuffet && hasZoneAdjustedPrice && cart[existingItemIndex].customPrice === undefined) {
        cart[existingItemIndex].customPrice = validPrice;
      }
      // Update image if not already set
      if (image && !cart[existingItemIndex].image) {
        cart[existingItemIndex].image = image;
      }
    } else {
      const cartItem = {
        id: id,
        name: name,
        name_es: nameEs || name || '',
        name_zh: nameZh || '',
        name_en: nameEn || '',
        price: isBuffet ? 0 : validPrice,
        originalPrice: originalUnitPrice,
        basePrice: originalUnitPrice,
        quantity: 1,
        image: image || '',
        category_id: categoryId || '',
        is_buffet: isBuffet
      };
      if (effectiveZoneCategoryId) {
        cartItem.zoneCategoryId = effectiveZoneCategoryId;
        cartItem.zone_category_id = effectiveZoneCategoryId;
      }
      if (!isBuffet && hasZoneAdjustedPrice) {
        cartItem.customPrice = validPrice;
      }
      cart.push(cartItem);
    }
    
    // Guardar y actualizar UI
    saveCart();
    renderCart();
    updateProductQuantityDisplay(id);
    updateOrderLimitUI();
    return true;
    
  }

  // Eliminar producto del carrito
  function removeFromCart(index) {
    // 验证索引和项目存在
    if (index < 0 || index >= cart.length) {
      console.error('Invalid cart index:', index);
      return;
    }
    
    const item = cart[index];
    
    if (!item) {
      console.error('Cart item not found at index:', index);
      return;
    }
    
    // 处理积分产品的积分恢复
    if (item.is_points_product && item.points_price) {
      previewPointsRefund(item.points_price);
    }
    
    if (item.quantity > 1) {
      cart[index].quantity -= 1;
    } else {
      cart.splice(index, 1);
    }
    
    saveCart();
    renderCart(); // 使用 renderCart 而不是 updateCartUI
    updateProductQuantityDisplay(item.id);
  }

  // Guardar carrito en localStorage
  function saveCart() {
    localStorage.setItem('cart', JSON.stringify(cart));
  }

  // Actualizar UI del carrito
  function updateCartUI() {
    // Actualizar contador
    const itemCount = cart.reduce((total, item) => total + item.quantity, 0);
    cartCount.textContent = itemCount;
    if (mobileCartCount) mobileCartCount.textContent = itemCount;

    // Actualizar bottom bar si existe
    if (bottomCartCount) {
      bottomCartCount.textContent = itemCount;
    }

    // Actualizar floating cart button
    updateFloatingCartButton(itemCount);

    // Update cart header count badges
    updateCartCountBadges(itemCount);

    // Mostrar/ocultar mensaje de carrito vacío
    if (cart.length === 0) {
      if (sideEmptyCart) sideEmptyCart.style.display = '';
      if (inlineEmptyCart) inlineEmptyCart.style.display = '';
      sideCartItems.innerHTML = '';
      inlineCartItems.innerHTML = '';
    } else {
      if (sideEmptyCart) sideEmptyCart.style.display = 'none';
      if (inlineEmptyCart) inlineEmptyCart.style.display = 'none';
      renderCartItems();
    }

    // Actualizar totales
    calculateTotals();
    // 🔥 更新产品备注数量标记
    if (typeof updateNotesCountBadge === 'function') updateNotesCountBadge();

    // V2: Update bottom cart bar
    updateBottomCartBar();

    // 🔥 同步扫码点餐最高商品数量锁定状态
    updateOrderLimitUI();
  }

  // Update cart header item count badges
  function updateCartCountBadges(itemCount) {
    const itemsLabel = currentLang === 'es' ? 'artículos' : currentLang === 'en' ? 'items' : '件商品';
    const badgeText = itemCount + ' ' + itemsLabel;
    const sideBadge = document.getElementById('side-cart-count-badge');
    const inlineBadge = document.getElementById('inline-cart-count-badge');
    if (sideBadge) sideBadge.textContent = badgeText;
    if (inlineBadge) inlineBadge.textContent = badgeText;
  }

  // V2: Update bottom cart bar
  function updateBottomCartBar() {
    const bar = document.getElementById('bottom-cart-bar');
    const countEl = document.getElementById('bottom-cart-item-count');
    const priceEl = document.getElementById('bottom-cart-total-price');
    if (!bar) return;

    const itemCount = cart.reduce((sum, item) => sum + item.quantity, 0);
    if (itemCount === 0) {
      bar.style.display = 'none';
      document.body.classList.remove('has-bottom-cart');
    } else {
      bar.style.display = 'flex';
      document.body.classList.add('has-bottom-cart');
      if (countEl) {
        const itemsLabel = currentLang === 'es' ? 'artículos' : currentLang === 'en' ? 'items' : '件商品';
        countEl.textContent = itemCount + ' ' + itemsLabel;
      }
      if (priceEl) {
        priceEl.textContent = formatCurrency(total);
      }
    }
  }

  // Renderizar carrito completo
  function renderCart() {
    renderCartItems();
    calculateTotals();
    updateCartUI();
    // 🔥 更新产品备注数量标记
    if (typeof updateNotesCountBadge === 'function') updateNotesCountBadge();
  }

  // Renderizar items del carrito
  function renderCartItems() {
    // 清空两个购物车内容区域
    sideCartItems.innerHTML = '';
    inlineCartItems.innerHTML = '';
    
    // 渲染每个购物车项目
    cart.forEach((item, index) => {
      // 创建侧边菜单购物车项
      const sideItemEl = createCartItemElement(item, index);
      sideCartItems.appendChild(sideItemEl);
      
      // 创建内联购物车项（克隆侧边菜单购物车项）
      const inlineItemEl = createCartItemElement(item, index);
      inlineCartItems.appendChild(inlineItemEl);
    });
    
    // 添加事件监听器到两个购物车的控制按钮
    attachCartEventListeners();
    
    // 更新所有产品的数量显示
    updateAllProductQuantityDisplays();
    
    // 同步积分显示（确保购物车积分显示正确）
    if (currentUser && realUserPoints > 0) {
      syncPointsWithCart();
    }
  }

  // 更新所有产品的数量显示
  function updateAllProductQuantityDisplays() {
    // 获取所有产品卡片
    document.querySelectorAll('.product-card').forEach(productCard => {
      const productId = productCard.dataset.id;
      const cartItem = cart.find(item => item.id === productId);
      const quantityControls = document.querySelector(`.quantity-controls-${productId}`);
      const addFirstBtn = document.querySelector(`.add-first-btn-${productId}`);
      const quantityDisplay = document.querySelector(`.quantity-controls-${productId} .quantity-display`);

      if (cartItem && cartItem.quantity > 0) {
        // Producto está en el carrito - mostrar controles de cantidad
        if (quantityControls && addFirstBtn) {
          quantityControls.classList.remove('hidden');
          addFirstBtn.classList.add('hidden');
        }
        if (quantityDisplay) {
          quantityDisplay.textContent = cartItem.quantity;
        }
        // 显示卡片内嵌 qty-controls
        showQtyControls(productId);
        const qtyValueEl = document.getElementById(`qty-value-${productId}`);
        if (qtyValueEl) {
          qtyValueEl.textContent = cartItem.quantity;
        }
      } else {
        // Producto no está en el carrito - mostrar botón "Añadir"
        if (quantityControls && addFirstBtn) {
          quantityControls.classList.add('hidden');
          addFirstBtn.classList.remove('hidden');
        }
        // 隐藏卡片内嵌 qty-controls
        hideQtyControls(productId);
      }
    });
  }

  // 重置所有产品状态到初始状态（订单完成后调用）
  function resetAllProductStates() {
    // 获取所有产品卡片并重置状态
    document.querySelectorAll('.product-card').forEach(productCard => {
      const productId = productCard.dataset.id;
      const quantityControls = document.querySelector(`.quantity-controls-${productId}`);
      const addFirstBtn = document.querySelector(`.add-first-btn-${productId}`);
      const quantityDisplay = document.querySelector(`.quantity-controls-${productId} .quantity-display`);

      // 隐藏数量控制器，显示"添加"按钮
      if (quantityControls && addFirstBtn) {
        quantityControls.classList.add('hidden');
        addFirstBtn.classList.remove('hidden');
      }

      // 重置数量显示为0
      if (quantityDisplay) {
        quantityDisplay.textContent = '0';
      }

      // 隐藏卡片内嵌 qty-controls
      if (productId) {
        hideQtyControls(productId);
      }
    });

    // 更新购物车计数器
    updateCartCount();
    updateOrderLimitUI();

  }

  // 更新购物车计数器
  function updateCartCount() {
    const itemCount = cart.reduce((total, item) => total + item.quantity, 0);
    
    // 更新所有购物车计数器显示
    if (cartCount) cartCount.textContent = itemCount;
    if (mobileCartCount) mobileCartCount.textContent = itemCount;
    if (bottomCartCount) bottomCartCount.textContent = itemCount;
  }

  // 创建购物车项目元素
  function createCartItemElement(item, index) {
    const itemEl = document.createElement('div');
    itemEl.className = 'cart-item-v2';

    // 检查是否为积分产品
    if (item.is_points_product) {
      itemEl.innerHTML = `
        <div class="cart-item-img-placeholder">
          <i class="fas fa-star" style="color: #6366F1;"></i>
        </div>
        <div class="cart-item-info">
          <div class="cart-item-name">${item.name}</div>
          <div class="cart-item-bottom">
            <div class="cart-item-qty">
              <button class="cart-item-qty-btn minus-btn decrease-btn" data-index="${index}">
                <i class="fas fa-minus" style="font-size:12px;"></i>
              </button>
              <span class="cart-item-qty-val">${item.quantity}</span>
              <button class="cart-item-qty-btn plus-btn increase-btn" data-index="${index}">
                <i class="fas fa-plus" style="font-size:12px;"></i>
              </button>
            </div>
            <span class="cart-item-price" style="color: #6366F1;">-${item.points_price * item.quantity} pts</span>
          </div>
        </div>
      `;
    } else {
      const hasNotes = item.notes && item.notes.trim();
      const imgHtml = item.image
        ? `<img src="${item.image}" alt="${item.name}" class="cart-item-img" onerror="this.outerHTML='<div class=\\'cart-item-img-placeholder\\'><i class=\\'fas fa-utensils\\'></i></div>'">`
        : `<div class="cart-item-img-placeholder"><i class="fas fa-utensils"></i></div>`;
      const buffetBadge = item.is_buffet ? '<span style="background:#f97316;color:#fff;font-size:10px;padding:1px 5px;border-radius:8px;margin-left:4px;">🍽️</span>' : '';
      itemEl.innerHTML = `
        ${imgHtml}
        <div class="cart-item-info">
          <div class="cart-item-name">${getItemDisplayName(item)}${buffetBadge}</div>
          ${hasNotes ? `<div class="cart-item-notes"><i class="fas fa-sticky-note" style="margin-right:4px;"></i>${escapeHtml(item.notes.trim())}</div>` : ''}
          <div class="cart-item-bottom">
            <div class="cart-item-qty">
              <button class="cart-item-qty-btn minus-btn decrease-btn" data-index="${index}">
                <i class="fas fa-minus" style="font-size:12px;"></i>
              </button>
              <span class="cart-item-qty-val">${item.quantity}</span>
              <button class="cart-item-qty-btn plus-btn increase-btn" data-index="${index}">
                <i class="fas fa-plus" style="font-size:12px;"></i>
              </button>
            </div>
            <span class="cart-item-price" ${item.is_buffet ? 'style="color:#f97316;"' : ''}>${item.is_buffet ? '€0.00' : formatCurrency(item.price * item.quantity)}</span>
          </div>
        </div>
      `;
    }

    return itemEl;
  }

  // 更新悬浮购物车按钮
  function updateFloatingCartButton(itemCount) {
    const floatingCartBtn = document.getElementById('floating-cart-btn');
    const floatingCartPrice = document.getElementById('floating-cart-price');
    
    if (!floatingCartBtn || !floatingCartPrice) return;
    
    // 显示/隐藏悬浮按钮
    if (itemCount > 0) {
      floatingCartBtn.classList.add('show');
      floatingCartPrice.textContent = formatCurrency(total);
    } else {
      floatingCartBtn.classList.remove('show');
    }
  }

  // 设置悬浮购物车按钮事件
  function setupFloatingCartButton() {
    const floatingCartIconBtn = document.getElementById('floating-cart-icon-btn');
    
    if (floatingCartIconBtn) {
      floatingCartIconBtn.addEventListener('click', function() {
        // 打开侧边购物车
        document.body.classList.add('cart-open');
      });
    }
  }

  // 为购物车按钮添加事件监听器 (使用事件委托)
  function attachCartEventListeners() {
    // 移除旧的事件监听器（如果存在）
    if (sideCartItems) {
      sideCartItems.removeEventListener('click', handleCartButtonClick);
      sideCartItems.addEventListener('click', handleCartButtonClick);
    }

    if (inlineCartItems) {
      inlineCartItems.removeEventListener('click', handleCartButtonClick);
      inlineCartItems.addEventListener('click', handleCartButtonClick);
    }
  }

  // ===== 产品详情弹窗 =====
  let detailQty = 1;
  let detailProductData = null;
  let detailCurrentNote = '';

  const allergenIconMap = {
    'gluten': '🌾', 'crustaceans': '🦐', 'eggs': '🥚', 'fish': '🐟',
    'peanuts': '🥜', 'soy': '🫘', 'milk': '🥛', 'nuts': '🌰',
    'celery': '🥬', 'mustard': '🟡', 'sesame': '⚪', 'sulphites': '🍷',
    'lupin': '🌸', 'molluscs': '🦪', 'chili': '🌶️', 'meat': '🥩'
  };
  const allergenNameMap = {
    'gluten': {es:'Gluten',en:'Gluten',zh:'麸质'}, 'crustaceans': {es:'Crustáceos',en:'Crustaceans',zh:'甲壳类'},
    'eggs': {es:'Huevos',en:'Eggs',zh:'鸡蛋'}, 'fish': {es:'Pescado',en:'Fish',zh:'鱼'},
    'peanuts': {es:'Cacahuetes',en:'Peanuts',zh:'花生'}, 'soy': {es:'Soja',en:'Soy',zh:'大豆'},
    'milk': {es:'Lácteos',en:'Milk',zh:'牛奶'}, 'nuts': {es:'Frutos secos',en:'Nuts',zh:'坚果'},
    'celery': {es:'Apio',en:'Celery',zh:'芹菜'}, 'mustard': {es:'Mostaza',en:'Mustard',zh:'芥末'},
    'sesame': {es:'Sésamo',en:'Sesame',zh:'芝麻'}, 'sulphites': {es:'Sulfitos',en:'Sulphites',zh:'亚硫酸盐'},
    'lupin': {es:'Altramuces',en:'Lupin',zh:'羽扇豆'}, 'molluscs': {es:'Moluscos',en:'Molluscs',zh:'软体动物'},
    'chili': {es:'Chile',en:'Chili Pepper',zh:'辣椒'}, 'meat': {es:'Carne',en:'Meat',zh:'肉类'}
  };

  function getAllergenName(key) {
    const names = allergenNameMap[key];
    if (!names) return key;
    return names[currentLang] || names['es'];
  }

  function updateNotePreview() {
    const preview = document.getElementById('detailNotePreview');
    if (preview) {
      preview.textContent = detailCurrentNote || '';
    }
  }

  function openProductDetail(card) {
    if (MENU_ONLY_MODE) return; // 🔥 仅菜单浏览模式：不打开产品详情
    const detailOverlay = document.getElementById('productDetailOverlay');
    const detailModal = document.getElementById('productDetailModal');
    if (!detailOverlay || !detailModal) return;

    const id = card.dataset.id;
    const name = card.dataset.name;
    const nameEs = card.dataset.nameEs || name;
    const nameZh = card.dataset.nameZh || '';
    const nameEn = card.dataset.nameEn || '';
    const price = parseFloat(card.dataset.price);
    const basePrice = parseFloat(card.dataset.basePrice || card.dataset.price);
    const zoneCategoryId = card.dataset.zoneCategoryId || tableZoneCategoryId || '';
    const image = card.dataset.image;
    const desc = card.dataset.description || '';
    const allergensStr = card.dataset.allergens || '';
    const isBuffet = isBuffetProduct(id);
    const categoryId = card.dataset.category || '';

    detailProductData = { id, name, nameEs, nameZh, nameEn, price, basePrice, zoneCategoryId, image, isBuffet, categoryId };
    detailQty = 1;
    detailCurrentNote = '';

    // 填充弹窗内容
    document.getElementById('detailProductImage').src = image || '';
    document.getElementById('detailProductImage').alt = name;
    document.getElementById('detailProductName').textContent = name;

    const descEl = document.getElementById('detailProductDesc');
    if (desc) {
      descEl.textContent = desc;
      descEl.style.display = '';
    } else {
      descEl.style.display = 'none';
    }

    // 过敏原标签
    const allergensEl = document.getElementById('detailAllergens');
    allergensEl.innerHTML = '';
    if (allergensStr) {
      const allergens = allergensStr.split(',');
      allergens.forEach(a => {
        const icon = allergenIconMap[a] || '';
        const aName = getAllergenName(a);
        if (icon) {
          const tag = document.createElement('span');
          tag.className = 'allergen-tag';
          tag.innerHTML = '<span class="allergen-emoji">' + icon + '</span> ' + aName;
          allergensEl.appendChild(tag);
        }
      });
      allergensEl.style.display = '';
    } else {
      allergensEl.style.display = 'none';
    }

    // 价格
    const displayPrice = isBuffet ? '€0.00' : '€' + price.toFixed(2);
    document.getElementById('detailProductPrice').textContent = displayPrice;
    document.getElementById('detailQtyValue').textContent = '1';
    setCategoryTimeLockedButton(document.getElementById('detailAddToCartBtn'), !isCategoryAvailable(categoryId), categoryId);

    // 重置备注预览
    updateNotePreview();

    // 显示弹窗
    detailModal.classList.remove('closing');
    detailOverlay.classList.add('active');
    updateOrderLimitUI();
  }

  function closeProductDetail() {
    const detailOverlay = document.getElementById('productDetailOverlay');
    const detailModal = document.getElementById('productDetailModal');
    if (!detailOverlay || !detailModal) return;
    detailModal.classList.add('closing');
    setTimeout(() => {
      detailOverlay.classList.remove('active');
      detailModal.classList.remove('closing');
    }, 200);
  }

  // 备注页面
  function openNotePage() {
    const noteOverlay = document.getElementById('notePageOverlay');
    if (!noteOverlay) return;
    const textarea = document.getElementById('notePageTextarea');
    textarea.value = detailCurrentNote;
    document.getElementById('noteCharCount').textContent = detailCurrentNote.length + '/200';
    // 重置快速标签选中状态
    document.querySelectorAll('#noteQuickTags .note-quick-tag').forEach(tag => {
      tag.classList.toggle('selected', detailCurrentNote.includes(tag.dataset.text));
    });
    noteOverlay.classList.remove('closing');
    noteOverlay.classList.add('active');
  }

  function closeNotePage() {
    const noteOverlay = document.getElementById('notePageOverlay');
    if (!noteOverlay) return;
    noteOverlay.classList.add('closing');
    setTimeout(() => {
      noteOverlay.classList.remove('active');
      noteOverlay.classList.remove('closing');
    }, 250);
  }

  function initProductDetailEvents() {
    const detailOverlay = document.getElementById('productDetailOverlay');
    const detailModal = document.getElementById('productDetailModal');
    if (!detailOverlay || !detailModal) return;

    // 关闭按钮
    document.getElementById('detailCloseBtn').addEventListener('click', closeProductDetail);

    // 打开备注页面
    document.getElementById('detailNoteToggle').addEventListener('click', openNotePage);

    // 数量 +/-
    document.getElementById('detailQtyMinus').addEventListener('click', function() {
      if (detailQty > 1) {
        detailQty--;
        document.getElementById('detailQtyValue').textContent = detailQty;
        updateOrderLimitUI();
      }
    });
    document.getElementById('detailQtyPlus').addEventListener('click', function() {
      if (maxOrderQty > 0 && getCartTotalQuantity() + detailQty >= maxOrderQty) {
        showMaxOrderLimitToast();
        updateOrderLimitUI();
        return;
      }
      detailQty++;
      document.getElementById('detailQtyValue').textContent = detailQty;
      updateOrderLimitUI();
    });

    // 加入购物车
    document.getElementById('detailAddToCartBtn').addEventListener('click', function() {
      if (!detailProductData) return;
      const { id, name, price, basePrice, zoneCategoryId, image, nameEs, nameZh, nameEn, categoryId } = detailProductData;
      if (getCategoryTimeRule(categoryId) && !isCategoryAvailable(categoryId)) {
        showCategoryTimeLockedToast(categoryId);
        return;
      }

      let addedCount = 0;
      for (let i = 0; i < detailQty; i++) {
        if (addToCart(id, name, price, image, nameEs, nameZh, nameEn, categoryId, basePrice, zoneCategoryId)) {
          addedCount++;
        } else {
          break;
        }
      }
      if (addedCount <= 0) {
        updateOrderLimitUI();
        return;
      }
      showQtyControls(id);

      if (detailCurrentNote) {
        const cartItem = cart.find(item => item.id === id);
        if (cartItem) {
          cartItem.notes = detailCurrentNote;
          saveCart();
          renderCart();
        }
      }

      closeProductDetail();
    });

    // ===== 备注页面事件 =====
    const noteOverlay = document.getElementById('notePageOverlay');
    if (!noteOverlay) return;

    // 返回按钮
    document.getElementById('notePageBack').addEventListener('click', closeNotePage);

    // 字符计数
    const noteTextarea = document.getElementById('notePageTextarea');
    noteTextarea.addEventListener('input', function() {
      document.getElementById('noteCharCount').textContent = this.value.length + '/200';
    });

    // 快速标签点击
    document.querySelectorAll('#noteQuickTags .note-quick-tag').forEach(tag => {
      tag.addEventListener('click', function() {
        const text = this.dataset.text;
        const textarea = document.getElementById('notePageTextarea');
        const isSelected = this.classList.contains('selected');

        if (isSelected) {
          // 取消选中：从文本中移除
          this.classList.remove('selected');
          let val = textarea.value;
          // 移除 "标签、" 或 "、标签" 或独立的 "标签"
          val = val.replace(new RegExp('、?' + text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '、?'), function(match) {
            if (match.startsWith('、') && match.endsWith('、')) return '、';
            return '';
          });
          // 清理开头/结尾的顿号
          val = val.replace(/^、|、$/g, '');
          textarea.value = val;
        } else {
          // 选中：追加到文本
          this.classList.add('selected');
          if (textarea.value.length > 0 && textarea.value.length < 200) {
            textarea.value += '、' + text;
          } else if (textarea.value.length === 0) {
            textarea.value = text;
          }
          // 限制200字
          if (textarea.value.length > 200) {
            textarea.value = textarea.value.substring(0, 200);
          }
        }
        document.getElementById('noteCharCount').textContent = textarea.value.length + '/200';
      });
    });

    // 确认备注
    document.getElementById('noteConfirmBtn').addEventListener('click', function() {
      detailCurrentNote = document.getElementById('notePageTextarea').value.trim();
      updateNotePreview();
      closeNotePage();
    });
  }

  // 为产品卡片添加事件监听器（新版：点击整个卡片添加）
  function attachProductEventListeners(container) {
    if (!container) return;
    
    // 检查是否已经附加过事件监听器
    if (container.dataset.eventListenerAttached === 'true') {
      return;
    }
    
    // 先移除可能存在的旧事件监听器
    if (container._clickHandler) {
      container.removeEventListener('click', container._clickHandler);
    }
    
    // 标记已附加事件监听器
    container.dataset.eventListenerAttached = 'true';
    
    // 创建事件处理函数并保存到容器属性中
    const clickHandler = function(e) {
      // 🔥 仅菜单浏览模式：屏蔽所有交互
      if (MENU_ONLY_MODE) return;

      // 检查是否点击了 qty-btn（+/- 按钮）
      const qtyBtn = e.target.closest('.qty-btn');
      if (qtyBtn) {
        e.stopPropagation();
        e.preventDefault();
        const productId = qtyBtn.dataset.id;
        if (!productId) return;

        const productCard = qtyBtn.closest('.product-card, .points-product-card');
        if (!productCard) return;

        if (qtyBtn.classList.contains('qty-plus')) {
          if (isProductCardCategoryLocked(productCard)) {
            showCategoryTimeLockedToast(productCard.dataset.category || '');
            updateOrderLimitUI();
            return;
          }
          if (maxOrderQty > 0 && getRemainingOrderQuantity() <= 0) {
            showMaxOrderLimitToast();
            updateOrderLimitUI();
            return;
          }
          // 增加数量
          const cartItem = cart.find(item => item.id === productId);
          if (cartItem) {
            cartItem.quantity++;
            saveCart();
            renderCart();
            updateProductQuantityDisplay(productId);
          }
        } else if (qtyBtn.classList.contains('qty-minus')) {
          // 减少数量
          const cartItemIndex = cart.findIndex(item => item.id === productId);
          if (cartItemIndex !== -1) {
            if (cart[cartItemIndex].quantity > 1) {
              cart[cartItemIndex].quantity--;
            } else {
              // 数量减到0，从购物车移除
              cart.splice(cartItemIndex, 1);
            }
            saveCart();
            renderCart();
            updateProductQuantityDisplay(productId);
          }
        }
        return;
      }

      // 检查是否点击了产品图片（打开产品详情）
      const productImage = e.target.closest('.product-image');
      if (productImage) {
        e.stopPropagation();
        e.preventDefault();
        const card = productImage.closest('.product-card');
        if (card) openProductDetail(card);
        return;
      }

      // 检查是否点击了添加按钮
      const addBtn = e.target.closest('.add-to-cart');
      if (!addBtn) return; // 点击卡片其他区域不执行任何操作
      if (addBtn.classList.contains('category-time-locked-btn')) {
        e.stopPropagation();
        e.preventDefault();
        showCategoryTimeLockedToast(addBtn.dataset.lockedCategoryId || '');
        updateOrderLimitUI();
        return;
      }
      if (addBtn.disabled || addBtn.classList.contains('limit-locked')) {
        e.stopPropagation();
        e.preventDefault();
        showMaxOrderLimitToast();
        updateOrderLimitUI();
        return;
      }

      const productCard = addBtn.closest('.product-card, .points-product-card');
      if (!productCard) return;

      // 阻止事件冒泡
      e.stopPropagation();

      // 获取产品信息
      const productId = productCard.dataset.id;
      const productName = productCard.dataset.name;
      const productPrice = productCard.dataset.price;
      const productImg = productCard.dataset.image;
      const pointsPrice = productCard.dataset.pointsPrice;
      const productNameEs = productCard.dataset.nameEs || productName;
      const productNameZh = productCard.dataset.nameZh || '';
      const productNameEn = productCard.dataset.nameEn || '';
      const productCategoryId = productCard.dataset.category || '';
      const productBasePrice = productCard.dataset.basePrice || productPrice;
      const productZoneCategoryId = productCard.dataset.zoneCategoryId || tableZoneCategoryId || '';

      if (!productId) return;

      // 添加产品到购物车
      if (pointsPrice) {
        // 积分产品
        const product = {
          id: productId,
          name: productName,
          points_price: parseInt(pointsPrice)
        };
        if (addPointsProductToCart(product)) {
          addBtn.classList.add('adding');
          setTimeout(() => addBtn.classList.remove('adding'), 240);
        }
      } else {
        // 普通产品
        const added = addToCart(productId, productName, parseFloat(productPrice), productImg, productNameEs, productNameZh, productNameEn, productCategoryId, productBasePrice, productZoneCategoryId);
        if (added) {
          addBtn.classList.add('adding');
          setTimeout(() => addBtn.classList.remove('adding'), 240);
          // 添加后显示 qty-controls
          showQtyControls(productId);
        }
      }
    };
    
    // 保存事件处理函数并添加事件监听器
    container._clickHandler = clickHandler;
    container.addEventListener('click', clickHandler);
    updateOrderLimitUI();
  }

  // 显示产品卡片上的数量控制器
  function showQtyControls(productId) {
    const qtyControls = document.getElementById(`qty-controls-${productId}`);
    if (qtyControls) {
      qtyControls.classList.add('show');
      qtyControls.classList.add('qty-reveal');
      setTimeout(() => qtyControls.classList.remove('qty-reveal'), 220);
    }
    // 隐藏对应的 add-to-cart 按钮
    const productCards = document.querySelectorAll(`[data-id="${productId}"]`);
    productCards.forEach(card => {
      const addBtn = card.querySelector('.add-to-cart');
      if (addBtn) {
        addBtn.classList.add('has-qty');
      }
    });
  }

  // 隐藏产品卡片上的数量控制器
  function hideQtyControls(productId) {
    const qtyControls = document.getElementById(`qty-controls-${productId}`);
    if (qtyControls) {
      qtyControls.classList.remove('show');
    }
    // 恢复对应的 add-to-cart 按钮
    const productCards = document.querySelectorAll(`[data-id="${productId}"]`);
    productCards.forEach(card => {
      const addBtn = card.querySelector('.add-to-cart');
      if (addBtn) {
        addBtn.classList.remove('has-qty');
      }
    });
  }

  // 更新产品数量显示
  function updateProductQuantityDisplay(productId) {
    const cartItem = cart.find(item => item.id === productId);
    const quantity = cartItem ? cartItem.quantity : 0;

    // 更新所有模块中该产品的数量显示（旧版 quantity-controls）
    const quantityDisplays = document.querySelectorAll(`.quantity-controls-${productId} .quantity-display`);
    quantityDisplays.forEach(display => {
      display.textContent = quantity;
    });

    // 更新卡片内嵌数量控制器的数字
    const qtyValueEl = document.getElementById(`qty-value-${productId}`);
    if (qtyValueEl) {
      qtyValueEl.textContent = quantity > 0 ? quantity : 1;
    }

    // 根据数量决定显示/隐藏 qty-controls
    if (quantity > 0) {
      showQtyControls(productId);
    } else {
      hideQtyControls(productId);
    }

    // 强制更新UI显示状态（旧版 quantity-controls 兼容）
    const productCards = document.querySelectorAll(`[data-id="${productId}"]`);
    productCards.forEach(card => {
      const quantityControls = card.querySelector(`.quantity-controls-${productId}`);
      const addButton = card.querySelector(`.add-first-btn-${productId}`);

      if (quantityControls && addButton) {
        if (quantity > 0) {
          quantityControls.classList.remove('hidden');
          addButton.classList.add('hidden');
        } else {
          quantityControls.classList.add('hidden');
          addButton.classList.remove('hidden');
        }
      }
    });
  }

  // 重置所有产品的状态到初始状态（显示添加按钮，隐藏数量控制器）
  function resetAllProductStates() {
    // 获取所有产品卡片
    const productCards = document.querySelectorAll('.product-card, .points-product-card');

    productCards.forEach(card => {
      const productId = card.dataset.id;

      // 隐藏卡片内嵌 qty-controls
      if (productId) {
        hideQtyControls(productId);
      }

      // 找到所有的数量控制器和添加按钮（旧版兼容）
      const quantityControls = card.querySelectorAll('[class*="quantity-controls-"]');
      const addButtons = card.querySelectorAll('[class*="add-first-btn-"]');

      // 隐藏所有数量控制器
      quantityControls.forEach(control => {
        control.classList.add('hidden');
      });

      // 显示所有添加按钮
      addButtons.forEach(button => {
        button.classList.remove('hidden');
      });

      // 重置数量显示为0
      const quantityDisplays = card.querySelectorAll('.quantity-display');
      quantityDisplays.forEach(display => {
        display.textContent = '0';
      });
    });

    updateOrderLimitUI();
  }

  // 处理购物车按钮点击事件
  function handleCartButtonClick(e) {
    const target = e.target.closest('.decrease-btn, .increase-btn');
    if (!target) return;

    e.stopPropagation();

    const index = parseInt(target.dataset.index);
    if (isNaN(index) || index < 0 || index >= cart.length) return;

    if (target.classList.contains('decrease-btn')) {
      removeFromCart(index);
    } else if (target.classList.contains('increase-btn')) {
      if (maxOrderQty > 0 && getRemainingOrderQuantity() <= 0) {
        showMaxOrderLimitToast();
        updateOrderLimitUI();
        return;
      }

      const item = cart[index];
      if (item) {
        if (item.is_points_product) {
          addPointsProductToCart({
            id: item.id,
            name: item.name,
            points_price: parseInt(item.points_price, 10) || 0
          });
        } else {
          const itemBasePrice = item.basePrice !== undefined ? item.basePrice : (item.originalPrice !== undefined ? item.originalPrice : item.price);
          addToCart(item.id, item.name, item.price, item.image, item.name_es, item.name_zh, item.name_en, item.category_id || getProductCategoryId(item.id), itemBasePrice, item.zoneCategoryId || item.zone_category_id || tableZoneCategoryId || '');
        }
      }
    }
  }

  // =============================================
  // 🔥 产品备注弹窗系统
  // =============================================
  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function openProductNotesModal() {
    const modal = document.getElementById('product-notes-modal');
    const panel = document.getElementById('product-notes-panel');
    const list = document.getElementById('notes-product-list');
    if (!modal || !panel || !list) return;

    // 只显示非积分产品
    const regularItems = cart.filter(item => !item.is_points_product);
    if (regularItems.length === 0) return;

    // 生成产品列表 - Redesign
    list.innerHTML = regularItems.map((item, idx) => {
      const cartIndex = cart.indexOf(item);
      const hasNotes = item.notes && item.notes.trim();
      const imgHtml = item.image
        ? `<img src="${item.image}" alt="" style="width:44px;height:44px;border-radius:10px;object-fit:cover;flex-shrink:0;" onerror="this.outerHTML='<div style=\\'width:44px;height:44px;border-radius:10px;background:#F5F4F1;display:flex;align-items:center;justify-content:center;flex-shrink:0;\\'><i class=\\'fas fa-utensils\\' style=\\'color:#9C9B99;font-size:16px;\\'></i></div>'">`
        : `<div style="width:44px;height:44px;border-radius:10px;background:#F5F4F1;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas fa-utensils" style="color:#9C9B99;font-size:16px;"></i></div>`;
      const noteBadge = hasNotes
        ? `<div style="display:flex;align-items:center;gap:4px;background:#E8F8EE;border-radius:6px;padding:4px 8px;"><i class="fas fa-comment-dots" style="color:#3D8A5A;font-size:10px;"></i><span style="font-family:'DM Sans',system-ui;font-size:11px;font-weight:500;color:#3D8A5A;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(item.notes.trim())}</span></div>`
        : '';
      return `
        <div class="notes-product-item" data-cart-index="${cartIndex}" style="border-bottom:1px solid #E5E4E1;${hasNotes ? 'background:#FAFAF8;' : ''}">
          <div class="notes-product-header" data-cart-index="${cartIndex}" style="display:flex;align-items:center;gap:12px;padding:14px 16px;cursor:pointer;">
            ${imgHtml}
            <div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:2px;">
              <div style="font-family:'DM Sans',system-ui;font-size:13px;font-weight:500;color:#1A1918;line-height:1.3;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${item.name}</div>
              <div style="font-family:'DM Sans',system-ui;font-size:11px;color:#9C9B99;">x${item.quantity} · ${formatCurrency(item.price * item.quantity)}</div>
              ${noteBadge}
            </div>
            <i class="fas fa-chevron-down notes-chevron" style="color:${hasNotes ? '#E84D25' : '#9C9B99'};font-size:14px;flex-shrink:0;transition:transform 0.2s;${hasNotes ? 'transform:rotate(180deg);' : ''}"></i>
          </div>
          <div class="notes-input-area ${hasNotes ? '' : 'hidden'}" style="padding:0 16px 14px;">
            <div style="position:relative;">
              <textarea class="notes-modal-input" data-cart-index="${cartIndex}" maxlength="200" rows="3" placeholder="<?php echo ruiyi_translate('Ej: Sin wasabi por favor, alergia...', 'e.g. No wasabi please, allergy...', '例如：不要芥末，过敏...'); ?>" style="width:100%;padding:10px 14px;border:1.5px solid #E84D25;border-radius:12px;font-family:'DM Sans',system-ui;font-size:13px;color:#1A1918;line-height:1.4;resize:none;outline:none;background:#FFFFFF;box-sizing:border-box;">${hasNotes ? item.notes.trim() : ''}</textarea>
              <div style="display:flex;justify-content:flex-end;margin-top:4px;">
                <span class="notes-char-count" style="font-family:'DM Sans',system-ui;font-size:11px;color:#9C9B99;">${hasNotes ? item.notes.trim().length : 0}/200</span>
              </div>
            </div>
          </div>
        </div>
      `;
    }).join('');
    // 移除最后一个子元素的底部边框
    const lastItem = list.querySelector('.notes-product-item:last-child');
    if (lastItem) lastItem.style.borderBottom = 'none';

    // 更新底部备注计数
    updateNotesSummaryRow();

    // 显示弹窗
    modal.classList.remove('hidden');
    modal.style.display = '';
    // 触发动画
    requestAnimationFrame(() => {
      panel.style.transform = 'translateY(0)';
    });

    // 如果只有一个产品且没有备注，自动展开
    if (regularItems.length === 1 && !regularItems[0].notes) {
      const firstItem = list.querySelector('.notes-product-item');
      if (firstItem) {
        toggleNotesItem(firstItem);
        const input = firstItem.querySelector('.notes-modal-input');
        if (input) setTimeout(() => input.focus(), 350);
      }
    }
  }

  function updateNotesSummaryRow() {
    const count = cart.filter(item => !item.is_points_product && item.notes && item.notes.trim()).length;
    const summaryRow = document.getElementById('notes-summary-row');
    const summaryText = document.getElementById('notes-summary-text');
    if (summaryRow && summaryText) {
      const label = currentLang === 'es' ? 'notas añadidas' : currentLang === 'en' ? 'notes added' : '条备注已添加';
      summaryText.textContent = count + ' ' + label;
      summaryRow.style.display = count > 0 ? 'flex' : 'none';
    }
  }

  function closeProductNotesModal() {
    const modal = document.getElementById('product-notes-modal');
    const panel = document.getElementById('product-notes-panel');
    if (!modal || !panel) return;

    panel.style.transform = 'translateY(100%)';
    setTimeout(() => {
      modal.classList.add('hidden');
    }, 300);

    // 保存后更新购物车显示
    renderCart();
    updateNotesCountBadge();
  }

  function toggleNotesItem(itemEl) {
    const inputArea = itemEl.querySelector('.notes-input-area');
    const chevron = itemEl.querySelector('.notes-chevron');
    if (!inputArea) return;

    const isHidden = inputArea.classList.contains('hidden');
    if (isHidden) {
      inputArea.classList.remove('hidden');
      if (chevron) chevron.style.transform = 'rotate(180deg)';
      const textarea = inputArea.querySelector('.notes-modal-input');
      if (textarea) setTimeout(() => textarea.focus(), 100);
    } else {
      inputArea.classList.add('hidden');
      if (chevron) chevron.style.transform = '';
    }
  }

  function saveNoteFromModal(cartIndex, value) {
    if (cartIndex < 0 || cartIndex >= cart.length) return;
    cart[cartIndex].notes = value.trim();
    saveCart();
  }

  function updateNotesCountBadge() {
    const count = cart.filter(item => !item.is_points_product && item.notes && item.notes.trim()).length;
    ['side-notes-count-badge', 'inline-notes-count-badge'].forEach(id => {
      const badge = document.getElementById(id);
      if (badge) {
        if (count > 0) {
          badge.textContent = count;
          badge.classList.remove('hidden');
          badge.classList.add('flex');
        } else {
          badge.classList.add('hidden');
          badge.classList.remove('flex');
        }
      }
    });
  }

  // 弹窗事件绑定
  document.addEventListener('DOMContentLoaded', function() {
    // 打开弹窗
    ['side-add-notes-btn', 'inline-add-notes-btn'].forEach(id => {
      const btn = document.getElementById(id);
      if (btn) btn.addEventListener('click', openProductNotesModal);
    });

    // 关闭弹窗
    const closeBtn = document.getElementById('close-notes-modal');
    const doneBtn = document.getElementById('done-notes-btn');
    const overlay = document.getElementById('product-notes-overlay');
    if (closeBtn) closeBtn.addEventListener('click', closeProductNotesModal);
    if (doneBtn) doneBtn.addEventListener('click', closeProductNotesModal);
    if (overlay) overlay.addEventListener('click', closeProductNotesModal);

    // 产品列表事件代理
    const notesList = document.getElementById('notes-product-list');
    if (notesList) {
      // 点击展开/收起
      notesList.addEventListener('click', function(e) {
        const header = e.target.closest('.notes-product-header');
        if (header) {
          const itemEl = header.closest('.notes-product-item');
          if (itemEl) toggleNotesItem(itemEl);
          return;
        }
      });

      // 输入事件 - 实时保存 + 更新字数统计
      notesList.addEventListener('input', function(e) {
        const textarea = e.target.closest('.notes-modal-input');
        if (!textarea) return;
        const cartIndex = parseInt(textarea.dataset.cartIndex);
        saveNoteFromModal(cartIndex, textarea.value);
        // 更新字数统计
        const charCount = textarea.closest('.notes-input-area')?.querySelector('.notes-char-count');
        if (charCount) charCount.textContent = textarea.value.length + '/200';
        // 更新底部备注计数
        updateNotesSummaryRow();
        // 更新展开头部的备注预览（badge）
        const itemEl = textarea.closest('.notes-product-item');
        if (itemEl) {
          const headerEl = itemEl.querySelector('.notes-product-header');
          const chevron = headerEl?.querySelector('.notes-chevron');
          if (textarea.value.trim()) {
            itemEl.style.background = '#FAFAF8';
            if (chevron) chevron.style.color = '#E84D25';
          } else {
            itemEl.style.background = '';
            if (chevron) chevron.style.color = '#9C9B99';
          }
        }
      });
    }

    // 初始化badge
    updateNotesCountBadge();
  });

  // Calcular totales
  function calculateTotals() {
    // 计算货币总额（不包括积分产品和自助餐产品）
    subtotal = cart.reduce((total, item) => {
      if (item.is_points_product) return total; // 跳过积分产品
      if (item.is_buffet) return total; // 🍽️ 跳过自助餐产品
      const itemPrice = parseFloat(item.price) || 0;
      const itemQuantity = parseInt(item.quantity) || 0;
      return total + (itemPrice * itemQuantity);
    }, 0);
    total = subtotal; // 价格已包含税费，不再单独计算增值税
    
    // 计算积分总额
    const totalPoints = cart.reduce((total, item) => {
      if (!item.is_points_product) return total; // 跳过普通产品
      const itemPoints = parseInt(item.points_price) || 0;
      const itemQuantity = parseInt(item.quantity) || 0;
      return total + (itemPoints * itemQuantity);
    }, 0);
    
    // 更新购物车的小计和总计
    sideCartSubtotal.textContent = formatCurrency(subtotal);
    inlineCartSubtotal.textContent = formatCurrency(subtotal);
    sideCartTotal.textContent = formatCurrency(total);
    inlineCartTotal.textContent = formatCurrency(total);
    if (mobileCartTotal) mobileCartTotal.textContent = formatCurrency(total);
    
    // 如果有积分产品，显示积分信息
    if (totalPoints > 0) {
      // 在总计下方添加积分信息（显示为负数表示扣除）
      const pointsInfo = ` -${totalPoints} ${t('points')}`;
      sideCartTotal.innerHTML = formatCurrency(total) + '<br><small class="text-blue-600">' + pointsInfo + '</small>';
      inlineCartTotal.innerHTML = formatCurrency(total) + '<br><small class="text-blue-600">' + pointsInfo + '</small>';
      if (mobileCartTotal) mobileCartTotal.innerHTML = formatCurrency(total) + '<br><small class="text-blue-600">' + pointsInfo + '</small>';
      
      if (bottomCartTotal) {
        bottomCartTotal.innerHTML = formatCurrency(total) + '<br><small class="text-blue-600">' + pointsInfo + '</small>';
      }
    }
    
    // Actualizar bottom bar si existe
    if (bottomCartTotal && totalPoints === 0) {
      bottomCartTotal.textContent = formatCurrency(total);
    }
    
    // 同步积分显示到购物车
    if (currentUser && realUserPoints > 0) {
      syncPointsWithCart();
    }
  }

  // Formatear moneda
  function formatCurrency(value) {
    // 确保值是有效数字
    const numValue = parseFloat(value);
    
    // 如果不是有效数字，返回 €0.00
    if (isNaN(numValue) || numValue === null || numValue === undefined) {
      return '€0.00';
    }
    
    return '€' + numValue.toFixed(2);
  }

  // Filtrar productos por categoría
  function filterProducts(categoryId, categoryName = '') {
    const discountSection = document.getElementById('discount-products-section');
    const popularSection = document.getElementById('popular-products-section');
    const pointsSection = document.getElementById('points-products-section');
    const regularSection = document.querySelector('.content-section:has(#products-container)');
    const productsContainer = document.getElementById('products-container');
    const regularSectionTitle = document.getElementById('regular-section-title');
    
    // 滚动产品区域到顶部
    const productArea = document.querySelector('.v5-product-area');
    if (productArea) productArea.scrollTo({ top: 0, behavior: 'smooth' });
    
    // 隐藏所有模块
    if (discountSection) discountSection.classList.add('hidden');
    if (popularSection) popularSection.classList.add('hidden');
    if (pointsSection) pointsSection.classList.add('hidden');
    if (regularSection) regularSection.classList.add('hidden');
    
    if (categoryId === '0') {
      updateCategoryTimeNotice(null);
      // 显示"所有产品"模块，隐藏特殊模块
      if (regularSection) regularSection.classList.remove('hidden');
      
      // 恢复默认标题
      if (regularSectionTitle) {
        regularSectionTitle.textContent = '<?php echo ruiyi_translate('Todos los Productos', 'All Products', '所有产品'); ?>';
      }
      
      // 显示所有常规产品
      document.querySelectorAll('#products-container .product-card').forEach(card => {
        card.classList.remove('hidden');
      });
    } else if (categoryId === 'popular' || categoryId === '热门') {
      updateCategoryTimeNotice(null);
      // 只显示热门产品模块，隐藏"所有产品"
      if (popularSection) {
        popularSection.classList.remove('hidden');
        // 如果热门产品还未加载，加载它们
        if (!popularSection.querySelector('#popular-products-container').children.length) {
          loadPopularProducts();
        }
      }
    } else if (categoryId === 'discount' || categoryId === '折扣') {
      updateCategoryTimeNotice(null);
      // 只显示折扣产品模块，隐藏"所有产品"
      if (discountSection) {
        discountSection.classList.remove('hidden');
        // 如果折扣产品还未加载，加载它们
        if (!discountSection.querySelector('#discount-products-container').children.length) {
          loadDiscountProducts();
        }
      }
    } else if (categoryId === 'points' || categoryId === '积分') {
      updateCategoryTimeNotice(null);
      // 只显示积分产品模块，隐藏"所有产品"
      if (pointsSection) {
        pointsSection.classList.remove('hidden');
        // 如果积分产品还未加载，先尝试加载
        const pointsContainer = document.getElementById('points-products-container');
        if (pointsContainer && !pointsContainer.children.length) {
          loadPointsProducts();
        }
      }
    } else {
      updateCategoryTimeNotice(categoryId);
      // 常规分类过滤 - 显示"所有产品"模块，按分类筛选
      if (regularSection) regularSection.classList.remove('hidden');
      
      // 更新标题为分类名称
      if (regularSectionTitle && categoryName) {
        regularSectionTitle.textContent = categoryName;
      }
      
      // 按分类过滤常规产品
      document.querySelectorAll('#products-container .product-card').forEach(card => {
        const productCategoryId = card.dataset.category || '';
        if (productCategoryId === categoryId) {
          card.classList.remove('hidden');
        } else {
          card.classList.add('hidden');
        }
      });
    }
    applyCategoryTimeLocks();
  }

  // Search products by name and optionally filter by category
  function searchProducts(searchTerm, categoryFilter = '0') {
    const discountSection = document.getElementById('discount-products-section');
    const popularSection = document.getElementById('popular-products-section');
    const pointsSection = document.getElementById('points-products-section');
    updateCategoryTimeNotice(categoryFilter === '0' ? null : categoryFilter);
    
    // Siempre ocultar secciones especiales durante la búsqueda
    if (discountSection) discountSection.style.display = 'none';
    if (popularSection) popularSection.style.display = 'none';
    if (pointsSection) pointsSection.style.display = 'none';
    
    const allCards = document.querySelectorAll('#products-container .product-card');
    let visibleCount = 0;
    
    allCards.forEach(card => {
      const productName = (card.dataset.name || '').toLowerCase();
      const productCategoryId = card.dataset.category || '';
      
      // Check if product matches search term
      const matchesSearch = productName.includes(searchTerm);
      
      // Check if product matches category filter (if not "All")
      const matchesCategory = categoryFilter === '0' || productCategoryId === categoryFilter;
      
      // Show product if it matches both search and category
      if (matchesSearch && matchesCategory) {
        card.classList.remove('hidden');
        visibleCount++;
      } else {
        card.classList.add('hidden');
      }
    });
    
    // Update search results info
    updateSearchResultsInfo(searchTerm, visibleCount);
    applyCategoryTimeLocks();
  }

  // Update search results information
  function updateSearchResultsInfo(searchTerm, resultCount) {
    const searchResultsInfo = document.getElementById('search-results-info');
    
    if (searchTerm && searchResultsInfo) {
      const currentLang = '<?php echo esc_js($current_lang); ?>';
      let message = '';
      
      if (resultCount === 0) {
        message = `${t('no_products_found')} "${searchTerm}"${t('products_found_suffix')}`;
      } else {
        if (currentLang === 'zh') {
          message = `找到 ${resultCount} ${t('products_found')}"${searchTerm}"${t('products_found_suffix')}`;
        } else if (currentLang === 'es') {
          message = `${resultCount} producto${resultCount > 1 ? 's' : ''} encontrado${resultCount > 1 ? 's' : ''} para "${searchTerm}"`;
        } else {
          message = `${resultCount} product${resultCount > 1 ? 's' : ''} found for "${searchTerm}"`;
        }
      }
      
      searchResultsInfo.textContent = message;
      searchResultsInfo.classList.remove('hidden');
    }
  }

  // 🔥 防重复提交标志
  let isSubmittingOrder = false;

  // 🔥🔥🔥 【点餐冷却时间 - 防爆单】（设计参考 .pen 文件）
  let cooldownInterval = null;

  // 获取所有 timer bar 元素
  function getAllTimerBars() {
    return [
      document.getElementById('side-cart-timer-bar'),
      document.getElementById('inline-cart-timer-bar'),
      document.getElementById('notes-timer-bar'),
      document.getElementById('main-page-timer-bar')
    ];
  }

  // 更新所有 timer bar 上的倒计时文字
  function updateAllTimerBars(timeStr) {
    getAllTimerBars().forEach(bar => {
      if (bar) {
        const timeEl = bar.querySelector('.timer-time');
        if (timeEl) timeEl.textContent = timeStr;
      }
    });
    // 同时更新成功弹窗中的计时器
    const successTimerTime = document.getElementById('success-timer-time');
    if (successTimerTime) successTimerTime.textContent = timeStr;
  }

  // 显示/隐藏所有 timer bars
  function setTimerBarsVisible(visible) {
    getAllTimerBars().forEach(bar => {
      if (bar) {
        if (visible) {
          bar.classList.add('active');
        } else {
          bar.classList.remove('active');
        }
      }
    });
    // 成功弹窗中的计时器区域
    const successTimerSection = document.getElementById('success-timer-section');
    if (successTimerSection) {
      successTimerSection.style.display = visible ? 'flex' : 'none';
    }
    // 主页面 timer bar 可见时，给 mobile-bottom-nav 和 floating-cart-btn 加底部间距
    const mainBar = document.getElementById('main-page-timer-bar');
    const mobileNav = document.querySelector('.mobile-bottom-nav');
    const floatingBtn = document.getElementById('floating-cart-btn');
    if (visible && mainBar) {
      if (mobileNav) mobileNav.style.marginBottom = '40px';
      if (floatingBtn) floatingBtn.style.marginBottom = '40px';
    } else {
      if (mobileNav) mobileNav.style.marginBottom = '';
      if (floatingBtn) floatingBtn.style.marginBottom = '';
    }
  }

  // 检查是否在冷却期内
  function checkOrderCooldown() {
    if (orderCooldownMinutes <= 0) return true;
    const lastOrderTime = parseInt(localStorage.getItem('last_order_time_' + tableFullId) || '0', 10);
    if (!lastOrderTime) return true;
    const cooldownMs = orderCooldownMinutes * 60 * 1000;
    const remainingMs = cooldownMs - (Date.now() - lastOrderTime);
    if (remainingMs > 0) {
      const remainMin = Math.floor(remainingMs / 60000);
      const remainSec = Math.floor((remainingMs % 60000) / 1000);
      const timeStr = String(remainMin).padStart(2, '0') + ':' + String(remainSec).padStart(2, '0');
      const currentLang = '<?php echo esc_js($current_lang); ?>';
      let msg = '';
      if (currentLang === 'es') {
        msg = 'Debe esperar ' + timeStr + ' antes de enviar otro pedido.';
      } else if (currentLang === 'en') {
        msg = 'Please wait ' + timeStr + ' before sending another order.';
      } else {
        msg = '请等待 ' + timeStr + ' 后再发送订单。';
      }
      Swal.fire({
        title: currentLang === 'es' ? 'Tiempo de espera' : (currentLang === 'en' ? 'Please wait' : '请稍候'),
        text: msg,
        icon: 'warning',
        confirmButtonColor: '#E84D25'
      });
      return false;
    }
    return true;
  }

  // 启动冷却倒计时（设计：timer bars + 按钮变灰 + lock 图标）
  // saveTimestamp=true 仅在新下单时传入；页面刷新恢复时传 false，不覆盖原始时间戳
  function startCooldownTimer(saveTimestamp = true) {
    if (saveTimestamp) {
      localStorage.setItem('last_order_time_' + tableFullId, Date.now().toString());
    }

    const currentLang = '<?php echo esc_js($current_lang); ?>';
    const inlineBtn = document.getElementById('inline-send-order-btn');
    const sideBtn = document.getElementById('send-order-btn');
    const mobileBtn = document.getElementById('mobile-send-order-btn');

    // 禁用按钮并添加 cooldown-disabled 样式
    [inlineBtn, sideBtn].forEach(btn => {
      if (btn) {
        btn.disabled = true;
        btn.classList.add('cooldown-disabled');
      }
    });
    if (mobileBtn) {
      mobileBtn.disabled = true;
      mobileBtn.classList.add('cooldown-disabled');
    }

    // 显示所有 timer bars
    setTimerBarsVisible(true);

    if (cooldownInterval) clearInterval(cooldownInterval);

    // 立即执行一次更新
    function updateCooldownUI() {
      const lastOrderTime = parseInt(localStorage.getItem('last_order_time_' + tableFullId) || '0', 10);
      const cooldownMs = orderCooldownMinutes * 60 * 1000;
      const remainingMs = cooldownMs - (Date.now() - lastOrderTime);
      if (remainingMs <= 0) {
        restoreSendButtons();
        return;
      }
      const remainMin = Math.floor(remainingMs / 60000);
      const remainSec = Math.floor((remainingMs % 60000) / 1000);
      const timeStr = String(remainMin).padStart(2, '0') + ':' + String(remainSec).padStart(2, '0');

      // 更新所有 timer bars 的时间
      updateAllTimerBars(timeStr);

      // 更新提交按钮文字：🔒 Enviar Pedido · MM:SS
      const fullLabel = currentLang === 'es' ? 'Enviar Pedido' : (currentLang === 'en' ? 'Send Order' : '提交订单');
      const btnText = fullLabel + ' · ' + timeStr;
      [inlineBtn, sideBtn].forEach(btn => {
        if (btn) btn.innerHTML = '<i class="fas fa-lock"></i> <span>' + btnText + '</span>';
      });
      if (mobileBtn) {
        mobileBtn.innerHTML = '<i class="fas fa-lock" style="margin-right:4px;font-size:12px;"></i>' + timeStr;
      }
    }

    updateCooldownUI();
    cooldownInterval = setInterval(updateCooldownUI, 1000);
  }

  // 恢复发送按钮和隐藏 timer bars
  function restoreSendButtons() {
    if (cooldownInterval) {
      clearInterval(cooldownInterval);
      cooldownInterval = null;
    }
    const currentLang = '<?php echo esc_js($current_lang); ?>';
    const inlineBtn = document.getElementById('inline-send-order-btn');
    const sideBtn = document.getElementById('send-order-btn');
    const mobileBtn = document.getElementById('mobile-send-order-btn');
    const fullLabel = currentLang === 'es' ? 'Enviar Pedido' : (currentLang === 'en' ? 'Send Order' : '提交订单');
    const shortLabel = currentLang === 'es' ? 'Enviar' : (currentLang === 'en' ? 'Send' : '发送');

    [inlineBtn, sideBtn].forEach(btn => {
      if (btn) {
        btn.disabled = false;
        btn.classList.remove('cooldown-disabled');
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> <span>' + fullLabel + '</span>';
      }
    });
    if (mobileBtn) {
      mobileBtn.disabled = false;
      mobileBtn.classList.remove('cooldown-disabled');
      mobileBtn.textContent = shortLabel;
    }

    // 隐藏所有 timer bars
    setTimerBarsVisible(false);
  }

  // Enviar pedido a cocina
  function sendOrder() {
    if (MENU_ONLY_MODE) return; // 🔥 仅菜单浏览模式
    // 🔥 防止重复提交（双重点击、重复事件监听等）
    if (isSubmittingOrder) {
      console.warn('[sendOrder] 订单正在提交中，忽略重复调用');
      return;
    }

    // 🔥 点餐冷却检查（防爆单）
    if (orderCooldownMinutes > 0 && !checkOrderCooldown()) {
      return;
    }

    // 🍽️ 自助餐模式：桌位未开台不允许发送订单
    if (buffetConfig.enabled && !buffetConfig.tableExempt && !buffetTableOpened) {
      Swal.fire({
        title: t('buffetTableNotOpened'),
        text: t('buffetTableNotOpenedMsg'),
        icon: 'warning',
        confirmButtonColor: '#E84D25'
      });
      return;
    }

    const hasRegularProducts = cart.filter(item => !item.is_points_product).length > 0;
    const hasPointsProducts = cart.filter(item => item.is_points_product).length > 0;

    if (!hasRegularProducts && !hasPointsProducts) {
      Swal.fire({
        title: t('cartEmpty'),
        text: t('addProducts'),
        icon: 'warning',
        confirmButtonColor: '#f13400'
      });
      return;
    }

    // Close side cart when sending order
    document.body.classList.remove('cart-open');

    // 🔥 扫码点餐最低商品数量验证
    const totalQty = cart.reduce((sum, item) => sum + item.quantity, 0);
    if (minOrderQty > 0 && totalQty < minOrderQty) {
      Swal.fire({
        title: t('minimumOrderTitle') || 'Pedido mínimo',
        text: (t('minimumOrderMessage') || 'Debes agregar al menos {min} productos para enviar el pedido.').replace('{min}', minOrderQty),
        icon: 'warning',
        confirmButtonColor: '#f13400'
      });
      return;
    }

    // 🔥 扫码点餐最高商品数量验证
    if (maxOrderQty > 0 && totalQty > maxOrderQty) {
      Swal.fire({
        title: t('maximumOrderTitle') || 'Límite de pedido',
        text: (t('maximumOrderMessage') || 'Solo puedes agregar un máximo de {max} productos por pedido.').replace('{max}', maxOrderQty),
        icon: 'warning',
        confirmButtonColor: '#f13400'
      });
      return;
    }

    const lockedCartItems = getLockedCartItems();
    if (lockedCartItems.length > 0) {
      const categoryId = lockedCartItems[0].category_id || getProductCategoryId(lockedCartItems[0].id);
      const rule = getCategoryTimeRule(categoryId);
      Swal.fire({
        title: t('error'),
        text: rule ? categoryTimeText('locked', rule) : '',
        icon: 'warning',
        confirmButtonColor: '#f13400'
      });
      return;
    }

    // 🔥 立即设置标志，防止后续重复调用
    isSubmittingOrder = true;

    // 直接发送订单，无需确认
    createOrder('cod', 0);
  }

  // Modal de Pago
  function payOrder() {
    const hasRegularProducts = cart.filter(item => !item.is_points_product).length > 0;
    const hasPointsProducts = cart.filter(item => item.is_points_product).length > 0;
    
    if (!hasRegularProducts && !hasPointsProducts) {
      Swal.fire({
        title: t('cartEmpty'),
        text: t('noProducts'),
        icon: 'warning',
        confirmButtonColor: '#f13400'
      });
      return;
    }

    const lockedCartItems = getLockedCartItems();
    if (lockedCartItems.length > 0) {
      const categoryId = lockedCartItems[0].category_id || getProductCategoryId(lockedCartItems[0].id);
      const rule = getCategoryTimeRule(categoryId);
      Swal.fire({
        title: t('error'),
        text: rule ? categoryTimeText('locked', rule) : '',
        icon: 'warning',
        confirmButtonColor: '#f13400'
      });
      return;
    }
    
    // Close side cart when opening payment modal
    document.body.classList.remove('cart-open');
    
    // Opciones de pago
    let selectedPaymentMethod = '';
    let tipAmount = 0;

    Swal.fire({
      title: t('processPayment'),
      html: `
        <div class="space-y-4 text-left">
          <div class="mb-4">
            <h3 class="font-bold mb-2">${t('selectPayment')}</h3>
            <div class="grid grid-cols-2 gap-2">
              <button id="pay-cash" class="payment-method p-2 border border-gray-300 rounded hover:bg-gray-100">
                <i class="fas fa-money-bill text-green-600 mr-1"></i> ${t('cash')}
              </button>
              <button id="pay-card" class="payment-method p-2 border border-gray-300 rounded hover:bg-gray-100">
                <i class="fas fa-credit-card text-blue-600 mr-1"></i> ${t('card')}
              </button>
            </div>
          </div>
          <div>
            <h3 class="font-bold mb-2">${t('tipOptional')}</h3>
            <div class="grid grid-cols-4 gap-2">
              <button class="tip-btn p-2 border border-gray-300 rounded hover:bg-gray-100" data-tip="0">0%</button>
              <button class="tip-btn p-2 border border-gray-300 rounded hover:bg-gray-100" data-tip="5">5%</button>
              <button class="tip-btn p-2 border border-gray-300 rounded hover:bg-gray-100" data-tip="10">10%</button>
              <button class="tip-btn p-2 border border-gray-300 rounded hover:bg-gray-100" data-tip="15">15%</button>
            </div>
          </div>
          <div>
            <h3 class="font-bold mb-2">${t('orderNotes')} (${t('optional')})</h3>
            <textarea id="customer-order-notes" class="w-full p-2 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-500" rows="2" placeholder="${t('addRemarksPlaceholder')}"></textarea>
          </div>
          <div class="mt-4 border-t pt-4">
            <div class="flex justify-between">
              <span>${t('subtotal')}:</span>
              <span>${formatCurrency(subtotal)}</span>
            </div>
            <div class="flex justify-between">
              <span>${t('tip')}:</span>
              <span id="payment-tip">${formatCurrency(0)}</span>
            </div>
            <div class="flex justify-between font-bold">
              <span>${t('total')}:</span>
              <span id="payment-total">${formatCurrency(total)}</span>
            </div>
          </div>
        </div>
      `,
      showCancelButton: true,
      confirmButtonColor: '#0073aa',
      confirmButtonText: t('confirmPayment'),
      cancelButtonText: t('cancel'),
      didOpen: () => {
        // Event listeners para métodos de pago
        document.querySelectorAll('.payment-method').forEach(btn => {
          btn.addEventListener('click', () => {
            document.querySelectorAll('.payment-method').forEach(b => 
              b.classList.remove('bg-gray-200', 'font-bold'));
            btn.classList.add('bg-gray-200', 'font-bold');
            
            if (btn.id === 'pay-cash') {
              selectedPaymentMethod = 'cod';
            } else if (btn.id === 'pay-card') {
              selectedPaymentMethod = 'card';
            }
          });
        });
        
        // Event listeners para propina
        document.querySelectorAll('.tip-btn').forEach(btn => {
          btn.addEventListener('click', () => {
            document.querySelectorAll('.tip-btn').forEach(b => 
              b.classList.remove('bg-gray-200', 'font-bold'));
            btn.classList.add('bg-gray-200', 'font-bold');
            
            const tipPercentage = parseInt(btn.dataset.tip) / 100;
            tipAmount = subtotal * tipPercentage;
            
            document.getElementById('payment-tip').textContent = formatCurrency(tipAmount);
            document.getElementById('payment-total').textContent = formatCurrency(subtotal + tipAmount);
          });
        });
      }
    }).then((result) => {
      if (result.isConfirmed) {
        if (!selectedPaymentMethod) {
          Swal.fire({
            title: t('error'),
            text: t('selectPayMethod'),
            icon: 'error',
            confirmButtonColor: '#f13400'
          });
          return;
        }
        
        // Crear pedido con método de pago y propina
        createOrder(selectedPaymentMethod, tipAmount);
      }
    });
  }

  // Crear Pedido en WooCommerce
  function createOrder(paymentMethod = 'cod', tipAmount = 0) {
    const inputEl = document.querySelector('.table-number-input');
    const tableNumber = tableFullId || inputEl?.dataset?.fullTableId || tableParam || inputEl?.value || 'Cliente Web';

    // Mostrar loader
    Swal.fire({
      title: t('processingOrder'),
      text: t('pleaseWait'),
      allowOutsideClick: false,
      didOpen: () => {
        Swal.showLoading();
      }
    });

    const normalizeOrderItemForSubmit = (item) => {
      const normalized = { ...(item || {}) };
      const effectivePrice = parseFloat(normalized.customPrice !== undefined ? normalized.customPrice : normalized.price);
      const originalPrice = parseFloat(normalized.originalPrice !== undefined ? normalized.originalPrice : (normalized.basePrice !== undefined ? normalized.basePrice : normalized.price));
      const zoneCategoryId = parseInt(normalized.zoneCategoryId || normalized.zone_category_id || tableZoneCategoryId || 0, 10) || 0;
      if (!isNaN(originalPrice) && originalPrice > 0) {
        normalized.originalPrice = originalPrice;
        normalized.basePrice = originalPrice;
      }
      if (zoneCategoryId) {
        normalized.zoneCategoryId = zoneCategoryId;
        normalized.zone_category_id = zoneCategoryId;
      }
      if (!isNaN(effectivePrice) && zoneCategoryId && !isNaN(originalPrice) && Math.abs(effectivePrice - originalPrice) > 0.004) {
        normalized.customPrice = effectivePrice;
      }
      return normalized;
    };

    // 分离普通产品、积分产品和自助餐产品
    const regularItems = cart.filter(item => !item.is_points_product && !item.is_buffet).map(normalizeOrderItemForSubmit);
    const pointsItems = cart.filter(item => item.is_points_product);
    const buffetItems = cart.filter(item => item.is_buffet);

    const orderNotes = document.getElementById('customer-order-notes')?.value.trim() ||
                       document.getElementById('cart-order-notes')?.value.trim() || '';

    // Preparar datos
    const formData = new URLSearchParams();
    formData.append('action', 'ruiyi_pos_create_order');
    formData.append('cart', JSON.stringify(regularItems));
    formData.append('points_items', JSON.stringify(pointsItems));
    if (buffetItems.length > 0) {
        formData.append('buffet_items', JSON.stringify(buffetItems));
    }
    formData.append('nonce', ajaxNonce);
    formData.append('table_number', tableNumber);
    if (tableGlobalNumber) {
      formData.append('table_global_number', tableGlobalNumber);
      formData.append('table_uid', tableUid || `table:${tableGlobalNumber}`);
    }
    if (tableDisplayName) {
      formData.append('table_display_name', tableDisplayName);
    }
    formData.append('order_type', 'servir');
    formData.append('payment_method', paymentMethod);
    formData.append('tip_amount', tipAmount);
    formData.append('order_notes', orderNotes);
    formData.append('is_guest', 'yes');
    formData.append('is_addition', 'true');
    formData.append('source', 'carrito-cliente');

    // Realizar petición AJAX
    fetch(ajaxUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: formData
    })
    .then(response => {
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      return response.json();
    })
    .then(data => {
      if (data.success) {
        // 🍽️ 仅自助餐商品 → 无WC订单创建，直接显示成功
        if (data.data.buffet_only) {
            console.log('[createOrder] 🍽️ buffet_only模式，无WC订单创建');
            // 通过WebSocket通知POS厨房打印
            try {
                if (window._customerSocket && window._customerSocketConnected()) {
                    window._customerSocket.emit('buffet:kitchen_print', {
                        table: tableNumber,
                        items: data.data.newly_added_items || [],
                        source: 'carrito-cliente',
                        site_id: '<?php echo get_current_blog_id(); ?>',
                        timestamp: Date.now()
                    });
                }
            } catch(e) { /* localStorage write for kitchen print metadata - non-critical */ }
            cart = [];
            saveCart();
            updateCartUI();
            isSubmittingOrder = false;
            if (orderCooldownMinutes > 0) { startCooldownTimer(); }
            Swal.fire({
                title: t('orderSent') || '✅',
                text: data.data.message || t('orderSentSuccess'),
                icon: 'success',
                confirmButtonColor: '#E84D25'
            });
            return;
        }

        const orderId = data.data.order_id;
        const orderTotal = data.data.total;
        const isMerged = data.data.is_merged;
        const orderVersion = data.data.version;

        // 更新localStorage中的桌位状态
        let mesaId = tableNumber;
        let tableNumeric = '';

        if (tableNumber && tableNumber !== 'Cliente Web') {
          tableNumeric = tableNumber.replace(/\D/g, '');
          if (/^\d+$/.test(tableNumber)) {
            mesaId = `Mesa ${tableNumber}`;
          }
        }

        if (mesaId && mesaId !== 'Cliente Web' && tableNumeric) {
          const mesasEstado = JSON.parse(localStorage.getItem('mesas_estado') || '{}');

          mesasEstado[mesaId] = {
            estado: 'ocupada',
            orderId: orderId,
            timestamp: new Date().toISOString(),
            total: orderTotal || 0,
            version: orderVersion || (Date.now() + '_0000'),
            orderCount: isMerged ? ((mesasEstado[mesaId]?.orderCount || 0) + 1) : 1,
            last_updated: Date.now(),
            _skipAutoUpdate: true  // 🔥 防止POS轮询在WebSocket/storage事件之前处理此桌位
          };

          localStorage.setItem('mesas_estado', JSON.stringify(mesasEstado));

          // WebSocket 通知 POS 收银页面
          try {
            if (window._customerSocket && window._customerSocketConnected()) {
              window._customerSocket.emit('order:created', {
                table: tableNumber,
                orderId: orderId,
                total: orderTotal,
                is_merged: isMerged,
                version: orderVersion,
                newly_added_items: data.data.newly_added_items || [],
                source: 'carrito-cliente',
                site_id: '<?php echo get_current_blog_id(); ?>',
                timestamp: Date.now()
              });
            }
          } catch (wsErr) {
            // WebSocket 发送失败，依赖服务端推送
          }
        }

        // 保存订单数据用于成功弹窗（在清空购物车之前）
        const orderItemCount = cart.filter(item => !item.is_points_product).reduce((sum, item) => sum + (item.quantity || 1), 0);
        const orderTotalFormatted = formatCurrency(total);

        // Vaciar carrito
        cart = [];
        saveCart();
        updateCartUI();

        // 清空订单备注
        const cartNotesTextarea = document.getElementById('cart-order-notes');
        if (cartNotesTextarea) {
            cartNotesTextarea.value = '';
        }

        // 重置积分显示
        if (currentUser) {
          resetPointsDisplay();
        }

        // 重置所有产品的数量显示状态
        resetAllProductStates();

        // 关闭加载弹窗
        Swal.close();

        // 重置提交标志
        isSubmittingOrder = false;

        // 🔥 启动冷却倒计时（防爆单）
        if (orderCooldownMinutes > 0) {
          startCooldownTimer();
        }

        // 显示自定义成功弹窗
        showOrderSuccessModal(orderId, tableDisplayName || tableNumber, orderItemCount, orderTotalFormatted);
      } else {
        isSubmittingOrder = false;
        let errorMessage = data.data?.message || 'Hubo un problema al procesar su pedido';
        if (Array.isArray(data.data?.locked_category_items) && data.data.locked_category_items.length > 0) {
          errorMessage = categoryTimeText('locked', data.data.locked_category_items[0]);
        }
        Swal.fire({
          title: t('error'),
          text: errorMessage,
          icon: 'error',
          confirmButtonColor: '#f13400'
        });
      }
    })
    .catch(error => {
      isSubmittingOrder = false;
      Swal.fire({
        title: t('error'),
        text: t('connectionError'),
        icon: 'error',
        confirmButtonColor: '#f13400'
      });
    });
  }

  // =============================================
  // 🔥 订单成功弹窗
  // =============================================
  function showOrderSuccessModal(orderId, table, itemCount, totalFormatted) {
    const modal = document.getElementById('order-success-modal');
    const content = document.getElementById('order-success-content');
    if (!modal || !content) return;

    // 填充数据
    const elOrderId = document.getElementById('success-order-id');
    const elTable = document.getElementById('success-table');
    const elItems = document.getElementById('success-items');
    const elTotal = document.getElementById('success-total');
    if (elOrderId) elOrderId.textContent = '#' + orderId;
    if (elTable) elTable.textContent = table || '-';
    if (elItems) elItems.textContent = itemCount;
    if (elTotal) elTotal.textContent = totalFormatted;

    // 🔥 显示/隐藏成功弹窗中的冷却计时器区域
    const successTimerSection = document.getElementById('success-timer-section');
    if (successTimerSection) {
      successTimerSection.style.display = (orderCooldownMinutes > 0) ? 'flex' : 'none';
    }

    // 显示弹窗
    modal.classList.remove('hidden');
    modal.style.display = '';
    // 触发入场动画
    requestAnimationFrame(() => {
      content.style.transform = 'scale(1)';
      content.style.opacity = '1';
    });
  }

  function hideOrderSuccessModal() {
    const modal = document.getElementById('order-success-modal');
    const content = document.getElementById('order-success-content');
    if (!modal || !content) return;

    content.style.transform = 'scale(0.9)';
    content.style.opacity = '0';
    setTimeout(() => {
      modal.classList.add('hidden');
      modal.style.display = 'none';
    }, 300);
  }

  // 成功弹窗事件绑定
  document.addEventListener('DOMContentLoaded', function() {
    const backBtn = document.getElementById('success-back-btn');
    if (backBtn) backBtn.addEventListener('click', hideOrderSuccessModal);
    const successOverlay = document.getElementById('order-success-overlay');
    if (successOverlay) {
      successOverlay.addEventListener('click', function(e) {
        if (e.target === successOverlay) hideOrderSuccessModal();
      });
    }

    // 🔥 页面加载时恢复冷却状态（防止刷新绕过）
    if (orderCooldownMinutes > 0) {
      const lastOrderTime = parseInt(localStorage.getItem('last_order_time_' + tableFullId) || '0', 10);
      if (lastOrderTime) {
        const cooldownMs = orderCooldownMinutes * 60 * 1000;
        const remainingMs = cooldownMs - (Date.now() - lastOrderTime);
        if (remainingMs > 0) {
          startCooldownTimer(false);
        }
      }
    }
  });

  // 更新桌位状态函数
  function updateTableStatus(tableNumber, status, orderId = null, total = null) {
    // 🔥 调试日志：记录发送的参数
    console.log('[updateTableStatus] 📤 发送桌位状态更新:', {
      tableNumber: tableNumber,
      status: status,
      orderId: orderId,
      total: total
    });

    const formData = new URLSearchParams();
    formData.append('action', 'update_table_status');
    formData.append('table_id', tableNumber);
    formData.append('status', status === 'occupied' ? 'occupied' : 'available');
    formData.append('nonce', ajaxNonce);
    formData.append('is_guest', 'yes');

    if (orderId) {
      formData.append('order_id', orderId);
    }
    if (total !== null) {
      formData.append('total', total);
    }

    fetch(ajaxUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: formData
    })
    .then(response => {
      // 🔥 调试日志：记录响应状态
      console.log('[updateTableStatus] 📥 响应状态:', response.status, response.statusText);
      if (!response.ok) {
        throw new Error('Network response was not ok: ' + response.status);
      }
      return response.json();
    })
    .then(data => {
      // 🔥 调试日志：记录响应数据
      if (data.success) {
        console.log('[updateTableStatus] ✅ 桌位状态更新成功:', data);
      } else {
        console.error('[updateTableStatus] ❌ 桌位状态更新失败:', data);
      }
    })
    .catch(error => {
      // 🔥 调试日志：记录错误
      console.error('[updateTableStatus] ❌ 请求错误:', error);
    });
  }

  // 显示左下角淡色提示
  function showBottomLeftToast(message, type = 'success') {
    const toast = document.getElementById('bottom-left-toast');
    if (!toast) return;
    
    toast.textContent = message;
    toast.className = `bottom-left-toast ${type} show`;
    
    // 3秒后隐藏
    setTimeout(() => {
      toast.classList.remove('show');
    }, 3000);
  }

  // Toggle modo oscuro
  function toggleDarkMode() {
    document.body.classList.toggle('dark');
    const isDark = document.body.classList.contains('dark');
    localStorage.setItem('darkMode', isDark ? 'true' : 'false');
    
    // Cambiar icono
    const icon = themeToggle.querySelector('i');
    if (isDark) {
      icon.classList.remove('fa-moon');
      icon.classList.add('fa-sun');
    } else {
      icon.classList.remove('fa-sun');
      icon.classList.add('fa-moon');
    }
  }

  // Comprobar modo oscuro guardado
  function checkDarkMode() {
    if (localStorage.getItem('darkMode') === 'true') {
      document.body.classList.add('dark');
      const icon = themeToggle.querySelector('i');
      icon.classList.remove('fa-moon');
      icon.classList.add('fa-sun');
    }
  }

  // Estilos para modo oscuro
  const darkModeStyles = document.createElement('style');
  darkModeStyles.textContent = `
    .dark {
      background-color: #1c1c1c;
      color: #f5f5f5;
    }
    .dark header,
    .dark .bg-white,
    .dark #empty-cart {
      background-color: #292c2d;
      color: #f5f5f5;
    }
    .dark .product-card div {
      background-color: #3a3a3a;
      border-color: #444;
    }
    .dark h3,
    .dark h4 {
      color: #f5f5f5;
    }
    .dark .text-gray-600,
    .dark .text-gray-800,
    .dark .text-gray-500 {
      color: #adb5bd;
    }
    .dark .border-gray-200,
    .dark .border-gray-100 {
      border-color: #444;
    }
    .dark .active-category {
      background-color: #f13400 !important;
      color: white;
    }
    .dark .category-btn {
      background-color: #3a3a3a;
      color: #f5f5f5;
    }
    .dark .swal2-popup {
      background-color: #292c2d;
      color: #f5f5f5;
    }
    .dark .swal2-title, 
    .dark .swal2-content {
      color: #f5f5f5;
    }
  `;
  document.head.appendChild(darkModeStyles);

  // Detectar tamaño de pantalla para ajustes móviles
  function setMobileStyles() {
    if (window.innerWidth < 768) {
      // Ajustes adicionales para móviles
      document.querySelectorAll('.category-btn').forEach(btn => {
        btn.classList.add('text-xs');
      });
      
      // 给底部添加足够的间距，确保内容不被底部导航栏遮挡
    } else {
      document.querySelectorAll('.category-btn').forEach(btn => {
        btn.classList.remove('text-xs');
      });
    }
  }

  // Cargar módulos de productos
  function loadProductModules() {
    loadDiscountProducts();
    loadPopularProducts();
    loadRegularProducts();
  }

  // Cargar productos con descuento
  function loadDiscountProducts() {
    const container = document.getElementById('discount-products-container');
    const loading = document.getElementById('discount-loading');
    
    fetch(ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'ruiyi_get_discount_products',
        nonce: ajaxNonce
      })
    })
    .then(response => response.json())
    .then(data => {
      loading.classList.add('hidden');
      if (data.success && data.data.products.length > 0) {
        container.innerHTML = '';
        // 清除事件监听器标记
        container.dataset.eventListenerAttached = 'false';
        data.data.products.forEach(product => {
          container.appendChild(createDiscountProductCard(product));
        });
        // 添加事件监听器
        attachProductEventListeners(container);
      } else {
        // Ocultar sección si no hay productos con descuento
        document.getElementById('discount-products-section').style.display = 'none';
      }
    })
    .catch(error => {
      loading.classList.add('hidden');
      document.getElementById('discount-products-section').style.display = 'none';
    });
  }

  // Cargar productos populares
  function loadPopularProducts() {
    const container = document.getElementById('popular-products-container');
    const loading = document.getElementById('popular-loading');
    
    fetch(ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'ruiyi_get_popular_products',
        limit: 8,
        nonce: ajaxNonce
      })
    })
    .then(response => response.json())
    .then(data => {
      loading.classList.add('hidden');
      if (data.success && data.data.products && data.data.products.length > 0) {
        container.innerHTML = '';
        // 清除事件监听器标记
        container.dataset.eventListenerAttached = 'false';
        data.data.products.forEach((product, index) => {
          container.appendChild(createPopularProductCard(product, index + 1));
        });
        // 添加事件监听器
        attachProductEventListeners(container);
      } else {
        // Ocultar sección si no hay productos populares
        document.getElementById('popular-products-section').style.display = 'none';
      }
    })
    .catch(error => {
      loading.classList.add('hidden');
      document.getElementById('popular-products-section').style.display = 'none';
    });
  }

  // Cargar productos regulares (mantener lógica existente)
  function loadRegularProducts() {
    // La lógica existente del foreach PHP se mantiene
    // pero se puede optimizar para excluir productos ya mostrados
  }

  // Crear tarjeta de producto con descuento
  function createDiscountProductCard(product) {
    const card = document.createElement('div');
    card.className = 'product-card relative cursor-pointer';
    card.dataset.id = product.id;
    card.dataset.price = product.sale_price;
    card.dataset.name = getProductDisplayName(product);
    card.dataset.nameEs = product.name || '';
    card.dataset.nameZh = product.name_zh || '';
    card.dataset.nameEn = product.name_en || '';
    card.dataset.image = product.image_url;
    
    card.innerHTML = `
      <!-- Etiqueta de descuento -->
      <div class="absolute top-2 left-2 bg-red-500 text-white text-xs px-2 py-1 rounded-full z-10 font-bold">
        -${product.discount_percentage}%
      </div>
      
      <div class="bg-white rounded-lg p-3 text-center hover:shadow-xl transition-all cursor-pointer transform hover:scale-105 border-2 border-red-200 hover:border-red-400 group">
        <div class="relative overflow-hidden rounded-lg mb-2">
          <img src="${product.image_url}" alt="${product.name}" 
              class="w-full h-24 sm:h-28 md:h-32 object-cover object-center group-hover:scale-110 transition-transform duration-300"
              onerror="this.src='https://via.placeholder.com/200x200?text=Sin+Imagen'">
        </div>
        <h3 class="font-medium text-sm mb-2 text-gray-800 leading-tight line-clamp-2">${getProductDisplayName(product)}</h3>

        <!-- Precios con descuento -->
        <div class="space-y-1 mb-3">
          <div class="text-gray-500 text-xs line-through">€${parseFloat(product.regular_price || 0).toFixed(2)}</div>
          <div class="text-red-600 font-bold text-lg">€${parseFloat(product.sale_price || 0).toFixed(2)}</div>
          <div class="text-green-600 text-xs font-medium">
            ${t('ahorro')}: €${parseFloat(product.discount_amount || 0).toFixed(2)}
          </div>
        </div>
      </div>
    `;
    
    return card;
  }

  // Crear tarjeta de producto popular
  function createPopularProductCard(product, rank) {
    const card = document.createElement('div');
    card.className = 'product-card relative cursor-pointer';
    card.dataset.id = product.id;
    card.dataset.price = product.price;
    card.dataset.name = getProductDisplayName(product);
    card.dataset.nameEs = product.name || '';
    card.dataset.nameZh = product.name_zh || '';
    card.dataset.nameEn = product.name_en || '';
    card.dataset.image = product.image_url || product.image;
    
    card.innerHTML = `
      <!-- Ranking badge -->
      <div class="absolute top-2 left-2 bg-orange-500 text-white text-xs px-2 py-1 rounded-full z-10 font-bold flex items-center">
        <i class="fas fa-fire mr-1"></i>#${rank}
      </div>
      
      <div class="bg-white rounded-lg p-3 text-center hover:shadow-xl transition-all cursor-pointer transform hover:scale-105 border-2 border-orange-200 hover:border-orange-400 group">
        <div class="relative overflow-hidden rounded-lg mb-2">
          <img src="${product.image_url || product.image}" alt="${product.name}" 
              class="w-full h-24 sm:h-28 md:h-32 object-cover object-center group-hover:scale-110 transition-transform duration-300"
              onerror="this.src='https://via.placeholder.com/200x200?text=Sin+Imagen'">
        </div>
        <h3 class="font-medium text-sm mb-2 text-gray-800 leading-tight line-clamp-2">${getProductDisplayName(product)}</h3>
        <div class="text-primary font-bold text-lg mb-2">€${parseFloat(product.price || 0).toFixed(2)}</div>
        ${product.sales_count ? `<div class="text-orange-600 text-xs mb-3">${product.sales_count} ${t('vendidos')}</div>` : '<div class="mb-3"></div>'}
      </div>
    `;
    
    return card;
  }

  // Inicializar
  document.addEventListener('DOMContentLoaded', () => {
    // 添加更多翻译
    addMoreTranslations();
    
    initCart();
    setMobileStyles();
    
    // 不再自动加载特殊产品模块，只有在用户点击对应分类时才加载
    // loadProductModules();
    
    // 不再预加载积分产品
    // loadPointsProducts();
    
    // 初始化悬浮购物车按钮
    setupFloatingCartButton();
    
    // 为普通产品模块添加事件监听器
    const productsContainer = document.getElementById('products-container');
    if (productsContainer) {
      attachProductEventListeners(productsContainer);
    }
    
    // 监听窗口大小变化
    window.addEventListener('resize', setMobileStyles);

    // 🍽️ 自助餐模式：页面加载时检查桌位是否已开台
    if (buffetConfig.enabled && !buffetConfig.tableExempt && (tableFullId || tableParam)) {
        checkBuffetTableStatus().then(opened => {
            if (!opened) {
                // 未开台 → 每10秒自动重新检查（直到开台）
                buffetTableCheckInterval = setInterval(() => {
                    checkBuffetTableStatus();
                }, 10000);
            }
        });
    }
  });

  // 🔥🔥🔥 【已移除】重复的 send-order-btn 事件监听器
  // 该按钮已在上方 DOMContentLoaded 中通过 sideSendOrderBtn.addEventListener('click', sendOrder) 绑定
  // 重复绑定会导致 sendOrder() 被调用两次，后端合并订单时数量翻倍

  // 创建订单完成后释放桌位
  function liberarMesa(tableNumber) {
    if (!tableNumber || tableNumber === 'Cliente Web') return;
    
    // 使用延迟，确保订单处理完毕
    setTimeout(() => {
      try {
        // 确保桌号为数字
        const tableId = parseInt(tableNumber.replace(/\D/g, ''));
        if (tableId > 0) {
          // 更新表状态为可用
          updateTableStatus(tableId, 'available');
          
          // 同步更新localStorage - 与page-pos.php保持一致
          const mesaId = `Mesa ${tableId}`;
          const mesasEstado = JSON.parse(localStorage.getItem('mesas_estado') || '{}');
          const carritosGuardados = JSON.parse(localStorage.getItem('carritos_mesas') || '{}');
          
          // 清除餐桌状态
          if (mesasEstado[mesaId]) {
            delete mesasEstado[mesaId];
            localStorage.setItem('mesas_estado', JSON.stringify(mesasEstado));
          }
          
          // 清除购物车数据
          if (carritosGuardados[mesaId]) {
            delete carritosGuardados[mesaId];
            localStorage.setItem('carritos_mesas', JSON.stringify(carritosGuardados));
          }
          
          // 桌位已释放
        } else {
          // 桌号无效
        }
      } catch (e) {
        // 释放桌位出错
      }
    }, 2000);
  }

  // 带确认的手动释放桌位
  function liberarMesaManualConConfirmacion() {
    const tableNumber = tableParam || document.querySelector('.table-number-input')?.value;
    if (!tableNumber || tableNumber === 'Cliente Web') {
      showToast(t('noTableSelected'), 'error');
      return;
    }
    
    // 检查localStorage中是否有该桌的订单
    const tableId = parseInt(tableNumber.replace(/\D/g, ''));
    const mesaId = `Mesa ${tableId}`;
    const mesasEstado = JSON.parse(localStorage.getItem('mesas_estado') || '{}');
    
    let mensaje = '';
    if (mesasEstado[mesaId] && mesasEstado[mesaId].orderId) {
      mensaje = `${t('confirmReleaseTable')} ${tableNumber}? ${t('orderWillBeCancelled')} #${mesasEstado[mesaId].orderId}`;
    } else {
      mensaje = `${t('confirmReleaseTable')} ${tableNumber}?`;
    }
    
    Swal.fire({
      title: t('releaseTable'),
      text: mensaje,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#f44336',
      cancelButtonColor: '#3085d6',
      confirmButtonText: t('yesRelease'),
      cancelButtonText: t('cancel')
    }).then((result) => {
      if (result.isConfirmed) {
        liberarMesaManual(tableNumber);
      }
    });
  }

  // 手动释放桌位（包括取消订单）
  function liberarMesaManual(tableNumber) {
    if (!tableNumber || tableNumber === 'Cliente Web') return;
    
    const tableId = parseInt(tableNumber.replace(/\D/g, ''));
    if (tableId <= 0) return;
    
    const mesaId = `Mesa ${tableId}`;
    const mesasEstado = JSON.parse(localStorage.getItem('mesas_estado') || '{}');
    
    // 检查是否有订单需要取消
    if (mesasEstado[mesaId] && mesasEstado[mesaId].orderId) {
      // 调用取消订单的AJAX请求 - 与page-pos.php保持一致
      const formData = new URLSearchParams();
      formData.append('action', 'ruiyi_pos_cancel_table_orders');
      formData.append('table_number', tableNumber); // Usar el número original, no el formato "Mesa X"
      formData.append('nonce', ajaxNonce);
      formData.append('is_guest', 'yes'); // 标记为客户端请求
      
      fetch(ajaxUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          console.log('Órdenes canceladas:', data.data);
          const cancelledCount = data.data.total_orders || 0;
          const detailedMessage = data.data.detailed_message || '';
          
          if (cancelledCount > 0) {
            // Mostrar mensaje de éxito con el número de órdenes canceladas
            showToast(detailedMessage || `✅ Se cancelaron ${cancelledCount} órdenes de la Mesa ${tableNumber}`, 'success');
          }
          
          // 清除本地状态
          liberarMesa(tableNumber);
          
          // Mostrar mensaje final de mesa liberada
          setTimeout(() => {
            showToast(`✅ Mesa ${tableNumber} liberada`, 'success');
          }, 500);
        } else {
          console.error('Error cancelando órdenes:', data.data?.message);
          showToast(`⚠️ Error al cancelar órdenes: ${data.data?.message || 'Error desconocido'}`, 'warning');
          // 即使失败也清除本地状态
          liberarMesa(tableNumber);
        }
      })
      .catch(error => {
        console.error('Error en la petición:', error);
        showToast(`⚠️ Error de conexión al cancelar órdenes`, 'warning');
        // 即使失败也清除本地状态
        liberarMesa(tableNumber);
      });
    } else {
      // 没有订单，直接释放
      liberarMesa(tableNumber);
    }
  }

  // 页面卸载时释放桌位
  window.addEventListener('beforeunload', function() {
    const tableNumber = tableParam || document.querySelector('.table-number-input')?.value;
    if (tableNumber && tableNumber !== 'Cliente Web') {
      try {
        // 确保桌号为数字
        const tableId = parseInt(tableNumber.replace(/\D/g, ''));
        if (tableId > 0) {
          // 尝试更新桌位状态为空闲
          // 注意：由于页面卸载，该请求可能不会完成，这是浏览器行为限制
          // 使用同步XMLHttpRequest可能会有更高的成功率
          updateTableStatus(tableId, 'available');
          // 页面卸载，尝试释放桌位
        }
      } catch (e) {
        // 页面卸载时无法记录错误
      }
    }
  });

  </script>
  <script>
  // Script para regresar al Home
  document.addEventListener('DOMContentLoaded', function() {
    const returnHomeBtn = document.getElementById('returnHomeBtn');
    const homeSplash = document.getElementById('homeSplash');
    const carritoCliente = document.getElementById('carritoClienteSection');

    if (returnHomeBtn && homeSplash && carritoCliente) {
      returnHomeBtn.classList.remove('hidden'); // Mostrar el botón

      returnHomeBtn.addEventListener('click', function() {
        // Mostrar el Splash
        homeSplash.classList.remove('opacity-0');
        homeSplash.classList.add('opacity-100');

        // Ocultar el carrito
        carritoCliente.classList.remove('opacity-100');
        carritoCliente.classList.add('opacity-0');
      });
    }
  });
  </script>
  <script>
  // Script para gestionar la transición Splash ↔ Carrito
  document.addEventListener('DOMContentLoaded', function() {
    const homeSplash = document.getElementById('homeSplash');
    const startBtn = document.getElementById('startAppBtn');
    const carritoCliente = document.getElementById('carritoClienteSection');
    const returnHomeBtn = document.getElementById('returnHomeBtn');

    if (startBtn && homeSplash && carritoCliente) {
      startBtn.addEventListener('click', function() {
        homeSplash.classList.remove('opacity-100');
        homeSplash.classList.add('opacity-0');

        carritoCliente.classList.remove('opacity-0');
        carritoCliente.classList.add('opacity-100');
      });
    }

    if (returnHomeBtn && homeSplash && carritoCliente) {
      returnHomeBtn.classList.remove('hidden'); // Mostrar botón home

      returnHomeBtn.addEventListener('click', function() {
        carritoCliente.classList.remove('opacity-100');
        carritoCliente.classList.add('opacity-0');

        homeSplash.classList.remove('opacity-0');
        homeSplash.classList.add('opacity-100');
      });
    }
  });

  </script>
  <script>
  document.addEventListener('DOMContentLoaded', function() {
    const returnHomeBtn = document.getElementById('returnHomeBtn');
    const homeSplash = document.getElementById('homeSplash');
    const carritoCliente = document.getElementById('carritoClienteSection');

    if (returnHomeBtn && homeSplash && carritoCliente) {
      returnHomeBtn.classList.remove('hidden'); // Mostrar el botón al cargar

      returnHomeBtn.addEventListener('click', function() {
        // 1️⃣ Agregar animación pulse
        returnHomeBtn.classList.add('pulse-animation');

        // 2️⃣ Después de la animación, cambiar las vistas
        setTimeout(() => {
          // Ocultar carrito
          carritoCliente.classList.remove('opacity-100');
          carritoCliente.classList.add('opacity-0');

          // Mostrar home splash
          homeSplash.classList.remove('opacity-0');
          homeSplash.classList.add('opacity-100');

          // Opcional: quitar la animación para permitir reanimarla si vuelves a presionar
          returnHomeBtn.classList.remove('pulse-animation');
        }, 400); // Tiempo igual a la duración de la animación
      });
    }
  });
  </script>
  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const loginScreen = document.getElementById('loginScreen');
    const mainScreen = document.getElementById('mainScreen');
    const logoutBtn = document.getElementById('logoutBtn'); // ← capturamos botón logout

    // 添加null检查防止错误
    if (loginScreen && mainScreen) {
      // Mostrar directamente la pantalla principal
      loginScreen.classList.add('hidden');
      mainScreen.classList.remove('hidden');
      
      // 添加检查确保元素存在
      const signUpBtn = document.getElementById('signUpBtn');
      if (signUpBtn) {
        signUpBtn.addEventListener('click', () => {
          loginScreen.classList.add('hidden');
          mainScreen.classList.remove('hidden');
        });
      }
      
      const googleLogin = document.getElementById('googleLogin');
      if (googleLogin) {
        googleLogin.addEventListener('click', () => {
          loginScreen.classList.add('hidden');
          mainScreen.classList.remove('hidden');
        });
      }
      
      const facebookLogin = document.getElementById('facebookLogin');
      if (facebookLogin) {
        facebookLogin.addEventListener('click', () => {
          loginScreen.classList.add('hidden');
          mainScreen.classList.remove('hidden');
        });
      }
    }

    // 👉 Evento para Logout
    if (logoutBtn && loginScreen && mainScreen) {
      logoutBtn.addEventListener('click', () => {
        mainScreen.classList.add('hidden');
        loginScreen.classList.remove('hidden');
      });
    }
  });
  </script>

  <!-- Floating Category Button -->
  <button class="floating-category-btn" id="floating-category-btn" aria-label="选择分类">
    <i class="fas fa-utensils"></i>
    <span><?php echo ruiyi_translate('Categorías', 'Categories', '菜品分类'); ?></span>
  </button>

  <!-- Category Popup Overlay -->
  <div class="category-popup-overlay" id="category-popup-overlay"></div>

  <!-- Category Popup -->
  <div class="category-popup" id="category-popup">
    <div class="category-popup-header">
      <div class="category-popup-title">
        <i class="fas fa-th-large"></i>
        <?php echo ruiyi_translate('Seleccionar Categoría', 'Select Category', '选择分类'); ?>
      </div>
      <button class="category-popup-close" id="category-popup-close">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="category-popup-body">
      <div class="category-popup-list" id="category-popup-list">
        <?php foreach ($categories as $category): ?>
        <button class="category-popup-item" data-category="<?php echo esc_attr($category['id']); ?>">
          <i class="fas fa-tag category-icon"></i>
          <span><?php echo esc_html($category['name']); ?></span>
        </button>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- 🔥🔥🔥 WebSocket 正式版：持久连接，页面加载时建立 -->
  <script>
  (function() {
    'use strict';

    var POS_HUB_URL = 'https://srv985735.hstgr.cloud';
    var customerSocket = null;
    var isConnected = false;

    function initCustomerWebSocket() {
      if (typeof io === 'undefined') {
        console.warn('[Customer-WS] Socket.IO 未加载，跳过');
        return;
      }

      try {
        customerSocket = io(POS_HUB_URL + '/pos-tables', {
          path: '/pos-hub/socket.io/',
          transports: ['websocket', 'polling'],
          query: {
            clientType: 'customer',
            table: typeof tableFullId !== 'undefined' ? tableFullId : 'unknown',
            site_id: '<?php echo get_current_blog_id(); ?>'
          },
          reconnection: true,
          reconnectionAttempts: Infinity,
          reconnectionDelay: 1000,
          reconnectionDelayMax: 10000,
          randomizationFactor: 0.5,
          timeout: 10000
        });

        customerSocket.on('connect', function() {
          isConnected = true;
          console.log('[Customer-WS] ✅ 已连接, socketId:', customerSocket.id);
        });

        customerSocket.on('disconnect', function(reason) {
          isConnected = false;
          console.warn('[Customer-WS] 🔌 断开:', reason);
        });

        customerSocket.on('connect_error', function(err) {
          isConnected = false;
          console.warn('[Customer-WS] ❌ 连接失败:', err.message);
        });

        // 监听订单确认（POS 端或服务端推送回来的确认）
        customerSocket.on('order:confirmed', function(data) {
          console.log('[Customer-WS] ✅ 订单已确认:', data);
        });

        // 暴露到全局供订单成功回调使用
        window._customerSocket = customerSocket;
        window._customerSocketConnected = function() { return isConnected; };

        console.log('[Customer-WS] 🚀 WebSocket 持久连接已启动');
      } catch (err) {
        console.error('[Customer-WS] 初始化失败:', err);
      }
    }

    // DOM 就绪后初始化
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initCustomerWebSocket);
    } else {
      initCustomerWebSocket();
    }
  })();
  </script>

  <!-- 🔥 清理URL中的scan参数 -->
  <script>
  (function() {
    var url = new URL(window.location.href);
    if (url.searchParams.has('scan')) {
      url.searchParams.delete('scan');
      window.history.replaceState({}, '', url.toString());
    }
  })();
  </script>

  <?php
  // 🔥 会话超时机制 - 防止用户在家通过保存的URL乱点餐
  // 使用 localStorage 持久化，刷新页面无法绕过
  // URL 必须带 scan=1 参数才能开启新会话（QR码扫描时自动携带）
  $session_timeout_minutes = intval(get_option('pos_session_timeout_minutes', '30'));
  $is_scan_entry = isset($_GET['scan']) && $_GET['scan'] == '1';
  if ($session_timeout_minutes > 0): ?>
  <!-- 会话超时遮罩 -->
  <div id="session-timeout-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%;
       background:rgba(0,0,0,0.85); z-index:99999; justify-content:center; align-items:center; flex-direction:column;">
    <div style="text-align:center; color:white; padding:40px;">
      <i class="fas fa-qrcode" style="font-size:80px; color:#f59e0b; margin-bottom:30px;"></i>
      <h2 style="font-size:24px; font-weight:bold; margin-bottom:15px; color:#f59e0b;">
        Sesión expirada / Session expired / 会话已过期
      </h2>
      <p style="font-size:18px; margin-bottom:8px;">Por favor, escanee el código QR nuevamente para ordenar.</p>
      <p style="font-size:18px; margin-bottom:8px;">Please scan the QR code again to order.</p>
      <p style="font-size:18px; margin-bottom:8px;">请重新扫码点餐。</p>
    </div>
  </div>
  <script>
  (function() {
    var TIMEOUT_MS = <?php echo $session_timeout_minutes; ?> * 60 * 1000;
    var STORAGE_KEY = 'pos_session_start';
    var IS_SCAN = <?php echo $is_scan_entry ? 'true' : 'false'; ?>;
    var overlay = document.getElementById('session-timeout-overlay');

    function now() { return Date.now(); }

    function getRemaining() {
      var start = localStorage.getItem(STORAGE_KEY);
      if (!start) return 0;
      var elapsed = now() - parseInt(start, 10);
      return Math.max(0, TIMEOUT_MS - elapsed);
    }

    function checkTimeout() {
      if (getRemaining() <= 0) {
        showOverlay();
      }
    }

    function showOverlay() {
      localStorage.removeItem(STORAGE_KEY);
      overlay.style.display = 'flex';
      clearInterval(intervalId);
    }

    // ====== 初始化逻辑 ======
    var intervalId;
    if (IS_SCAN) {
      // ✅ 扫码进入 → 开启新会话
      localStorage.setItem(STORAGE_KEY, now().toString());
      intervalId = setInterval(checkTimeout, 1000);
    } else {
      // ❌ 非扫码进入（刷新/书签/手动输入）
      var existing = localStorage.getItem(STORAGE_KEY);
      if (!existing || now() - parseInt(existing, 10) >= TIMEOUT_MS) {
        showOverlay();
      } else {
        intervalId = setInterval(checkTimeout, 1000);
      }
    }
  })();
  </script>
  <?php endif; ?>

  <!-- 产品详情弹窗 -->
  <div class="product-detail-overlay" id="productDetailOverlay">
    <div class="product-detail-modal" id="productDetailModal">
      <!-- 产品图片 -->
      <div class="detail-image-wrapper">
        <img src="" alt="" id="detailProductImage">
        <button class="detail-close-btn" id="detailCloseBtn"><i class="fas fa-times"></i></button>
      </div>
      <!-- 产品信息 -->
      <div class="detail-body">
        <h2 class="detail-product-name" id="detailProductName"></h2>
        <p class="detail-product-desc" id="detailProductDesc"></p>
        <div class="detail-allergens" id="detailAllergens"></div>
        <!-- 备注入口 -->
        <div class="detail-note-section">
          <button class="detail-note-btn" id="detailNoteToggle">
            <div class="note-btn-left">
              <span><?php echo ruiyi_translate('Nota', 'Note', '备注'); ?></span>
              <span class="note-preview" id="detailNotePreview"></span>
            </div>
            <i class="fas fa-chevron-right"></i>
          </button>
        </div>
      </div>
      <!-- 底部操作栏 -->
      <div class="detail-bottom-bar">
        <div class="detail-price-qty-row">
          <div class="detail-price" id="detailProductPrice"></div>
          <div class="detail-qty-controls">
            <button class="detail-qty-btn minus" id="detailQtyMinus"><i class="fas fa-minus"></i></button>
            <span class="detail-qty-value" id="detailQtyValue">1</span>
            <button class="detail-qty-btn plus" id="detailQtyPlus"><i class="fas fa-plus"></i></button>
          </div>
        </div>
        <button class="detail-add-btn" id="detailAddToCartBtn">
          <span><?php echo ruiyi_translate('Añadir al carrito', 'Add to cart', '加入购物车'); ?></span>
          <i class="fas fa-arrow-right"></i>
        </button>
      </div>
    </div>
  </div>

  <!-- 备注全屏页面 -->
  <div class="note-page-overlay" id="notePageOverlay">
    <div class="note-page-header">
      <button class="note-page-back" id="notePageBack"><i class="fas fa-chevron-left"></i></button>
      <span class="note-page-title"><?php echo ruiyi_translate('Nota', 'Note', '备注'); ?></span>
    </div>
    <div class="note-page-body">
      <div class="note-textarea-wrapper">
        <textarea class="note-textarea" id="notePageTextarea" maxlength="200" placeholder="<?php echo ruiyi_translate(
          'Añade cualquier requisito especial, como alergias alimentarias o preferencias. El restaurante hará todo lo posible para satisfacer tu solicitud.',
          'Add any special requirements, such as food allergies or preferences. The restaurant will try its best to accommodate your request.',
          '添加任何特殊要求，如饮食过敏或偏好。餐厅将尽力满足您的要求。'
        ); ?>"></textarea>
        <span class="note-char-count" id="noteCharCount">0/200</span>
      </div>
      <div class="note-quick-title"><?php echo ruiyi_translate('Entrada rápida', 'Quick input', '快速输入'); ?></div>
      <div class="note-quick-tags" id="noteQuickTags">
        <?php
        $quick_notes = array(
          array('es' => 'Para llevar', 'en' => 'Takeaway', 'zh' => '外带'),
          array('es' => 'Menos picante', 'en' => 'Less spicy', 'zh' => '少辣'),
          array('es' => 'Menos sal', 'en' => 'Less salt', 'zh' => '少盐'),
          array('es' => 'Menos aceite', 'en' => 'Less oil', 'zh' => '少油'),
          array('es' => 'Al vapor', 'en' => 'Steamed', 'zh' => '清蒸'),
          array('es' => 'Sin cilantro', 'en' => 'No cilantro', 'zh' => '不加香菜'),
          array('es' => 'Extra picante', 'en' => 'Extra spicy', 'zh' => '加辣'),
          array('es' => 'Con chile', 'en' => 'With chili', 'zh' => '剁椒'),
          array('es' => 'Con cebolleta', 'en' => 'With scallion oil', 'zh' => '葱油'),
          array('es' => 'Vapor seco', 'en' => 'Dry steamed', 'zh' => '干蒸'),
          array('es' => 'Jengibre y cebolleta', 'en' => 'Ginger & scallion', 'zh' => '姜葱'),
          array('es' => 'Estofado', 'en' => 'Braised', 'zh' => '红烧'),
          array('es' => 'Vapor casero', 'en' => 'Home steamed', 'zh' => '家蒸'),
          array('es' => 'Sin ajo', 'en' => 'No garlic', 'zh' => '免蒜'),
          array('es' => 'Sin glutamato', 'en' => 'No MSG', 'zh' => '免味精'),
          array('es' => 'Sin cebolla', 'en' => 'No onion', 'zh' => '免葱'),
          array('es' => 'Sin cebolla morada', 'en' => 'No shallot', 'zh' => '免洋葱'),
          array('es' => 'Sin picante', 'en' => 'No spicy', 'zh' => '免辣'),
          array('es' => 'Sin salsa soja', 'en' => 'No soy sauce', 'zh' => '免酱油'),
          array('es' => 'Sin guisantes', 'en' => 'No peas', 'zh' => '免青豆'),
          array('es' => 'Sin cangrejo', 'en' => 'No crab', 'zh' => '免蟹肉'),
          array('es' => 'Sin huevo', 'en' => 'No egg', 'zh' => '免蛋'),
          array('es' => 'Sin brotes soja', 'en' => 'No bean sprouts', 'zh' => '免豆芽'),
        );
        foreach ($quick_notes as $qn):
          $label = ruiyi_translate($qn['es'], $qn['en'], $qn['zh']);
        ?>
        <button class="note-quick-tag" data-text="<?php echo esc_attr($label); ?>"><?php echo esc_html($label); ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="note-page-footer">
      <button class="note-confirm-btn" id="noteConfirmBtn"><?php echo ruiyi_translate('Continuar', 'Continue', '继续'); ?></button>
    </div>
  </div>

  <!-- PWA Service Worker 注册 -->
  <script>
  // beforeinstallprompt: 不拦截，保留浏览器菜单中的"安装应用"选项
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
      navigator.serviceWorker.register('<?php echo get_template_directory_uri(); ?>/sw-customer-register.php', {
        scope: '/cliente-carrito/'
      }).then(function(reg) {
        console.log('[PWA] Service Worker 注册成功, scope:', reg.scope);
      }).catch(function(err) {
        console.warn('[PWA] Service Worker 注册失败:', err);
      });
    });
  }
  </script>

  </body>
  </html>
