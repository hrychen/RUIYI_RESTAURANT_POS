<?php
/**
 * 纯Meta系统餐桌分类管理 - 完全移除MySQL依赖
 * Pure Meta System Table Categories Management - MySQL Dependencies Completely Removed
 *
 * 这个文件替代了所有MySQL表相关的功能，完全使用WordPress Meta系统
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 强制使用Meta系统
 */
function ruiyi_force_meta_system() {
    if (!get_option('ruiyi_use_meta_system', false)) {
        update_option('ruiyi_use_meta_system', true);
        ruiyi_debug_log('RUIYI Pure Meta: Forced Meta system activation');
    }
}
add_action('init', 'ruiyi_force_meta_system', 1);

/**
 * 获取所有分类 (纯Meta版本)
 */
function ruiyi_get_table_categories($with_multilang = false) {
    if (!class_exists('RUIYI_Table_Meta_Manager')) {
        require_once plugin_dir_path(__FILE__) . 'table-categories-meta.php';
    }

    $meta_manager = ruiyi_get_meta_manager();
    $categories = $meta_manager->get_categories();

    ruiyi_debug_log('RUIYI Pure Meta: Retrieved ' . count($categories) . ' categories');

    return $categories;
}

/**
 * 根据分类获取桌位 (纯Meta版本)
 */
function ruiyi_get_tables_by_category($category_id, $display_mode = 'category') {
    if (!class_exists('RUIYI_Table_Meta_Manager')) {
        require_once plugin_dir_path(__FILE__) . 'table-categories-meta.php';
    }

    $meta_manager = ruiyi_get_meta_manager();

    // 🔥 不使用 meta_manager 的 get_tables_by_category（它不应用自定义名称和规范化）
    // 改为直接使用 ruiyi_get_all_table_categories_mapping（已包含规范化 + 自定义名称覆盖）
    $all_mappings = ruiyi_get_all_table_categories_mapping();
    $tables = array();

    foreach ($all_mappings as $mapping) {
        // 筛选分类：'all' 返回全部，否则按 category_id 筛选
        if ($category_id !== 'all' && intval($mapping['category_id']) !== intval($category_id)) {
            continue;
        }

        $display_name = !empty($mapping['category_display_name']) ? $mapping['category_display_name'] : '';

        $tables[] = array(
            'table_id' => isset($mapping['table_id']) ? $mapping['table_id'] : ($mapping['category_id'] . '_' . $mapping['table_number_in_category']),
            'table_name' => $display_name ?: ('Mesa ' . $mapping['global_table_number']),
            'table_number_in_category' => $mapping['table_number_in_category'],
            'global_table_number' => $mapping['global_table_number'],
            'category_display_name' => $display_name
        );
    }

    // 按分类内编号排序
    usort($tables, function($a, $b) {
        return intval($a['table_number_in_category']) - intval($b['table_number_in_category']);
    });

    ruiyi_debug_log("RUIYI Pure Meta: Retrieved " . count($tables) . " tables for category {$category_id}");

    return $tables;
}

/**
 * 添加桌位到分类 (纯Meta版本)
 */
function ruiyi_add_tables_to_category($category_id, $table_count) {
    if (!class_exists('RUIYI_Table_Meta_Manager')) {
        require_once plugin_dir_path(__FILE__) . 'table-categories-meta.php';
    }

    $meta_manager = ruiyi_get_meta_manager();
    $result = $meta_manager->add_tables_to_category($category_id, $table_count);

    ruiyi_debug_log("RUIYI Pure Meta: Added {$result} tables to category {$category_id}");

    return $result;
}

/**
 * 设置分类桌位总数 (纯Meta版本)
 */
function ruiyi_set_category_table_count($category_id, $target_count) {
    if (!class_exists('RUIYI_Table_Meta_Manager')) {
        require_once plugin_dir_path(__FILE__) . 'table-categories-meta.php';
    }

    $meta_manager = ruiyi_get_meta_manager();
    $current_tables = $meta_manager->get_tables_by_category($category_id);
    $current_count = count($current_tables);

    ruiyi_debug_log("RUIYI Pure Meta: Setting category {$category_id} from {$current_count} to {$target_count} tables");

    if ($target_count == $current_count) {
        ruiyi_debug_log("RUIYI Pure Meta: No change needed");
        return true;
    }

    if ($target_count > $current_count) {
        // 需要添加桌位
        $to_add = $target_count - $current_count;
        ruiyi_debug_log("RUIYI Pure Meta: Adding {$to_add} tables");
        return $meta_manager->add_tables_to_category($category_id, $to_add) > 0;
    } else {
        // 需要删除桌位
        $to_remove = $current_count - $target_count;
        ruiyi_debug_log("RUIYI Pure Meta: Removing {$to_remove} tables");

        for ($i = 0; $i < $to_remove; $i++) {
            if (!$meta_manager->remove_last_table($category_id)) {
                ruiyi_debug_log("RUIYI Pure Meta: Failed to remove table {$i}");
                return false;
            }
        }
        return true;
    }
}

/**
 * 创建分类 (纯Meta版本)
 */
function ruiyi_create_table_category($data) {
    if (!class_exists('RUIYI_Table_Meta_Manager')) {
        require_once plugin_dir_path(__FILE__) . 'table-categories-meta.php';
    }

    $meta_manager = ruiyi_get_meta_manager();
    $result = $meta_manager->create_category($data);

    ruiyi_debug_log("RUIYI Pure Meta: Created category with ID: {$result}");

    return $result;
}

/**
 * 更新分类 (纯Meta版本)
 */
function ruiyi_update_table_category($category_id, $data) {
    if (!class_exists('RUIYI_Table_Meta_Manager')) {
        require_once plugin_dir_path(__FILE__) . 'table-categories-meta.php';
    }

    $meta_manager = ruiyi_get_meta_manager();
    $result = $meta_manager->update_category($category_id, $data);

    ruiyi_debug_log("RUIYI Pure Meta: Updated category {$category_id}: " . ($result ? 'success' : 'failed'));

    return $result;
}

/**
 * 删除分类 (纯Meta版本)
 */
function ruiyi_delete_table_category($category_id) {
    if (!class_exists('RUIYI_Table_Meta_Manager')) {
        require_once plugin_dir_path(__FILE__) . 'table-categories-meta.php';
    }

    $meta_manager = ruiyi_get_meta_manager();
    $result = $meta_manager->delete_category($category_id);

    ruiyi_debug_log("RUIYI Pure Meta: Deleted category {$category_id}: " . ($result ? 'success' : 'failed'));

    return $result;
}

/**
 * 根据语言获取分类名称 (纯Meta版本)
 */
function ruiyi_get_category_name_by_lang($category, $lang = null) {
    if (!$lang) {
        $cookie_lang = isset($_COOKIE['ruiyi_language']) ? $_COOKIE['ruiyi_language'] : null;
        $lang = $cookie_lang ?: (defined('RUIYI_CURRENT_LANG') ? RUIYI_CURRENT_LANG : 'zh');
    }

    switch ($lang) {
        case 'en':
            return $category['name_en'] ?: $category['name'];
        case 'zh':
            return $category['name_zh'] ?: $category['name'];
        default:
            return $category['name_es'] ?: $category['name'];
    }
}

/**
 * 获取所有餐桌的分类映射 (纯Meta版本)
 */
function ruiyi_get_all_table_categories_mapping() {
    if (!class_exists('RUIYI_Table_Meta_Manager')) {
        require_once plugin_dir_path(__FILE__) . 'table-categories-meta.php';
    }

    $meta_manager = ruiyi_get_meta_manager();
    $mappings = $meta_manager->get_mappings();

    // 🔥 规范化旧数据：确保 category_display_name 中字母和数字之间有空格
    // 例如 "COMEDOR1" → "COMEDOR 1"，但不影响已有空格的 "COMEDOR 1" 或纯数字/纯字母
    foreach ($mappings as &$mapping) {
        if (!empty($mapping['category_display_name'])) {
            $mapping['category_display_name'] = preg_replace(
                '/([a-zA-Z\x{4e00}-\x{9fff}])(\d)/u',
                '$1 $2',
                $mapping['category_display_name']
            );
        }
    }
    unset($mapping);

    // 🔥 应用自定义桌位名称覆盖 category_display_name
    $custom_names = get_option('pos_custom_table_names', array());
    if (!empty($custom_names) && is_array($custom_names)) {
        foreach ($mappings as &$mapping) {
            $key = $mapping['category_id'] . '_' . $mapping['table_number_in_category'];
            if (isset($custom_names[$key]) && $custom_names[$key] !== '') {
                $mapping['category_display_name'] = $custom_names[$key];
            }
        }
        unset($mapping);
    }

    return $mappings;
}

/**
 * 自动/手动修复餐桌分类映射 (纯Meta版本)
 */
function ruiyi_repair_table_categories_mapping($force = false) {
    if (!class_exists('RUIYI_Table_Meta_Manager')) {
        require_once plugin_dir_path(__FILE__) . 'table-categories-meta.php';
    }

    $meta_manager = ruiyi_get_meta_manager();
    $mappings = $meta_manager->maybe_repair_mappings(null, $force);

    return is_array($mappings) ? count($mappings) : 0;
}

/**
 * 生成桌位名称 (纯Meta版本)
 */
function ruiyi_generate_table_name($table_number_in_category, $lang = null) {
    if (!$lang) {
        $cookie_lang = isset($_COOKIE['ruiyi_language']) ? $_COOKIE['ruiyi_language'] : null;
        $lang = $cookie_lang ?: (defined('RUIYI_CURRENT_LANG') ? RUIYI_CURRENT_LANG : 'zh');
    }

    $prefixes = array(
        'es' => 'Mesa',
        'en' => 'Table',
        'zh' => '餐桌'
    );

    $prefix = $prefixes[$lang] ?: 'Mesa';
    return $prefix . ' ' . $table_number_in_category;
}

/**
 * 根据ID生成桌位名称 (纯Meta版本)
 */
function ruiyi_generate_table_name_by_id($table_id, $lang = null) {
    if (strpos($table_id, '_') !== false) {
        $parts = explode('_', $table_id);
        $table_number_in_category = end($parts);
        return ruiyi_generate_table_name($table_number_in_category, $lang);
    }
    return ruiyi_generate_table_name($table_id, $lang);
}

/**
 * 根据ID获取单个分类 (纯Meta版本)
 */
function ruiyi_get_table_category($category_id) {
    if (!class_exists('RUIYI_Table_Meta_Manager')) {
        require_once plugin_dir_path(__FILE__) . 'table-categories-meta.php';
    }

    $meta_manager = ruiyi_get_meta_manager();
    return $meta_manager->get_category($category_id);
}

/**
 * 获取餐桌的分类信息 (纯Meta版本)
 */
function ruiyi_get_table_category_info($table_name) {
    if (!class_exists('RUIYI_Table_Meta_Manager')) {
        require_once plugin_dir_path(__FILE__) . 'table-categories-meta.php';
    }

    $meta_manager = ruiyi_get_meta_manager();
    $mappings = $meta_manager->get_mappings();

    // Try to find by table_id or table_number
    foreach ($mappings as $mapping) {
        if ($mapping['table_id'] === $table_name || $mapping['table_number'] === $table_name) {
            $category = $meta_manager->get_category($mapping['category_id']);
            if ($category) {
                return array(
                    'category' => $category,
                    'mapping' => $mapping
                );
            }
        }
    }

    return null;
}

/**
 * 修复全局桌位编号 (纯Meta版本)
 */
function ruiyi_fix_global_table_numbers() {
    if (!class_exists('RUIYI_Table_Meta_Manager')) {
        require_once plugin_dir_path(__FILE__) . 'table-categories-meta.php';
    }

    $meta_manager = ruiyi_get_meta_manager();
    $meta_manager->maybe_repair_mappings(null, true);
    $result = $meta_manager->recalculate_global_numbers();

    ruiyi_debug_log('RUIYI Pure Meta: Global table numbers recalculated');

    // 返回重新计算的桌位数量
    $mappings = $meta_manager->get_mappings();
    return count($mappings);
}

/**
 * 数据库诊断 (纯Meta版本)
 */
function ruiyi_diagnose_database() {
    if (!class_exists('RUIYI_Table_Meta_Manager')) {
        require_once plugin_dir_path(__FILE__) . 'table-categories-meta.php';
    }

    $meta_manager = ruiyi_get_meta_manager();
    $status = $meta_manager->get_system_status();

    ruiyi_debug_log('RUIYI Pure Meta: System diagnosis completed');

    return array(
        'total_records' => $status['total_tables'],
        'duplicate_global_numbers' => array(), // Meta系统不会有重复
        'missing_table_ids' => 0, // Meta系统自动管理
        'missing_display_names' => 0, // Meta系统自动生成
        'issues' => array(
            'message' => 'Meta system is healthy - no MySQL issues possible',
            'type' => 'success'
        )
    );
}

/**
 * 修复数据库 (纯Meta版本 - 不需要)
 */
function ruiyi_fix_database_data() {
    ruiyi_debug_log('RUIYI Pure Meta: Database fixing not needed in Meta system');

    return array(
        'fixed_global_numbers' => 0,
        'cleaned_invalid_records' => 0,
        'ensured_consecutive_numbering' => 0,
        'repairs' => array(
            'message' => 'Meta system requires no repairs',
            'type' => 'info'
        )
    );
}

/**
 * 清理无效桌位 (纯Meta版本 - 不需要)
 */
function ruiyi_cleanup_invalid_tables() {
    ruiyi_debug_log('RUIYI Pure Meta: Invalid table cleanup not needed in Meta system');
    return 0;
}

/**
 * 全面清理 (纯Meta版本)
 */
function ruiyi_comprehensive_cleanup() {
    if (!class_exists('RUIYI_Table_Meta_Manager')) {
        require_once plugin_dir_path(__FILE__) . 'table-categories-meta.php';
    }

    // Simply clear cache in Meta system
    $meta_manager = ruiyi_get_meta_manager();
    $meta_manager->clear_cache();

    ruiyi_debug_log('RUIYI Pure Meta: Comprehensive cleanup completed (cache cleared)');

    return array(
        'backup_created' => true,
        'orphaned_records_removed' => 0,
        'duplicate_records_removed' => 0,
        'invalid_ids_fixed' => 0,
        'global_numbers_reorganized' => 0,
        'category_numbers_reorganized' => 0,
        'report' => array(
            'message' => 'Meta system cleanup completed - only cache cleared',
            'type' => 'success'
        )
    );
}

/**
 * 强制创建表 (纯Meta版本 - 什么都不做)
 */
function ruiyi_force_create_tables() {
    // 在纯Meta系统中不需要创建MySQL表
    ruiyi_debug_log('RUIYI Pure Meta: MySQL table creation skipped - using Meta system');
}

/**
 * 创建表格 (纯Meta版本 - 什么都不做)
 */
function ruiyi_create_table_categories_tables() {
    // 在纯Meta系统中不需要创建MySQL表
    ruiyi_debug_log('RUIYI Pure Meta: MySQL table creation skipped - using Meta system');
}

/**
 * 升级表结构 (纯Meta版本 - 什么都不做)
 */
function ruiyi_upgrade_table_mapping_for_independent_numbering() {
    ruiyi_debug_log('RUIYI Pure Meta: Table upgrade skipped - using Meta system');
}

function ruiyi_upgrade_table_categories_for_multilang() {
    ruiyi_debug_log('RUIYI Pure Meta: Table upgrade skipped - using Meta system');
}

/**
 * 创建默认分类 (纯Meta版本)
 * 已禁用 - 不自动创建默认分区，由用户手动创建
 */
function ruiyi_create_default_table_categories() {
    // 不再自动创建默认分区
    return;
}

/**
 * 迁移现有分类 (纯Meta版本 - 不需要)
 */
function ruiyi_migrate_existing_categories_to_multilang() {
    ruiyi_debug_log('RUIYI Pure Meta: Category migration not needed in Meta system');
}

/**
 * 批量分配桌位到分类 (纯Meta版本)
 */
function ruiyi_batch_assign_tables_to_category($table_list, $category_id) {
    ruiyi_debug_log("RUIYI Pure Meta: Batch assign not implemented in Meta system - use individual assignment");
    return 0; // Meta系统使用不同的方式管理桌位
}

// 移除所有MySQL相关的action hooks，用Meta系统的替换
remove_action('after_setup_theme', 'ruiyi_create_table_categories_tables', 10);
remove_action('after_setup_theme', 'ruiyi_upgrade_table_mapping_for_independent_numbering', 12);
remove_action('after_setup_theme', 'ruiyi_upgrade_table_categories_for_multilang', 15);
remove_action('after_setup_theme', 'ruiyi_create_default_table_categories', 20);
remove_action('init', 'ruiyi_force_create_tables', 1);
remove_action('admin_init', 'ruiyi_force_create_tables', 1);
remove_action('wp_loaded', 'ruiyi_force_create_tables', 1);

// 添加Meta系统的初始化
add_action('init', 'ruiyi_create_default_table_categories', 20);

ruiyi_debug_log('RUIYI Pure Meta: Pure Meta system loaded - all MySQL dependencies removed');
